<?php
/**
 * Pont entre l'éditeur Gutenberg et le plugin.
 * Injecte la sidebar AI Content Filler dans l'éditeur de blocs.
 * Réutilise l'endpoint REST /generate existant.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AICF_Gutenberg_Bridge {

    /** @var AICF_Gutenberg_Bridge|null */
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
            'aicf-gutenberg-panel',
            AICF_PLUGIN_URL . 'assets/css/gutenberg-panel.css',
            array(),
            AICF_VERSION
        );

        // JS de la sidebar — dépend des packages Gutenberg/React
        wp_enqueue_script(
            'aicf-gutenberg-panel',
            AICF_PLUGIN_URL . 'assets/js/gutenberg-panel.js',
            array( 'wp-plugins', 'wp-edit-post', 'wp-element', 'wp-data', 'wp-api-fetch', 'wp-i18n', 'wp-components' ),
            AICF_VERSION,
            true
        );

        $is_pro = aicf_is_pro();

        // Configuration passée au JS (jamais la clé API)
        wp_localize_script( 'aicf-gutenberg-panel', 'aicfGutenbergConfig', array(
            'restUrl'        => esc_url_raw( rest_url( AICF_Elementor_Bridge::REST_NAMESPACE . '/generate' ) ),
            'nonce'          => wp_create_nonce( 'wp_rest' ),
            'isPro'          => $is_pro,
            'upgradeUrl'     => esc_url_raw( AICF_License::get_upgrade_url() ),
            'freeBlocks'     => $is_pro ? array() : AICF_License::FREE_BLOCKS,
            'freeTemplates'  => $is_pro ? array() : AICF_License::FREE_TEMPLATES,
            'dailyRemaining' => AICF_License::get_remaining_generations(),
            'dailyLimit'     => $is_pro ? -1 : AICF_License::FREE_DAILY_LIMIT,
            'i18n'           => array(
                'panelTitle'     => __( 'AI Content Filler', 'ai-content-filler' ),
                'idle'           => __( 'Prêt à générer', 'ai-content-filler' ),
                'loading'        => __( 'Génération en cours...', 'ai-content-filler' ),
                'success'        => __( 'Contenu généré avec succès !', 'ai-content-filler' ),
                'error'          => __( 'Erreur', 'ai-content-filler' ),
                'no_blocks'      => __( 'Aucun bloc texte trouvé sur cette page.', 'ai-content-filler' ),
                'empty_prompt'   => __( 'Veuillez saisir un prompt.', 'ai-content-filler' ),
                'no_api_key'     => __( 'Configurez votre clé API dans Réglages > AI Content Filler.', 'ai-content-filler' ),
                'rate_limited'   => __( 'Veuillez patienter quelques secondes avant de relancer.', 'ai-content-filler' ),
                'daily_limit'    => __( 'Limite quotidienne atteinte. Revenez demain ou passez en Pro.', 'ai-content-filler' ),
                'pro_feature'    => __( 'Fonctionnalité Pro', 'ai-content-filler' ),
                'upgrade'        => __( 'Passer en Pro', 'ai-content-filler' ),
                'pro_block'      => __( 'Ce type de bloc est réservé au plan Pro.', 'ai-content-filler' ),
                'scan_btn'       => __( 'Scanner les blocs', 'ai-content-filler' ),
                'generate_btn'   => __( 'Générer', 'ai-content-filler' ),
                'rescan_btn'     => __( '↺', 'ai-content-filler' ),
                'back_btn'       => __( '←', 'ai-content-filler' ),
                'select_all'     => __( 'Tout sélectionner', 'ai-content-filler' ),
                'deselect_all'   => __( 'Tout désélectionner', 'ai-content-filler' ),
                'templates_label' => __( 'Template :', 'ai-content-filler' ),
                'prompt_placeholder' => __( "Décrivez le contenu à générer...\nEx : Agence web spécialisée en e-commerce, cible : TPE/PME", 'ai-content-filler' ),
                'blocks_found'   => __( 'blocs trouvés', 'ai-content-filler' ),
                'selected'       => __( 'sélectionnés', 'ai-content-filler' ),
                'free_plan'      => __( 'FREE', 'ai-content-filler' ),
                'pro_plan'       => __( 'PRO', 'ai-content-filler' ),
                'daily_remaining' => __( 'générations restantes aujourd\'hui', 'ai-content-filler' ),
                'unlimited'      => __( 'Générations illimitées', 'ai-content-filler' ),
            ),
        ) );
    }
}
