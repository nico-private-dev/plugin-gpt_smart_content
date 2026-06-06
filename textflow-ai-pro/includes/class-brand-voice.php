<?php
/**
 * Brand Voice — profils de voix de marque.
 *
 * Permet de définir plusieurs profils (nom, secteur, ton, persona)
 * et d'en activer un par défaut. Le profil actif est injecté dans
 * chaque prompt via le filtre `txflow_generate_prompt`.
 *
 * REST endpoints (namespace : textflow-ai/v1) :
 *   GET    /brand-voices         — liste des profils
 *   POST   /brand-voices         — créer un profil
 *   PUT    /brand-voices/{id}    — modifier un profil
 *   DELETE /brand-voices/{id}    — supprimer un profil
 *   POST   /brand-voices/{id}/activate — activer un profil
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TXFLOW_Pro_Brand_Voice {

    /** @var TXFLOW_Pro_Brand_Voice|null */
    private static $instance = null;

    /** Clé wp_options pour stocker les profils */
    const OPTION_KEY = 'txflow_pro_brand_voices';

    /** Clé wp_options pour le profil actif */
    const ACTIVE_KEY = 'txflow_pro_brand_voice_active';

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Hook sur le prompt — ajoute le contexte de marque
        add_filter( 'txflow_generate_prompt', array( $this, 'inject_brand_voice' ), 10, 3 );

        // REST endpoints
        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

        // Page admin
        add_action( 'admin_menu', array( $this, 'add_admin_page' ) );
    }

    // -------------------------------------------------------------------------
    // REST API
    // -------------------------------------------------------------------------

    public function register_rest_routes() {
        $ns = 'textflow-ai/v1';

        register_rest_route( $ns, '/brand-voices', array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'rest_list' ),
                'permission_callback' => array( $this, 'rest_permission' ),
            ),
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'rest_create' ),
                'permission_callback' => array( $this, 'rest_permission' ),
            ),
        ) );

        register_rest_route( $ns, '/brand-voices/(?P<id>[a-z0-9\-]+)', array(
            array(
                'methods'             => 'PUT',
                'callback'            => array( $this, 'rest_update' ),
                'permission_callback' => array( $this, 'rest_permission' ),
            ),
            array(
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => array( $this, 'rest_delete' ),
                'permission_callback' => array( $this, 'rest_permission' ),
            ),
        ) );

        register_rest_route( $ns, '/brand-voices/(?P<id>[a-z0-9\-]+)/activate', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'rest_activate' ),
            'permission_callback' => array( $this, 'rest_permission' ),
        ) );
    }

    public function rest_permission() {
        return current_user_can( 'manage_options' );
    }

    public function rest_list( WP_REST_Request $request ) {
        return rest_ensure_response( array(
            'voices' => self::get_all(),
            'active' => self::get_active_id(),
        ) );
    }

    public function rest_create( WP_REST_Request $request ) {
        $data = $request->get_json_params();
        $voice = self::create_voice( $data );
        if ( is_wp_error( $voice ) ) {
            return $voice;
        }
        return rest_ensure_response( $voice );
    }

    public function rest_update( WP_REST_Request $request ) {
        $id   = $request->get_param( 'id' );
        $data = $request->get_json_params();
        $voice = self::update_voice( $id, $data );
        if ( is_wp_error( $voice ) ) {
            return $voice;
        }
        return rest_ensure_response( $voice );
    }

    public function rest_delete( WP_REST_Request $request ) {
        $id = $request->get_param( 'id' );
        self::delete_voice( $id );
        return rest_ensure_response( array( 'deleted' => true ) );
    }

    public function rest_activate( WP_REST_Request $request ) {
        $id = $request->get_param( 'id' );
        update_option( self::ACTIVE_KEY, sanitize_text_field( $id ) );
        return rest_ensure_response( array( 'active' => $id ) );
    }

    // -------------------------------------------------------------------------
    // Injection dans le prompt
    // -------------------------------------------------------------------------

    public function inject_brand_voice( $prompt, $widgets, $page_id ) {
        $active_id = self::get_active_id();
        if ( empty( $active_id ) ) {
            return $prompt;
        }

        $voice = self::get_voice( $active_id );
        if ( ! $voice ) {
            return $prompt;
        }

        $context  = "\n\n--- BRAND VOICE ---\n";
        $context .= sprintf( "Entreprise / Marque : %s\n", esc_html( $voice['name'] ) );
        if ( ! empty( $voice['industry'] ) ) {
            $context .= sprintf( "Secteur d'activité : %s\n", esc_html( $voice['industry'] ) );
        }
        if ( ! empty( $voice['tone'] ) ) {
            $context .= sprintf( "Ton : %s\n", esc_html( $voice['tone'] ) );
        }
        if ( ! empty( $voice['persona'] ) ) {
            $context .= sprintf( "Description de la marque / persona : %s\n", esc_html( $voice['persona'] ) );
        }
        if ( ! empty( $voice['notes'] ) ) {
            $context .= sprintf( "Notes complémentaires : %s\n", esc_html( $voice['notes'] ) );
        }
        $context .= "--- FIN BRAND VOICE ---\n";
        $context .= "Respecte scrupuleusement ces paramètres de marque dans tout le contenu généré.";

        return $prompt . $context;
    }

    // -------------------------------------------------------------------------
    // CRUD interne
    // -------------------------------------------------------------------------

    public static function get_all() {
        return get_option( self::OPTION_KEY, array() );
    }

    public static function get_active_id() {
        return get_option( self::ACTIVE_KEY, '' );
    }

    public static function get_voice( $id ) {
        $voices = self::get_all();
        foreach ( $voices as $v ) {
            if ( $v['id'] === $id ) {
                return $v;
            }
        }
        return null;
    }

    public static function create_voice( array $data ) {
        $voices = self::get_all();
        $voice  = array(
            'id'       => wp_generate_uuid4(),
            'name'     => sanitize_text_field( $data['name'] ?? '' ),
            'industry' => sanitize_text_field( $data['industry'] ?? '' ),
            'tone'     => sanitize_text_field( $data['tone'] ?? '' ),
            'persona'  => sanitize_textarea_field( $data['persona'] ?? '' ),
            'notes'    => sanitize_textarea_field( $data['notes'] ?? '' ),
        );
        if ( empty( $voice['name'] ) ) {
            return new WP_Error( 'missing_name', __( 'Le nom du profil est requis.', 'textflow-ai-pro' ), array( 'status' => 400 ) );
        }
        $voices[] = $voice;
        update_option( self::OPTION_KEY, $voices );
        return $voice;
    }

    public static function update_voice( $id, array $data ) {
        $voices = self::get_all();
        $found  = false;
        foreach ( $voices as &$v ) {
            if ( $v['id'] === $id ) {
                $v['name']     = sanitize_text_field( $data['name'] ?? $v['name'] );
                $v['industry'] = sanitize_text_field( $data['industry'] ?? $v['industry'] );
                $v['tone']     = sanitize_text_field( $data['tone'] ?? $v['tone'] );
                $v['persona']  = sanitize_textarea_field( $data['persona'] ?? $v['persona'] );
                $v['notes']    = sanitize_textarea_field( $data['notes'] ?? $v['notes'] );
                $found = $v;
                break;
            }
        }
        if ( ! $found ) {
            return new WP_Error( 'not_found', __( 'Profil introuvable.', 'textflow-ai-pro' ), array( 'status' => 404 ) );
        }
        update_option( self::OPTION_KEY, $voices );
        return $found;
    }

    public static function delete_voice( $id ) {
        $voices = array_filter( self::get_all(), function( $v ) use ( $id ) {
            return $v['id'] !== $id;
        } );
        update_option( self::OPTION_KEY, array_values( $voices ) );
        // Désactiver si c'était le profil actif
        if ( self::get_active_id() === $id ) {
            delete_option( self::ACTIVE_KEY );
        }
    }

    // -------------------------------------------------------------------------
    // Page admin
    // -------------------------------------------------------------------------

    public function add_admin_page() {
        add_submenu_page(
            'options-general.php',
            __( 'Brand Voice — TextFlow Pro', 'textflow-ai-pro' ),
            __( 'Brand Voice', 'textflow-ai-pro' ),
            'manage_options',
            'txflow-pro-brand-voice',
            array( $this, 'render_admin_page' )
        );
    }

    public function render_admin_page() {
        $voices    = self::get_all();
        $active_id = self::get_active_id();
        ?>
        <div class="wrap txflow-pro-wrap">
            <div class="txflow-pro-header">
                <h1>🎯 <?php esc_html_e( 'Brand Voice', 'textflow-ai-pro' ); ?></h1>
                <p><?php esc_html_e( 'Définissez la voix de votre marque. Le profil actif sera automatiquement injecté dans chaque génération IA.', 'textflow-ai-pro' ); ?></p>
            </div>

            <div class="txflow-pro-card" id="txflow-bv-form-card">
                <h2><?php esc_html_e( 'Nouveau profil', 'textflow-ai-pro' ); ?></h2>
                <form id="txflow-bv-form" data-action="create">
                    <table class="form-table">
                        <tr>
                            <th><label for="bv-name"><?php esc_html_e( 'Nom du profil *', 'textflow-ai-pro' ); ?></label></th>
                            <td><input type="text" id="bv-name" name="name" class="regular-text" required placeholder="Ex : Mon entreprise"></td>
                        </tr>
                        <tr>
                            <th><label for="bv-industry"><?php esc_html_e( "Secteur d'activité", 'textflow-ai-pro' ); ?></label></th>
                            <td><input type="text" id="bv-industry" name="industry" class="regular-text" placeholder="Ex : E-commerce, SaaS, Immobilier…"></td>
                        </tr>
                        <tr>
                            <th><label for="bv-tone"><?php esc_html_e( 'Ton', 'textflow-ai-pro' ); ?></label></th>
                            <td>
                                <select id="bv-tone" name="tone">
                                    <option value=""><?php esc_html_e( '— Aucun —', 'textflow-ai-pro' ); ?></option>
                                    <option value="professionnel"><?php esc_html_e( 'Professionnel', 'textflow-ai-pro' ); ?></option>
                                    <option value="décontracté"><?php esc_html_e( 'Décontracté', 'textflow-ai-pro' ); ?></option>
                                    <option value="inspirant"><?php esc_html_e( 'Inspirant', 'textflow-ai-pro' ); ?></option>
                                    <option value="expert"><?php esc_html_e( 'Expert / Technique', 'textflow-ai-pro' ); ?></option>
                                    <option value="humain"><?php esc_html_e( 'Humain / Empathique', 'textflow-ai-pro' ); ?></option>
                                    <option value="percutant"><?php esc_html_e( 'Percutant / Direct', 'textflow-ai-pro' ); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="bv-persona"><?php esc_html_e( 'Description de la marque', 'textflow-ai-pro' ); ?></label></th>
                            <td><textarea id="bv-persona" name="persona" rows="4" class="large-text" placeholder="<?php esc_attr_e( 'Décrivez votre marque, vos valeurs, votre cible client…', 'textflow-ai-pro' ); ?>"></textarea></td>
                        </tr>
                        <tr>
                            <th><label for="bv-notes"><?php esc_html_e( 'Notes complémentaires', 'textflow-ai-pro' ); ?></label></th>
                            <td><textarea id="bv-notes" name="notes" rows="3" class="large-text" placeholder="<?php esc_attr_e( 'Mots-clés à éviter, expressions récurrentes, etc.', 'textflow-ai-pro' ); ?>"></textarea></td>
                        </tr>
                    </table>
                    <input type="hidden" id="bv-edit-id" name="id" value="">
                    <p class="submit">
                        <button type="submit" class="button button-primary" id="bv-submit-btn"><?php esc_html_e( 'Créer le profil', 'textflow-ai-pro' ); ?></button>
                        <button type="button" class="button" id="bv-cancel-btn" style="display:none;"><?php esc_html_e( 'Annuler', 'textflow-ai-pro' ); ?></button>
                    </p>
                </form>
            </div>

            <div class="txflow-pro-card" id="txflow-bv-list-card">
                <h2><?php esc_html_e( 'Profils enregistrés', 'textflow-ai-pro' ); ?></h2>
                <div id="txflow-bv-list">
                    <?php if ( empty( $voices ) ) : ?>
                        <p class="txflow-empty"><?php esc_html_e( 'Aucun profil enregistré.', 'textflow-ai-pro' ); ?></p>
                    <?php else : ?>
                        <?php foreach ( $voices as $v ) : $is_active = ( $v['id'] === $active_id ); ?>
                        <div class="txflow-bv-item <?php echo $is_active ? 'txflow-bv-active' : ''; ?>" data-id="<?php echo esc_attr( $v['id'] ); ?>">
                            <div class="txflow-bv-item-header">
                                <strong><?php echo esc_html( $v['name'] ); ?></strong>
                                <?php if ( $is_active ) : ?>
                                    <span class="txflow-badge-active"><?php esc_html_e( '✓ Actif', 'textflow-ai-pro' ); ?></span>
                                <?php endif; ?>
                                <?php if ( ! empty( $v['tone'] ) ) : ?>
                                    <span class="txflow-badge-tone"><?php echo esc_html( $v['tone'] ); ?></span>
                                <?php endif; ?>
                            </div>
                            <?php if ( ! empty( $v['industry'] ) ) : ?>
                                <div class="txflow-bv-industry"><?php echo esc_html( $v['industry'] ); ?></div>
                            <?php endif; ?>
                            <?php if ( ! empty( $v['persona'] ) ) : ?>
                                <div class="txflow-bv-persona"><?php echo esc_html( wp_trim_words( $v['persona'], 20 ) ); ?></div>
                            <?php endif; ?>
                            <div class="txflow-bv-actions">
                                <?php if ( ! $is_active ) : ?>
                                    <button class="button button-small txflow-bv-activate-btn"
                                            data-id="<?php echo esc_attr( $v['id'] ); ?>">
                                        <?php esc_html_e( 'Activer', 'textflow-ai-pro' ); ?>
                                    </button>
                                <?php endif; ?>
                                <button class="button button-small txflow-bv-edit-btn"
                                        data-voice="<?php echo esc_attr( wp_json_encode( $v ) ); ?>">
                                    <?php esc_html_e( 'Modifier', 'textflow-ai-pro' ); ?>
                                </button>
                                <button class="button button-small txflow-bv-delete-btn"
                                        data-id="<?php echo esc_attr( $v['id'] ); ?>">
                                    <?php esc_html_e( 'Supprimer', 'textflow-ai-pro' ); ?>
                                </button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }
}
