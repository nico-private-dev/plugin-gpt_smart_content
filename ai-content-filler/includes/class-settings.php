<?php
/**
 * Gestion de la page de réglages admin du plugin.
 * Accessible via Paramètres > AI Content Filler.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AICF_Settings {

    /** @var AICF_Settings|null */
    private static $instance = null;

    /** Préfixe pour toutes les options en base */
    const OPTION_PREFIX = 'aicf_';

    /** Slug de la page de réglages */
    const PAGE_SLUG = 'ai-content-filler';

    /** Groupe d'options pour Settings API */
    const OPTION_GROUP = 'aicf_settings_group';

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
    }

    /**
     * Ajoute la page dans le menu Paramètres.
     */
    public function add_settings_page() {
        add_options_page(
            __( 'AI Content Filler', 'ai-content-filler' ),
            __( 'AI Content Filler', 'ai-content-filler' ),
            'manage_options',
            self::PAGE_SLUG,
            array( $this, 'render_settings_page' )
        );
    }

    /**
     * Enregistre les champs de réglages via la Settings API de WordPress.
     */
    public function register_settings() {
        // --- Section principale ---
        add_settings_section(
            'aicf_main_section',
            __( 'Configuration de l\'API Claude', 'ai-content-filler' ),
            function () {
                echo '<p>' . esc_html__( 'Configurez votre connexion à l\'API Anthropic Claude et le brief client.', 'ai-content-filler' ) . '</p>';
            },
            self::PAGE_SLUG
        );

        // Clé API
        register_setting( self::OPTION_GROUP, self::OPTION_PREFIX . 'api_key', array(
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ) );
        add_settings_field(
            self::OPTION_PREFIX . 'api_key',
            __( 'Clé API Claude (Anthropic)', 'ai-content-filler' ),
            array( $this, 'render_api_key_field' ),
            self::PAGE_SLUG,
            'aicf_main_section'
        );

        // Brief client
        register_setting( self::OPTION_GROUP, self::OPTION_PREFIX . 'client_brief', array(
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_textarea_field',
            'default'           => '',
        ) );
        add_settings_field(
            self::OPTION_PREFIX . 'client_brief',
            __( 'Brief client', 'ai-content-filler' ),
            array( $this, 'render_client_brief_field' ),
            self::PAGE_SLUG,
            'aicf_main_section'
        );

        // Modèle Claude
        register_setting( self::OPTION_GROUP, self::OPTION_PREFIX . 'model', array(
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => 'claude-sonnet-4-5-20250929',
        ) );
        add_settings_field(
            self::OPTION_PREFIX . 'model',
            __( 'Modèle Claude', 'ai-content-filler' ),
            array( $this, 'render_model_field' ),
            self::PAGE_SLUG,
            'aicf_main_section'
        );

        // Température
        register_setting( self::OPTION_GROUP, self::OPTION_PREFIX . 'temperature', array(
            'type'              => 'number',
            'sanitize_callback' => array( $this, 'sanitize_temperature' ),
            'default'           => 0.7,
        ) );
        add_settings_field(
            self::OPTION_PREFIX . 'temperature',
            __( 'Température', 'ai-content-filler' ),
            array( $this, 'render_temperature_field' ),
            self::PAGE_SLUG,
            'aicf_main_section'
        );

        // Longueur max en tokens
        register_setting( self::OPTION_GROUP, self::OPTION_PREFIX . 'max_tokens', array(
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
            'default'           => 2000,
        ) );
        add_settings_field(
            self::OPTION_PREFIX . 'max_tokens',
            __( 'Longueur max (tokens)', 'ai-content-filler' ),
            array( $this, 'render_max_tokens_field' ),
            self::PAGE_SLUG,
            'aicf_main_section'
        );
    }

    // ------------------------------------------------------------------
    // Rendus des champs
    // ------------------------------------------------------------------

    public function render_api_key_field() {
        $value = get_option( self::OPTION_PREFIX . 'api_key', '' );
        printf(
            '<input type="password" id="%1$s" name="%1$s" value="%2$s" class="regular-text" autocomplete="off" />
             <p class="description">%3$s</p>',
            esc_attr( self::OPTION_PREFIX . 'api_key' ),
            esc_attr( $value ),
            esc_html__( 'Votre clé API Anthropic (commence par sk-ant-...). Elle ne sera jamais exposée côté frontend.', 'ai-content-filler' )
        );
    }

    public function render_client_brief_field() {
        $value = get_option( self::OPTION_PREFIX . 'client_brief', '' );
        printf(
            '<textarea id="%1$s" name="%1$s" rows="8" cols="80" class="large-text">%2$s</textarea>
             <p class="description">%3$s</p>',
            esc_attr( self::OPTION_PREFIX . 'client_brief' ),
            esc_textarea( $value ),
            esc_html__( 'Décrivez le contexte métier : activité, ton éditorial, cible, valeurs, mots-clés importants. Ce texte sera injecté comme system prompt pour guider la rédaction.', 'ai-content-filler' )
        );
    }

    public function render_model_field() {
        $value   = get_option( self::OPTION_PREFIX . 'model', 'claude-sonnet-4-5-20250929' );
        $models  = array(
            'claude-sonnet-4-5-20250929' => 'Claude Sonnet 4.5 (recommandé)',
            'claude-sonnet-4-20250514'   => 'Claude Sonnet 4',
            'claude-haiku-4-20250414'    => 'Claude Haiku 4 (rapide, économique)',
        );
        echo '<select id="' . esc_attr( self::OPTION_PREFIX . 'model' ) . '" name="' . esc_attr( self::OPTION_PREFIX . 'model' ) . '">';
        foreach ( $models as $model_id => $label ) {
            printf(
                '<option value="%s" %s>%s</option>',
                esc_attr( $model_id ),
                selected( $value, $model_id, false ),
                esc_html( $label )
            );
        }
        echo '</select>';
    }

    public function render_temperature_field() {
        $value = get_option( self::OPTION_PREFIX . 'temperature', 0.7 );
        printf(
            '<input type="number" id="%1$s" name="%1$s" value="%2$s" min="0" max="1" step="0.1" class="small-text" />
             <p class="description">%3$s</p>',
            esc_attr( self::OPTION_PREFIX . 'temperature' ),
            esc_attr( $value ),
            esc_html__( '0 = très factuel, 1 = très créatif. Recommandé : 0.7', 'ai-content-filler' )
        );
    }

    public function render_max_tokens_field() {
        $value = get_option( self::OPTION_PREFIX . 'max_tokens', 2000 );
        printf(
            '<input type="number" id="%1$s" name="%1$s" value="%2$s" min="100" max="4096" step="100" class="small-text" />
             <p class="description">%3$s</p>',
            esc_attr( self::OPTION_PREFIX . 'max_tokens' ),
            esc_attr( $value ),
            esc_html__( 'Nombre minimum de tokens. Le plugin ajuste automatiquement selon le nombre de widgets (300 tokens/widget). 2000 ≈ ~1500 mots.', 'ai-content-filler' )
        );
    }

    // ------------------------------------------------------------------
    // Sanitisation personnalisée
    // ------------------------------------------------------------------

    /**
     * Contraint la température entre 0 et 1.
     */
    public function sanitize_temperature( $value ) {
        $value = floatval( $value );
        return max( 0, min( 1, round( $value, 1 ) ) );
    }

    // ------------------------------------------------------------------
    // Rendu de la page complète
    // ------------------------------------------------------------------

    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields( self::OPTION_GROUP );
                do_settings_sections( self::PAGE_SLUG );
                submit_button( __( 'Enregistrer les réglages', 'ai-content-filler' ) );
                ?>
            </form>
        </div>
        <?php
    }

    // ------------------------------------------------------------------
    // Accesseurs statiques utilisés par les autres classes
    // ------------------------------------------------------------------

    public static function get_api_key() {
        return get_option( self::OPTION_PREFIX . 'api_key', '' );
    }

    public static function get_client_brief() {
        return get_option( self::OPTION_PREFIX . 'client_brief', '' );
    }

    public static function get_model() {
        return get_option( self::OPTION_PREFIX . 'model', 'claude-sonnet-4-5-20250929' );
    }

    public static function get_temperature() {
        return floatval( get_option( self::OPTION_PREFIX . 'temperature', 0.7 ) );
    }

    public static function get_max_tokens() {
        return absint( get_option( self::OPTION_PREFIX . 'max_tokens', 2000 ) );
    }
}
