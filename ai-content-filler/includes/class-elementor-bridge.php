<?php
/**
 * Pont entre Elementor et le plugin.
 * - Enregistre l'endpoint REST API
 * - Injecte les scripts/styles dans l'éditeur Elementor
 * - Gère le rate limiting
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AICF_Elementor_Bridge {

    /** @var AICF_Elementor_Bridge|null */
    private static $instance = null;

    /** Namespace de la REST API */
    const REST_NAMESPACE = 'ai-content-filler/v1';

    /** Clé du transient pour le rate limiting (préfixe + user_id) */
    const RATE_LIMIT_PREFIX = 'aicf_rate_limit_';

    /** Délai minimum entre deux appels (en secondes) */
    const RATE_LIMIT_SECONDS = 10;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );

        // Injection des assets uniquement dans l'éditeur Elementor
        add_action( 'elementor/editor/after_enqueue_scripts', array( $this, 'enqueue_editor_assets' ) );
    }

    /**
     * Enregistre la route REST POST /wp-json/ai-content-filler/v1/generate
     */
    public function register_routes() {
        register_rest_route( self::REST_NAMESPACE, '/generate', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'handle_generate_request' ),
            'permission_callback' => array( $this, 'check_permissions' ),
            'args'                => array(
                'page_id'     => array(
                    'required'          => true,
                    'validate_callback' => function ( $value ) {
                        return is_numeric( $value ) && intval( $value ) > 0;
                    },
                    'sanitize_callback' => 'absint',
                ),
                'user_prompt' => array(
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_textarea_field',
                ),
                'widgets'     => array(
                    'required'          => true,
                    'validate_callback' => function ( $value ) {
                        return is_array( $value ) && ! empty( $value );
                    },
                ),
            ),
        ) );
    }

    /**
     * Vérifie que l'utilisateur a le droit d'éditer des posts.
     */
    public function check_permissions( WP_REST_Request $request ) {
        return current_user_can( 'edit_posts' );
    }

    /**
     * Callback principal de l'endpoint /generate.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function handle_generate_request( WP_REST_Request $request ) {
        // --- Rate limiting ---
        $user_id        = get_current_user_id();
        $transient_key  = self::RATE_LIMIT_PREFIX . $user_id;

        if ( get_transient( $transient_key ) ) {
            return new WP_Error(
                'aicf_rate_limited',
                sprintf(
                    __( 'Veuillez patienter %d secondes entre chaque génération.', 'ai-content-filler' ),
                    self::RATE_LIMIT_SECONDS
                ),
                array( 'status' => 429 )
            );
        }

        // Poser le verrou de rate limiting
        set_transient( $transient_key, true, self::RATE_LIMIT_SECONDS );

        // --- Extraction et nettoyage des paramètres ---
        $page_id     = $request->get_param( 'page_id' );
        $user_prompt = $request->get_param( 'user_prompt' );
        $raw_widgets = $request->get_param( 'widgets' );

        // Nettoyage de chaque widget
        $widgets = array();
        foreach ( $raw_widgets as $w ) {
            if ( empty( $w['id'] ) || empty( $w['type'] ) ) {
                continue;
            }
            $widgets[] = array(
                'id'           => sanitize_text_field( $w['id'] ),
                'type'         => sanitize_text_field( $w['type'] ),
                'current_text' => isset( $w['current_text'] ) ? sanitize_textarea_field( $w['current_text'] ) : '',
            );
        }

        if ( empty( $widgets ) ) {
            return new WP_Error(
                'aicf_no_widgets',
                __( 'Aucun widget valide trouvé dans la requête.', 'ai-content-filler' ),
                array( 'status' => 400 )
            );
        }

        // --- Appel à l'API Claude ---
        $api_handler = new AICF_API_Handler();
        $result      = $api_handler->generate_content( $user_prompt, $widgets, $page_id );

        if ( is_wp_error( $result ) ) {
            // Supprimer le rate limit en cas d'erreur pour permettre un retry
            delete_transient( $transient_key );
            return $result;
        }

        return new WP_REST_Response( array(
            'success' => true,
            'widgets' => $result,
        ), 200 );
    }

    /**
     * Enqueue les scripts et styles dans l'éditeur Elementor uniquement.
     */
    public function enqueue_editor_assets() {
        // CSS du panneau
        wp_enqueue_style(
            'aicf-editor-panel',
            AICF_PLUGIN_URL . 'assets/css/editor-panel.css',
            array(),
            AICF_VERSION
        );

        // JS du panneau
        wp_enqueue_script(
            'aicf-editor-panel',
            AICF_PLUGIN_URL . 'assets/js/editor-panel.js',
            array( 'jquery' ),
            AICF_VERSION,
            true
        );

        // Variables JS (nonce, URL API, etc.) — jamais la clé API !
        wp_localize_script( 'aicf-editor-panel', 'aicfConfig', array(
            'restUrl'  => esc_url_raw( rest_url( self::REST_NAMESPACE . '/generate' ) ),
            'nonce'    => wp_create_nonce( 'wp_rest' ),
            'i18n'     => array(
                'idle'          => __( 'Prêt à générer', 'ai-content-filler' ),
                'loading'       => __( 'Génération en cours...', 'ai-content-filler' ),
                'success'       => __( 'Contenu généré avec succès !', 'ai-content-filler' ),
                'error'         => __( 'Erreur', 'ai-content-filler' ),
                'no_widgets'    => __( 'Aucun widget Heading ou Text Editor trouvé sur cette page.', 'ai-content-filler' ),
                'empty_prompt'  => __( 'Veuillez saisir un prompt.', 'ai-content-filler' ),
                'no_api_key'    => __( 'Configurez votre clé API dans Réglages > AI Content Filler.', 'ai-content-filler' ),
                'rate_limited'  => __( 'Veuillez patienter quelques secondes avant de relancer.', 'ai-content-filler' ),
                'save_reminder' => __( 'N\'oubliez pas de sauvegarder la page avec le bouton Elementor.', 'ai-content-filler' ),
            ),
        ) );
    }
}
