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
    const TIMEOUT = 60;

    /** Tokens minimum par widget pour éviter la troncature */
    const TOKENS_PER_WIDGET = 300;

    /**
     * Génère le contenu pour une liste de widgets via l'API Claude.
     *
     * @param string $user_prompt  Le prompt saisi par l'utilisateur dans l'éditeur.
     * @param array  $widgets      Tableau de widgets [ { id, type, current_text } ].
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

        // Construction du system prompt à partir du brief client
        $system_prompt = $this->build_system_prompt();

        // Construction du user prompt avec la liste des widgets
        $user_message = $this->build_user_message( $user_prompt, $widgets, $page_id );

        $widget_count = count( $widgets );

        // Premier essai
        $result = $this->call_claude_api( $api_key, $system_prompt, $user_message, $widget_count );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        // Tentative de parsing du JSON retourné par Claude
        $parsed = $this->parse_response( $result );

        // Si le JSON est invalide, on retente une fois avec un prompt plus strict
        if ( is_wp_error( $parsed ) ) {
            $strict_message = $user_message . "\n\n"
                . "CRITICAL INSTRUCTION: Your previous response was not valid JSON. "
                . "You MUST respond with ONLY a raw JSON object. No markdown, no code blocks, no explanation. "
                . "Start your response with { and end with }. "
                . "Exact format: {\"widgets\": [{\"id\": \"WIDGET_ID\", \"content\": \"CONTENT\"}, ...]}";

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
        $system .= "- Pour les widgets de type 'heading' : rédige un titre court et percutant (5 à 8 mots maximum).\n";
        $system .= "- Pour les widgets de type 'text-editor' : rédige un ou plusieurs paragraphes en HTML valide (utilise les balises <p>, <strong>, <em> si pertinent).\n";
        $system .= "- Respecte exactement les IDs des widgets fournis dans ta réponse.\n";
        $system .= "- Réponds UNIQUEMENT avec un objet JSON valide, sans texte avant ni après.\n";
        $system .= "- Format de réponse obligatoire : {\"widgets\": [{\"id\": \"ID_DU_WIDGET\", \"content\": \"CONTENU_GENERE\"}, ...]}\n";
        $system .= "- N'invente pas de widgets supplémentaires, traite uniquement ceux fournis.\n";
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
            $type = ( $widget['type'] === 'heading' ) ? 'heading (titre court, 5-8 mots max)' : 'text-editor (paragraphe HTML)';

            $message .= $num . ". Widget ID: \"" . $widget['id'] . "\"\n";
            $message .= "   Type: " . $type . "\n";
            $message .= "   Contenu actuel: \"" . $widget['current_text'] . "\"\n\n";
        }

        $message .= "Génère le contenu pour chaque widget ci-dessus en respectant le format JSON demandé.";

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
        // Calculer les tokens nécessaires : au moins TOKENS_PER_WIDGET par widget,
        // avec un minimum égal au réglage utilisateur
        $configured_tokens = AICF_Settings::get_max_tokens();
        $needed_tokens     = max( $configured_tokens, $widget_count * self::TOKENS_PER_WIDGET );
        // Plafonner à 4096 pour éviter les abus
        $max_tokens = min( $needed_tokens, 4096 );

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

        // Erreur réseau / timeout
        if ( is_wp_error( $response ) ) {
            return new WP_Error(
                'aicf_api_request_failed',
                __( 'Erreur de connexion à l\'API Claude : ', 'ai-content-filler' ) . $response->get_error_message(),
                array( 'status' => 502 )
            );
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $body_raw    = wp_remote_retrieve_body( $response );

        // Erreur HTTP (clé invalide, quota dépassé, etc.)
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

        // Extraction du texte de la réponse Claude
        $data = json_decode( $body_raw, true );

        if ( ! isset( $data['content'][0]['text'] ) ) {
            return new WP_Error(
                'aicf_api_empty_response',
                __( 'La réponse de Claude est vide ou dans un format inattendu.', 'ai-content-filler' ),
                array( 'status' => 500 )
            );
        }

        // Vérifier si la réponse a été tronquée (stop_reason = max_tokens)
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

        // Stratégie 1 : essayer le texte brut directement (cas idéal)
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

        // Aucune stratégie n'a fonctionné
        // Fournir un extrait de la réponse dans l'erreur pour le debug
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
     * Vérifie qu'un tableau décodé a la structure attendue : { widgets: [ { id, content }, ... ] }
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

        // Vérifier qu'au moins le premier widget a un id et un content
        $first = $decoded['widgets'][0];
        return isset( $first['id'] ) && isset( $first['content'] );
    }
}
