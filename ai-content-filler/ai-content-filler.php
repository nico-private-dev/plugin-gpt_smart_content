<?php
/**
 * Plugin Name: TextFlow AI
 * Plugin URI:  https://textflowlab.com
 * Description: Generate content for your Gutenberg blocks and Elementor widgets in one click using AI (Claude, GPT-4o, DeepSeek).
 * Version:     1.2.0
 * Author:      nicolombe
 * Author URI:  https://textflowlab.com
 * License:     GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: textflow-ai
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

// Sécurité : empêcher l'accès direct au fichier
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Constantes du plugin
define( 'TXFLOW_VERSION', '1.2.0' );
define( 'TXFLOW_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'TXFLOW_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'TXFLOW_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Charger Freemius AVANT plugins_loaded
require_once TXFLOW_PLUGIN_DIR . 'includes/freemius-init.php';

/**
 * Classe principale du plugin.
 * Orchestre le chargement des composants et vérifie les dépendances.
 */
final class TextFlow_AI {

    /** @var TextFlow_AI|null Instance unique (singleton) */
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
        require_once TXFLOW_PLUGIN_DIR . 'includes/class-settings.php';
        require_once TXFLOW_PLUGIN_DIR . 'includes/class-api-handler.php';
        require_once TXFLOW_PLUGIN_DIR . 'includes/class-elementor-bridge.php';
        require_once TXFLOW_PLUGIN_DIR . 'includes/class-gutenberg-bridge.php';
    }

    /**
     * Initialise les hooks WordPress.
     */
    private function init_hooks() {
        // Page de réglages admin
        TXFLOW_Settings::get_instance();

        // Endpoint REST API + injection dans l'éditeur Elementor
        TXFLOW_Elementor_Bridge::get_instance();

        // Sidebar dans l'éditeur Gutenberg
        TXFLOW_Gutenberg_Bridge::get_instance();

        // Lien rapide vers les réglages depuis la page des plugins
        add_filter( 'plugin_action_links_' . TXFLOW_PLUGIN_BASENAME, array( $this, 'add_settings_link' ) );
    }

    /**
     * Ajoute un lien "Réglages" sur la page des plugins.
     */
    public function add_settings_link( $links ) {
        $settings_link = '<a href="' . esc_url( admin_url( 'options-general.php?page=textflow-ai' ) ) . '">'
            . esc_html__( 'Réglages', 'textflow-ai' ) . '</a>';
        array_unshift( $links, $settings_link );
        return $links;
    }
}

// Démarrage du plugin après le chargement de tous les plugins
add_action( 'plugins_loaded', function () {
    TextFlow_AI::get_instance();
} );
