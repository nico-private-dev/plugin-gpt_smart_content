<?php
/**
 * Initialisation du SDK Freemius pour TextFlow AI.
 * Chargé avant plugins_loaded pour garantir la disponibilité du SDK.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'txflow_fs' ) ) {

    function txflow_fs() {
        global $txflow_fs;

        if ( ! isset( $txflow_fs ) ) {
            // Inclure le SDK Freemius
            require_once dirname( __FILE__ ) . '/../vendor/freemius/start.php';

            $txflow_fs = fs_dynamic_init( array(
                'id'                  => '24812',
                'slug'                => 'textflow-ai',
                'premium_slug'        => 'textflow-ai-pro',
                'type'                => 'plugin',
                'public_key'          => 'pk_4948ceebe34298f0b4c10d565e2a5',
                'is_premium'          => false,
                'has_addons'          => true,
                'has_paid_plans'      => false,
                'is_org_compliant'    => true,
                'menu'                => array(
                    'slug'        => 'textflow-ai',
                    'first-path'  => 'options-general.php?page=textflow-ai',
                    'account'     => true,
                    'contact'     => false,
                    'support'     => false,
                ),
            ) );
        }

        return $txflow_fs;
    }

    // Initialiser Freemius maintenant
    txflow_fs();

    // Signal que Freemius est prêt
    do_action( 'txflow_fs_loaded' );
}
