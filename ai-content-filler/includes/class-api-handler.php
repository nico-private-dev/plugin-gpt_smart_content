<?php
/**
 * Communication avec l'API Anthropic Claude.
 * Construit les prompts, envoie la requête et parse la réponse JSON.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AICF_API_Handler {

    /** URL de l'API Messages d'Anthropic */
    const API_URL = 'https://api.anthropic.com/v1/messages';

    /** Version de l'API Anthropic */
    const API_VERSION = '2023-06-01';

    /** Timeout en secondes pour l'appel HTTP */
    const TIMEOUT = 90;

    /** Tokens minimum par widget pour éviter la troncature */
    const TOKENS_PER_WIDGET = 400;

    /**
     * Registre des types de widgets supportés côté serveur.
     * Chaque type est associé à une description concise pour le prompt.
     */
    private static $widget_types = array(
        'heading'            => 'titre court et percutant, 5-8 mots max',
        'text-editor'        => 'un ou plusieurs paragraphes en HTML valide (<p>, <strong>, <em>)',
        'button'             => 'texte de bouton, appel à l\'action court (2-5 mots)',
        'icon-box'           => 'titre court + description en HTML',
        'image-box'          => 'titre court + description en HTML',
        'testimonial'        => 'témoignage client réaliste avec nom et poste',
        'counter'            => 'intitulé court de compteur',
        'progress'           => 'intitulé de compétence ou progression',
        'alert'              => 'titre d\'alerte et description concise',
        'star-rating'        => 'intitulé court pour la note',
        'call-to-action'     => 'titre accrocheur, description courte et texte de bouton',
        'animated-headline'  => 'texte avant et texte surligné percutants',
        'flip-box'           => 'titres et descriptions pour les deux faces',
        'price-table'        => 'titre de plan, sous-titre, période et texte de bouton',
        'blockquote'         => 'citation percutante et pertinente',
    );

    /**
     * Génère le contenu pour une liste de widgets via l'API Claude.
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
        $result = $this->call_claude_api( $api_key, $system_prompt, $user_message, $widget_count );

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

            $result = $this->call_claude_api( $api_key, $system_prompt, $strict_message, $widget_count );

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
     * Construit le system prompt à partir du brief client enregistré dans les réglages.
     */
    private function build_system_prompt() {
        $brief = AICF_Settings::get_client_brief();

        $system = "Tu es un rédacteur web professionnel. Tu rédiges du contenu pour des sites web créés avec WordPress et Elementor.\n\n";

        if ( ! empty( $brief ) ) {
            $system .= "BRIEF CLIENT :\n" . $brief . "\n\n";
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
        $system .= "- Respecte exactement les IDs des widgets et les clés des champs fournis dans ta réponse.\n";
        $system .= "- Réponds UNIQUEMENT avec un objet JSON valide, sans texte avant ni après.\n";
        $system .= "- Format de réponse obligatoire : {\"widgets\": [{\"id\": \"ID_DU_WIDGET\", \"content\": {\"clé_du_champ\": \"CONTENU_GENERE\", ...}}, ...]}\n";
        $system .= "- N'invente pas de widgets ni de champs supplémentaires, traite uniquement ceux fournis.\n";
        $system .= "- Rédige en français sauf si le prompt de l'utilisateur indique une autre langue.\n";

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
     * Effectue l'appel HTTP vers l'API Claude.
     *
     * @param string $api_key        Clé API Anthropic.
     * @param string $system_prompt  System prompt.
     * @param string $user_message   Message utilisateur.
     * @param int    $widget_count   Nombre de widgets (pour calculer les tokens nécessaires).
     * @return string|WP_Error       Texte de la réponse de Claude, ou WP_Error.
     */
    private function call_claude_api( $api_key, $system_prompt, $user_message, $widget_count = 1 ) {
        $configured_tokens = AICF_Settings::get_max_tokens();
        $needed_tokens     = max( $configured_tokens, $widget_count * self::TOKENS_PER_WIDGET );
        // Plafonner à 8192 pour éviter les abus tout en supportant les pages complexes
        $max_tokens = min( $needed_tokens, 8192 );

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

        $response = wp_remote_post( self::API_URL, array(
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
                __( 'Erreur de connexion à l\'API Claude : ', 'ai-content-filler' ) . $response->get_error_message(),
                array( 'status' => 502 )
            );
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $body_raw    = wp_remote_retrieve_body( $response );

        if ( $status_code < 200 || $status_code >= 300 ) {
            $error_data = json_decode( $body_raw, true );
            $error_msg  = isset( $error_data['error']['message'] )
                ? $error_data['error']['message']
                : __( 'Erreur inconnue de l\'API Claude.', 'ai-content-filler' );

            return new WP_Error(
                'aicf_api_error',
                sprintf( __( 'API Claude (HTTP %d) : %s', 'ai-content-filler' ), $status_code, $error_msg ),
                array( 'status' => $status_code )
            );
        }

        $data = json_decode( $body_raw, true );

        if ( ! isset( $data['content'][0]['text'] ) ) {
            return new WP_Error(
                'aicf_api_empty_response',
                __( 'La réponse de Claude est vide ou dans un format inattendu.', 'ai-content-filler' ),
                array( 'status' => 500 )
            );
        }

        $stop_reason = isset( $data['stop_reason'] ) ? $data['stop_reason'] : '';
        if ( $stop_reason === 'max_tokens' ) {
            return new WP_Error(
                'aicf_response_truncated',
                __( 'La réponse de Claude a été tronquée (trop de contenu pour le nombre de tokens alloué). Essayez avec moins de widgets ou augmentez la limite de tokens dans les réglages.', 'ai-content-filler' ),
                array( 'status' => 500 )
            );
        }

        return $data['content'][0]['text'];
    }

    /**
     * Parse la réponse texte de Claude pour en extraire le JSON des widgets.
     * Plusieurs stratégies d'extraction pour gérer tous les formats possibles.
     *
     * @param string $raw_text  Texte brut retourné par Claude.
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
                __( 'Impossible d\'extraire un JSON valide de la réponse de Claude. Début de la réponse : "%s"', 'ai-content-filler' ),
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
