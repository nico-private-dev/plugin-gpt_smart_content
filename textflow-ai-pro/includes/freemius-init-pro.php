<?php
/**
 * Initialisation Freemius pour TextFlow AI Pro (add-on).
 *
 * IMPORTANT : Avant de mettre en production, remplacez les valeurs
 * 'id' et 'public_key' par celles de votre add-on créé dans
 * le tableau de bord Freemius (https://dashboard.freemius.com).
 *
 * Étapes :
 *  1. Connectez-vous à dashboard.freemius.com
 *  2. Allez dans votre plugin "TextFlow AI" → Add-ons → Add New Add-on
 *  3. Remplissez le formulaire (nom : "TextFlow AI Pro", type : Plugin)
 *  4. Copiez l'ID et la Public Key générés
 *  5. Remplacez TXFLOW_PRO_FS_ID et TXFLOW_PRO_FS_PUBLIC_KEY ci-dessous
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ⚠️  Remplacer par vos valeurs Freemius add-on
define( 'TXFLOW_PRO_FS_ID',         'XXXXX' );
define( 'TXFLOW_PRO_FS_PUBLIC_KEY', 'pk_XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX' );

if ( ! function_exists( 'txflow_fs_pro' ) ) {

    function txflow_fs_pro() {
        global $txflow_fs_pro;

        if ( ! isset( $txflow_fs_pro ) ) {

            // Le SDK Freemius est fourni par le plugin principal
            if ( ! function_exists( 'txflow_fs' ) ) {
                // Plugin principal non chargé — reporter l'init
                return null;
            }

            $txflow_fs_pro = txflow_fs()->init_addon( array(
                'id'             => TXFLOW_PRO_FS_ID,
                'slug'           => 'textflow-ai-pro',
                'public_key'     => TXFLOW_PRO_FS_PUBLIC_KEY,
                'is_premium'     => true,
                'has_paid_plans' => true,
                'menu'           => array(
                    'slug'   => 'textflow-ai-pro',
                    'parent' => array(
                        'slug' => 'textflow-ai',
                    ),
                ),
            ) );
        }

        return $txflow_fs_pro;
    }

    // Initialiser l'add-on une fois que le plugin parent a chargé Freemius
    add_action( 'txflow_fs_loaded', function () {
        txflow_fs_pro();
    } );
}
