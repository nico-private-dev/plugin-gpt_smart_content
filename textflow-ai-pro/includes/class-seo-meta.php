<?php
/**
 * SEO Meta — génération automatique du titre SEO et de la méta-description.
 *
 * Supporte : Yoast SEO, RankMath, AIOSEO.
 * Ajoute un bouton "Générer méta SEO" dans la sidebar Gutenberg.
 *
 * REST endpoint :
 *   POST /seo-meta/generate   { post_id, prompt }
 *     → { title, description }
 *   POST /seo-meta/save       { post_id, title, description, plugin }
 *     → { saved: true }
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TXFLOW_Pro_Seo_Meta {

    /** @var TXFLOW_Pro_Seo_Meta|null */
    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

        // Ajouter le bouton SEO dans la sidebar Gutenberg
        add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_gutenberg_seo_script' ), 20 );
    }

    // -------------------------------------------------------------------------
    // REST API
    // -------------------------------------------------------------------------

    public function register_rest_routes() {
        $ns = 'textflow-ai/v1';

        register_rest_route( $ns, '/seo-meta/generate', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'rest_generate' ),
            'permission_callback' => array( $this, 'rest_permission' ),
            'args'                => array(
                'post_id' => array( 'required' => true, 'sanitize_callback' => 'absint' ),
                'prompt'  => array( 'required' => false, 'sanitize_callback' => 'sanitize_textarea_field' ),
            ),
        ) );

        register_rest_route( $ns, '/seo-meta/save', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'rest_save' ),
            'permission_callback' => array( $this, 'rest_permission' ),
        ) );
    }

    public function rest_permission() {
        return current_user_can( 'edit_posts' );
    }

    /**
     * Génère un titre SEO et une méta-description via l'API IA.
     */
    public function rest_generate( WP_REST_Request $request ) {
        $post_id = $request->get_param( 'post_id' );
        $post    = get_post( $post_id );

        if ( ! $post ) {
            return new WP_Error( 'not_found', __( 'Post introuvable.', 'textflow-ai-pro' ), array( 'status' => 404 ) );
        }

        // Contexte : titre de page + prompt utilisateur + contenu existant (extrait)
        $page_title = get_the_title( $post_id );
        $user_prompt = $request->get_param( 'prompt' ) ?: '';
        $content_excerpt = wp_trim_words( wp_strip_all_tags( $post->post_content ), 80 );

        $prompt = "Génère un titre SEO (60 caractères max) et une méta-description SEO (155 caractères max) pour la page suivante.\n\n"
            . "Titre de la page : {$page_title}\n"
            . ( $user_prompt ? "Contexte du site : {$user_prompt}\n" : '' )
            . ( $content_excerpt ? "Extrait du contenu : {$content_excerpt}\n" : '' )
            . "\nRéponds UNIQUEMENT avec un JSON valide :\n"
            . '{"title":"...","description":"..."}';

        // Appel à l'API IA via le handler principal
        if ( ! class_exists( 'TXFLOW_API_Handler' ) ) {
            return new WP_Error( 'plugin_missing', __( 'Plugin TextFlow AI requis.', 'textflow-ai-pro' ), array( 'status' => 500 ) );
        }

        $handler = new TXFLOW_API_Handler();
        // On passe un faux widget "seo-meta" pour obtenir une réponse JSON
        $result = $handler->generate_content(
            $prompt,
            array( array( 'id' => 'seo_title', 'type' => 'seo-title', 'fields' => array( 'content' => 'titre SEO' ) ),
                   array( 'id' => 'seo_desc',  'type' => 'seo-description', 'fields' => array( 'content' => 'méta-description' ) ) ),
            $post_id
        );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        // Extraire le JSON depuis la réponse
        $seo = null;
        if ( isset( $result['seo_title'] ) && isset( $result['seo_desc'] ) ) {
            $seo = array(
                'title'       => $result['seo_title'],
                'description' => $result['seo_desc'],
            );
        } else {
            // Essayer de parser depuis le résultat brut
            foreach ( $result as $v ) {
                $parsed = json_decode( $v, true );
                if ( $parsed && isset( $parsed['title'] ) ) {
                    $seo = $parsed;
                    break;
                }
            }
        }

        if ( ! $seo ) {
            // Construire depuis les valeurs disponibles
            $seo = array(
                'title'       => implode( ' — ', array_slice( array_values( $result ), 0, 1 ) ),
                'description' => implode( ' ', array_slice( array_values( $result ), 0, 2 ) ),
            );
        }

        return rest_ensure_response( $seo );
    }

    /**
     * Sauvegarde le titre SEO et la description dans le plugin SEO actif.
     */
    public function rest_save( WP_REST_Request $request ) {
        $data    = $request->get_json_params();
        $post_id = absint( $data['post_id'] ?? 0 );
        $title   = sanitize_text_field( $data['title'] ?? '' );
        $desc    = sanitize_text_field( $data['description'] ?? '' );
        $plugin  = sanitize_text_field( $data['plugin'] ?? 'auto' );

        if ( ! $post_id ) {
            return new WP_Error( 'invalid', __( 'post_id requis.', 'textflow-ai-pro' ), array( 'status' => 400 ) );
        }

        $saved_to = $this->save_to_seo_plugin( $post_id, $title, $desc, $plugin );

        return rest_ensure_response( array(
            'saved'   => true,
            'plugin'  => $saved_to,
            'title'   => $title,
            'description' => $desc,
        ) );
    }

    /**
     * Détecte le plugin SEO actif et sauvegarde les valeurs.
     */
    private function save_to_seo_plugin( $post_id, $title, $desc, $plugin = 'auto' ) {
        // Yoast SEO
        if ( ( $plugin === 'auto' || $plugin === 'yoast' ) && defined( 'WPSEO_VERSION' ) ) {
            update_post_meta( $post_id, '_yoast_wpseo_title', $title );
            update_post_meta( $post_id, '_yoast_wpseo_metadesc', $desc );
            return 'yoast';
        }

        // RankMath
        if ( ( $plugin === 'auto' || $plugin === 'rankmath' ) && defined( 'RANK_MATH_VERSION' ) ) {
            update_post_meta( $post_id, 'rank_math_title', $title );
            update_post_meta( $post_id, 'rank_math_description', $desc );
            return 'rankmath';
        }

        // AIOSEO
        if ( ( $plugin === 'auto' || $plugin === 'aioseo' ) && defined( 'AIOSEO_VERSION' ) ) {
            update_post_meta( $post_id, '_aioseo_title', $title );
            update_post_meta( $post_id, '_aioseo_description', $desc );
            return 'aioseo';
        }

        // Fallback : post meta générique
        update_post_meta( $post_id, '_txflow_seo_title', $title );
        update_post_meta( $post_id, '_txflow_seo_description', $desc );
        return 'meta';
    }

    // -------------------------------------------------------------------------
    // Injection Gutenberg
    // -------------------------------------------------------------------------

    /**
     * Injecte un script inline dans la sidebar Gutenberg pour ajouter
     * le panneau SEO Meta au bas de la sidebar TextFlow.
     */
    public function enqueue_gutenberg_seo_script() {
        if ( ! wp_script_is( 'txflow-gutenberg-panel', 'enqueued' ) ) {
            return;
        }

        $seo_plugin = $this->detect_seo_plugin();

        $js = <<<JS
(function() {
    var seoPlugin = {$this->js_encode( $seo_plugin )};
    var restUrl   = {$this->js_encode( rest_url( 'textflow-ai/v1' ) )};
    var nonce     = {$this->js_encode( wp_create_nonce( 'wp_rest' ) )};

    // Attendre que la sidebar TextFlow soit enregistrée
    wp.domReady(function() {
        if ( typeof wp === 'undefined' || ! wp.plugins ) return;

        wp.plugins.registerPlugin('txflow-seo-meta-panel', {
            render: function() {
                var el       = wp.element.createElement;
                var useState = wp.element.useState;
                var select   = wp.data.select;

                var PluginDocumentSettingPanel = wp.editPost.PluginDocumentSettingPanel;

                return el(PluginDocumentSettingPanel, {
                    name: 'txflow-seo-meta',
                    title: '🔍 TextFlow AI — Méta SEO' + (seoPlugin ? ' (' + seoPlugin + ')' : ''),
                    icon: 'search'
                }, el(TxflowSeoMetaPanel, { restUrl: restUrl, nonce: nonce, seoPlugin: seoPlugin }));
            }
        });
    });

    function TxflowSeoMetaPanel(props) {
        var el       = wp.element.createElement;
        var useState = wp.element.useState;
        var postId   = wp.data.select('core/editor').getCurrentPostId();

        var _st = useState({ title: '', description: '', status: 'idle', prompt: '' });
        var state    = _st[0];
        var setState = _st[1];

        function generate() {
            setState(function(s){ return Object.assign({}, s, { status: 'generating' }); });
            wp.apiFetch({
                path: '/textflow-ai/v1/seo-meta/generate',
                method: 'POST',
                data: { post_id: postId, prompt: state.prompt }
            }).then(function(res) {
                setState(function(s){ return Object.assign({}, s, { title: res.title, description: res.description, status: 'done' }); });
            }).catch(function(err) {
                setState(function(s){ return Object.assign({}, s, { status: 'error' }); });
            });
        }

        function save() {
            wp.apiFetch({
                path: '/textflow-ai/v1/seo-meta/save',
                method: 'POST',
                data: { post_id: postId, title: state.title, description: state.description }
            }).then(function() {
                setState(function(s){ return Object.assign({}, s, { status: 'saved' }); });
            });
        }

        return el('div', { style: { padding: '8px 0' } },
            el('p', { style: { fontSize: '12px', color: '#6b7280', marginBottom: '8px' } },
                'Générez automatiquement le titre SEO et la méta-description de cette page.'
            ),
            el('textarea', {
                placeholder: 'Contexte du site (optionnel)…',
                value: state.prompt,
                onChange: function(e){ setState(function(s){ return Object.assign({}, s, { prompt: e.target.value }); }); },
                style: { width: '100%', minHeight: '60px', fontSize: '12px', marginBottom: '8px', resize: 'vertical' }
            }),
            el('button', {
                className: 'components-button is-primary',
                onClick: generate,
                disabled: state.status === 'generating',
                style: { marginBottom: '12px' }
            }, state.status === 'generating' ? 'Génération…' : 'Générer les métas'),

            state.title ? el('div', null,
                el('label', { style: { fontWeight: '600', fontSize: '12px', display: 'block', marginBottom: '4px' } }, 'Titre SEO'),
                el('input', {
                    type: 'text',
                    value: state.title,
                    onChange: function(e){ setState(function(s){ return Object.assign({}, s, { title: e.target.value }); }); },
                    style: { width: '100%', marginBottom: '8px', fontSize: '12px' }
                }),
                el('p', { style: { fontSize: '11px', color: state.title.length > 60 ? 'red' : '#6b7280', margin: '0 0 12px' } },
                    state.title.length + '/60 caractères'
                ),
                el('label', { style: { fontWeight: '600', fontSize: '12px', display: 'block', marginBottom: '4px' } }, 'Méta-description'),
                el('textarea', {
                    value: state.description,
                    onChange: function(e){ setState(function(s){ return Object.assign({}, s, { description: e.target.value }); }); },
                    style: { width: '100%', minHeight: '72px', fontSize: '12px', marginBottom: '4px', resize: 'vertical' }
                }),
                el('p', { style: { fontSize: '11px', color: state.description.length > 155 ? 'red' : '#6b7280', margin: '0 0 12px' } },
                    state.description.length + '/155 caractères'
                ),
                el('button', {
                    className: 'components-button is-secondary',
                    onClick: save
                }, state.status === 'saved' ? '✓ Enregistré !' : 'Enregistrer dans ' + (seoPlugin || 'les métas'))
            ) : null,

            state.status === 'error' ? el('p', { style: { color: 'red', fontSize: '12px' } }, 'Erreur lors de la génération.') : null
        );
    }
})();
JS;

        wp_add_inline_script( 'txflow-gutenberg-panel', $js );
    }

    /**
     * Détecte le plugin SEO actif.
     */
    private function detect_seo_plugin() {
        if ( defined( 'WPSEO_VERSION' ) ) {
            return 'Yoast SEO';
        }
        if ( defined( 'RANK_MATH_VERSION' ) ) {
            return 'RankMath';
        }
        if ( defined( 'AIOSEO_VERSION' ) ) {
            return 'AIOSEO';
        }
        return null;
    }

    private function js_encode( $value ) {
        return wp_json_encode( $value );
    }
}
