<?php
/**
 * Pont entre l'éditeur Gutenberg et le plugin.
 * Injecte la sidebar TextFlow AI dans l'éditeur de blocs.
 * Réutilise l'endpoint REST /generate existant.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TXFLOW_Gutenberg_Bridge {

    /** @var TXFLOW_Gutenberg_Bridge|null */
    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor_assets' ) );
    }

    /**
     * Enqueue les scripts et styles dans l'éditeur Gutenberg uniquement.
     */
    public function enqueue_editor_assets() {
        // CSS de la sidebar
        wp_enqueue_style(
            'txflow-gutenberg-panel',
            TXFLOW_PLUGIN_URL . 'assets/css/gutenberg-panel.css',
            array(),
            TXFLOW_VERSION
        );

        // JS de la sidebar — dépend des packages Gutenberg/React
        wp_enqueue_script(
            'txflow-gutenberg-panel',
            TXFLOW_PLUGIN_URL . 'assets/js/gutenberg-panel.js',
            array( 'wp-plugins', 'wp-edit-post', 'wp-element', 'wp-data', 'wp-api-fetch', 'wp-i18n', 'wp-components' ),
            TXFLOW_VERSION,
            true
        );

        // Configuration passée au JS (jamais la clé API)
        wp_localize_script( 'txflow-gutenberg-panel', 'aicfGutenbergConfig', array(
            'restUrl' => esc_url_raw( rest_url( TXFLOW_Elementor_Bridge::REST_NAMESPACE . '/generate' ) ),
            'nonce'   => wp_create_nonce( 'wp_rest' ),
            'i18n'    => array(
                'panelTitle'         => __( 'TextFlow AI', 'textflow-ai' ),
                'idle'               => __( 'Prêt à générer', 'textflow-ai' ),
                'loading'            => __( 'Génération en cours...', 'textflow-ai' ),
                'success'            => __( 'Contenu généré avec succès !', 'textflow-ai' ),
                'error'              => __( 'Erreur', 'textflow-ai' ),
                'no_blocks'          => __( 'Aucun bloc texte trouvé sur cette page.', 'textflow-ai' ),
                'empty_prompt'       => __( 'Veuillez saisir un prompt.', 'textflow-ai' ),
                'no_api_key'         => __( 'Configurez votre clé API dans Réglages > TextFlow AI.', 'textflow-ai' ),
                'rate_limited'       => __( 'Veuillez patienter quelques secondes avant de relancer.', 'textflow-ai' ),
                'scan_btn'           => __( 'Scanner les blocs', 'textflow-ai' ),
                'generate_btn'       => __( 'Générer', 'textflow-ai' ),
                'rescan_btn'         => __( '↺', 'textflow-ai' ),
                'back_btn'           => __( '←', 'textflow-ai' ),
                'select_all'         => __( 'Tout sélectionner', 'textflow-ai' ),
                'deselect_all'       => __( 'Tout désélectionner', 'textflow-ai' ),
                'templates_label'    => __( 'Template :', 'textflow-ai' ),
                'prompt_placeholder' => __( "Décrivez le contenu à générer...\nEx : Agence web spécialisée en e-commerce, cible : TPE/PME", 'textflow-ai' ),
                'blocks_found'       => __( 'blocs trouvés', 'textflow-ai' ),
                'selected'           => __( 'sélectionnés', 'textflow-ai' ),
                'save_reminder'      => __( 'Pensez à sauvegarder la page', 'textflow-ai' ),
            ),
        ) );
    }
}
