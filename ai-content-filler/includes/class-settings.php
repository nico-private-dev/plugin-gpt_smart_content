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
            'claude-haiku-4-5-20251001'    => 'Claude Haiku 4 (rapide, économique)',
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

    /** Liste des fournisseurs supportés */
    const PROVIDERS = array( 'anthropic', 'openai', 'deepseek' );

    private function __construct() {
        add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
        add_action( 'wp_ajax_aicf_test_api', array( $this, 'ajax_test_api' ) );

        // Migration : ancienne clé unique → clé par fournisseur
        self::maybe_migrate_api_key();
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
        wp_enqueue_style( 'wp-color-picker' );
        wp_enqueue_style(
            'aicf-admin-settings',
            AICF_PLUGIN_URL . 'assets/css/admin-settings.css',
            array( 'wp-color-picker' ),
            AICF_VERSION
        );
        wp_enqueue_media();
        wp_enqueue_script(
            'aicf-admin-settings',
            AICF_PLUGIN_URL . 'assets/js/admin-settings.js',
            array( 'jquery', 'media-upload', 'wp-color-picker' ),
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

        // Clés API — une par fournisseur
        foreach ( self::PROVIDERS as $provider ) {
            register_setting( self::OPTION_GROUP, self::OPTION_PREFIX . 'api_key_' . $provider, array(
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default'           => '',
            ) );
        }
        add_settings_field(
            self::OPTION_PREFIX . 'api_keys',
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
        // SECTION 3 : Style de rédaction
        // =====================================================================
        add_settings_section(
            'aicf_style_section',
            __( 'Style de rédaction', 'ai-content-filler' ),
            '__return_false',
            self::PAGE_SLUG
        );

        // Ton rédactionnel
        register_setting( self::OPTION_GROUP, self::OPTION_PREFIX . 'tone', array(
            'type'              => 'string',
            'sanitize_callback' => array( $this, 'sanitize_tone' ),
            'default'           => '',
        ) );
        add_settings_field(
            self::OPTION_PREFIX . 'tone',
            __( 'Ton rédactionnel', 'ai-content-filler' ),
            array( $this, 'render_tone_field' ),
            self::PAGE_SLUG,
            'aicf_style_section'
        );

        // Style des titres
        register_setting( self::OPTION_GROUP, self::OPTION_PREFIX . 'heading_style', array(
            'type'              => 'string',
            'sanitize_callback' => array( $this, 'sanitize_heading_style' ),
            'default'           => 'none',
        ) );
        add_settings_field(
            self::OPTION_PREFIX . 'heading_style',
            __( 'Style des titres', 'ai-content-filler' ),
            array( $this, 'render_heading_style_field' ),
            self::PAGE_SLUG,
            'aicf_style_section'
        );

        // Couleur d'accent pour les titres
        register_setting( self::OPTION_GROUP, self::OPTION_PREFIX . 'heading_style_color', array(
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_hex_color',
            'default'           => '#6366f1',
        ) );
        add_settings_field(
            self::OPTION_PREFIX . 'heading_style_color',
            __( 'Couleur d\'accent', 'ai-content-filler' ),
            array( $this, 'render_heading_style_color_field' ),
            self::PAGE_SLUG,
            'aicf_style_section'
        );

        // =====================================================================
        // SECTION 4 : Brief client
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

    /**
     * Retourne le badge HTML "PRO" pour les champs réservés au plan payant.
     */
    private static function pro_badge() {
        if ( aicf_is_pro() ) {
            return '';
        }
        return ' <span class="aicf-pro-badge">PRO</span>';
    }

    /**
     * Retourne le lien d'upgrade HTML.
     */
    private static function upgrade_link( $text = '' ) {
        if ( aicf_is_pro() ) {
            return '';
        }
        if ( empty( $text ) ) {
            $text = __( 'Passer en Pro', 'ai-content-filler' );
        }
        return ' <a href="' . esc_url( AICF_License::get_upgrade_url() ) . '" class="aicf-upgrade-link">' . esc_html( $text ) . ' &rarr;</a>';
    }

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
        $current_provider = self::get_provider();
        $hints            = array(
            'anthropic' => __( 'Commence par sk-ant-... — Disponible sur console.anthropic.com', 'ai-content-filler' ),
            'openai'    => __( 'Commence par sk-... — Disponible sur platform.openai.com', 'ai-content-filler' ),
            'deepseek'  => __( 'Disponible sur platform.deepseek.com', 'ai-content-filler' ),
        );

        // Un champ input par fournisseur, seul celui du provider actif est visible
        foreach ( self::PROVIDERS as $provider ) :
            $option_name = self::OPTION_PREFIX . 'api_key_' . $provider;
            $value       = get_option( $option_name, '' );
            $is_active   = ( $current_provider === $provider );
            $hint        = isset( $hints[ $provider ] ) ? $hints[ $provider ] : '';
        ?>
        <div class="aicf-api-key-row" data-provider="<?php echo esc_attr( $provider ); ?>" <?php echo $is_active ? '' : 'style="display:none;"'; ?>>
            <div class="aicf-api-key-wrap">
                <input
                    type="password"
                    id="<?php echo esc_attr( $option_name ); ?>"
                    name="<?php echo esc_attr( $option_name ); ?>"
                    value="<?php echo esc_attr( $value ); ?>"
                    class="regular-text aicf-api-key-input"
                    autocomplete="off"
                />
                <button
                    type="button"
                    class="button aicf-toggle-key"
                    data-target="<?php echo esc_attr( $option_name ); ?>"
                    title="<?php esc_attr_e( 'Afficher / masquer', 'ai-content-filler' ); ?>"
                >
                    <span class="dashicons dashicons-visibility"></span>
                </button>
                <button type="button" class="button aicf-test-api aicf-test-api-btn">
                    <?php esc_html_e( 'Tester la connexion', 'ai-content-filler' ); ?>
                </button>
                <span class="aicf-test-result"></span>
            </div>
            <p class="description aicf-api-key-hint"><?php echo esc_html( $hint ); ?></p>
        </div>
        <?php endforeach;
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
        $is_pro    = aicf_is_pro();
        $free_langs = AICF_License::FREE_LANGUAGES;
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
            $disabled = '';
            $pro_label = '';
            if ( ! $is_pro && ! in_array( $code, $free_langs, true ) ) {
                $disabled  = ' disabled="disabled"';
                $pro_label = ' (Pro)';
            }
            printf(
                '<option value="%s" %s%s>%s%s</option>',
                esc_attr( $code ),
                selected( $value, $code, false ),
                $disabled,
                esc_html( $label ),
                esc_html( $pro_label )
            );
        }
        echo '</select>';
        if ( ! $is_pro ) {
            echo '<p class="description">' . esc_html__( 'Français et anglais en version gratuite.', 'ai-content-filler' ) . self::upgrade_link( __( 'Débloquer toutes les langues', 'ai-content-filler' ) ) . '</p>';
        } else {
            echo '<p class="description">' . esc_html__( 'Langue par défaut du contenu généré. L\'utilisateur peut la surcharger dans son prompt.', 'ai-content-filler' ) . '</p>';
        }
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
        $is_pro        = aicf_is_pro();
        $attachment_id = absint( get_option( self::OPTION_PREFIX . 'brief_attachment_id', 0 ) );
        $info          = $is_pro ? self::get_brief_attachment_info() : null;
        ?>
        <div class="aicf-file-upload-wrap <?php echo $is_pro ? '' : 'aicf-pro-locked'; ?>">
            <input
                type="hidden"
                id="<?php echo esc_attr( self::OPTION_PREFIX . 'brief_attachment_id' ); ?>"
                name="<?php echo esc_attr( self::OPTION_PREFIX . 'brief_attachment_id' ); ?>"
                value="<?php echo esc_attr( $is_pro ? $attachment_id : 0 ); ?>"
            />
            <?php if ( $is_pro ) : ?>
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
            <p class="description"><?php esc_html_e( 'Importez un fichier de brief. Son contenu sera extrait et combiné avec le brief textuel ci-dessus.', 'ai-content-filler' ); ?></p>
            <?php else : ?>
            <p class="description"><?php echo self::pro_badge() . ' ' . esc_html__( 'Importez un brief au format PDF, TXT ou MD.', 'ai-content-filler' ) . self::upgrade_link(); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }

    public function render_tone_field() {
        $value  = self::get_tone();
        $is_pro = aicf_is_pro();
        $tones  = array(
            ''             => __( 'Par défaut (neutre)', 'ai-content-filler' ),
            'professional' => __( 'Professionnel — sérieux, rassurant', 'ai-content-filler' ),
            'casual'       => __( 'Décontracté — amical, accessible', 'ai-content-filler' ),
            'commercial'   => __( 'Commercial — persuasif, orienté bénéfices', 'ai-content-filler' ),
            'technical'    => __( 'Technique — expert, précis', 'ai-content-filler' ),
        );

        $disabled_attr = $is_pro ? '' : ' disabled="disabled"';
        echo '<select id="' . esc_attr( self::OPTION_PREFIX . 'tone' ) . '" name="' . esc_attr( self::OPTION_PREFIX . 'tone' ) . '" class="regular-text"' . $disabled_attr . '>';
        foreach ( $tones as $code => $label ) {
            printf(
                '<option value="%s" %s>%s</option>',
                esc_attr( $code ),
                selected( $value, $code, false ),
                esc_html( $label )
            );
        }
        echo '</select>';
        if ( ! $is_pro ) {
            // Champ caché pour conserver la valeur par défaut
            echo '<input type="hidden" name="' . esc_attr( self::OPTION_PREFIX . 'tone' ) . '" value="" />';
            echo self::pro_badge();
            echo '<p class="description">' . esc_html__( 'Personnalisez le ton rédactionnel du contenu.', 'ai-content-filler' ) . self::upgrade_link() . '</p>';
        } else {
            echo '<p class="description">' . esc_html__( 'Le ton sera appliqué à tout le contenu généré sur le site.', 'ai-content-filler' ) . '</p>';
        }
    }

    public function render_heading_style_field() {
        $value   = self::get_heading_style();
        $is_pro  = aicf_is_pro();
        $styles  = array(
            'none'      => __( 'Normal — texte brut', 'ai-content-filler' ),
            'highlight' => __( 'Surlignement — effet marqueur sur les mots-clés', 'ai-content-filler' ),
            'underline' => __( 'Soulignement — trait d\'accent sous les mots-clés', 'ai-content-filler' ),
            'color'     => __( 'Couleur — mots-clés en couleur d\'accent', 'ai-content-filler' ),
        );

        $disabled_attr = $is_pro ? '' : ' disabled="disabled"';
        echo '<select id="' . esc_attr( self::OPTION_PREFIX . 'heading_style' ) . '" name="' . esc_attr( self::OPTION_PREFIX . 'heading_style' ) . '" class="regular-text"' . $disabled_attr . '>';
        foreach ( $styles as $code => $label ) {
            printf(
                '<option value="%s" %s>%s</option>',
                esc_attr( $code ),
                selected( $value, $code, false ),
                esc_html( $label )
            );
        }
        echo '</select>';
        if ( ! $is_pro ) {
            echo '<input type="hidden" name="' . esc_attr( self::OPTION_PREFIX . 'heading_style' ) . '" value="none" />';
            echo self::pro_badge();
            echo '<p class="description">' . esc_html__( 'Ajoutez des effets visuels sur les mots-clés de vos titres.', 'ai-content-filler' ) . self::upgrade_link() . '</p>';
        } else {
            echo '<p class="description">' . esc_html__( 'L\'IA ajoutera du HTML inline sur 1 à 2 mots-clés dans chaque titre pour créer l\'effet choisi.', 'ai-content-filler' ) . '</p>';
        }
    }

    public function render_heading_style_color_field() {
        $value          = self::get_heading_style_color();
        $is_pro         = aicf_is_pro();
        $global_colors  = self::get_elementor_global_colors();
        ?>
        <div class="aicf-color-field-wrap <?php echo $is_pro ? '' : 'aicf-pro-locked'; ?>">
            <input
                type="text"
                id="<?php echo esc_attr( self::OPTION_PREFIX . 'heading_style_color' ); ?>"
                name="<?php echo esc_attr( self::OPTION_PREFIX . 'heading_style_color' ); ?>"
                value="<?php echo esc_attr( $value ); ?>"
                class="aicf-color-picker"
                data-default-color="#6366f1"
                <?php echo $is_pro ? '' : 'disabled="disabled"'; ?>
            />
            <?php if ( ! empty( $global_colors ) && $is_pro ) : ?>
            <div class="aicf-global-colors">
                <span class="aicf-global-colors-label"><?php esc_html_e( 'Couleurs du site (Elementor) :', 'ai-content-filler' ); ?></span>
                <div class="aicf-global-colors-swatches">
                    <?php foreach ( $global_colors as $gc ) : ?>
                    <button
                        type="button"
                        class="aicf-color-swatch"
                        data-color="<?php echo esc_attr( $gc['color'] ); ?>"
                        title="<?php echo esc_attr( $gc['title'] ); ?>"
                        style="background-color: <?php echo esc_attr( $gc['color'] ); ?>;"
                    ></button>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php if ( ! $is_pro ) : ?>
            <p class="description"><?php echo self::pro_badge() . ' ' . esc_html__( 'Personnalisez la couleur d\'accent des titres.', 'ai-content-filler' ); ?></p>
        <?php else : ?>
            <p class="description"><?php esc_html_e( 'Couleur utilisée pour le surlignement, soulignement ou coloration des mots-clés dans les titres.', 'ai-content-filler' ); ?></p>
        <?php endif;
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

    public function sanitize_tone( $value ) {
        $allowed = array( '', 'professional', 'casual', 'commercial', 'technical' );
        return in_array( $value, $allowed, true ) ? $value : '';
    }

    public function sanitize_heading_style( $value ) {
        $allowed = array( 'none', 'highlight', 'underline', 'color' );
        return in_array( $value, $allowed, true ) ? $value : 'none';
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

            <?php if ( ! aicf_is_pro() ) : ?>
            <div class="aicf-notice aicf-notice-upgrade">
                <div class="aicf-notice-upgrade-content">
                    <strong><?php esc_html_e( 'Version gratuite', 'ai-content-filler' ); ?></strong> —
                    <?php printf(
                        /* translators: %d: daily generation limit */
                        esc_html__( '%d générations/jour, 5 types de widgets, 2 templates. ', 'ai-content-filler' ),
                        AICF_License::FREE_DAILY_LIMIT
                    ); ?>
                    <a href="<?php echo esc_url( AICF_License::get_upgrade_url() ); ?>" class="aicf-upgrade-link">
                        <?php esc_html_e( 'Passer en Pro pour tout débloquer', 'ai-content-filler' ); ?> &rarr;
                    </a>
                </div>
            </div>
            <?php endif; ?>

            <?php if ( empty( $api_key ) ) :
                $provider_labels = array( 'anthropic' => 'Anthropic Claude', 'openai' => 'OpenAI', 'deepseek' => 'DeepSeek' );
                $current_label   = isset( $provider_labels[ self::get_provider() ] ) ? $provider_labels[ self::get_provider() ] : self::get_provider();
            ?>
            <div class="aicf-notice aicf-notice-warning">
                <?php printf(
                    /* translators: %s: provider name (e.g. "Anthropic Claude") */
                    esc_html__( 'Aucune clé API configurée pour %s. Le plugin ne pourra pas générer de contenu.', 'ai-content-filler' ),
                    '<strong>' . esc_html( $current_label ) . '</strong>'
                ); ?>
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

                <!-- Section 3 : Style de rédaction -->
                <div class="aicf-card">
                    <div class="aicf-card-header">
                        <span class="aicf-card-icon">&#127912;</span>
                        <h2><?php esc_html_e( 'Style de rédaction', 'ai-content-filler' ); ?></h2>
                    </div>
                    <div class="aicf-card-body">
                        <p class="aicf-section-desc"><?php esc_html_e( 'Ces paramètres s\'appliquent à tout le contenu généré sur le site, quel que soit la page.', 'ai-content-filler' ); ?></p>
                        <table class="form-table" role="presentation">
                            <?php do_settings_fields( self::PAGE_SLUG, 'aicf_style_section' ); ?>
                        </table>
                    </div>
                </div>

                <!-- Section 4 : Brief client -->
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
        $provider = self::get_provider();
        return get_option( self::OPTION_PREFIX . 'api_key_' . $provider, '' );
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

    public static function get_tone() {
        return get_option( self::OPTION_PREFIX . 'tone', '' );
    }

    public static function get_heading_style() {
        return get_option( self::OPTION_PREFIX . 'heading_style', 'none' );
    }

    public static function get_heading_style_color() {
        return get_option( self::OPTION_PREFIX . 'heading_style_color', '#6366f1' );
    }

    /**
     * Récupère les couleurs globales définies dans le kit Elementor actif.
     * Retourne un tableau de [ 'id' => ..., 'title' => ..., 'color' => '#hex' ].
     *
     * @return array
     */
    public static function get_elementor_global_colors() {
        $colors = array();

        $kit_id = absint( get_option( 'elementor_active_kit', 0 ) );
        if ( ! $kit_id ) {
            return $colors;
        }

        $settings = get_post_meta( $kit_id, '_elementor_page_settings', true );
        if ( ! is_array( $settings ) ) {
            return $colors;
        }

        // Couleurs système (primary, secondary, text, accent)
        if ( ! empty( $settings['system_colors'] ) && is_array( $settings['system_colors'] ) ) {
            foreach ( $settings['system_colors'] as $sc ) {
                if ( ! empty( $sc['color'] ) ) {
                    $colors[] = array(
                        'id'    => isset( $sc['_id'] ) ? $sc['_id'] : '',
                        'title' => isset( $sc['title'] ) ? $sc['title'] : '',
                        'color' => $sc['color'],
                    );
                }
            }
        }

        // Couleurs personnalisées
        if ( ! empty( $settings['custom_colors'] ) && is_array( $settings['custom_colors'] ) ) {
            foreach ( $settings['custom_colors'] as $cc ) {
                if ( ! empty( $cc['color'] ) ) {
                    $colors[] = array(
                        'id'    => isset( $cc['_id'] ) ? $cc['_id'] : '',
                        'title' => isset( $cc['title'] ) ? $cc['title'] : '',
                        'color' => $cc['color'],
                    );
                }
            }
        }

        return $colors;
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
     * Migre l'ancienne clé API unique (aicf_api_key) vers le format par fournisseur.
     * Exécuté une seule fois : copie la valeur vers aicf_api_key_anthropic (l'ancien défaut)
     * puis supprime l'option obsolète.
     */
    private static function maybe_migrate_api_key() {
        $old_key = get_option( self::OPTION_PREFIX . 'api_key', '' );
        if ( ! empty( $old_key ) ) {
            $anthropic_key = get_option( self::OPTION_PREFIX . 'api_key_anthropic', '' );
            if ( empty( $anthropic_key ) ) {
                update_option( self::OPTION_PREFIX . 'api_key_anthropic', $old_key );
            }
            delete_option( self::OPTION_PREFIX . 'api_key' );
        }
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
