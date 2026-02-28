/**
 * AI Content Filler — Sidebar Gutenberg
 * Génère le contenu des blocs natifs WP et Kadence via l'API IA.
 */
( function () {
    'use strict';

    var el          = wp.element.createElement;
    var useState    = wp.element.useState;
    var useEffect   = wp.element.useEffect;
    var useCallback = wp.element.useCallback;
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
        'kadence/testimonials': {
            label: 'Témoignages',
            cssClass: 'aicf-wl-type-testimonial',
            repeater: 'items',
            repeaterFields: { content: 'Témoignage', name: 'Nom', occupation: 'Poste' },
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
    var TEMPLATES = {
        landing:  "Génère un contenu percutant pour une landing page. Mets en avant les bénéfices, utilise des verbes d'action.",
        service:  "Rédige le contenu d'une page de services professionnels. Ton sérieux, axé résultats.",
        about:    "Crée le contenu d'une page À propos. Présente l'équipe, les valeurs et la mission.",
        home:     "Génère le contenu de la page d'accueil. Accrocheur, orienté conversion.",
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
        // Pour les RichText stockés en HTML, retourner tel quel
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
                // Supprimer les balises HTML pour l'aperçu
                return val.replace( /<[^>]+>/g, '' ).substring( 0, 50 );
            }
        }
        return '';
    }

    /**
     * Scan récursif de tous les blocs pour trouver les blocs texte supportés.
     * @param {Array} blocks - Tableau de blocs Gutenberg
     * @returns {Array} - Tableau de { id, type, label, cssClass, fields, preview }
     */
    function scanBlocks( blocks ) {
        var result = [];

        blocks.forEach( function ( block ) {
            if ( ! block.name ) return;

            var blockDef = BLOCK_REGISTRY[ block.name ];

            if ( blockDef ) {
                var fields = {};

                // Champs directs
                if ( blockDef.fields ) {
                    Object.keys( blockDef.fields ).forEach( function ( fieldKey ) {
                        fields[ fieldKey ] = getAttr( block.attributes, fieldKey );
                    } );
                }

                // Champs repeater (ex: kadence/testimonials → items[])
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
                    result.push( {
                        id:       block.clientId,
                        type:     block.name,
                        label:    blockDef.label,
                        cssClass: blockDef.cssClass || 'aicf-wl-type-default',
                        fields:   fields,
                        preview:  getPreview( fields ),
                    } );
                }
            }

            // Récursion dans les blocs imbriqués
            if ( block.innerBlocks && block.innerBlocks.length > 0 ) {
                result = result.concat( scanBlocks( block.innerBlocks ) );
            }
        } );

        return result;
    }

    /**
     * Nettoie la valeur d'un champ selon le type de bloc pour éviter les erreurs
     * de validation Gutenberg (ex: double <p> dans core/paragraph).
     *
     * Gutenberg stocke uniquement le contenu INTÉRIEUR dans l'attribut :
     *   core/paragraph.content  → pas de <p> englobant
     *   core/heading.content    → pas de <h*> englobant
     *   core/button.text        → texte brut
     */
    function sanitizeForBlock( blockType, fieldKey, value ) {
        if ( typeof value !== 'string' ) return value;

        if ( blockType === 'core/paragraph' && fieldKey === 'content' ) {
            // Supprimer la balise <p> englobante si présente
            return value.replace( /^<p[^>]*>([\s\S]*?)<\/p>\s*$/i, '$1' ).trim();
        }

        if ( blockType === 'core/heading' && fieldKey === 'content' ) {
            // Supprimer la balise <h1-6> englobante si présente
            return value.replace( /^<h[1-6][^>]*>([\s\S]*?)<\/h[1-6]>\s*$/i, '$1' ).trim();
        }

        if ( ( blockType === 'core/button' || blockType === 'kadence/singlebtn' ) && fieldKey === 'text' ) {
            // Texte brut pour les boutons, supprimer toute balise englobante
            return value.replace( /<[^>]+>/g, '' ).trim();
        }

        return value;
    }

    /**
     * Applique le contenu généré aux blocs Gutenberg.
     * @param {Array} widgets - Tableau de { id, type, content: {} }
     */
    function applyContent( widgets ) {
        var dispatch = wp.data.dispatch( 'core/block-editor' );
        var select   = wp.data.select( 'core/block-editor' );

        widgets.forEach( function ( widget ) {
            var content   = widget.content;
            var blockType = widget.type || '';

            // Rétrocompatibilité : content peut être une string
            if ( typeof content === 'string' ) {
                content = { content: content };
            }

            if ( ! content || typeof content !== 'object' ) return;

            var directAttrs    = {};
            var repeaterUpdates = {}; // { repKey: { index: { field: value } } }

            // Séparer champs directs vs champs repeater (notation pointée)
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

            // Blocs RichText : le composant React garde un état interne qui ne se
            // réinitialise pas avec updateBlockAttributes() seul → on utilise
            // replaceBlock() avec un clone pour forcer la réinstanciation.
            var RICH_TEXT_BLOCKS = [
                'core/paragraph',
                'core/heading',
                'core/quote',
                'kadence/advancedheading',
            ];

            // Appliquer les champs directs
            if ( Object.keys( directAttrs ).length > 0 ) {
                if ( RICH_TEXT_BLOCKS.indexOf( blockType ) !== -1 ) {
                    var block = select.getBlock( widget.id );
                    if ( block && wp.blocks && wp.blocks.cloneBlock ) {
                        var newBlock = wp.blocks.cloneBlock( block, directAttrs );
                        dispatch.replaceBlock( widget.id, newBlock );
                    } else {
                        dispatch.updateBlockAttributes( widget.id, directAttrs );
                    }
                } else {
                    dispatch.updateBlockAttributes( widget.id, directAttrs );
                }
            }

            // Appliquer les repeaters
            Object.keys( repeaterUpdates ).forEach( function ( repKey ) {
                var block = select.getBlock( widget.id );
                if ( ! block ) return;

                // Cloner le tableau existant
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

                var attrUpdate       = {};
                attrUpdate[ repKey ] = currentItems;
                dispatch.updateBlockAttributes( widget.id, attrUpdate );
            } );
        } );
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

        var _dailyRemaining   = useState( config.dailyRemaining );
        var dailyRemaining    = _dailyRemaining[ 0 ];
        var setDailyRemaining = _dailyRemaining[ 1 ];

        var isPro        = !! config.isPro;
        var freeBlocks   = config.freeBlocks || [];

        // Vérifie si un bloc est autorisé en free
        function isBlockAllowed( type ) {
            if ( isPro ) return true;
            return freeBlocks.indexOf( type ) !== -1;
        }

        // Applique un template dans le prompt
        function onTemplateClick( key ) {
            if ( ! isPro && config.freeTemplates.indexOf( key ) === -1 ) return;
            if ( activeTemplate === key ) {
                setActiveTemplate( '' );
                setPrompt( '' );
            } else {
                setActiveTemplate( key );
                setPrompt( TEMPLATES[ key ] || '' );
            }
        }

        // Scanner les blocs de la page
        function onScan() {
            if ( ! prompt.trim() ) {
                setStatus( { type: 'error', message: i18n.empty_prompt } );
                return;
            }

            var allBlocks = wp.data.select( 'core/block-editor' ).getBlocks();
            var found     = scanBlocks( allBlocks );

            if ( found.length === 0 ) {
                setStatus( { type: 'error', message: i18n.no_blocks } );
                return;
            }

            setScanned( found );
            // Pré-sélectionner tous les blocs autorisés
            setSelected( found.filter( function ( b ) { return isBlockAllowed( b.type ); } ).map( function ( b ) { return b.id; } ) );
            setStatus( { type: 'idle', message: found.length + ' ' + i18n.blocks_found } );
            setStep( 'select' );
        }

        // Tout sélectionner / désélectionner
        function onToggleAll() {
            var allowed = scanned.filter( function ( b ) { return isBlockAllowed( b.type ); } ).map( function ( b ) { return b.id; } );
            if ( selected.length === allowed.length ) {
                setSelected( [] );
            } else {
                setSelected( allowed );
            }
        }

        // Coche individuelle
        function onToggleBlock( id, isAllowed ) {
            if ( ! isAllowed ) return;
            var idx = selected.indexOf( id );
            if ( idx === -1 ) {
                setSelected( selected.concat( [ id ] ) );
            } else {
                setSelected( selected.filter( function ( s ) { return s !== id; } ) );
            }
        }

        // Lancer la génération
        function onGenerate() {
            if ( isGenerating ) return;
            if ( selected.length === 0 ) return;

            var blocksToSend = scanned.filter( function ( b ) {
                return selected.indexOf( b.id ) !== -1 && isBlockAllowed( b.type );
            } );

            if ( blocksToSend.length === 0 ) return;

            // Récupérer le page_id depuis l'éditeur
            var pageId = wp.data.select( 'core/editor' )
                ? wp.data.select( 'core/editor' ).getCurrentPostId()
                : 0;

            setGenerating( true );
            setStep( 'generating' );
            setStatus( { type: 'loading', message: i18n.loading } );

            var payload = {
                page_id:     pageId,
                user_prompt: prompt,
                widgets:     blocksToSend.map( function ( b ) {
                    return { id: b.id, type: b.type, fields: b.fields };
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
                if ( data.widgets && Array.isArray( data.widgets ) ) {
                    applyContent( data.widgets );
                }

                // Mettre à jour le compteur quotidien
                if ( typeof data.dailyRemaining !== 'undefined' ) {
                    setDailyRemaining( data.dailyRemaining );
                }

                setStatus( { type: 'success', message: i18n.success } );
                setStep( 'select' );
                setGenerating( false );
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
        }

        // ---------------------------------------------------------------
        // Rendu
        // ---------------------------------------------------------------

        // Badge plan
        var planBadge = el( 'span', {
            className: 'aicf-gb-plan-badge ' + ( isPro ? 'aicf-plan-pro' : 'aicf-plan-free' ),
        }, isPro ? i18n.pro_plan : i18n.free_plan );

        // Compteur quotidien
        var dailyInfo = null;
        if ( ! isPro ) {
            var limitClass = 'aicf-gb-daily';
            if ( dailyRemaining <= 2 ) limitClass += ' aicf-daily-limit-low';
            if ( dailyRemaining === 0 ) limitClass += ' aicf-daily-limit-reached';
            dailyInfo = el( 'div', { className: limitClass },
                dailyRemaining === 0
                    ? i18n.daily_limit
                    : dailyRemaining + ' ' + i18n.daily_remaining
            );
        } else {
            dailyInfo = el( 'div', { className: 'aicf-gb-daily' }, i18n.unlimited );
        }

        // Templates
        var templateBtns = Object.keys( TEMPLATES ).map( function ( key ) {
            var isFreeTemplate = config.freeTemplates.indexOf( key ) !== -1;
            var isLocked       = ! isPro && ! isFreeTemplate;
            var isActive       = activeTemplate === key;

            var cls = 'aicf-tpl-btn';
            if ( isActive ) cls += ' aicf-tpl-active';
            if ( isLocked ) cls += ' aicf-tpl-locked';

            var label = key.charAt( 0 ).toUpperCase() + key.slice( 1 );
            if ( isLocked ) label += ' 🔒';

            return el( 'button', {
                key:       key,
                className: cls,
                onClick:   function () { onTemplateClick( key ); },
                title:     isLocked ? i18n.pro_feature : '',
            }, label );
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

        // Statut
        var statusCls = 'aicf-gb-status aicf-status-' + status.type;
        var statusBar = status.message
            ? el( 'div', { className: statusCls }, status.message )
            : null;

        // ---- Étape : prompt ----
        if ( step === 'prompt' ) {
            return el( 'div', { className: 'aicf-gb-panel' },
                el( 'div', { className: 'aicf-gb-header-info' }, planBadge, dailyInfo ),
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
        var isSelecting  = step === 'select';
        var allowedCount = scanned.filter( function ( b ) { return isBlockAllowed( b.type ); } ).length;
        var selectedCount = selected.length;

        // Liste des blocs
        var blockItems = scanned.map( function ( block ) {
            var allowed  = isBlockAllowed( block.type );
            var isChecked = selected.indexOf( block.id ) !== -1;

            var itemCls = 'aicf-wl-item';
            if ( ! allowed ) itemCls += ' aicf-wl-item-locked';

            return el( 'div', { key: block.id, className: itemCls },
                el( 'label', { className: 'aicf-wl-checkbox-label' },
                    el( 'input', {
                        type:     'checkbox',
                        checked:  isChecked,
                        disabled: ! allowed || isGenerating,
                        onChange: function () { onToggleBlock( block.id, allowed ); },
                    } ),
                    el( 'span', { className: 'aicf-wl-checkmark' } )
                ),
                el( 'div', { className: 'aicf-wl-info' },
                    el( 'span', { className: 'aicf-wl-type ' + block.cssClass },
                        block.label,
                        ! allowed && el( 'span', { className: 'aicf-wl-pro-badge' }, 'PRO' )
                    ),
                    block.preview && el( 'span', { className: 'aicf-wl-preview' }, block.preview )
                )
            );
        } );

        var blockList = el( 'div', { className: 'aicf-gb-block-list' },
            el( 'div', { className: 'aicf-wl-header' },
                el( 'span', { className: 'aicf-wl-count' },
                    selectedCount + ' / ' + allowedCount + ' ' + i18n.selected
                ),
                el( 'button', {
                    className: 'aicf-wl-toggle-all',
                    onClick:   onToggleAll,
                    disabled:  isGenerating,
                }, selectedCount === allowedCount ? i18n.deselect_all : i18n.select_all )
            ),
            el( 'div', { className: 'aicf-wl-items' }, blockItems )
        );

        var generateLabel = i18n.generate_btn + ( selectedCount > 0 ? ' (' + selectedCount + ')' : '' );

        var actionBar = el( 'div', { className: 'aicf-gb-action-bar' },
            el( 'button', {
                className: 'aicf-gb-btn aicf-gb-btn-back',
                onClick:   onBack,
                disabled:  isGenerating,
                title:     'Retour au prompt',
            }, i18n.back_btn ),
            el( 'button', {
                className: 'aicf-gb-btn aicf-gb-btn-primary aicf-gb-btn-flex',
                onClick:   onGenerate,
                disabled:  isGenerating || selectedCount === 0,
            }, isGenerating ? i18n.loading : generateLabel )
        );

        return el( 'div', { className: 'aicf-gb-panel' },
            el( 'div', { className: 'aicf-gb-header-info' }, planBadge, dailyInfo ),
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
                // Entrée dans le menu "Plus d'outils"
                el( wp.editPost.PluginSidebarMoreMenuItem, {
                    target: 'aicf-sidebar',
                    icon:   'superhero-alt',
                }, i18n.panelTitle ),
                // La sidebar
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
