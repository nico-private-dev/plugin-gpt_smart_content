<?php
/**
 * Initialisation du SDK Freemius pour la gestion des licences free/pro.
 *
 * INSTRUCTIONS DE CONFIGURATION :
 * 1. Créer un compte sur https://freemius.com
 * 2. Créer un plugin "AI Content Filler" dans le dashboard Freemius
 * 3. Télécharger le SDK Freemius et le placer dans /vendor/freemius/
 * 4. Remplacer les valeurs 'id', 'public_key' et 'premium_suffix' ci-dessous
 *    par celles fournies dans le dashboard Freemius
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'aicf_fs' ) ) {

    /**
     * Retourne l'instance globale du SDK Freemius.
     *
     * @return Freemius|null
     */
    function aicf_fs() {
        global $aicf_fs;

        if ( ! isset( $aicf_fs ) ) {
            $sdk_path = AICF_PLUGIN_DIR . 'vendor/freemius/start.php';

            // Si le SDK n'est pas installé, retourner null
            if ( ! file_exists( $sdk_path ) ) {
                return null;
            }

            require_once $sdk_path;

            $aicf_fs = fs_dynamic_init( array(
                'id'                  => '00000',          // TODO: remplacer par l'ID Freemius
                'slug'                => 'ai-content-filler',
                'type'                => 'plugin',
                'public_key'          => 'pk_XXXXXXXX',    // TODO: remplacer par la clé publique
                'is_premium'          => false,
                'premium_suffix'      => 'Pro',
                'has_addons'          => false,
                'has_paid_plans'      => true,
                'trial'               => array(
                    'days'    => 7,
                    'is_require_payment' => false,
                ),
                'menu'                => array(
                    'slug'    => 'ai-content-filler',
                    'parent'  => array(
                        'slug' => 'options-general.php',
                    ),
                ),
            ) );
        }

        return $aicf_fs;
    }

    // Initialiser Freemius
    aicf_fs();

    // Signal pour les autres composants
    do_action( 'aicf_fs_loaded' );
}
