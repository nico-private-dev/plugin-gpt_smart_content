<?php
/**
 * Content History — historique des générations.
 *
 * Enregistre chaque génération dans une table dédiée et permet
 * de restaurer n'importe quelle version précédente.
 *
 * Table : {prefix}txflow_history
 *   id, post_id, prompt, generated_at, content_snapshot (JSON), user_id
 *
 * REST endpoints :
 *   GET    /history              — liste (paginée)
 *   GET    /history/{id}         — détail d'une entrée
 *   DELETE /history/{id}         — supprimer une entrée
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TXFLOW_Pro_Content_History {

    /** @var TXFLOW_Pro_Content_History|null */
    private static $instance = null;

    const TABLE_NAME = 'txflow_history';

    /** Nombre de jours de rétention */
    const RETENTION_DAYS = 30;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
        add_action( 'admin_menu', array( $this, 'add_admin_page' ) );

        // Enregistrer les générations via le filtre de résultat
        add_action( 'txflow_content_generated', array( $this, 'record_generation' ), 10, 3 );

        // Nettoyage hebdomadaire
        add_action( 'txflow_pro_history_cleanup', array( $this, 'cleanup_old_entries' ) );
        if ( ! wp_next_scheduled( 'txflow_pro_history_cleanup' ) ) {
            wp_schedule_event( time(), 'weekly', 'txflow_pro_history_cleanup' );
        }
    }

    // -------------------------------------------------------------------------
    // Création de table
    // -------------------------------------------------------------------------

    public static function create_table() {
        global $wpdb;
        $table      = $wpdb->prefix . self::TABLE_NAME;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id           BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id      BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            user_id      BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            prompt       TEXT NOT NULL,
            content_json LONGTEXT NOT NULL,
            generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY post_id (post_id),
            KEY generated_at (generated_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    // -------------------------------------------------------------------------
    // Enregistrement automatique
    // -------------------------------------------------------------------------

    /**
     * Appelé via do_action('txflow_content_generated', $post_id, $prompt, $content_map).
     * $content_map = [ widgetId => generatedContent, ... ]
     */
    public function record_generation( $post_id, $prompt, $content_map ) {
        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . self::TABLE_NAME,
            array(
                'post_id'      => absint( $post_id ),
                'user_id'      => get_current_user_id(),
                'prompt'       => sanitize_textarea_field( $prompt ),
                'content_json' => wp_json_encode( $content_map ),
                'generated_at' => current_time( 'mysql' ),
            ),
            array( '%d', '%d', '%s', '%s', '%s' )
        );
    }

    // -------------------------------------------------------------------------
    // REST API
    // -------------------------------------------------------------------------

    public function register_rest_routes() {
        $ns = 'textflow-ai/v1';

        register_rest_route( $ns, '/history', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( $this, 'rest_list' ),
            'permission_callback' => array( $this, 'rest_permission' ),
            'args'                => array(
                'page'    => array( 'default' => 1,  'sanitize_callback' => 'absint' ),
                'per_page'=> array( 'default' => 20, 'sanitize_callback' => 'absint' ),
                'post_id' => array( 'default' => 0,  'sanitize_callback' => 'absint' ),
            ),
        ) );

        register_rest_route( $ns, '/history/(?P<id>\d+)', array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'rest_get' ),
                'permission_callback' => array( $this, 'rest_permission' ),
            ),
            array(
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => array( $this, 'rest_delete' ),
                'permission_callback' => array( $this, 'rest_permission' ),
            ),
        ) );
    }

    public function rest_permission() {
        return current_user_can( 'edit_posts' );
    }

    public function rest_list( WP_REST_Request $request ) {
        global $wpdb;
        $table    = $wpdb->prefix . self::TABLE_NAME;
        $page     = $request->get_param( 'page' );
        $per_page = min( $request->get_param( 'per_page' ), 50 );
        $post_id  = $request->get_param( 'post_id' );
        $offset   = ( $page - 1 ) * $per_page;

        $where = '';
        $args  = array();
        if ( $post_id ) {
            $where = 'WHERE post_id = %d';
            $args[] = $post_id;
        }

        $total = $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where}", $args ); // phpcs:ignore

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, post_id, user_id, prompt, generated_at FROM {$table} {$where} ORDER BY generated_at DESC LIMIT %d OFFSET %d", // phpcs:ignore
                array_merge( $args, array( $per_page, $offset ) )
            ),
            ARRAY_A
        );

        // Enrichir avec le titre du post
        foreach ( $rows as &$row ) {
            $row['post_title'] = get_the_title( $row['post_id'] );
        }

        return rest_ensure_response( array(
            'items' => $rows,
            'total' => (int) $total,
            'pages' => (int) ceil( $total / $per_page ),
        ) );
    }

    public function rest_get( WP_REST_Request $request ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;
        $row   = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $request->get_param( 'id' ) ), // phpcs:ignore
            ARRAY_A
        );
        if ( ! $row ) {
            return new WP_Error( 'not_found', __( 'Entrée introuvable.', 'textflow-ai-pro' ), array( 'status' => 404 ) );
        }
        $row['content_map'] = json_decode( $row['content_json'], true );
        unset( $row['content_json'] );
        return rest_ensure_response( $row );
    }

    public function rest_delete( WP_REST_Request $request ) {
        global $wpdb;
        $wpdb->delete( $wpdb->prefix . self::TABLE_NAME, array( 'id' => $request->get_param( 'id' ) ), array( '%d' ) );
        return rest_ensure_response( array( 'deleted' => true ) );
    }

    // -------------------------------------------------------------------------
    // Nettoyage
    // -------------------------------------------------------------------------

    public function cleanup_old_entries() {
        global $wpdb;
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}" . self::TABLE_NAME . " WHERE generated_at < DATE_SUB(NOW(), INTERVAL %d DAY)", // phpcs:ignore
                self::RETENTION_DAYS
            )
        );
    }

    // -------------------------------------------------------------------------
    // Page admin
    // -------------------------------------------------------------------------

    public function add_admin_page() {
        add_submenu_page(
            'options-general.php',
            __( 'Historique — TextFlow Pro', 'textflow-ai-pro' ),
            __( 'Historique IA', 'textflow-ai-pro' ),
            'edit_posts',
            'txflow-pro-history',
            array( $this, 'render_admin_page' )
        );
    }

    public function render_admin_page() {
        ?>
        <div class="wrap txflow-pro-wrap">
            <div class="txflow-pro-header">
                <h1>🕒 <?php esc_html_e( 'Historique des générations', 'textflow-ai-pro' ); ?></h1>
                <p><?php printf(
                    /* translators: %d: number of days */
                    esc_html__( 'Les %d derniers jours de générations. Cliquez sur une entrée pour voir le contenu généré.', 'textflow-ai-pro' ),
                    self::RETENTION_DAYS
                ); ?></p>
            </div>

            <div class="txflow-pro-card">
                <div id="txflow-history-loading" style="color:#6b7280;"><?php esc_html_e( 'Chargement…', 'textflow-ai-pro' ); ?></div>
                <div id="txflow-history-content" style="display:none;"></div>
            </div>
        </div>

        <script>
        (function($){
            var restUrl = <?php echo wp_json_encode( rest_url( 'textflow-ai/v1' ) ); ?>;
            var nonce   = <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;

            $.ajax({
                url: restUrl + '/history?per_page=50',
                headers: { 'X-WP-Nonce': nonce },
                success: function(data) {
                    $('#txflow-history-loading').hide();
                    if ( !data.items || data.items.length === 0 ) {
                        $('#txflow-history-content').html('<p class="txflow-empty"><?php esc_html_e( 'Aucune génération enregistrée.', 'textflow-ai-pro' ); ?></p>').show();
                        return;
                    }
                    var html = '<table class="wp-list-table widefat fixed striped">'
                        + '<thead><tr>'
                        + '<th><?php esc_html_e( 'Page', 'textflow-ai-pro' ); ?></th>'
                        + '<th><?php esc_html_e( 'Prompt (aperçu)', 'textflow-ai-pro' ); ?></th>'
                        + '<th><?php esc_html_e( 'Date', 'textflow-ai-pro' ); ?></th>'
                        + '<th style="width:80px;"><?php esc_html_e( 'Actions', 'textflow-ai-pro' ); ?></th>'
                        + '</tr></thead><tbody>';

                    data.items.forEach(function(row) {
                        var prompt = row.prompt.length > 60 ? row.prompt.substring(0,60) + '…' : row.prompt;
                        html += '<tr>'
                            + '<td><strong>' + (row.post_title || '#' + row.post_id) + '</strong></td>'
                            + '<td style="color:#6b7280;">' + prompt + '</td>'
                            + '<td>' + row.generated_at + '</td>'
                            + '<td>'
                            + '<button class="button button-small txflow-hist-view-btn" data-id="' + row.id + '"><?php esc_html_e( 'Voir', 'textflow-ai-pro' ); ?></button> '
                            + '<button class="button button-small txflow-hist-del-btn" data-id="' + row.id + '"><?php esc_html_e( 'Suppr.', 'textflow-ai-pro' ); ?></button>'
                            + '</td>'
                            + '</tr>';
                    });
                    html += '</tbody></table>';
                    $('#txflow-history-content').html(html).show();
                },
                error: function() {
                    $('#txflow-history-loading').html('<p style="color:red;"><?php esc_html_e( 'Erreur de chargement.', 'textflow-ai-pro' ); ?></p>');
                }
            });

            $(document).on('click', '.txflow-hist-view-btn', function() {
                var id = $(this).data('id');
                $.ajax({
                    url: restUrl + '/history/' + id,
                    headers: { 'X-WP-Nonce': nonce },
                    success: function(row) {
                        var msg = '<?php esc_html_e( 'Prompt :', 'textflow-ai-pro' ); ?> ' + row.prompt + '\n\n';
                        if ( row.content_map ) {
                            Object.keys(row.content_map).forEach(function(k) {
                                msg += k + ' :\n' + row.content_map[k] + '\n\n';
                            });
                        }
                        alert(msg);
                    }
                });
            });

            $(document).on('click', '.txflow-hist-del-btn', function() {
                if ( !confirm('<?php esc_html_e( 'Supprimer cette entrée ?', 'textflow-ai-pro' ); ?>') ) return;
                var $btn = $(this);
                $.ajax({
                    url: restUrl + '/history/' + $btn.data('id'),
                    method: 'DELETE',
                    headers: { 'X-WP-Nonce': nonce },
                    success: function() {
                        $btn.closest('tr').fadeOut();
                    }
                });
            });

        })(jQuery);
        </script>
        <?php
    }
}
