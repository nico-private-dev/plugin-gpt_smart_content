<?php
/**
 * Initialisation du SDK Freemius pour la gestion des licences free/pro.
 *
 * SDK téléchargé depuis https://freemius.com et placé dans /vendor/freemius/
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'tex_fs' ) ) {

    /**
     * Retourne l'instance globale du SDK Freemius.
     *
     * @return Freemius|null
     */
    function tex_fs() {
        global $tex_fs;

        if ( ! isset( $tex_fs ) ) {
            $sdk_path = AICF_PLUGIN_DIR . 'vendor/freemius/start.php';

            // Si le SDK n'est pas installé, retourner null
            if ( ! file_exists( $sdk_path ) ) {
                return null;
            }

            require_once $sdk_path;

            $tex_fs = fs_dynamic_init( array(
                'id'                  => '24812',
                'slug'                => 'textflow',
                'premium_slug'        => 'textflow-premium',
                'type'                => 'plugin',
                'public_key'          => 'pk_4948ceebe34298f0b4c10d565e2a5',
                'is_premium'          => false,
                'premium_suffix'      => 'Pro',
                'has_addons'          => false,
                'has_paid_plans'      => true,
                'trial'               => array(
                    'days'               => 7,
                    'is_require_payment' => false,
                ),
                'menu'                => array(
                    'slug'    => 'ai-content-filler',
                    'parent'  => array(
                        'slug' => 'options-general.php',
                    ),
                    'account' => true,
                    'contact' => true,
                    'support' => false,
                ),
            ) );
        }

        return $tex_fs;
    }

    // Initialiser Freemius
    tex_fs();

    // Signal pour les autres composants
    do_action( 'aicf_fs_loaded' );
}
