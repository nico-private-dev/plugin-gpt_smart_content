<?php
/**
 * Initialisation du SDK Freemius.
 * Code genere par le wizard Freemius, adapte a l'architecture du plugin.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'tex_fs' ) ) {
    // Create a helper function for easy SDK access.
    function tex_fs() {
        global $tex_fs;

        if ( ! isset( $tex_fs ) ) {
            // Include Freemius SDK.
            require_once dirname( __FILE__ ) . '/../vendor/freemius/start.php';

            $tex_fs = fs_dynamic_init( array(
                'id'                  => '24812',
                'slug'                => 'textflow',
                'type'                => 'plugin',
                'public_key'          => 'pk_4948ceebe34298f0b4c10d565e2a5',
                'is_premium'          => true,
                'premium_suffix'      => 'Pro',
                'has_premium_version' => true,
                'has_addons'          => false,
                'has_paid_plans'      => true,
                'is_org_compliant'    => true,
                'wp_org_gatekeeper'   => 'OA7#BoRiBNqdf52FvzEf!!074aRLPs8fspif$7K1#4u4Csys1fQlCecVcUTOs2mcpeVHi#C2j9d09fOTvbC0HloPT7fFee5WdS3G',
                'trial'               => array(
                    'days'               => 7,
                    'is_require_payment' => false,
                ),
                'menu'                => array(
                    'slug'       => 'ai-content-filler',
                    'first-path' => 'plugins.php',
                    'parent'     => array(
                        'slug' => 'options-general.php',
                    ),
                    'account'    => true,
                    'contact'    => true,
                    'support'    => false,
                ),
            ) );
        }

        return $tex_fs;
    }

    // Init Freemius.
    tex_fs();
    // Signal that SDK was initiated.
    do_action( 'tex_fs_loaded' );
}
