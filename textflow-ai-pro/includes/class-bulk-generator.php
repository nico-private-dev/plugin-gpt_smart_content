<?php
/**
 * Bulk Generator — génération de contenu en masse.
 *
 * Permet de sélectionner plusieurs pages/articles et de générer
 * leur contenu en une seule opération via un système de file AJAX.
 *
 * REST endpoints :
 *   GET  /bulk-generate/posts     — liste des posts disponibles
 *   POST /bulk-generate/start     — démarre la queue
 *   POST /bulk-generate/process   — traite un élément de la queue
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TXFLOW_Pro_Bulk_Generator {

    /** @var TXFLOW_Pro_Bulk_Generator|null */
    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
        add_action( 'admin_menu', array( $this, 'add_admin_page' ) );
    }

    // -------------------------------------------------------------------------
    // REST API
    // -------------------------------------------------------------------------

    public function register_rest_routes() {
        $ns = 'textflow-ai/v1';

        register_rest_route( $ns, '/bulk-generate/posts', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( $this, 'rest_list_posts' ),
            'permission_callback' => array( $this, 'rest_permission' ),
        ) );

        register_rest_route( $ns, '/bulk-generate/process', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'rest_process_post' ),
            'permission_callback' => array( $this, 'rest_permission' ),
        ) );
    }

    public function rest_permission() {
        return current_user_can( 'edit_pages' );
    }

    /**
     * Retourne la liste des posts/pages publiés ou en brouillon.
     */
    public function rest_list_posts( WP_REST_Request $request ) {
        $post_types = array( 'page', 'post' );

        // Support des CPT de WooCommerce, etc. si disponibles
        if ( post_type_exists( 'product' ) ) {
            $post_types[] = 'product';
        }

        $posts = get_posts( array(
            'post_type'      => $post_types,
            'post_status'    => array( 'publish', 'draft' ),
            'posts_per_page' => 100,
            'orderby'        => 'modified',
            'order'          => 'DESC',
            'fields'         => 'ids',
        ) );

        $result = array();
        foreach ( $posts as $post_id ) {
            $result[] = array(
                'id'        => $post_id,
                'title'     => get_the_title( $post_id ),
                'type'      => get_post_type( $post_id ),
                'status'    => get_post_status( $post_id ),
                'editUrl'   => get_edit_post_link( $post_id, 'raw' ),
                'permalink' => get_permalink( $post_id ),
            );
        }

        return rest_ensure_response( $result );
    }

    /**
     * Traite un seul post de la queue :
     * scanne ses blocs Gutenberg et génère le contenu.
     *
     * Body JSON attendu :
     *  { post_id, prompt, widget_ids[] }
     */
    public function rest_process_post( WP_REST_Request $request ) {
        $params  = $request->get_json_params();
        $post_id = absint( $params['post_id'] ?? 0 );
        $prompt  = sanitize_textarea_field( $params['prompt'] ?? '' );

        if ( ! $post_id || ! get_post( $post_id ) ) {
            return new WP_Error( 'invalid_post', __( 'Post introuvable.', 'textflow-ai-pro' ), array( 'status' => 400 ) );
        }
        if ( empty( $prompt ) ) {
            return new WP_Error( 'empty_prompt', __( 'Le prompt est requis.', 'textflow-ai-pro' ), array( 'status' => 400 ) );
        }

        // On délègue au endpoint /generate du plugin principal
        // en construisant une requête REST interne
        $rest_url = rest_url( 'textflow-ai/v1/generate' );
        $response = wp_remote_post( $rest_url, array(
            'headers' => array(
                'Content-Type'  => 'application/json',
                'X-WP-Nonce'    => wp_create_nonce( 'wp_rest' ),
                'Cookie'        => $_SERVER['HTTP_COOKIE'] ?? '',
            ),
            'body'    => wp_json_encode( array(
                'prompt'  => $prompt,
                'widgets' => $params['widgets'] ?? array(),
                'page_id' => $post_id,
            ) ),
            'timeout' => 120,
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 ) {
            return new WP_Error(
                'generate_failed',
                $body['message'] ?? __( 'Erreur lors de la génération.', 'textflow-ai-pro' ),
                array( 'status' => $code )
            );
        }

        return rest_ensure_response( $body );
    }

    // -------------------------------------------------------------------------
    // Page admin
    // -------------------------------------------------------------------------

    public function add_admin_page() {
        add_submenu_page(
            'options-general.php',
            __( 'Génération en masse — TextFlow Pro', 'textflow-ai-pro' ),
            __( 'Génération en masse', 'textflow-ai-pro' ),
            'edit_pages',
            'txflow-pro-bulk',
            array( $this, 'render_admin_page' )
        );
    }

    public function render_admin_page() {
        ?>
        <div class="wrap txflow-pro-wrap">
            <div class="txflow-pro-header">
                <h1>⚡ <?php esc_html_e( 'Génération en masse', 'textflow-ai-pro' ); ?></h1>
                <p><?php esc_html_e( 'Sélectionnez les pages/articles à remplir et lancez la génération en une seule opération.', 'textflow-ai-pro' ); ?></p>
            </div>

            <div class="txflow-pro-card">
                <div id="txflow-bulk-step1">
                    <h2><?php esc_html_e( 'Étape 1 — Configurer la génération', 'textflow-ai-pro' ); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th><label for="bulk-prompt"><?php esc_html_e( 'Prompt commun', 'textflow-ai-pro' ); ?></label></th>
                            <td>
                                <textarea id="bulk-prompt" rows="4" class="large-text"
                                    placeholder="<?php esc_attr_e( 'Décrivez le contexte général du site ou du projet…', 'textflow-ai-pro' ); ?>"></textarea>
                                <p class="description"><?php esc_html_e( 'Ce prompt sera utilisé pour toutes les pages sélectionnées. Vous pouvez utiliser les templates si le plugin Pro les définit.', 'textflow-ai-pro' ); ?></p>
                            </td>
                        </tr>
                    </table>

                    <h2 style="margin-top:24px;"><?php esc_html_e( 'Étape 2 — Sélectionner les pages', 'textflow-ai-pro' ); ?></h2>
                    <div id="txflow-bulk-post-list">
                        <p class="description"><?php esc_html_e( 'Chargement des pages…', 'textflow-ai-pro' ); ?></p>
                    </div>

                    <p style="margin-top:16px;">
                        <button type="button" id="txflow-bulk-start-btn" class="button button-primary" disabled>
                            <?php esc_html_e( 'Lancer la génération', 'textflow-ai-pro' ); ?>
                        </button>
                        <span id="txflow-bulk-selection-count" style="margin-left:12px; color:#6b7280;"></span>
                    </p>
                </div>

                <div id="txflow-bulk-progress" style="display:none;">
                    <h2><?php esc_html_e( 'Génération en cours…', 'textflow-ai-pro' ); ?></h2>
                    <div id="txflow-bulk-progress-bar-wrap" style="background:#e5e7eb; border-radius:4px; height:12px; margin:16px 0;">
                        <div id="txflow-bulk-progress-bar" style="background:#6366f1; height:100%; border-radius:4px; width:0%; transition:width .3s;"></div>
                    </div>
                    <div id="txflow-bulk-progress-text" style="color:#6b7280; font-size:13px;"></div>
                    <div id="txflow-bulk-log" style="margin-top:16px; max-height:300px; overflow-y:auto; background:#f9fafb; border:1px solid #e5e7eb; border-radius:4px; padding:12px; font-size:12px; font-family:monospace;"></div>
                    <button type="button" id="txflow-bulk-done-btn" class="button" style="margin-top:16px; display:none;">
                        <?php esc_html_e( '← Retour', 'textflow-ai-pro' ); ?>
                    </button>
                </div>
            </div>
        </div>

        <script>
        (function($){
            var restUrl  = <?php echo wp_json_encode( rest_url( 'textflow-ai/v1' ) ); ?>;
            var nonce    = <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;
            var posts    = [];
            var selected = {};

            // Charger la liste des posts
            $.ajax({
                url: restUrl + '/bulk-generate/posts',
                headers: { 'X-WP-Nonce': nonce },
                success: function(data) {
                    renderPostList(data);
                },
                error: function() {
                    $('#txflow-bulk-post-list').html('<p style="color:red;"><?php esc_html_e( 'Erreur lors du chargement des pages.', 'textflow-ai-pro' ); ?></p>');
                }
            });

            function renderPostList(data) {
                posts = data;
                var html = '<table class="wp-list-table widefat fixed striped" style="margin-top:8px;">'
                    + '<thead><tr>'
                    + '<td class="manage-column column-cb check-column"><input type="checkbox" id="txflow-bulk-select-all"></td>'
                    + '<th><?php esc_html_e( 'Titre', 'textflow-ai-pro' ); ?></th>'
                    + '<th><?php esc_html_e( 'Type', 'textflow-ai-pro' ); ?></th>'
                    + '<th><?php esc_html_e( 'Statut', 'textflow-ai-pro' ); ?></th>'
                    + '</tr></thead><tbody>';

                data.forEach(function(p) {
                    html += '<tr>'
                        + '<th scope="row" class="check-column"><input type="checkbox" class="txflow-bulk-post-cb" value="' + p.id + '"></th>'
                        + '<td><strong><a href="' + p.editUrl + '" target="_blank">' + p.title + '</a></strong></td>'
                        + '<td>' + p.type + '</td>'
                        + '<td>' + p.status + '</td>'
                        + '</tr>';
                });
                html += '</tbody></table>';
                $('#txflow-bulk-post-list').html(html);
                updateCount();
            }

            function updateCount() {
                var n = $('.txflow-bulk-post-cb:checked').length;
                $('#txflow-bulk-selection-count').text(n + ' <?php esc_html_e( 'page(s) sélectionnée(s)', 'textflow-ai-pro' ); ?>');
                $('#txflow-bulk-start-btn').prop('disabled', n === 0 || $('#bulk-prompt').val().trim() === '');
            }

            $(document).on('change', '.txflow-bulk-post-cb, #txflow-bulk-select-all', function() {
                if ( $(this).is('#txflow-bulk-select-all') ) {
                    $('.txflow-bulk-post-cb').prop('checked', $(this).is(':checked'));
                }
                updateCount();
            });
            $('#bulk-prompt').on('input', updateCount);

            $('#txflow-bulk-start-btn').on('click', function() {
                var selectedIds = [];
                $('.txflow-bulk-post-cb:checked').each(function() {
                    selectedIds.push(parseInt($(this).val(), 10));
                });
                if ( selectedIds.length === 0 ) return;
                startBulk(selectedIds, $('#bulk-prompt').val().trim());
            });

            function startBulk(ids, prompt) {
                $('#txflow-bulk-step1').hide();
                $('#txflow-bulk-progress').show();
                var total   = ids.length;
                var current = 0;

                function processNext() {
                    if ( current >= total ) {
                        $('#txflow-bulk-progress-text').text('<?php esc_html_e( 'Terminé !', 'textflow-ai-pro' ); ?>');
                        $('#txflow-bulk-done-btn').show();
                        return;
                    }
                    var postId = ids[current];
                    var post   = posts.find(function(p){ return p.id === postId; });
                    $('#txflow-bulk-progress-text').text(
                        '(' + (current+1) + '/' + total + ') ' + (post ? post.title : '#' + postId)
                    );
                    $('#txflow-bulk-progress-bar').css('width', Math.round((current/total)*100) + '%');

                    $.ajax({
                        url: restUrl + '/bulk-generate/process',
                        method: 'POST',
                        contentType: 'application/json',
                        headers: { 'X-WP-Nonce': nonce },
                        data: JSON.stringify({ post_id: postId, prompt: prompt, widgets: [] }),
                        success: function(res) {
                            logLine('✓ ' + (post ? post.title : '#' + postId));
                            current++;
                            processNext();
                        },
                        error: function(xhr) {
                            var msg = xhr.responseJSON ? (xhr.responseJSON.message || 'Erreur') : 'Erreur';
                            logLine('✗ ' + (post ? post.title : '#' + postId) + ' — ' + msg);
                            current++;
                            processNext();
                        }
                    });
                }

                processNext();
            }

            function logLine(msg) {
                var $log = $('#txflow-bulk-log');
                $log.append('<div>' + msg + '</div>');
                $log.scrollTop($log[0].scrollHeight);
            }

            $('#txflow-bulk-done-btn').on('click', function() {
                $('#txflow-bulk-progress').hide();
                $('#txflow-bulk-step1').show();
                $('#txflow-bulk-progress-bar').css('width', '0%');
                $('#txflow-bulk-log').empty();
            });

        })(jQuery);
        </script>
        <?php
    }
}
