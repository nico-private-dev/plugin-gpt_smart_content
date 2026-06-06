<?php
/**
 * Plugin Name: TextFlow AI Pro
 * Plugin URI:  https://textflowlab.com/pro
 * Description: Add-on Pro pour TextFlow AI — Brand Voice, génération en masse, templates personnalisés, historique et méta SEO.
 * Version:     1.0.0
 * Author:      nicolombe
 * Author URI:  https://textflowlab.com
 * License:     GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: textflow-ai-pro
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 *
 * Requires TextFlow AI (plugin gratuit) installé et activé.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Constantes
define( 'TXFLOW_PRO_VERSION', '1.0.0' );
define( 'TXFLOW_PRO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'TXFLOW_PRO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'TXFLOW_PRO_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Charger Freemius add-on AVANT plugins_loaded
require_once TXFLOW_PRO_PLUGIN_DIR . 'includes/freemius-init-pro.php';

/**
 * Vérifie que le plugin principal TextFlow AI est actif.
 */
function txflow_pro_check_dependency() {
    if ( ! defined( 'TXFLOW_VERSION' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-error"><p>'
                . sprintf(
                    /* translators: link to plugins page */
                    wp_kses_post( __( '<strong>TextFlow AI Pro</strong> nécessite le plugin <strong>TextFlow AI</strong> (gratuit). <a href="%s">Installez-le</a> pour continuer.', 'textflow-ai-pro' ) ),
                    esc_url( admin_url( 'plugin-install.php?s=textflow-ai&tab=search' ) )
                )
                . '</p></div>';
        } );
        return false;
    }
    return true;
}

/**
 * Démarre le plugin Pro.
 */
function txflow_pro_init() {
    if ( ! txflow_pro_check_dependency() ) {
        return;
    }

    // Charger les classes Pro
    require_once TXFLOW_PRO_PLUGIN_DIR . 'includes/class-brand-voice.php';
    require_once TXFLOW_PRO_PLUGIN_DIR . 'includes/class-custom-templates.php';
    require_once TXFLOW_PRO_PLUGIN_DIR . 'includes/class-bulk-generator.php';
    require_once TXFLOW_PRO_PLUGIN_DIR . 'includes/class-content-history.php';
    require_once TXFLOW_PRO_PLUGIN_DIR . 'includes/class-seo-meta.php';

    // Initialiser chaque module
    TXFLOW_Pro_Brand_Voice::get_instance();
    TXFLOW_Pro_Custom_Templates::get_instance();
    TXFLOW_Pro_Bulk_Generator::get_instance();
    TXFLOW_Pro_Content_History::get_instance();
    TXFLOW_Pro_Seo_Meta::get_instance();

    // Enqueue assets admin Pro
    add_action( 'admin_enqueue_scripts', 'txflow_pro_enqueue_admin_assets' );
}
add_action( 'plugins_loaded', 'txflow_pro_init', 20 ); // Après le plugin principal (priorité 10)

/**
 * Assets admin communs au plugin Pro.
 */
function txflow_pro_enqueue_admin_assets( $hook ) {
    // Charger sur toutes les pages admin Pro
    if ( strpos( $hook, 'txflow-pro' ) === false && strpos( $hook, 'textflow-ai' ) === false ) {
        return;
    }
    wp_enqueue_style(
        'txflow-pro-admin',
        TXFLOW_PRO_PLUGIN_URL . 'assets/css/pro-admin.css',
        array(),
        TXFLOW_PRO_VERSION
    );
    wp_enqueue_script(
        'txflow-pro-admin',
        TXFLOW_PRO_PLUGIN_URL . 'assets/js/pro-admin.js',
        array( 'jquery' ),
        TXFLOW_PRO_VERSION,
        true
    );
    wp_localize_script( 'txflow-pro-admin', 'txflowPro', array(
        'ajaxUrl' => admin_url( 'admin-ajax.php' ),
        'nonce'   => wp_create_nonce( 'txflow_pro_nonce' ),
        'restUrl' => rest_url( 'textflow-ai/v1' ),
        'restNonce' => wp_create_nonce( 'wp_rest' ),
        'i18n'    => array(
            'saving'      => __( 'Enregistrement…', 'textflow-ai-pro' ),
            'saved'       => __( 'Enregistré !', 'textflow-ai-pro' ),
            'deleting'    => __( 'Suppression…', 'textflow-ai-pro' ),
            'confirm_del' => __( 'Supprimer cet élément ?', 'textflow-ai-pro' ),
            'generating'  => __( 'Génération en cours…', 'textflow-ai-pro' ),
            'error'       => __( 'Erreur', 'textflow-ai-pro' ),
        ),
    ) );
}

/**
 * Activation : créer les tables DB.
 */
register_activation_hook( __FILE__, function () {
    if ( class_exists( 'TXFLOW_Pro_Content_History' ) ) {
        TXFLOW_Pro_Content_History::create_table();
    }
} );
