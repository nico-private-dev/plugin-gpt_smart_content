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

    /** Modèles disponibles par fournisseur */
    const MODELS = array(
        'anthropic' => array(
            'claude-sonnet-4-5-20250929' => 'Claude Sonnet 4.5 (recommandé)',
            'claude-sonnet-4-20250514'   => 'Claude Sonnet 4',
            'claude-haiku-4-20250414'    => 'Claude Haiku 4 (rapide, économique)',
            'claude-opus-4-6'            => 'Claude Opus 4.6 (le plus puissant)',
        ),
        'openai' => array(
            'gpt-4o'      => 'GPT-4o (recommandé)',
            'gpt-4o-mini' => 'GPT-4o Mini (rapide, économique)',
            'gpt-4-turbo' => 'GPT-4 Turbo',
            'o1-mini'     => 'o1 Mini',
        ),
        'deepseek' => array(
            'deepseek-chat'     => 'DeepSeek Chat (recommandé)',
            'deepseek-reasoner' => 'DeepSeek Reasoner (R1)',
        ),
    );

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
        add_action( 'wp_ajax_aicf_test_api', array( $this, 'ajax_test_api' ) );
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
     * Charge les assets CSS/JS uniquement sur la page de réglages du plugin.
     */
    public function enqueue_admin_assets( $hook ) {
        if ( 'settings_page_ai-content-filler' !== $hook ) {
            return;
        }
        wp_enqueue_style(
            'aicf-admin-settings',
            AICF_PLUGIN_URL . 'assets/css/admin-settings.css',
            array(),
            AICF_VERSION
        );
        wp_enqueue_media();
        wp_enqueue_script(
            'aicf-admin-settings',
            AICF_PLUGIN_URL . 'assets/js/admin-settings.js',
            array( 'jquery', 'media-upload' ),
            AICF_VERSION,
            true
        );
        wp_localize_script( 'aicf-admin-settings', 'aicfAdmin', array(
            'ajaxUrl'           => admin_url( 'admin-ajax.php' ),
            'nonce'             => wp_create_nonce( 'aicf_admin_nonce' ),
            'models'            => self::MODELS,
            'currentProvider'   => self::get_provider(),
            'currentModel'      => self::get_model(),
            'mediaTitle'        => __( 'Choisir le fichier de brief', 'ai-content-filler' ),
            'mediaButton'       => __( 'Utiliser ce fichier', 'ai-content-filler' ),
            'currentAttachment' => self::get_brief_attachment_info(),
            'apiKeyHints'       => array(
                'anthropic' => __( 'Commence par sk-ant-... — Disponible sur console.anthropic.com', 'ai-content-filler' ),
                'openai'    => __( 'Commence par sk-... — Disponible sur platform.openai.com', 'ai-content-filler' ),
                'deepseek'  => __( 'Disponible sur platform.deepseek.com', 'ai-content-filler' ),
            ),
            'i18n' => array(
                'testing'       => __( 'Test en cours…', 'ai-content-filler' ),
                'testButton'    => __( 'Tester la connexion', 'ai-content-filler' ),
                'networkError'  => __( 'Erreur réseau', 'ai-content-filler' ),
            ),
        ) );
    }

    /**
     * Enregistre tous les champs de réglages via la Settings API de WordPress.
     */
    public function register_settings() {

        // =====================================================================
        // SECTION 1 : Connexion API
        // =====================================================================
        add_settings_section(
            'aicf_api_section',
            __( 'Connexion API', 'ai-content-filler' ),
            '__return_false',
            self::PAGE_SLUG
        );

        // Fournisseur IA
        register_setting( self::OPTION_GROUP, self::OPTION_PREFIX . 'provider', array(
            'type'              => 'string',
            'sanitize_callback' => array( $this, 'sanitize_provider' ),
            'default'           => 'anthropic',
        ) );
        add_settings_field(
            self::OPTION_PREFIX . 'provider',
            __( 'Fournisseur IA', 'ai-content-filler' ),
            array( $this, 'render_provider_field' ),
            self::PAGE_SLUG,
            'aicf_api_section'
        );

        // Clé API
        register_setting( self::OPTION_GROUP, self::OPTION_PREFIX . 'api_key', array(
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ) );
        add_settings_field(
            self::OPTION_PREFIX . 'api_key',
            __( 'Clé API', 'ai-content-filler' ),
            array( $this, 'render_api_key_field' ),
            self::PAGE_SLUG,
            'aicf_api_section'
        );

        // Modèle
        register_setting( self::OPTION_GROUP, self::OPTION_PREFIX . 'model', array(
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => 'claude-sonnet-4-5-20250929',
        ) );
        add_settings_field(
            self::OPTION_PREFIX . 'model',
            __( 'Modèle', 'ai-content-filler' ),
            array( $this, 'render_model_field' ),
            self::PAGE_SLUG,
            'aicf_api_section'
        );

        // =====================================================================
        // SECTION 2 : Paramètres de génération
        // =====================================================================
        add_settings_section(
            'aicf_generation_section',
            __( 'Paramètres de génération', 'ai-content-filler' ),
            '__return_false',
            self::PAGE_SLUG
        );

        // Langue
        register_setting( self::OPTION_GROUP, self::OPTION_PREFIX . 'language', array(
            'type'              => 'string',
            'sanitize_callback' => array( $this, 'sanitize_language' ),
            'default'           => 'fr',
        ) );
        add_settings_field(
            self::OPTION_PREFIX . 'language',
            __( 'Langue du contenu', 'ai-content-filler' ),
            array( $this, 'render_language_field' ),
            self::PAGE_SLUG,
            'aicf_generation_section'
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
            'aicf_generation_section'
        );

        // Max tokens
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
            'aicf_generation_section'
        );

        // =====================================================================
        // SECTION 3 : Brief client
        // =====================================================================
        add_settings_section(
            'aicf_brief_section',
            __( 'Brief client', 'ai-content-filler' ),
            '__return_false',
            self::PAGE_SLUG
        );

        // Brief texte
        register_setting( self::OPTION_GROUP, self::OPTION_PREFIX . 'client_brief', array(
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_textarea_field',
            'default'           => '',
        ) );
        add_settings_field(
            self::OPTION_PREFIX . 'client_brief',
            __( 'Brief textuel', 'ai-content-filler' ),
            array( $this, 'render_client_brief_field' ),
            self::PAGE_SLUG,
            'aicf_brief_section'
        );

        // Brief fichier
        register_setting( self::OPTION_GROUP, self::OPTION_PREFIX . 'brief_attachment_id', array(
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
            'default'           => 0,
        ) );
        add_settings_field(
            self::OPTION_PREFIX . 'brief_attachment_id',
            __( 'Fichier de brief', 'ai-content-filler' ),
            array( $this, 'render_brief_file_field' ),
            self::PAGE_SLUG,
            'aicf_brief_section'
        );
    }

    // ------------------------------------------------------------------
    // Rendus des champs
    // ------------------------------------------------------------------

    public function render_provider_field() {
        $value     = self::get_provider();
        $providers = array(
            'anthropic' => array(
                'label' => 'Anthropic Claude',
                'desc'  => __( 'Claude Sonnet, Haiku, Opus', 'ai-content-filler' ),
                'icon'  => '🧠',
            ),
            'openai' => array(
                'label' => 'OpenAI ChatGPT',
                'desc'  => __( 'GPT-4o et variantes', 'ai-content-filler' ),
                'icon'  => '💬',
            ),
            'deepseek' => array(
                'label' => 'DeepSeek',
                'desc'  => __( 'Chat & Reasoner R1', 'ai-content-filler' ),
                'icon'  => '🔍',
            ),
        );
        ?>
        <div class="aicf-provider-grid">
            <?php foreach ( $providers as $id => $info ) : ?>
            <label class="aicf-provider-card <?php echo ( $value === $id ) ? 'active' : ''; ?>" data-provider="<?php echo esc_attr( $id ); ?>">
                <input
                    type="radio"
                    name="<?php echo esc_attr( self::OPTION_PREFIX . 'provider' ); ?>"
                    value="<?php echo esc_attr( $id ); ?>"
                    <?php checked( $value, $id ); ?>
                    class="aicf-provider-radio"
                />
                <span class="aicf-provider-icon"><?php echo esc_html( $info['icon'] ); ?></span>
                <span class="aicf-provider-name"><?php echo esc_html( $info['label'] ); ?></span>
                <span class="aicf-provider-desc"><?php echo esc_html( $info['desc'] ); ?></span>
            </label>
            <?php endforeach; ?>
        </div>
        <p class="description"><?php esc_html_e( 'Chaque fournisseur requiert sa propre clé API.', 'ai-content-filler' ); ?></p>
        <?php
    }

    public function render_api_key_field() {
        $value    = get_option( self::OPTION_PREFIX . 'api_key', '' );
        $provider = self::get_provider();
        $hints    = array(
            'anthropic' => __( 'Commence par sk-ant-... — Disponible sur console.anthropic.com', 'ai-content-filler' ),
            'openai'    => __( 'Commence par sk-... — Disponible sur platform.openai.com', 'ai-content-filler' ),
            'deepseek'  => __( 'Disponible sur platform.deepseek.com', 'ai-content-filler' ),
        );
        $hint = isset( $hints[ $provider ] ) ? $hints[ $provider ] : '';
        ?>
        <div class="aicf-api-key-wrap">
            <input
                type="password"
                id="<?php echo esc_attr( self::OPTION_PREFIX . 'api_key' ); ?>"
                name="<?php echo esc_attr( self::OPTION_PREFIX . 'api_key' ); ?>"
                value="<?php echo esc_attr( $value ); ?>"
                class="regular-text aicf-api-key-input"
                autocomplete="off"
            />
            <button
                type="button"
                class="button aicf-toggle-key"
                data-target="<?php echo esc_attr( self::OPTION_PREFIX . 'api_key' ); ?>"
                title="<?php esc_attr_e( 'Afficher / masquer', 'ai-content-filler' ); ?>"
            >
                <span class="dashicons dashicons-visibility"></span>
            </button>
            <button type="button" class="button aicf-test-api" id="aicf-test-api-btn">
                <?php esc_html_e( 'Tester la connexion', 'ai-content-filler' ); ?>
            </button>
            <span class="aicf-test-result" id="aicf-test-result"></span>
        </div>
        <p class="description" id="aicf-api-key-hint"><?php echo esc_html( $hint ); ?></p>
        <?php
    }

    public function render_model_field() {
        $current_provider = self::get_provider();
        $current_model    = self::get_model();
        $all_models       = self::MODELS;
        $provider_models  = isset( $all_models[ $current_provider ] ) ? $all_models[ $current_provider ] : array();
        ?>
        <select
            id="<?php echo esc_attr( self::OPTION_PREFIX . 'model' ); ?>"
            name="<?php echo esc_attr( self::OPTION_PREFIX . 'model' ); ?>"
            class="aicf-model-select regular-text"
        >
            <?php foreach ( $provider_models as $model_id => $label ) : ?>
            <option value="<?php echo esc_attr( $model_id ); ?>" <?php selected( $current_model, $model_id ); ?>>
                <?php echo esc_html( $label ); ?>
            </option>
            <?php endforeach; ?>
        </select>
        <p class="description"><?php esc_html_e( 'La liste de modèles se met à jour automatiquement selon le fournisseur sélectionné.', 'ai-content-filler' ); ?></p>
        <?php
    }

    public function render_language_field() {
        $value     = self::get_language();
        $languages = array(
            'fr' => '🇫🇷 Français',
            'en' => '🇬🇧 English',
            'es' => '🇪🇸 Español',
            'de' => '🇩🇪 Deutsch',
            'it' => '🇮🇹 Italiano',
            'pt' => '🇵🇹 Português',
            'nl' => '🇳🇱 Nederlands',
            'ar' => '🇸🇦 العربية',
        );
        echo '<select id="' . esc_attr( self::OPTION_PREFIX . 'language' ) . '" name="' . esc_attr( self::OPTION_PREFIX . 'language' ) . '" class="regular-text">';
        foreach ( $languages as $code => $label ) {
            printf(
                '<option value="%s" %s>%s</option>',
                esc_attr( $code ),
                selected( $value, $code, false ),
                esc_html( $label )
            );
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__( 'Langue par défaut du contenu généré. L\'utilisateur peut la surcharger dans son prompt.', 'ai-content-filler' ) . '</p>';
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
            '<input type="number" id="%1$s" name="%1$s" value="%2$s" min="100" max="8000" step="100" class="small-text" />
             <p class="description">%3$s</p>',
            esc_attr( self::OPTION_PREFIX . 'max_tokens' ),
            esc_attr( $value ),
            esc_html__( 'Nombre minimum de tokens alloués. Le plugin ajuste automatiquement selon le nombre de widgets (400 tokens/widget). 2000 ≈ ~1500 mots.', 'ai-content-filler' )
        );
    }

    public function render_client_brief_field() {
        $value = get_option( self::OPTION_PREFIX . 'client_brief', '' );
        printf(
            '<textarea id="%1$s" name="%1$s" rows="8" cols="80" class="large-text" placeholder="%4$s">%2$s</textarea>
             <p class="description">%3$s</p>',
            esc_attr( self::OPTION_PREFIX . 'client_brief' ),
            esc_textarea( $value ),
            esc_html__( 'Décrivez le contexte métier : activité, ton éditorial, cible, valeurs, mots-clés importants. Ce texte sera injecté comme contexte pour guider la rédaction.', 'ai-content-filler' ),
            esc_attr__( 'Ex : Entreprise spécialisée dans le conseil RH, ton professionnel et chaleureux, cible PME de 10 à 200 salariés…', 'ai-content-filler' )
        );
    }

    public function render_brief_file_field() {
        $attachment_id = absint( get_option( self::OPTION_PREFIX . 'brief_attachment_id', 0 ) );
        $info          = self::get_brief_attachment_info();
        ?>
        <div class="aicf-file-upload-wrap">
            <input
                type="hidden"
                id="<?php echo esc_attr( self::OPTION_PREFIX . 'brief_attachment_id' ); ?>"
                name="<?php echo esc_attr( self::OPTION_PREFIX . 'brief_attachment_id' ); ?>"
                value="<?php echo esc_attr( $attachment_id ); ?>"
            />
            <div class="aicf-file-preview <?php echo $info ? '' : 'aicf-file-empty'; ?>" id="aicf-file-preview" <?php echo $info ? '' : 'style="display:none;"'; ?>>
                <?php if ( $info ) : ?>
                <span class="aicf-file-icon">📄</span>
                <span class="aicf-file-name"><?php echo esc_html( $info['filename'] ); ?></span>
                <span class="aicf-file-type"><?php echo esc_html( strtoupper( $info['ext'] ) ); ?></span>
                <button type="button" class="aicf-remove-file" id="aicf-remove-file" title="<?php esc_attr_e( 'Supprimer', 'ai-content-filler' ); ?>">✕</button>
                <?php endif; ?>
            </div>
            <button type="button" class="button aicf-select-file" id="aicf-select-file-btn">
                📎 <?php esc_html_e( 'Choisir un fichier (PDF, TXT, MD)', 'ai-content-filler' ); ?>
            </button>
        </div>
        <p class="description"><?php esc_html_e( 'Importez un fichier de brief. Son contenu sera extrait et combiné avec le brief textuel ci-dessus.', 'ai-content-filler' ); ?></p>
        <?php
    }

    // ------------------------------------------------------------------
    // Sanitisation personnalisée
    // ------------------------------------------------------------------

    public function sanitize_temperature( $value ) {
        $value = floatval( $value );
        return max( 0, min( 1, round( $value, 1 ) ) );
    }

    public function sanitize_provider( $value ) {
        $allowed = array_keys( self::MODELS );
        return in_array( $value, $allowed, true ) ? $value : 'anthropic';
    }

    public function sanitize_language( $value ) {
        $allowed = array( 'fr', 'en', 'es', 'de', 'it', 'pt', 'nl', 'ar' );
        return in_array( $value, $allowed, true ) ? $value : 'fr';
    }

    // ------------------------------------------------------------------
    // AJAX : tester la connexion API
    // ------------------------------------------------------------------

    public function ajax_test_api() {
        check_ajax_referer( 'aicf_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Permission refusée.', 'ai-content-filler' ) );
        }

        $provider = isset( $_POST['provider'] ) ? sanitize_text_field( wp_unslash( $_POST['provider'] ) ) : 'anthropic';
        $api_key  = isset( $_POST['api_key'] )  ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) )  : '';
        $model    = isset( $_POST['model'] )     ? sanitize_text_field( wp_unslash( $_POST['model'] ) )    : '';

        if ( empty( $api_key ) ) {
            wp_send_json_error( __( 'Veuillez saisir une clé API avant de tester.', 'ai-content-filler' ) );
        }

        $result = AICF_API_Handler::test_connection( $provider, $api_key, $model );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( __( 'Connexion réussie !', 'ai-content-filler' ) );
    }

    // ------------------------------------------------------------------
    // Rendu de la page complète
    // ------------------------------------------------------------------

    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $api_key = self::get_api_key();
        ?>
        <div class="wrap aicf-settings-wrap">

            <div class="aicf-settings-header">
                <div class="aicf-header-icon">✨</div>
                <div>
                    <h1 class="aicf-header-title"><?php esc_html_e( 'AI Content Filler', 'ai-content-filler' ); ?></h1>
                    <p class="aicf-header-subtitle"><?php esc_html_e( 'Générez du contenu intelligent pour vos pages Elementor', 'ai-content-filler' ); ?></p>
                </div>
            </div>

            <?php if ( empty( $api_key ) ) : ?>
            <div class="aicf-notice aicf-notice-warning">
                ⚠️ <?php esc_html_e( 'Aucune clé API configurée. Le plugin ne pourra pas générer de contenu.', 'ai-content-filler' ); ?>
            </div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields( self::OPTION_GROUP ); ?>

                <!-- Section 1 : Connexion API -->
                <div class="aicf-card">
                    <div class="aicf-card-header">
                        <span class="aicf-card-icon">🔑</span>
                        <h2><?php esc_html_e( 'Connexion API', 'ai-content-filler' ); ?></h2>
                    </div>
                    <div class="aicf-card-body">
                        <table class="form-table" role="presentation">
                            <?php do_settings_fields( self::PAGE_SLUG, 'aicf_api_section' ); ?>
                        </table>
                    </div>
                </div>

                <!-- Section 2 : Paramètres de génération -->
                <div class="aicf-card">
                    <div class="aicf-card-header">
                        <span class="aicf-card-icon">⚙️</span>
                        <h2><?php esc_html_e( 'Paramètres de génération', 'ai-content-filler' ); ?></h2>
                    </div>
                    <div class="aicf-card-body">
                        <table class="form-table" role="presentation">
                            <?php do_settings_fields( self::PAGE_SLUG, 'aicf_generation_section' ); ?>
                        </table>
                    </div>
                </div>

                <!-- Section 3 : Brief client -->
                <div class="aicf-card">
                    <div class="aicf-card-header">
                        <span class="aicf-card-icon">📋</span>
                        <h2><?php esc_html_e( 'Brief client', 'ai-content-filler' ); ?></h2>
                    </div>
                    <div class="aicf-card-body">
                        <p class="aicf-section-desc"><?php esc_html_e( 'Le brief est injecté comme contexte système pour guider la rédaction. Vous pouvez saisir votre brief directement et/ou importer un fichier (PDF, TXT, MD).', 'ai-content-filler' ); ?></p>
                        <table class="form-table" role="presentation">
                            <?php do_settings_fields( self::PAGE_SLUG, 'aicf_brief_section' ); ?>
                        </table>
                    </div>
                </div>

                <?php submit_button( __( 'Enregistrer les réglages', 'ai-content-filler' ) ); ?>
            </form>

        </div>
        <?php
    }

    // ------------------------------------------------------------------
    // Accesseurs statiques utilisés par les autres classes
    // ------------------------------------------------------------------

    public static function get_provider() {
        return get_option( self::OPTION_PREFIX . 'provider', 'anthropic' );
    }

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

    public static function get_language() {
        return get_option( self::OPTION_PREFIX . 'language', 'fr' );
    }

    public static function get_brief_attachment_id() {
        return absint( get_option( self::OPTION_PREFIX . 'brief_attachment_id', 0 ) );
    }

    /**
     * Retourne les métadonnées du fichier de brief attaché, ou null s'il n'y en a pas.
     *
     * @return array|null
     */
    public static function get_brief_attachment_info() {
        $id = self::get_brief_attachment_id();
        if ( ! $id ) {
            return null;
        }
        $path = get_attached_file( $id );
        if ( ! $path || ! file_exists( $path ) ) {
            return null;
        }
        return array(
            'id'       => $id,
            'filename' => basename( $path ),
            'ext'      => strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ),
            'path'     => $path,
        );
    }

    /**
     * Extrait le contenu textuel du fichier de brief (PDF, TXT, MD).
     *
     * @return string
     */
    public static function extract_brief_file_content() {
        $info = self::get_brief_attachment_info();
        if ( ! $info ) {
            return '';
        }
        if ( in_array( $info['ext'], array( 'txt', 'md', 'markdown' ), true ) ) {
            $content = @file_get_contents( $info['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
            return $content ? $content : '';
        }
        if ( 'pdf' === $info['ext'] ) {
            return self::extract_pdf_text( $info['path'] );
        }
        return '';
    }

    /**
     * Extraction basique de texte depuis un PDF sans bibliothèque externe.
     * Fonctionne pour les PDFs simples avec texte non encodé.
     *
     * @param string $pdf_path Chemin absolu vers le fichier PDF.
     * @return string
     */
    private static function extract_pdf_text( $pdf_path ) {
        $content = @file_get_contents( $pdf_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
        if ( ! $content ) {
            return '';
        }
        $text = '';
        // Extraire les blocs BT...ET (Begin Text / End Text) du flux PDF
        if ( preg_match_all( '/BT\s(.*?)\sET/s', $content, $bt_blocks ) ) {
            foreach ( $bt_blocks[1] as $block ) {
                if ( preg_match_all( '/\(([^)]*)\)/', $block, $strings ) ) {
                    foreach ( $strings[1] as $s ) {
                        $s     = preg_replace( '/\\\\([nrtbf()\\\\])/', ' ', $s );
                        $text .= $s . ' ';
                    }
                }
            }
        }
        // Fallback : toutes les chaînes entre parenthèses d'au moins 3 caractères
        if ( empty( trim( $text ) ) ) {
            preg_match_all( '/\(([^\)]{3,})\)/', $content, $matches );
            $text = implode( ' ', $matches[1] );
        }
        $text = preg_replace( '/[^\x20-\x7E\x0A\x0D]/', ' ', $text );
        $text = preg_replace( '/\s+/', ' ', $text );
        return trim( $text );
    }
}
