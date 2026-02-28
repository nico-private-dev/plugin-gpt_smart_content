<?php
/**
 * Plugin Name: AI Content Filler
 * Plugin URI:  https://example.com/ai-content-filler
 * Description: Génère automatiquement le contenu des widgets Elementor (Heading, Text Editor) via l'API Claude d'Anthropic.
 * Version:     1.0.5
 * Author:      AI Content Filler
 * Author URI:  https://example.com
 * License:     GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ai-content-filler
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

// Sécurité : empêcher l'accès direct au fichier
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Constantes du plugin
define( 'AICF_VERSION', '1.0.5' );
define( 'AICF_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AICF_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'AICF_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// ---------------------------------------------------------------
// Freemius SDK — doit être chargé AVANT plugins_loaded pour que
// le SDK puisse enregistrer ses propres hooks WordPress.
// ---------------------------------------------------------------
require_once AICF_PLUGIN_DIR . 'includes/class-license.php';
require_once AICF_PLUGIN_DIR . 'includes/freemius-init.php';

/**
 * Classe principale du plugin.
 * Orchestre le chargement des composants et vérifie les dépendances.
 */
final class AI_Content_Filler {

    /** @var AI_Content_Filler|null Instance unique (singleton) */
    private static $instance = null;

    /**
     * Retourne l'instance unique du plugin.
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->load_dependencies();
        $this->init_hooks();
    }

    /**
     * Charge les fichiers de classes nécessaires.
     */
    private function load_dependencies() {
        require_once AICF_PLUGIN_DIR . 'includes/class-settings.php';
        require_once AICF_PLUGIN_DIR . 'includes/class-api-handler.php';
        require_once AICF_PLUGIN_DIR . 'includes/class-elementor-bridge.php';
        require_once AICF_PLUGIN_DIR . 'includes/class-gutenberg-bridge.php';
    }

    /**
     * Initialise les hooks WordPress.
     */
    private function init_hooks() {
        // Page de réglages admin
        AICF_Settings::get_instance();

        // Endpoint REST API + injection dans l'éditeur Elementor
        AICF_Elementor_Bridge::get_instance();

        // Sidebar dans l'éditeur Gutenberg
        AICF_Gutenberg_Bridge::get_instance();

        // Lien rapide vers les réglages depuis la page des plugins
        add_filter( 'plugin_action_links_' . AICF_PLUGIN_BASENAME, array( $this, 'add_settings_link' ) );

        // Vérification qu'Elementor est actif
        add_action( 'admin_notices', array( $this, 'check_elementor_dependency' ) );
    }

    /**
     * Ajoute un lien "Réglages" sur la page des plugins.
     */
    public function add_settings_link( $links ) {
        $settings_link = '<a href="' . esc_url( admin_url( 'options-general.php?page=ai-content-filler' ) ) . '">'
            . esc_html__( 'Réglages', 'ai-content-filler' ) . '</a>';
        array_unshift( $links, $settings_link );
        return $links;
    }

    /**
     * Affiche un avertissement si ni Elementor ni Gutenberg ne sont disponibles.
     * Le plugin fonctionne avec l'un ou l'autre — aucun avertissement si Gutenberg est actif.
     */
    public function check_elementor_dependency() {
        // Gutenberg est intégré à WordPress depuis 5.0 — toujours disponible
        // On n'affiche donc plus d'avertissement si Elementor est absent.
    }
}

// Démarrage du plugin après le chargement de tous les plugins
add_action( 'plugins_loaded', function () {
    AI_Content_Filler::get_instance();
} );
