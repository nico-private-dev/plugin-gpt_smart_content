<?php
/**
 * Custom Templates — templates de prompts personnalisés.
 *
 * Permet de sauvegarder des prompts réutilisables qui apparaissent
 * dans les panneaux Elementor et Gutenberg (via les éditeurs).
 *
 * REST endpoints :
 *   GET    /custom-templates         — liste
 *   POST   /custom-templates         — créer
 *   PUT    /custom-templates/{id}    — modifier
 *   DELETE /custom-templates/{id}    — supprimer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TXFLOW_Pro_Custom_Templates {

    /** @var TXFLOW_Pro_Custom_Templates|null */
    private static $instance = null;

    const OPTION_KEY = 'txflow_pro_custom_templates';

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
        add_action( 'admin_menu', array( $this, 'add_admin_page' ) );

        // Injecter les templates personnalisés dans les configs des éditeurs
        add_action( 'enqueue_block_editor_assets', array( $this, 'inject_into_gutenberg' ), 20 );
        add_action( 'elementor/editor/before_enqueue_scripts', array( $this, 'inject_into_elementor' ), 20 );
    }

    // -------------------------------------------------------------------------
    // REST API
    // -------------------------------------------------------------------------

    public function register_rest_routes() {
        $ns = 'textflow-ai/v1';

        register_rest_route( $ns, '/custom-templates', array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'rest_list' ),
                'permission_callback' => '__return_true', // Lecture publique (token REST protège)
            ),
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'rest_create' ),
                'permission_callback' => array( $this, 'rest_permission' ),
            ),
        ) );

        register_rest_route( $ns, '/custom-templates/(?P<id>[a-z0-9\-]+)', array(
            array(
                'methods'             => 'PUT',
                'callback'            => array( $this, 'rest_update' ),
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
        return current_user_can( 'manage_options' );
    }

    public function rest_list( WP_REST_Request $request ) {
        return rest_ensure_response( self::get_all() );
    }

    public function rest_create( WP_REST_Request $request ) {
        $data = $request->get_json_params();
        $tpl  = self::create_template( $data );
        if ( is_wp_error( $tpl ) ) {
            return $tpl;
        }
        return rest_ensure_response( $tpl );
    }

    public function rest_update( WP_REST_Request $request ) {
        $id  = $request->get_param( 'id' );
        $data = $request->get_json_params();
        $tpl  = self::update_template( $id, $data );
        if ( is_wp_error( $tpl ) ) {
            return $tpl;
        }
        return rest_ensure_response( $tpl );
    }

    public function rest_delete( WP_REST_Request $request ) {
        self::delete_template( $request->get_param( 'id' ) );
        return rest_ensure_response( array( 'deleted' => true ) );
    }

    // -------------------------------------------------------------------------
    // Injection dans les éditeurs
    // -------------------------------------------------------------------------

    /**
     * Ajoute les templates personnalisés dans la config Gutenberg via wp_add_inline_script.
     */
    public function inject_into_gutenberg() {
        $templates = self::get_all();
        if ( empty( $templates ) ) {
            return;
        }
        $js = 'if(window.aicfGutenbergConfig){aicfGutenbergConfig.customTemplates=' . wp_json_encode( $templates ) . ';}';
        wp_add_inline_script( 'txflow-gutenberg-panel', $js );
    }

    /**
     * Ajoute les templates personnalisés dans la config Elementor.
     */
    public function inject_into_elementor() {
        $templates = self::get_all();
        if ( empty( $templates ) ) {
            return;
        }
        $js = 'if(window.aicfConfig){aicfConfig.customTemplates=' . wp_json_encode( $templates ) . ';}';
        wp_add_inline_script( 'txflow-editor-panel', $js );
    }

    // -------------------------------------------------------------------------
    // CRUD interne
    // -------------------------------------------------------------------------

    public static function get_all() {
        return get_option( self::OPTION_KEY, array() );
    }

    public static function create_template( array $data ) {
        $tpls = self::get_all();
        $tpl  = array(
            'id'     => wp_generate_uuid4(),
            'label'  => sanitize_text_field( $data['label'] ?? '' ),
            'prompt' => sanitize_textarea_field( $data['prompt'] ?? '' ),
        );
        if ( empty( $tpl['label'] ) || empty( $tpl['prompt'] ) ) {
            return new WP_Error( 'missing_fields', __( 'Label et prompt sont requis.', 'textflow-ai-pro' ), array( 'status' => 400 ) );
        }
        $tpls[] = $tpl;
        update_option( self::OPTION_KEY, $tpls );
        return $tpl;
    }

    public static function update_template( $id, array $data ) {
        $tpls  = self::get_all();
        $found = false;
        foreach ( $tpls as &$t ) {
            if ( $t['id'] === $id ) {
                $t['label']  = sanitize_text_field( $data['label'] ?? $t['label'] );
                $t['prompt'] = sanitize_textarea_field( $data['prompt'] ?? $t['prompt'] );
                $found = $t;
                break;
            }
        }
        if ( ! $found ) {
            return new WP_Error( 'not_found', __( 'Template introuvable.', 'textflow-ai-pro' ), array( 'status' => 404 ) );
        }
        update_option( self::OPTION_KEY, $tpls );
        return $found;
    }

    public static function delete_template( $id ) {
        $tpls = array_filter( self::get_all(), function( $t ) use ( $id ) {
            return $t['id'] !== $id;
        } );
        update_option( self::OPTION_KEY, array_values( $tpls ) );
    }

    // -------------------------------------------------------------------------
    // Page admin
    // -------------------------------------------------------------------------

    public function add_admin_page() {
        add_submenu_page(
            'options-general.php',
            __( 'Templates personnalisés — TextFlow Pro', 'textflow-ai-pro' ),
            __( 'Templates IA', 'textflow-ai-pro' ),
            'manage_options',
            'txflow-pro-templates',
            array( $this, 'render_admin_page' )
        );
    }

    public function render_admin_page() {
        $templates = self::get_all();
        ?>
        <div class="wrap txflow-pro-wrap">
            <div class="txflow-pro-header">
                <h1>📋 <?php esc_html_e( 'Templates personnalisés', 'textflow-ai-pro' ); ?></h1>
                <p><?php esc_html_e( 'Créez vos propres prompts réutilisables. Ils apparaîtront dans le panneau Elementor et la sidebar Gutenberg.', 'textflow-ai-pro' ); ?></p>
            </div>

            <div class="txflow-pro-card">
                <h2><?php esc_html_e( 'Nouveau template', 'textflow-ai-pro' ); ?></h2>
                <form id="txflow-tpl-form" data-action="create">
                    <table class="form-table">
                        <tr>
                            <th><label for="tpl-label"><?php esc_html_e( 'Nom du template *', 'textflow-ai-pro' ); ?></label></th>
                            <td><input type="text" id="tpl-label" name="label" class="regular-text" required
                                placeholder="<?php esc_attr_e( 'Ex : Page de service', 'textflow-ai-pro' ); ?>"></td>
                        </tr>
                        <tr>
                            <th><label for="tpl-prompt"><?php esc_html_e( 'Prompt *', 'textflow-ai-pro' ); ?></label></th>
                            <td>
                                <textarea id="tpl-prompt" name="prompt" rows="5" class="large-text" required
                                    placeholder="<?php esc_attr_e( 'Saisissez le prompt que vous voulez réutiliser…', 'textflow-ai-pro' ); ?>"></textarea>
                                <p class="description"><?php esc_html_e( 'Conseil : soyez précis. Ex : "Agence web spécialisée en e-commerce, cible TPE/PME, ton professionnel et rassurant."', 'textflow-ai-pro' ); ?></p>
                            </td>
                        </tr>
                    </table>
                    <input type="hidden" id="tpl-edit-id" name="id" value="">
                    <p class="submit">
                        <button type="submit" class="button button-primary" id="tpl-submit-btn"><?php esc_html_e( 'Créer le template', 'textflow-ai-pro' ); ?></button>
                        <button type="button" class="button" id="tpl-cancel-btn" style="display:none;"><?php esc_html_e( 'Annuler', 'textflow-ai-pro' ); ?></button>
                    </p>
                </form>
            </div>

            <div class="txflow-pro-card">
                <h2><?php esc_html_e( 'Templates enregistrés', 'textflow-ai-pro' ); ?></h2>
                <div id="txflow-tpl-list">
                    <?php if ( empty( $templates ) ) : ?>
                        <p class="txflow-empty"><?php esc_html_e( 'Aucun template enregistré.', 'textflow-ai-pro' ); ?></p>
                    <?php else : ?>
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e( 'Nom', 'textflow-ai-pro' ); ?></th>
                                    <th><?php esc_html_e( 'Prompt (aperçu)', 'textflow-ai-pro' ); ?></th>
                                    <th style="width:140px;"><?php esc_html_e( 'Actions', 'textflow-ai-pro' ); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $templates as $t ) : ?>
                                <tr data-id="<?php echo esc_attr( $t['id'] ); ?>">
                                    <td><strong><?php echo esc_html( $t['label'] ); ?></strong></td>
                                    <td style="color:#6b7280;"><?php echo esc_html( mb_strimwidth( $t['prompt'], 0, 80, '…' ) ); ?></td>
                                    <td>
                                        <button class="button button-small txflow-tpl-edit-btn"
                                                data-tpl="<?php echo esc_attr( wp_json_encode( $t ) ); ?>">
                                            <?php esc_html_e( 'Modifier', 'textflow-ai-pro' ); ?>
                                        </button>
                                        <button class="button button-small txflow-tpl-delete-btn"
                                                data-id="<?php echo esc_attr( $t['id'] ); ?>">
                                            <?php esc_html_e( 'Supprimer', 'textflow-ai-pro' ); ?>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }
}
