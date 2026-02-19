/**
 * AI Content Filler — Admin Settings JS
 *
 * Gère :
 *   1. Changement de fournisseur → mise à jour de la liste de modèles
 *   2. Afficher / masquer la clé API
 *   3. Bouton "Tester la connexion" (AJAX)
 *   4. Import de fichier de brief via la médiathèque WordPress
 */
( function ( $ ) {
    'use strict';

    var models      = aicfAdmin.models;
    var apiKeyHints = aicfAdmin.apiKeyHints;
    var i18n        = aicfAdmin.i18n;
    var mediaFrame;

    // =========================================================================
    // 1. Changement de fournisseur : mise à jour de la liste de modèles + hint
    // =========================================================================

    $( document ).on( 'change', '.aicf-provider-radio', function () {
        var provider = $( this ).val();

        // Mettre en surbrillance la card sélectionnée
        $( '.aicf-provider-card' ).removeClass( 'active' );
        $( this ).closest( '.aicf-provider-card' ).addClass( 'active' );

        // Mettre à jour la liste des modèles
        var select        = $( '#aicf_model' );
        var providerModels = models[ provider ] || {};
        select.empty();

        $.each( providerModels, function ( id, label ) {
            select.append( $( '<option>', { value: id, text: label } ) );
        } );

        // Mettre à jour le hint de la clé API
        var hint = apiKeyHints[ provider ] || '';
        $( '#aicf-api-key-hint' ).text( hint );
    } );

    // =========================================================================
    // 2. Afficher / masquer la clé API
    // =========================================================================

    $( document ).on( 'click', '.aicf-toggle-key', function () {
        var targetId = $( this ).data( 'target' );
        var input    = $( '#' + targetId );
        var icon     = $( this ).find( '.dashicons' );

        if ( 'password' === input.attr( 'type' ) ) {
            input.attr( 'type', 'text' );
            icon.removeClass( 'dashicons-visibility' ).addClass( 'dashicons-hidden' );
        } else {
            input.attr( 'type', 'password' );
            icon.removeClass( 'dashicons-hidden' ).addClass( 'dashicons-visibility' );
        }
    } );

    // =========================================================================
    // 3. Bouton "Tester la connexion"
    // =========================================================================

    $( document ).on( 'click', '#aicf-test-api-btn', function () {
        var btn      = $( this );
        var resultEl = $( '#aicf-test-result' );
        var provider = $( 'input[name="aicf_provider"]:checked' ).val() || 'anthropic';
        var apiKey   = $( '#aicf_api_key' ).val();
        var model    = $( '#aicf_model' ).val();

        btn.prop( 'disabled', true ).text( i18n.testing );
        resultEl.text( '' ).attr( 'class', 'aicf-test-result' );

        $.post( aicfAdmin.ajaxUrl, {
            action:   'aicf_test_api',
            nonce:    aicfAdmin.nonce,
            provider: provider,
            api_key:  apiKey,
            model:    model
        } )
        .done( function ( response ) {
            if ( response.success ) {
                resultEl.text( '✅ ' + response.data ).addClass( 'aicf-test-success' );
            } else {
                resultEl.text( '❌ ' + response.data ).addClass( 'aicf-test-error' );
            }
        } )
        .fail( function () {
            resultEl.text( '❌ ' + i18n.networkError ).addClass( 'aicf-test-error' );
        } )
        .always( function () {
            btn.prop( 'disabled', false ).text( i18n.testButton );
        } );
    } );

    // =========================================================================
    // 4. Import de fichier de brief via la médiathèque WordPress
    // =========================================================================

    $( document ).on( 'click', '#aicf-select-file-btn', function ( e ) {
        e.preventDefault();

        if ( mediaFrame ) {
            mediaFrame.open();
            return;
        }

        mediaFrame = wp.media( {
            title:    aicfAdmin.mediaTitle,
            button:   { text: aicfAdmin.mediaButton },
            multiple: false
        } );

        mediaFrame.on( 'select', function () {
            var attachment = mediaFrame.state().get( 'selection' ).first().toJSON();
            var filename   = attachment.filename || attachment.url.split( '/' ).pop();
            var ext        = filename.split( '.' ).pop().toUpperCase();

            $( '#aicf_brief_attachment_id' ).val( attachment.id );

            var preview = $( '#aicf-file-preview' );
            preview
                .html(
                    '<span class="aicf-file-icon">📄</span>' +
                    '<span class="aicf-file-name">' + escHtml( filename ) + '</span>' +
                    '<span class="aicf-file-type">' + escHtml( ext ) + '</span>' +
                    '<button type="button" class="aicf-remove-file" id="aicf-remove-file" title="Supprimer">✕</button>'
                )
                .show()
                .removeClass( 'aicf-file-empty' );
        } );

        mediaFrame.open();
    } );

    $( document ).on( 'click', '#aicf-remove-file', function () {
        $( '#aicf_brief_attachment_id' ).val( '0' );
        $( '#aicf-file-preview' ).empty().hide().addClass( 'aicf-file-empty' );
    } );

    // =========================================================================
    // Utilitaire : échapper le HTML pour éviter les injections dans le DOM
    // =========================================================================

    function escHtml( str ) {
        return String( str )
            .replace( /&/g, '&amp;' )
            .replace( /</g, '&lt;' )
            .replace( />/g, '&gt;' )
            .replace( /"/g, '&quot;' )
            .replace( /'/g, '&#039;' );
    }

} )( jQuery );
