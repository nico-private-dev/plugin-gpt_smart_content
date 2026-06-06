<?php
/**
 * Stub de licence — toutes les fonctionnalités sont disponibles.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TXFLOW_License {

    /**
     * Toujours vrai : toutes les fonctionnalités sont disponibles.
     *
     * @return bool
     */
    public static function is_pro() {
        return true;
    }
}
