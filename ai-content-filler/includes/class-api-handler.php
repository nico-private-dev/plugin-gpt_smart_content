<?php
/**
 * Communication avec les APIs IA (Anthropic Claude, OpenAI, DeepSeek).
 * Construit les prompts, envoie la requête et parse la réponse JSON.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AICF_API_Handler {

    /** URL de l'API Messages d'Anthropic */
    const ANTHROPIC_API_URL = 'https://api.anthropic.com/v1/messages';

    /** URL de l'API Chat Completions d'OpenAI */
    const OPENAI_API_URL = 'https://api.openai.com/v1/chat/completions';

    /** URL de l'API DeepSeek (format OpenAI-compatible) */
    const DEEPSEEK_API_URL = 'https://api.deepseek.com/v1/chat/completions';

    /** Version de l'API Anthropic */
    const API_VERSION = '2023-06-01';

    /** Timeout en secondes pour l'appel HTTP */
    const TIMEOUT = 90;

    /** Tokens minimum par widget pour éviter la troncature */
    const TOKENS_PER_WIDGET = 400;

    /** Noms de langues pour le system prompt */
    private static $language_names = array(
        'fr' => 'français',
        'en' => 'anglais (English)',
        'es' => 'espagnol (español)',
        'de' => 'allemand (Deutsch)',
        'it' => 'italien (italiano)',
        'pt' => 'portugais (português)',
        'nl' => 'néerlandais (Nederlands)',
        'ar' => 'arabe (العربية)',
    );

    /**
     * Registre des types de widgets supportés côté serveur.
     * Chaque type est associé à une description concise pour le prompt.
     */
    private static $widget_types = array(
        // Elementor Free
        'heading'               => 'titre court et percutant, 5-8 mots max',
        'text-editor'           => 'un ou plusieurs paragraphes en HTML valide (<p>, <strong>, <em>)',
        'button'                => 'texte de bouton, appel à l\'action court (2-5 mots)',
        'icon-box'              => 'titre court + description en HTML',
        'image-box'             => 'titre court + description en HTML',
        'testimonial'           => 'témoignage client réaliste avec nom et poste',
        'counter'               => 'intitulé court de compteur',
        'progress'              => 'intitulé de compétence ou progression',
        'alert'                 => 'titre d\'alerte et description concise',
        'star-rating'           => 'intitulé court pour la note',
        // Elementor Pro (champs directs)
        'call-to-action'        => 'titre accrocheur, description courte et texte de bouton',
        'animated-headline'     => 'texte avant et texte surligné percutants',
        'flip-box'              => 'titres et descriptions pour les deux faces',
        'price-table'           => 'titre de plan, sous-titre, période et texte de bouton',
        'blockquote'            => 'citation percutante et pertinente',
        // Elementor Pro (repeaters — champs en notation pointée repKey.index.field)
        'testimonial-carousel'  => 'carrousel de témoignages clients (nom, poste, avis) — chaque item en notation slides.N.champ',
        'reviews'               => 'avis clients avec nom, poste et contenu — chaque item en notation slides.N.champ',
        'slides'                => 'diapositives avec titre, description et bouton — chaque item en notation slides.N.champ',
        'price-list'            => 'liste de prix avec titre, description et prix — chaque item en notation price_list.N.champ',
        // Elementor Free (repeaters)
        'accordion'             => 'accordéon avec titre et contenu riche par item — chaque item en notation tabs.N.champ',
        'toggle'                => 'bloc toggle avec titre et contenu riche par item — chaque item en notation tabs.N.champ',
        'tabs'                  => 'onglets avec titre et contenu riche par item — chaque item en notation tabs.N.champ',
    );

    /**
     * Génère le contenu pour une liste de widgets via l'API IA configurée.
     *
     * @param string $user_prompt  Le prompt saisi par l'utilisateur dans l'éditeur.
     * @param array  $widgets      Tableau de widgets [ { id, type, fields } ].
     * @param int    $page_id      ID de la page WordPress.
     * @return array|WP_Error      Tableau de widgets avec contenu généré, ou WP_Error.
     */
    public function generate_content( $user_prompt, $widgets, $page_id ) {
        $api_key = AICF_Settings::get_api_key();

        if ( empty( $api_key ) ) {
            return new WP_Error(
                'aicf_no_api_key',
                __( 'Configurez votre clé API dans Réglages > AI Content Filler.', 'ai-content-filler' ),
                array( 'status' => 400 )
            );
        }

        $system_prompt = $this->build_system_prompt();
        $user_message  = $this->build_user_message( $user_prompt, $widgets, $page_id );
        $widget_count  = count( $widgets );

        // Premier essai
        $result = $this->call_api( $system_prompt, $user_message, $widget_count );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $parsed = $this->parse_response( $result );

        // Si le JSON est invalide, on retente une fois avec un prompt plus strict
        if ( is_wp_error( $parsed ) ) {
            $strict_message = $user_message . "\n\n"
                . "CRITICAL INSTRUCTION: Your previous response was not valid JSON. "
                . "You MUST respond with ONLY a raw JSON object. No markdown, no code blocks, no explanation. "
                . "Start your response with { and end with }. "
                . "Exact format: {\"widgets\": [{\"id\": \"WIDGET_ID\", \"content\": {\"field_key\": \"CONTENT\", ...}}, ...]}";

            $result = $this->call_api( $system_prompt, $strict_message, $widget_count );

            if ( is_wp_error( $result ) ) {
                return $result;
            }

            $parsed = $this->parse_response( $result );

            if ( is_wp_error( $parsed ) ) {
                return $parsed;
            }
        }

        return $parsed;
    }

    /**
     * Construit le system prompt à partir du brief client et des réglages.
     */
    private function build_system_prompt() {
        $brief      = AICF_Settings::get_client_brief();
        $file_brief = AICF_Settings::extract_brief_file_content();
        $lang_code  = AICF_Settings::get_language();
        $lang_name  = isset( self::$language_names[ $lang_code ] ) ? self::$language_names[ $lang_code ] : 'français';

        $system = "Tu es un rédacteur web professionnel. Tu rédiges du contenu pour des sites web créés avec WordPress et Elementor.\n\n";

        if ( ! empty( $brief ) ) {
            $system .= "BRIEF CLIENT :\n" . $brief . "\n\n";
        }

        if ( ! empty( $file_brief ) ) {
            $system .= "BRIEF CLIENT (document importé) :\n" . $file_brief . "\n\n";
        }

        $system .= "RÈGLES DE RÉDACTION :\n";
        $system .= "- Adapte le style au type de champ :\n";
        $system .= "  * Titres (title, title_text, heading, before_text, highlighted_text, alert_title, etc.) : courts et percutants, 5-8 mots max.\n";
        $system .= "  * Paragraphes/descriptions (editor, description, description_text, alert_description, footer_additional_info, etc.) : HTML valide avec <p>, <strong>, <em>.\n";
        $system .= "  * Boutons (text, button_text) : appel à l'action clair, 2-5 mots.\n";
        $system .= "  * Témoignages (testimonial_content) : avis client réaliste et crédible.\n";
        $system .= "  * Noms (testimonial_name) : prénom et nom réalistes.\n";
        $system .= "  * Postes (testimonial_job) : intitulé de poste crédible.\n";
        $system .= "  * Citations (blockquote_content) : citation pertinente et percutante.\n";
        $system .= "  * Sous-titres (sub_heading) : phrase d'accroche courte.\n";
        $system .= "  * Périodes (period) : durée courte (ex: '/mois', '/an').\n";
        $system .= "- Pour les widgets à items multiples (carrousels, listes), les champs utilisent la notation pointée : repKey.index.field (ex: slides.0.content, slides.1.name). Reproduis exactement ces clés dans ta réponse.\n";
        $system .= "- Respecte exactement les IDs des widgets et les clés des champs fournis dans ta réponse.\n";
        $system .= "- Réponds UNIQUEMENT avec un objet JSON valide, sans texte avant ni après.\n";
        $system .= "- Format de réponse obligatoire : {\"widgets\": [{\"id\": \"ID_DU_WIDGET\", \"content\": {\"clé_du_champ\": \"CONTENU_GENERE\", ...}}, ...]}\n";
        $system .= "- N'invente pas de widgets ni de champs supplémentaires, traite uniquement ceux fournis.\n";
        $system .= "- Rédige en " . $lang_name . " sauf si le prompt de l'utilisateur indique explicitement une autre langue.\n";

        return $system;
    }

    /**
     * Construit le message utilisateur avec le contexte de la page et la liste des widgets.
     */
    private function build_user_message( $user_prompt, $widgets, $page_id ) {
        $page_title = get_the_title( $page_id );

        $message = "PAGE : \"" . $page_title . "\" (ID: " . $page_id . ")\n\n";
        $message .= "CONSIGNE DE L'UTILISATEUR :\n" . $user_prompt . "\n\n";
        $message .= "LISTE DES WIDGETS À REMPLIR :\n\n";

        foreach ( $widgets as $index => $widget ) {
            $num  = $index + 1;
            $type = $widget['type'];
            $hint = isset( self::$widget_types[ $type ] ) ? self::$widget_types[ $type ] : $type;

            $message .= $num . ". Widget ID: \"" . $widget['id'] . "\"\n";
            $message .= "   Type: " . $type . " (" . $hint . ")\n";

            // Format multi-champs (nouveau)
            if ( isset( $widget['fields'] ) && is_array( $widget['fields'] ) && ! empty( $widget['fields'] ) ) {
                $message .= "   Champs :\n";
                foreach ( $widget['fields'] as $field_key => $field_value ) {
                    $display_value = ! empty( $field_value ) ? '"' . $field_value . '"' : '(vide)';
                    $message .= "     - " . $field_key . " : " . $display_value . "\n";
                }
            }
            // Rétrocompatibilité ancien format (current_text)
            elseif ( isset( $widget['current_text'] ) ) {
                $message .= "   Contenu actuel: \"" . $widget['current_text'] . "\"\n";
            }

            $message .= "\n";
        }

        $message .= "Génère le contenu pour chaque widget ci-dessus en respectant le format JSON demandé.\n";
        $message .= "Pour chaque widget, retourne un objet content avec les mêmes clés de champs que celles listées ci-dessus.";

        return $message;
    }

    /**
     * Dispatche l'appel API vers le bon fournisseur selon les réglages.
     *
     * @param string $system_prompt  System prompt.
     * @param string $user_message   Message utilisateur.
     * @param int    $widget_count   Nombre de widgets.
     * @return string|WP_Error       Texte brut retourné par l'IA, ou WP_Error.
     */
    private function call_api( $system_prompt, $user_message, $widget_count = 1 ) {
        $provider = AICF_Settings::get_provider();
        $api_key  = AICF_Settings::get_api_key();

        switch ( $provider ) {
            case 'openai':
                return $this->call_openai_compatible_api(
                    $api_key,
                    self::OPENAI_API_URL,
                    $system_prompt,
                    $user_message,
                    $widget_count
                );
            case 'deepseek':
                return $this->call_openai_compatible_api(
                    $api_key,
                    self::DEEPSEEK_API_URL,
                    $system_prompt,
                    $user_message,
                    $widget_count
                );
            case 'anthropic':
            default:
                return $this->call_anthropic_api(
                    $api_key,
                    $system_prompt,
                    $user_message,
                    $widget_count
                );
        }
    }

    /**
     * Effectue l'appel HTTP vers l'API Anthropic (Claude).
     *
     * @param string $api_key        Clé API Anthropic.
     * @param string $system_prompt  System prompt.
     * @param string $user_message   Message utilisateur.
     * @param int    $widget_count   Nombre de widgets.
     * @return string|WP_Error       Texte de la réponse, ou WP_Error.
     */
    private function call_anthropic_api( $api_key, $system_prompt, $user_message, $widget_count = 1 ) {
        $max_tokens = $this->compute_max_tokens( $widget_count );

        $body = array(
            'model'       => AICF_Settings::get_model(),
            'max_tokens'  => $max_tokens,
            'temperature' => AICF_Settings::get_temperature(),
            'system'      => $system_prompt,
            'messages'    => array(
                array(
                    'role'    => 'user',
                    'content' => $user_message,
                ),
            ),
        );

        $response = wp_remote_post( self::ANTHROPIC_API_URL, array(
            'timeout' => self::TIMEOUT,
            'headers' => array(
                'Content-Type'      => 'application/json',
                'x-api-key'         => $api_key,
                'anthropic-version' => self::API_VERSION,
            ),
            'body'    => wp_json_encode( $body ),
        ) );

        if ( is_wp_error( $response ) ) {
            return new WP_Error(
                'aicf_api_request_failed',
                __( 'Erreur de connexion à l\'API : ', 'ai-content-filler' ) . $response->get_error_message(),
                array( 'status' => 502 )
            );
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $body_raw    = wp_remote_retrieve_body( $response );

        if ( $status_code < 200 || $status_code >= 300 ) {
            $error_data = json_decode( $body_raw, true );
            $error_msg  = isset( $error_data['error']['message'] )
                ? $error_data['error']['message']
                : __( 'Erreur inconnue de l\'API.', 'ai-content-filler' );

            return new WP_Error(
                'aicf_api_error',
                sprintf( __( 'API Anthropic (HTTP %d) : %s', 'ai-content-filler' ), $status_code, $error_msg ),
                array( 'status' => $status_code )
            );
        }

        $data = json_decode( $body_raw, true );

        if ( ! isset( $data['content'][0]['text'] ) ) {
            return new WP_Error(
                'aicf_api_empty_response',
                __( 'La réponse de l\'API est vide ou dans un format inattendu.', 'ai-content-filler' ),
                array( 'status' => 500 )
            );
        }

        if ( isset( $data['stop_reason'] ) && 'max_tokens' === $data['stop_reason'] ) {
            return new WP_Error(
                'aicf_response_truncated',
                __( 'La réponse a été tronquée (trop de contenu pour le nombre de tokens alloué). Essayez avec moins de widgets ou augmentez la limite de tokens dans les réglages.', 'ai-content-filler' ),
                array( 'status' => 500 )
            );
        }

        return $data['content'][0]['text'];
    }

    /**
     * Effectue l'appel HTTP vers une API compatible OpenAI (OpenAI ou DeepSeek).
     *
     * @param string $api_key       Clé API.
     * @param string $api_url       URL de l'endpoint.
     * @param string $system_prompt System prompt.
     * @param string $user_message  Message utilisateur.
     * @param int    $widget_count  Nombre de widgets.
     * @return string|WP_Error      Texte de la réponse, ou WP_Error.
     */
    private function call_openai_compatible_api( $api_key, $api_url, $system_prompt, $user_message, $widget_count = 1 ) {
        $max_tokens = $this->compute_max_tokens( $widget_count );

        $body = array(
            'model'       => AICF_Settings::get_model(),
            'max_tokens'  => $max_tokens,
            'temperature' => AICF_Settings::get_temperature(),
            'messages'    => array(
                array(
                    'role'    => 'system',
                    'content' => $system_prompt,
                ),
                array(
                    'role'    => 'user',
                    'content' => $user_message,
                ),
            ),
        );

        $response = wp_remote_post( $api_url, array(
            'timeout' => self::TIMEOUT,
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ),
            'body'    => wp_json_encode( $body ),
        ) );

        if ( is_wp_error( $response ) ) {
            return new WP_Error(
                'aicf_api_request_failed',
                __( 'Erreur de connexion à l\'API : ', 'ai-content-filler' ) . $response->get_error_message(),
                array( 'status' => 502 )
            );
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $body_raw    = wp_remote_retrieve_body( $response );

        if ( $status_code < 200 || $status_code >= 300 ) {
            $error_data = json_decode( $body_raw, true );
            $error_msg  = isset( $error_data['error']['message'] )
                ? $error_data['error']['message']
                : __( 'Erreur inconnue de l\'API.', 'ai-content-filler' );

            return new WP_Error(
                'aicf_api_error',
                sprintf( __( 'API (HTTP %d) : %s', 'ai-content-filler' ), $status_code, $error_msg ),
                array( 'status' => $status_code )
            );
        }

        $data = json_decode( $body_raw, true );

        if ( ! isset( $data['choices'][0]['message']['content'] ) ) {
            return new WP_Error(
                'aicf_api_empty_response',
                __( 'La réponse de l\'API est vide ou dans un format inattendu.', 'ai-content-filler' ),
                array( 'status' => 500 )
            );
        }

        if ( isset( $data['choices'][0]['finish_reason'] ) && 'length' === $data['choices'][0]['finish_reason'] ) {
            return new WP_Error(
                'aicf_response_truncated',
                __( 'La réponse a été tronquée (trop de contenu pour le nombre de tokens alloué). Essayez avec moins de widgets ou augmentez la limite de tokens dans les réglages.', 'ai-content-filler' ),
                array( 'status' => 500 )
            );
        }

        return $data['choices'][0]['message']['content'];
    }

    /**
     * Calcule le nombre de tokens à allouer pour la réponse.
     *
     * @param int $widget_count Nombre de widgets à remplir.
     * @return int
     */
    private function compute_max_tokens( $widget_count ) {
        $configured = AICF_Settings::get_max_tokens();
        $needed     = max( $configured, $widget_count * self::TOKENS_PER_WIDGET );
        return min( $needed, 8192 );
    }

    /**
     * Teste la connexion à l'API avec les identifiants fournis.
     * Méthode statique appelée depuis la page de réglages (AJAX).
     *
     * @param string $provider Fournisseur ('anthropic', 'openai', 'deepseek').
     * @param string $api_key  Clé API à tester.
     * @param string $model    Modèle à utiliser pour le test.
     * @return true|WP_Error
     */
    public static function test_connection( $provider, $api_key, $model ) {
        switch ( $provider ) {
            case 'openai':
                return self::test_openai_compatible( $api_key, $model, self::OPENAI_API_URL );
            case 'deepseek':
                return self::test_openai_compatible( $api_key, $model, self::DEEPSEEK_API_URL );
            case 'anthropic':
            default:
                return self::test_anthropic( $api_key, $model );
        }
    }

    /**
     * Test de connexion Anthropic : envoie un message minimal avec max_tokens=10.
     */
    private static function test_anthropic( $api_key, $model ) {
        $body = array(
            'model'      => $model ?: 'claude-haiku-4-20250414',
            'max_tokens' => 10,
            'messages'   => array(
                array( 'role' => 'user', 'content' => 'Hi' ),
            ),
        );

        $response = wp_remote_post( self::ANTHROPIC_API_URL, array(
            'timeout' => 15,
            'headers' => array(
                'Content-Type'      => 'application/json',
                'x-api-key'         => $api_key,
                'anthropic-version' => self::API_VERSION,
            ),
            'body'    => wp_json_encode( $body ),
        ) );

        return self::check_test_response( $response, 'Anthropic' );
    }

    /**
     * Test de connexion compatible OpenAI (OpenAI ou DeepSeek) : envoie un message minimal.
     */
    private static function test_openai_compatible( $api_key, $model, $api_url ) {
        $body = array(
            'model'      => $model ?: 'gpt-4o-mini',
            'max_tokens' => 10,
            'messages'   => array(
                array( 'role' => 'user', 'content' => 'Hi' ),
            ),
        );

        $response = wp_remote_post( $api_url, array(
            'timeout' => 15,
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ),
            'body'    => wp_json_encode( $body ),
        ) );

        return self::check_test_response( $response, 'API' );
    }

    /**
     * Vérifie la réponse HTTP d'un test de connexion.
     *
     * @param array|WP_Error $response    Réponse HTTP.
     * @param string         $api_label   Nom de l'API pour les messages d'erreur.
     * @return true|WP_Error
     */
    private static function check_test_response( $response, $api_label ) {
        if ( is_wp_error( $response ) ) {
            return new WP_Error(
                'aicf_test_failed',
                __( 'Impossible de joindre l\'API : ', 'ai-content-filler' ) . $response->get_error_message()
            );
        }

        $status_code = wp_remote_retrieve_response_code( $response );

        if ( $status_code >= 200 && $status_code < 300 ) {
            return true;
        }

        $body_raw   = wp_remote_retrieve_body( $response );
        $error_data = json_decode( $body_raw, true );
        $error_msg  = isset( $error_data['error']['message'] )
            ? $error_data['error']['message']
            : __( 'Clé API invalide ou non autorisée.', 'ai-content-filler' );

        return new WP_Error(
            'aicf_test_failed',
            sprintf( '%s (HTTP %d) : %s', $api_label, $status_code, $error_msg )
        );
    }

    /**
     * Parse la réponse texte de l'IA pour en extraire le JSON des widgets.
     * Plusieurs stratégies d'extraction pour gérer tous les formats possibles.
     *
     * @param string $raw_text  Texte brut retourné par l'IA.
     * @return array|WP_Error   Tableau [ { id, content } ] ou WP_Error.
     */
    private function parse_response( $raw_text ) {
        $text = trim( $raw_text );

        // Stratégie 1 : essayer le texte brut directement
        $decoded = json_decode( $text, true );
        if ( json_last_error() === JSON_ERROR_NONE && $this->is_valid_widget_response( $decoded ) ) {
            return $decoded['widgets'];
        }

        // Stratégie 2 : extraire le contenu d'un bloc markdown ```json ... ```
        if ( preg_match( '/```(?:json)?\s*(.+?)\s*```/s', $text, $matches ) ) {
            $decoded = json_decode( trim( $matches[1] ), true );
            if ( json_last_error() === JSON_ERROR_NONE && $this->is_valid_widget_response( $decoded ) ) {
                return $decoded['widgets'];
            }
        }

        // Stratégie 3 : trouver le premier { et le dernier } pour extraire le JSON
        $first_brace = strpos( $text, '{' );
        $last_brace  = strrpos( $text, '}' );

        if ( $first_brace !== false && $last_brace !== false && $last_brace > $first_brace ) {
            $json_candidate = substr( $text, $first_brace, $last_brace - $first_brace + 1 );
            $decoded = json_decode( $json_candidate, true );
            if ( json_last_error() === JSON_ERROR_NONE && $this->is_valid_widget_response( $decoded ) ) {
                return $decoded['widgets'];
            }
        }

        $preview = mb_substr( $text, 0, 200 );
        return new WP_Error(
            'aicf_invalid_json',
            sprintf(
                __( 'Impossible d\'extraire un JSON valide de la réponse de l\'IA. Début de la réponse : "%s"', 'ai-content-filler' ),
                $preview
            ),
            array( 'status' => 500 )
        );
    }

    /**
     * Vérifie qu'un tableau décodé a la structure attendue.
     * Accepte content comme string (ancien format) ou array/object (nouveau format multi-champs).
     *
     * @param mixed $decoded  Données décodées depuis JSON.
     * @return bool
     */
    private function is_valid_widget_response( $decoded ) {
        if ( ! is_array( $decoded ) || ! isset( $decoded['widgets'] ) || ! is_array( $decoded['widgets'] ) ) {
            return false;
        }

        if ( empty( $decoded['widgets'] ) ) {
            return false;
        }

        $first = $decoded['widgets'][0];
        if ( ! isset( $first['id'] ) || ! isset( $first['content'] ) ) {
            return false;
        }

        // Accepter content comme string (ancien) ou array (nouveau multi-champs)
        return is_string( $first['content'] ) || is_array( $first['content'] );
    }
}
