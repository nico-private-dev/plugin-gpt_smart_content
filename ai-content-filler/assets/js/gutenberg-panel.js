/**
 * TextFlow AI — Sidebar Gutenberg
 * Génère le contenu des blocs natifs WP et Kadence via l'API IA.
 */
( function () {
    'use strict';

    var el          = wp.element.createElement;
    var useState    = wp.element.useState;
    var useEffect   = wp.element.useEffect;
    var useRef      = wp.element.useRef;
    var Fragment    = wp.element.Fragment;
    var config      = aicfGutenbergConfig;
    var i18n        = config.i18n;

    // ---------------------------------------------------------------
    // Registre des blocs supportés
    // ---------------------------------------------------------------

    var BLOCK_REGISTRY = {
        // --- Blocs natifs WordPress ---
        'core/heading': {
            label: 'Titre',
            cssClass: 'aicf-wl-type-heading',
            fields: { content: 'Titre' },
        },
        'core/paragraph': {
            label: 'Paragraphe',
            cssClass: 'aicf-wl-type-text',
            fields: { content: 'Contenu' },
        },
        'core/button': {
            label: 'Bouton',
            cssClass: 'aicf-wl-type-button',
            fields: { text: 'Texte du bouton' },
        },
        'core/image': {
            label: 'Image',
            cssClass: 'aicf-wl-type-default',
            fields: { alt: 'Texte alternatif', caption: 'Légende' },
        },
        'core/quote': {
            label: 'Citation',
            cssClass: 'aicf-wl-type-box',
            fields: { citation: 'Auteur' },
        },
        // --- Kadence Blocks ---
        'kadence/advancedheading': {
            label: 'Titre Kadence',
            cssClass: 'aicf-wl-type-heading',
            fields: { content: 'Titre' },
        },
        'kadence/image': {
            label: 'Image Kadence',
            cssClass: 'aicf-wl-type-default',
            fields: { alt: 'Texte alternatif', caption: 'Légende' },
        },
        'kadence/infobox': {
            label: 'Info Box',
            cssClass: 'aicf-wl-type-box',
            fields: { title: 'Titre', contentText: 'Texte', linkText: 'Bouton' },
        },
        'kadence/singlebtn': {
            label: 'Bouton Kadence',
            cssClass: 'aicf-wl-type-button',
            fields: { text: 'Texte du bouton' },
        },
        // kadence/testimonial (singulier) = innerBlock dans kadence/testimonials (v3+)
        'kadence/testimonial': {
            label: 'Témoignage',
            cssClass: 'aicf-wl-type-testimonial',
            fields: { title: 'Titre', content: 'Témoignage', name: 'Nom', occupation: 'Poste' },
        },
        'kadence/pane': {
            label: 'Accordéon',
            cssClass: 'aicf-wl-type-box',
            fields: { title: 'Titre' },
        },
        'kadence/tab': {
            label: 'Onglet',
            cssClass: 'aicf-wl-type-box',
            fields: { title: 'Titre onglet' },
        },
    };

    // Templates de prompt prédéfinis
    var PROMPT_TEMPLATES = {
        landing: {
            label: 'Landing',
            prompt: "Page d'atterrissage pour [votre offre]. Objectif : convertir les visiteurs en clients. Messages clairs, arguments percutants, appels à l'action forts.",
        },
        service: {
            label: 'Service',
            prompt: "Page de service présentant [vos services]. Mettez en avant les avantages, le processus et les résultats concrets pour le client.",
        },
        about: {
            label: 'À propos',
            prompt: "Page à propos de l'entreprise. Présentez l'histoire, les valeurs, la mission et l'équipe. Ton authentique et engageant.",
        },
        home: {
            label: 'Accueil',
            prompt: "Page d'accueil du site. Présentez l'activité, les services phares et les avantages concurrentiels. Donnez envie d'explorer le site.",
        },
        blog: {
            label: 'Blog',
            prompt: "Article de blog sur [sujet]. Contenu informatif, structuré avec des sous-titres, engageant et optimisé SEO.",
        },
    };

    // ---------------------------------------------------------------
    // Fonctions utilitaires
    // ---------------------------------------------------------------

    /**
     * Lit la valeur d'un attribut de bloc, en gérant les cas nuls/undefined.
     */
    function getAttr( attributes, key ) {
        var val = attributes[ key ];
        if ( val === undefined || val === null ) return '';
        if ( typeof val === 'string' ) return val;
        return String( val );
    }

    /**
     * Récupère la première valeur non vide d'un objet de champs (pour l'aperçu).
     */
    function getPreview( fields ) {
        var keys = Object.keys( fields );
        for ( var i = 0; i < keys.length; i++ ) {
            var val = fields[ keys[ i ] ];
            if ( val && val.trim() ) {
                return val.replace( /<[^>]+>/g, '' ).substring( 0, 50 );
            }
        }
        return '';
    }

    /**
     * Scan récursif de tous les blocs pour trouver les blocs texte supportés.
     */
    function scanBlocks( blocks ) {
        var result = [];

        blocks.forEach( function ( block ) {
            if ( ! block.name ) return;

            var blockDef = BLOCK_REGISTRY[ block.name ];

            if ( blockDef ) {
                var fields = {};

                if ( blockDef.fields ) {
                    Object.keys( blockDef.fields ).forEach( function ( fieldKey ) {
                        fields[ fieldKey ] = getAttr( block.attributes, fieldKey );
                    } );
                }

                if ( blockDef.repeater && blockDef.repeaterFields ) {
                    var repKey = blockDef.repeater;
                    var items  = block.attributes[ repKey ] || [];
                    items.forEach( function ( item, index ) {
                        Object.keys( blockDef.repeaterFields ).forEach( function ( fieldKey ) {
                            var dotKey       = repKey + '.' + index + '.' + fieldKey;
                            fields[ dotKey ] = item[ fieldKey ] || '';
                        } );
                    } );
                }

                if ( Object.keys( fields ).length > 0 ) {
                    var entry = {
                        id:       block.clientId,
                        type:     block.name,
                        label:    blockDef.label,
                        cssClass: blockDef.cssClass || 'aicf-wl-type-default',
                        fields:   fields,
                        preview:  getPreview( fields ),
                    };

                    if ( block.name === 'kadence/advancedheading' ) {
                        var htmlTag = ( block.attributes && block.attributes.htmlTag ) || 'h2';
                        entry.apiType = 'kadence/advancedheading-' + htmlTag;
                        if ( htmlTag === 'p' || htmlTag === 'span' || htmlTag === 'div' ) {
                            entry.label    = 'Texte Kadence';
                            entry.cssClass = 'aicf-wl-type-text';
                        }
                    }

                    result.push( entry );
                }
            }

            if ( block.innerBlocks && block.innerBlocks.length > 0 ) {
                result = result.concat( scanBlocks( block.innerBlocks ) );
            }
        } );

        return result;
    }

    /**
     * Nettoie la valeur d'un champ selon le type de bloc.
     */
    function sanitizeForBlock( blockType, fieldKey, value ) {
        if ( typeof value !== 'string' ) return value;

        if ( blockType === 'core/paragraph' && fieldKey === 'content' ) {
            return value.replace( /^<p[^>]*>([\s\S]*?)<\/p>\s*$/i, '$1' ).trim();
        }

        if ( blockType === 'core/heading' && fieldKey === 'content' ) {
            return value.replace( /^<h[1-6][^>]*>([\s\S]*?)<\/h[1-6]>\s*$/i, '$1' ).trim();
        }

        if ( blockType.indexOf( 'kadence/advancedheading' ) === 0 && fieldKey === 'content' ) {
            value = value.replace( /^<p[^>]*>([\s\S]*?)<\/p>\s*$/i, '$1' ).trim();
            value = value.replace( /^<h[1-6][^>]*>([\s\S]*?)<\/h[1-6]>\s*$/i, '$1' ).trim();
            return value;
        }

        if ( ( blockType === 'core/button' || blockType === 'kadence/singlebtn' ) && fieldKey === 'text' ) {
            return value.replace( /<[^>]+>/g, '' ).trim();
        }

        if ( blockType === 'kadence/testimonial' && fieldKey === 'content' ) {
            return value.replace( /^<p[^>]*>([\s\S]*?)<\/p>\s*$/i, '$1' ).trim();
        }

        if ( blockType === 'kadence/testimonial' && ( fieldKey === 'title' || fieldKey === 'name' || fieldKey === 'occupation' ) ) {
            return value.replace( /<[^>]+>/g, '' ).trim();
        }

        if ( blockType === 'kadence/image' && fieldKey === 'alt' ) {
            return value.replace( /<[^>]+>/g, '' ).trim();
        }

        return value;
    }

    /**
     * Applique le contenu généré aux blocs Gutenberg.
     * Retourne le nombre de blocs mis à jour avec succès.
     *
     * Utilise createBlock + replaceBlocks (plus fiable que cloneBlock + replaceBlock,
     * notamment pour les blocs dont le contenu était vide avant génération).
     */
    function applyContent( widgets ) {
        var dispatch     = wp.data.dispatch( 'core/block-editor' );
        var select       = wp.data.select( 'core/block-editor' );
        var appliedCount = 0;

        widgets.forEach( function ( widget ) {
            try {
                var content = widget.content;
                var block   = select.getBlock( widget.id );

                if ( ! block ) {
                    console.warn( '[TextFlow] Bloc introuvable dans l\'éditeur :', widget.id );
                    return;
                }

                var blockType = block.name;

                if ( typeof content === 'string' ) {
                    content = { content: content };
                }

                if ( ! content || typeof content !== 'object' ) {
                    console.warn( '[TextFlow] Contenu invalide pour le bloc :', widget.id, content );
                    return;
                }

                var directAttrs     = {};
                var repeaterUpdates = {};

                Object.keys( content ).forEach( function ( key ) {
                    if ( key.indexOf( '.' ) === -1 ) {
                        directAttrs[ key ] = sanitizeForBlock( blockType, key, content[ key ] );
                    } else {
                        var parts  = key.split( '.' );
                        var repKey = parts[ 0 ];
                        var index  = parseInt( parts[ 1 ], 10 );
                        var field  = parts[ 2 ];

                        if ( ! repeaterUpdates[ repKey ] ) {
                            repeaterUpdates[ repKey ] = {};
                        }
                        if ( ! repeaterUpdates[ repKey ][ index ] ) {
                            repeaterUpdates[ repKey ][ index ] = {};
                        }
                        repeaterUpdates[ repKey ][ index ][ field ] = content[ key ];
                    }
                } );

                // createBlock + replaceBlocks : force un remontage complet du composant,
                // ce qui règle les cas où le bloc était vide (RichText ne se met pas à
                // jour via updateBlockAttributes si son état interne est « vide »).
                if ( Object.keys( directAttrs ).length > 0 ) {
                    var mergedAttrs = Object.assign( {}, block.attributes, directAttrs );
                    if ( wp.blocks && wp.blocks.createBlock ) {
                        var newBlock = wp.blocks.createBlock( block.name, mergedAttrs, block.innerBlocks || [] );
                        dispatch.replaceBlocks( [ widget.id ], [ newBlock ] );
                    } else {
                        dispatch.updateBlockAttributes( widget.id, directAttrs );
                    }
                    appliedCount++;
                }

                // Repeater (ex. items d'un bloc avec tableau d'objets).
                // Utilise le snapshot « block » pris avant le replaceBlocks.
                if ( Object.keys( repeaterUpdates ).length > 0 ) {
                    Object.keys( repeaterUpdates ).forEach( function ( repKey ) {
                        var currentItems = JSON.parse(
                            JSON.stringify( block.attributes[ repKey ] || [] )
                        );
                        var updates = repeaterUpdates[ repKey ];

                        Object.keys( updates ).forEach( function ( idx ) {
                            var i = parseInt( idx, 10 );
                            if ( ! currentItems[ i ] ) {
                                currentItems[ i ] = {};
                            }
                            Object.assign( currentItems[ i ], updates[ idx ] );
                        } );

                        var attrPatch       = {};
                        attrPatch[ repKey ] = currentItems;

                        // Cherche le bloc courant (son clientId peut avoir changé après
                        // le replaceBlocks ci-dessus si directAttrs était non vide).
                        var targetBlock = select.getBlock( widget.id ) || block;
                        var mergedRep   = Object.assign( {}, targetBlock.attributes, attrPatch );

                        if ( wp.blocks && wp.blocks.createBlock ) {
                            var repBlock = wp.blocks.createBlock( targetBlock.name, mergedRep, targetBlock.innerBlocks || [] );
                            dispatch.replaceBlocks( [ targetBlock.clientId ], [ repBlock ] );
                        } else {
                            dispatch.updateBlockAttributes( targetBlock.clientId, attrPatch );
                        }
                        appliedCount++;
                    } );
                }
            } catch ( e ) {
                console.error( '[TextFlow] Erreur lors de l\'application du contenu pour le bloc', widget.id, e );
            }
        } );

        return appliedCount;
    }

    // ---------------------------------------------------------------
    // Composant principal de la sidebar
    // ---------------------------------------------------------------

    function AICFPanel() {
        var _step     = useState( 'prompt' );
        var step      = _step[ 0 ];
        var setStep   = _step[ 1 ];

        var _prompt   = useState( '' );
        var prompt    = _prompt[ 0 ];
        var setPrompt = _prompt[ 1 ];

        var _template   = useState( '' );
        var activeTemplate = _template[ 0 ];
        var setActiveTemplate = _template[ 1 ];

        var _scanned   = useState( [] );
        var scanned    = _scanned[ 0 ];
        var setScanned = _scanned[ 1 ];

        var _selected   = useState( [] );
        var selected    = _selected[ 0 ];
        var setSelected = _selected[ 1 ];

        var _status   = useState( { type: 'idle', message: i18n.idle } );
        var status    = _status[ 0 ];
        var setStatus = _status[ 1 ];

        var _generating   = useState( false );
        var isGenerating  = _generating[ 0 ];
        var setGenerating = _generating[ 1 ];

        var _progress   = useState( 0 );
        var progress    = _progress[ 0 ];
        var setProgress = _progress[ 1 ];

        var _eta   = useState( 0 );
        var eta    = _eta[ 0 ];
        var setEta = _eta[ 1 ];

        // IDs des blocs dont le contenu a été appliqué (état "done")
        var _blocksApplied        = useState( [] );
        var blocksApplied         = _blocksApplied[ 0 ];
        var setBlocksApplied      = _blocksApplied[ 1 ];

        // IDs des blocs qui ont un historique (bouton revert visible)
        var _blocksWithHistory    = useState( [] );
        var blocksWithHistory     = _blocksWithHistory[ 0 ];
        var setBlocksWithHistory  = _blocksWithHistory[ 1 ];

        // ID du bloc en cours de régénération individuelle
        var _regenBlockId   = useState( null );
        var regenBlockId    = _regenBlockId[ 0 ];
        var setRegenBlockId = _regenBlockId[ 1 ];

        // Historique du contenu (ref → pas de re-render quand on sauvegarde)
        var historyRef = useRef( {} );

        // Refs pour le timer de progression
        var timerRef = useRef( null );
        var startRef = useRef( 0 );
        var durRef   = useRef( 5000 );

        // Timer de progression
        useEffect( function () {
            if ( ! isGenerating ) {
                if ( timerRef.current ) {
                    clearInterval( timerRef.current );
                    timerRef.current = null;
                }
                return;
            }
            startRef.current = Date.now();
            timerRef.current = setInterval( function () {
                var elapsed   = Date.now() - startRef.current;
                var pct       = Math.round( Math.min( 88, ( elapsed / durRef.current ) * 88 ) );
                var remaining = Math.max( 0, Math.round( ( durRef.current - elapsed ) / 1000 ) );
                setProgress( pct );
                setEta( remaining );
            }, 250 );
            return function () {
                clearInterval( timerRef.current );
                timerRef.current = null;
            };
        }, [ isGenerating ] );

        // ---- Historique ----

        function saveBlockToHistory( block ) {
            historyRef.current[ block.id ] = JSON.parse( JSON.stringify( block.fields ) );
        }

        function saveAllToHistory( blocks ) {
            blocks.forEach( function ( b ) { saveBlockToHistory( b ); } );
            setBlocksWithHistory( Object.keys( historyRef.current ) );
        }

        // ---- Actions templates ----

        function onTemplateClick( key ) {
            if ( activeTemplate === key ) {
                setActiveTemplate( '' );
                setPrompt( '' );
            } else {
                setActiveTemplate( key );
                setPrompt( PROMPT_TEMPLATES[ key ] ? PROMPT_TEMPLATES[ key ].prompt : '' );
            }
        }

        // ---- Scanner ----

        function doScan() {
            var allBlocks = wp.data.select( 'core/block-editor' ).getBlocks();
            return scanBlocks( allBlocks );
        }

        function onScan() {
            if ( ! prompt.trim() ) {
                setStatus( { type: 'error', message: i18n.empty_prompt } );
                return;
            }

            var found = doScan();

            if ( found.length === 0 ) {
                setStatus( { type: 'error', message: i18n.no_blocks } );
                return;
            }

            setScanned( found );
            setSelected( found.map( function ( b ) { return b.id; } ) );
            setStatus( { type: 'idle', message: found.length + ' ' + i18n.blocks_found } );
            setStep( 'select' );
        }

        // Re-scanner sans revenir au prompt (mise à jour de la liste)
        function onRescan() {
            var found = doScan();

            if ( found.length === 0 ) {
                setStatus( { type: 'error', message: i18n.no_blocks } );
                return;
            }

            setScanned( found );
            setSelected( found.map( function ( b ) { return b.id; } ) );
            setStatus( { type: 'idle', message: found.length + ' ' + i18n.blocks_found } );
        }

        // ---- Actions liste ----

        function onToggleAll() {
            if ( selected.length === scanned.length ) {
                setSelected( [] );
            } else {
                setSelected( scanned.map( function ( b ) { return b.id; } ) );
            }
        }

        function onToggleBlock( id ) {
            var idx = selected.indexOf( id );
            if ( idx === -1 ) {
                setSelected( selected.concat( [ id ] ) );
            } else {
                setSelected( selected.filter( function ( s ) { return s !== id; } ) );
            }
        }

        // Retirer un bloc de la liste (sans revenir au prompt)
        function onRemoveBlock( id ) {
            var newScanned  = scanned.filter( function ( b ) { return b.id !== id; } );
            var newSelected = selected.filter( function ( s ) { return s !== id; } );
            setScanned( newScanned );
            setSelected( newSelected );

            if ( newScanned.length === 0 ) {
                onBack();
                setStatus( { type: 'error', message: i18n.no_blocks } );
            }
        }

        // Restaurer le contenu précédent d'un bloc
        function onRevertBlock( id ) {
            var previousFields = historyRef.current[ id ];
            if ( ! previousFields ) return;

            applyContent( [ { id: id, content: previousFields } ] );

            delete historyRef.current[ id ];
            setBlocksWithHistory( Object.keys( historyRef.current ) );
            setBlocksApplied( blocksApplied.filter( function ( bid ) { return bid !== id; } ) );
            setStatus( { type: 'success', message: 'Contenu précédent restauré.' } );
        }

        // Régénérer un seul bloc
        function onRegenerateBlock( id ) {
            if ( isGenerating || regenBlockId ) return;

            var block = null;
            for ( var i = 0; i < scanned.length; i++ ) {
                if ( scanned[ i ].id === id ) { block = scanned[ i ]; break; }
            }
            if ( ! block || ! prompt.trim() ) return;

            saveBlockToHistory( block );
            setBlocksWithHistory( Object.keys( historyRef.current ) );
            setRegenBlockId( id );
            setStatus( { type: 'loading', message: 'Régénération du bloc...' } );

            var pageId = wp.data.select( 'core/editor' )
                ? wp.data.select( 'core/editor' ).getCurrentPostId()
                : 0;

            fetch( config.restUrl, {
                method:  'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce':   config.nonce,
                },
                body: JSON.stringify( {
                    page_id:     pageId,
                    user_prompt: prompt,
                    widgets:     [ { id: block.id, type: block.apiType || block.type, fields: block.fields } ],
                } ),
            } )
            .then( function ( res ) {
                return res.json().then( function ( data ) {
                    return { ok: res.ok, data: data };
                } );
            } )
            .then( function ( res ) {
                if ( ! res.ok ) {
                    var msg = ( res.data && res.data.message ) ? res.data.message : i18n.error;
                    throw new Error( msg );
                }
                var data = res.data;
                if ( data.widgets && Array.isArray( data.widgets ) ) {
                    applyContent( data.widgets );
                }
                setBlocksApplied( function ( prev ) {
                    return prev.indexOf( id ) === -1 ? prev.concat( [ id ] ) : prev;
                } );
                setStatus( { type: 'success', message: 'Bloc régénéré ! — ' + i18n.save_reminder } );
            } )
            .catch( function ( err ) {
                setStatus( { type: 'error', message: err.message || i18n.error } );
            } )
            .finally( function () {
                setRegenBlockId( null );
            } );
        }

        // ---- Génération ----

        function onGenerate() {
            if ( isGenerating ) return;
            if ( selected.length === 0 ) return;

            var blocksToSend = scanned.filter( function ( b ) {
                return selected.indexOf( b.id ) !== -1;
            } );

            if ( blocksToSend.length === 0 ) return;

            var pageId = wp.data.select( 'core/editor' )
                ? wp.data.select( 'core/editor' ).getCurrentPostId()
                : 0;

            // Sauvegarder l'état actuel avant génération
            saveAllToHistory( blocksToSend );

            durRef.current = Math.min( 60000, Math.max( 5000, 3000 + blocksToSend.length * 2500 ) );
            setProgress( 0 );
            setEta( Math.round( durRef.current / 1000 ) );
            setGenerating( true );
            setStep( 'generating' );
            setStatus( { type: 'loading', message: i18n.loading + ' (' + blocksToSend.length + ' bloc' + ( blocksToSend.length > 1 ? 's' : '' ) + ')' } );

            var payload = {
                page_id:     pageId,
                user_prompt: prompt,
                widgets:     blocksToSend.map( function ( b ) {
                    return { id: b.id, type: b.apiType || b.type, fields: b.fields };
                } ),
            };

            fetch( config.restUrl, {
                method:  'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce':   config.nonce,
                },
                body: JSON.stringify( payload ),
            } )
            .then( function ( res ) {
                return res.json().then( function ( data ) {
                    return { ok: res.ok, status: res.status, data: data };
                } );
            } )
            .then( function ( res ) {
                if ( ! res.ok ) {
                    var msg = ( res.data && res.data.message ) ? res.data.message : i18n.error;
                    throw new Error( msg );
                }

                var data = res.data;
                var appliedCount = 0;
                if ( data.widgets && Array.isArray( data.widgets ) ) {
                    appliedCount = applyContent( data.widgets );

                    // Marquer les blocs comme appliqués
                    var appliedIds = data.widgets.map( function ( w ) { return w.id; } );
                    setBlocksApplied( function ( prev ) {
                        var combined = prev.concat( appliedIds );
                        return combined.filter( function ( v, i ) { return combined.indexOf( v ) === i; } );
                    } );
                }

                setProgress( 100 );
                var successMsg = i18n.success + ' (' + appliedCount + '/' + blocksToSend.length + ' bloc' + ( blocksToSend.length > 1 ? 's' : '' ) + ') — ' + i18n.save_reminder;
                setTimeout( function () {
                    setStatus( { type: 'success', message: successMsg } );
                    setStep( 'select' );
                    setGenerating( false );
                }, 350 );
            } )
            .catch( function ( err ) {
                setStatus( { type: 'error', message: err.message || i18n.error } );
                setStep( 'select' );
                setGenerating( false );
            } );
        }

        // Retour à l'étape prompt
        function onBack() {
            setStep( 'prompt' );
            setStatus( { type: 'idle', message: i18n.idle } );
            setBlocksApplied( [] );
            setBlocksWithHistory( [] );
            historyRef.current = {};
        }

        // ---------------------------------------------------------------
        // Rendu
        // ---------------------------------------------------------------

        // Boutons de templates
        var templateBtns = Object.keys( PROMPT_TEMPLATES ).map( function ( key ) {
            var tpl    = PROMPT_TEMPLATES[ key ];
            var isActive = activeTemplate === key;
            var cls    = 'aicf-tpl-btn' + ( isActive ? ' aicf-tpl-active' : '' );

            return el( 'button', {
                key:       key,
                className: cls,
                onClick:   function () { onTemplateClick( key ); },
            }, tpl.label );
        } );

        // Zone templates
        var templatesRow = el( 'div', { className: 'aicf-gb-templates' },
            el( 'span', { className: 'aicf-gb-templates-label' }, i18n.templates_label ),
            el( 'div', { className: 'aicf-gb-templates-btns' }, templateBtns )
        );

        // Prompt
        var promptArea = el( 'textarea', {
            className:   'aicf-gb-prompt',
            value:       prompt,
            placeholder: i18n.prompt_placeholder,
            onChange:    function ( e ) { setPrompt( e.target.value ); },
            disabled:    isGenerating,
            rows:        4,
        } );

        // Statut / progress bar
        var statusBar;
        if ( status.type === 'loading' ) {
            var etaText = eta > 0 ? '~' + eta + 's' : '\u2026';
            statusBar = el( 'div', { className: 'aicf-gb-status aicf-status-loading' },
                el( 'span', { className: 'aicf-progress-text' },
                    status.message + ' \u2014 ',
                    el( 'span', { className: 'aicf-progress-eta' }, etaText )
                ),
                el( 'div', { className: 'aicf-progress-bar-track' },
                    el( 'div', { className: 'aicf-progress-bar-fill', style: { width: progress + '%' } } )
                )
            );
        } else {
            statusBar = status.message
                ? el( 'div', { className: 'aicf-gb-status aicf-status-' + status.type }, status.message )
                : null;
        }

        // ---- Étape : prompt ----
        if ( step === 'prompt' ) {
            return el( 'div', { className: 'aicf-gb-panel' },
                templatesRow,
                promptArea,
                el( 'button', {
                    className: 'aicf-gb-btn aicf-gb-btn-primary',
                    onClick:   onScan,
                }, i18n.scan_btn ),
                statusBar
            );
        }

        // ---- Étape : select / generating ----
        var selectedCount = selected.length;
        var totalCount    = scanned.length;

        // Liste des blocs
        var blockItems = scanned.map( function ( block ) {
            var isChecked  = selected.indexOf( block.id ) !== -1;
            var isDone     = blocksApplied.indexOf( block.id ) !== -1;
            var hasHistory = blocksWithHistory.indexOf( block.id ) !== -1;
            var isRegen    = regenBlockId === block.id;

            var itemCls = 'aicf-wl-item' + ( isDone ? ' aicf-wl-item-done' : '' );

            // Badge compteur de champs (si > 1 champ direct)
            var directFieldCount = Object.keys( block.fields ).filter( function ( k ) {
                return k.indexOf( '.' ) === -1;
            } ).length;
            var fieldBadge = null;
            if ( directFieldCount > 1 ) {
                fieldBadge = el( 'span', { className: 'aicf-wl-field-count' },
                    directFieldCount + ' champs'
                );
            }

            // Boutons d'action par bloc
            var actionBtns = [];

            // Bouton régénérer
            actionBtns.push( el( 'button', {
                key:       'regen-' + block.id,
                className: 'aicf-widget-regen' + ( isRegen ? ' aicf-spinning' : '' ),
                title:     'Régénérer ce bloc',
                disabled:  isGenerating || !! regenBlockId,
                onClick:   function ( e ) { e.stopPropagation(); onRegenerateBlock( block.id ); },
            }, '\u21bb' ) );

            // Bouton revert (visible uniquement si historique disponible)
            if ( hasHistory ) {
                actionBtns.push( el( 'button', {
                    key:       'revert-' + block.id,
                    className: 'aicf-widget-revert',
                    title:     'Restaurer le contenu précédent',
                    disabled:  isGenerating || !! regenBlockId,
                    onClick:   function ( e ) { e.stopPropagation(); onRevertBlock( block.id ); },
                }, '\u21a9' ) );
            }

            // Bouton retirer
            actionBtns.push( el( 'button', {
                key:       'remove-' + block.id,
                className: 'aicf-widget-remove',
                title:     'Exclure ce bloc',
                disabled:  isGenerating || !! regenBlockId,
                onClick:   function ( e ) { e.stopPropagation(); onRemoveBlock( block.id ); },
            }, '\u00d7' ) );

            return el( 'div', { key: block.id, className: itemCls },
                el( 'label', { className: 'aicf-wl-checkbox-label' },
                    el( 'input', {
                        type:     'checkbox',
                        checked:  isChecked,
                        disabled: isGenerating || !! regenBlockId,
                        onChange: function () { onToggleBlock( block.id ); },
                    } ),
                    el( 'span', { className: 'aicf-wl-checkmark' } )
                ),
                el( 'div', { className: 'aicf-wl-info' },
                    el( 'span', { className: 'aicf-wl-type ' + block.cssClass },
                        block.label,
                        fieldBadge
                    ),
                    el( 'span', { className: 'aicf-wl-preview' }, block.preview )
                ),
                el( 'div', { className: 'aicf-wl-actions' }, actionBtns )
            );
        } );

        var blockList = el( 'div', { className: 'aicf-gb-block-list' },
            el( 'div', { className: 'aicf-wl-header' },
                el( 'span', { className: 'aicf-wl-count' },
                    selectedCount + ' / ' + totalCount + ' ' + i18n.selected
                ),
                el( 'button', {
                    className: 'aicf-wl-toggle-all',
                    onClick:   onToggleAll,
                    disabled:  isGenerating || !! regenBlockId,
                }, selectedCount === totalCount ? i18n.deselect_all : i18n.select_all )
            ),
            el( 'div', { className: 'aicf-wl-items' }, blockItems )
        );

        var generateLabel = i18n.generate_btn + ( selectedCount > 0 ? ' (' + selectedCount + ')' : '' );

        var actionBar = el( 'div', { className: 'aicf-gb-action-bar' },
            el( 'button', {
                className: 'aicf-gb-btn aicf-gb-btn-back',
                onClick:   onBack,
                disabled:  isGenerating || !! regenBlockId,
                title:     'Retour au prompt',
            }, i18n.back_btn ),
            el( 'button', {
                className: 'aicf-gb-btn aicf-gb-btn-rescan',
                onClick:   onRescan,
                disabled:  isGenerating || !! regenBlockId,
                title:     'Re-scanner les blocs',
            }, i18n.rescan_btn ),
            el( 'button', {
                className: 'aicf-gb-btn aicf-gb-btn-primary aicf-gb-btn-flex',
                onClick:   onGenerate,
                disabled:  isGenerating || !! regenBlockId || selectedCount === 0,
            }, isGenerating ? i18n.loading : generateLabel )
        );

        return el( 'div', { className: 'aicf-gb-panel' },
            templatesRow,
            promptArea,
            blockList,
            actionBar,
            statusBar
        );
    }

    // ---------------------------------------------------------------
    // Enregistrement du plugin Gutenberg
    // ---------------------------------------------------------------

    wp.plugins.registerPlugin( 'aicf-gutenberg-panel', {
        render: function () {
            return el( Fragment, null,
                el( wp.editPost.PluginSidebarMoreMenuItem, {
                    target: 'aicf-sidebar',
                    icon:   'superhero-alt',
                }, i18n.panelTitle ),
                el( wp.editPost.PluginSidebar, {
                    name:  'aicf-sidebar',
                    title: i18n.panelTitle,
                    icon:  'superhero-alt',
                },
                    el( AICFPanel, null )
                )
            );
        },
    } );

} )();
