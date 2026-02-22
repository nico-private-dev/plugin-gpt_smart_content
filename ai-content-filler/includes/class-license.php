<?php
/**
 * Gestion des licences et feature gating free/pro.
 *
 * Centralise toutes les vérifications de plan pour faciliter
 * le passage à un autre système de licences si nécessaire.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AICF_License {

    /**
     * Vérifie si l'utilisateur a un plan Pro actif.
     *
     * @return bool
     */
    public static function is_pro() {
        // Vérifier via Freemius si le SDK est disponible
        if ( function_exists( 'aicf_fs' ) && aicf_fs() !== null ) {
            return aicf_fs()->is_plan( 'pro' ) || aicf_fs()->is_trial();
        }

        // Fallback : vérifier un filtre custom (utile pour le dev/test)
        return (bool) apply_filters( 'aicf_is_pro', false );
    }

    /**
     * Retourne l'URL de la page d'upgrade.
     *
     * @return string
     */
    public static function get_upgrade_url() {
        if ( function_exists( 'aicf_fs' ) && aicf_fs() !== null ) {
            return aicf_fs()->get_upgrade_url();
        }

        // URL par défaut si Freemius n'est pas configuré
        return admin_url( 'options-general.php?page=ai-content-filler-pricing' );
    }

    /**
     * Retourne le nom du plan actuel.
     *
     * @return string
     */
    public static function get_plan_name() {
        if ( self::is_pro() ) {
            return 'Pro';
        }
        return 'Free';
    }

    // -----------------------------------------------------------------
    // Feature gating : widgets supportés
    // -----------------------------------------------------------------

    /** Widgets disponibles en version gratuite */
    const FREE_WIDGETS = array(
        'heading',
        'text-editor',
        'button',
        'icon-box',
        'image-box',
    );

    /**
     * Vérifie si un type de widget est disponible dans le plan actuel.
     *
     * @param string $widget_type Type de widget Elementor.
     * @return bool
     */
    public static function is_widget_allowed( $widget_type ) {
        if ( self::is_pro() ) {
            return true;
        }
        return in_array( $widget_type, self::FREE_WIDGETS, true );
    }

    /**
     * Retourne la liste des types de widgets autorisés.
     *
     * @return array
     */
    public static function get_allowed_widgets() {
        if ( self::is_pro() ) {
            return array(); // vide = tous autorisés
        }
        return self::FREE_WIDGETS;
    }

    // -----------------------------------------------------------------
    // Feature gating : fournisseurs IA
    // -----------------------------------------------------------------

    /** Fournisseurs disponibles en version gratuite */
    const FREE_PROVIDERS = array( 'anthropic', 'openai', 'deepseek' );

    /**
     * Vérifie si un fournisseur est disponible.
     *
     * @param string $provider
     * @return bool
     */
    public static function is_provider_allowed( $provider ) {
        if ( self::is_pro() ) {
            return true;
        }
        return in_array( $provider, self::FREE_PROVIDERS, true );
    }

    // -----------------------------------------------------------------
    // Feature gating : templates de prompts
    // -----------------------------------------------------------------

    /** Templates disponibles en version gratuite */
    const FREE_TEMPLATES = array( 'landing', 'service' );

    // -----------------------------------------------------------------
    // Feature gating : langues
    // -----------------------------------------------------------------

    /** Langues disponibles en version gratuite */
    const FREE_LANGUAGES = array( 'fr', 'en' );

    // -----------------------------------------------------------------
    // Feature gating : features individuelles
    // -----------------------------------------------------------------

    /**
     * Vérifie si une feature spécifique est disponible.
     *
     * @param string $feature Nom de la feature.
     * @return bool
     */
    public static function has_feature( $feature ) {
        if ( self::is_pro() ) {
            return true;
        }

        // Features disponibles en gratuit
        $free_features = array(
            'basic_generation',    // Génération de base
            'scan_widgets',        // Scan des widgets
            'basic_templates',     // Templates Landing + Service
        );

        return in_array( $feature, $free_features, true );
    }

    /**
     * Vérifie si les features Pro sont requises pour une action.
     * Liste des features Pro :
     * - tone : ton rédactionnel
     * - heading_style : style des titres (highlight, underline, color)
     * - regen_widget : régénération individuelle d'un widget
     * - revert_widget : historique / restauration
     * - cost_estimate : estimation des coûts
     * - brief_file : import de fichier de brief
     * - all_templates : tous les templates (about, home, blog)
     * - all_languages : toutes les langues
     * - all_widgets : tous les types de widgets
     *
     * @param string $feature
     * @return bool  true si la feature est Pro-only et l'utilisateur est en Free.
     */
    public static function is_pro_feature( $feature ) {
        if ( self::is_pro() ) {
            return false; // L'utilisateur Pro a accès, ce n'est pas bloqué
        }

        $pro_features = array(
            'tone',
            'heading_style',
            'regen_widget',
            'revert_widget',
            'cost_estimate',
            'brief_file',
            'all_templates',
            'all_languages',
            'all_widgets',
        );

        return in_array( $feature, $pro_features, true );
    }

    // -----------------------------------------------------------------
    // Rate limiting pour le plan gratuit
    // -----------------------------------------------------------------

    /** Nombre max de générations par jour en plan gratuit */
    const FREE_DAILY_LIMIT = 10;

    /** Clé du transient pour le compteur quotidien */
    const DAILY_COUNT_PREFIX = 'aicf_daily_gen_';

    /**
     * Vérifie si l'utilisateur a atteint sa limite quotidienne.
     *
     * @return bool true si la limite est atteinte.
     */
    public static function is_daily_limit_reached() {
        if ( self::is_pro() ) {
            return false;
        }

        $user_id = get_current_user_id();
        $key     = self::DAILY_COUNT_PREFIX . $user_id;
        $count   = (int) get_transient( $key );

        return $count >= self::FREE_DAILY_LIMIT;
    }

    /**
     * Incrémente le compteur de générations quotidiennes.
     */
    public static function increment_daily_count() {
        if ( self::is_pro() ) {
            return;
        }

        $user_id = get_current_user_id();
        $key     = self::DAILY_COUNT_PREFIX . $user_id;
        $count   = (int) get_transient( $key );

        // Expire à minuit (secondes restantes jusqu'à la fin du jour)
        $now          = current_time( 'timestamp' );
        $end_of_day   = strtotime( 'tomorrow midnight', $now );
        $seconds_left = $end_of_day - $now;

        set_transient( $key, $count + 1, $seconds_left );
    }

    /**
     * Retourne le nombre de générations restantes pour aujourd'hui.
     *
     * @return int  -1 si illimité (plan Pro).
     */
    public static function get_remaining_generations() {
        if ( self::is_pro() ) {
            return -1;
        }

        $user_id = get_current_user_id();
        $key     = self::DAILY_COUNT_PREFIX . $user_id;
        $count   = (int) get_transient( $key );

        return max( 0, self::FREE_DAILY_LIMIT - $count );
    }
}

/**
 * Fonction raccourci globale.
 *
 * @return bool
 */
function aicf_is_pro() {
    return AICF_License::is_pro();
}
