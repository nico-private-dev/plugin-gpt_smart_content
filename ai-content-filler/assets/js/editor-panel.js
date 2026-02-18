/**
 * AI Content Filler — Panneau injecté dans l'éditeur Elementor.
 *
 * Ce script crée un panneau flottant en bas à gauche de l'éditeur,
 * scanne les widgets de la page (heading, text-editor, button, icon-box, etc.),
 * permet à l'utilisateur de sélectionner/désélectionner les widgets,
 * et envoie le tout à l'API REST du plugin pour génération via Claude.
 */
(function ($) {
    'use strict';

    if (typeof aicfConfig === 'undefined') {
        console.error('[AI Content Filler] aicfConfig non trouvé. Le script ne peut pas démarrer.');
        return;
    }

    var config = aicfConfig;
    var isGenerating = false;
    var panelCreated = false;

    // État du flux en 2 étapes
    var currentStep = 'prompt';  // 'prompt' | 'select' | 'generating'
    var scannedWidgets = [];
    var cachedRootContainer = null;
    var cachedPageId = 0;

    // ---------------------------------------------------------------
    // Registre des types de widgets supportés
    // Chaque entrée définit les champs texte à extraire et le label UI.
    // ---------------------------------------------------------------
    var WIDGET_REGISTRY = {
        // --- Elementor Free ---
        'heading': {
            label: 'Titre',
            cssClass: 'heading',
            fields: { 'title': 'Titre' }
        },
        'text-editor': {
            label: 'Texte',
            cssClass: 'text',
            fields: { 'editor': 'Contenu HTML' }
        },
        'button': {
            label: 'Bouton',
            cssClass: 'button',
            fields: { 'text': 'Texte du bouton' }
        },
        'icon-box': {
            label: 'Boîte icône',
            cssClass: 'box',
            fields: { 'title_text': 'Titre', 'description_text': 'Description' }
        },
        'image-box': {
            label: 'Boîte image',
            cssClass: 'box',
            fields: { 'title_text': 'Titre', 'description_text': 'Description' }
        },
        'testimonial': {
            label: 'Témoignage',
            cssClass: 'testimonial',
            fields: { 'testimonial_content': 'Témoignage', 'testimonial_name': 'Nom', 'testimonial_job': 'Poste' }
        },
        'counter': {
            label: 'Compteur',
            cssClass: 'data',
            fields: { 'title': 'Titre' }
        },
        'progress': {
            label: 'Progression',
            cssClass: 'data',
            fields: { 'title': 'Titre' }
        },
        'alert': {
            label: 'Alerte',
            cssClass: 'alert',
            fields: { 'alert_title': 'Titre', 'alert_description': 'Description' }
        },
        'star-rating': {
            label: 'Étoiles',
            cssClass: 'data',
            fields: { 'title': 'Titre' }
        },
        // --- Elementor Pro ---
        'call-to-action': {
            label: 'CTA',
            cssClass: 'cta',
            fields: { 'title': 'Titre', 'description': 'Description', 'button_text': 'Bouton' }
        },
        'animated-headline': {
            label: 'Titre animé',
            cssClass: 'heading',
            fields: { 'before_text': 'Texte avant', 'highlighted_text': 'Texte surligné' }
        },
        'flip-box': {
            label: 'Flip Box',
            cssClass: 'box',
            fields: { 'title_text_a': 'Titre avant', 'description_text_a': 'Desc. avant', 'title_text_b': 'Titre arrière', 'description_text_b': 'Desc. arrière' }
        },
        'price-table': {
            label: 'Table de prix',
            cssClass: 'data',
            fields: { 'heading': 'Titre', 'sub_heading': 'Sous-titre', 'period': 'Période', 'footer_additional_info': 'Info complémentaire', 'button_text': 'Bouton' }
        },
        'blockquote': {
            label: 'Citation',
            cssClass: 'testimonial',
            fields: { 'blockquote_content': 'Citation' }
        }
    };

    /**
     * Crée et injecte le panneau HTML dans l'éditeur.
     */
    function createPanel() {
        if (panelCreated || document.getElementById('aicf-panel')) {
            return;
        }
        panelCreated = true;

        var panelHTML =
            '<div id="aicf-panel">' +
                '<div id="aicf-panel-header">' +
                    '<span class="aicf-panel-title">AI Content Filler</span>' +
                    '<button id="aicf-panel-toggle" type="button" title="Réduire/Agrandir">&#9660;</button>' +
                '</div>' +
                '<div id="aicf-panel-body">' +
                    '<textarea id="aicf-prompt" placeholder="Décrivez l\'objectif de cette page..." rows="3"></textarea>' +
                    '<div id="aicf-widget-list-container"></div>' +
                    '<button id="aicf-scan-btn" type="button">&#128269; Scanner les widgets</button>' +
                    '<button id="aicf-generate-btn" type="button" style="display:none;">&#10024; Générer le contenu</button>' +
                    '<div id="aicf-status" class="aicf-status-idle">' + config.i18n.idle + '</div>' +
                '</div>' +
            '</div>';

        $('body').append(panelHTML);

        $(document).on('click', '#aicf-scan-btn', onScanClick);
        $(document).on('click', '#aicf-generate-btn', onGenerateClick);
        $(document).on('click', '#aicf-panel-toggle', onTogglePanel);
        $(document).on('click', '.aicf-widget-remove', onWidgetRemove);
        $(document).on('change', '.aicf-widget-checkbox', onWidgetCheckboxChange);
        $(document).on('click', '#aicf-select-all', onSelectAllToggle);
        $(document).on('click', '#aicf-back-to-prompt', onBackToPrompt);
    }

    function onTogglePanel() {
        var $body = $('#aicf-panel-body');
        var $btn = $('#aicf-panel-toggle');
        $body.toggleClass('aicf-collapsed');
        $btn.html($body.hasClass('aicf-collapsed') ? '&#9650;' : '&#9660;');
    }

    function setStatus(message, type) {
        var $status = $('#aicf-status');
        $status
            .removeClass('aicf-status-idle aicf-status-loading aicf-status-success aicf-status-error')
            .addClass('aicf-status-' + type)
            .text(message);
    }

    // ---------------------------------------------------------------
    // Scan des widgets Elementor
    // ---------------------------------------------------------------

    /**
     * Parcourt récursivement les containers Elementor pour trouver
     * tous les widgets supportés et extraire leurs champs texte.
     */
    function scanWidgets(container) {
        var widgets = [];

        if (!container) {
            return widgets;
        }

        var children = null;

        if (container.children && container.children.length > 0) {
            children = container.children;
        } else if (container.model && container.model.get && container.model.get('elements')) {
            var elements = container.model.get('elements');
            if (elements && elements.models) {
                children = [];
                elements.models.forEach(function (childModel) {
                    if (childModel.container) {
                        children.push(childModel.container);
                    } else {
                        children.push({ model: childModel, children: getChildrenFromModel(childModel) });
                    }
                });
            }
        }

        if (!children || children.length === 0) {
            return widgets;
        }

        for (var i = 0; i < children.length; i++) {
            var child = children[i];

            if (!child || !child.model) {
                continue;
            }

            try {
                var elType = child.model.get('elType');
                var widgetType = child.model.get('widgetType');

                if (elType === 'widget' && widgetType && WIDGET_REGISTRY[widgetType]) {
                    var reg = WIDGET_REGISTRY[widgetType];
                    var widgetId = child.model.get('id');
                    var fields = {};

                    for (var key in reg.fields) {
                        if (reg.fields.hasOwnProperty(key)) {
                            fields[key] = getSettingValue(child.model, key) || '';
                        }
                    }

                    widgets.push({
                        id: widgetId,
                        type: widgetType,
                        fields: fields
                    });
                }
            } catch (e) {
                console.warn('[AI Content Filler] Erreur lors du scan d\'un widget:', e);
            }

            var childWidgets = scanWidgets(child);
            if (childWidgets.length > 0) {
                widgets = widgets.concat(childWidgets);
            }
        }

        return widgets;
    }

    function getSettingValue(model, settingKey) {
        if (typeof model.getSetting === 'function') {
            try {
                var val = model.getSetting(settingKey);
                if (val) return val;
            } catch (e) {}
        }

        try {
            var settings = model.get('settings');
            if (settings && typeof settings.get === 'function') {
                var val2 = settings.get(settingKey);
                if (val2) return val2;
            }
        } catch (e) {}

        return '';
    }

    function getChildrenFromModel(model) {
        var result = [];
        try {
            var elements = model.get('elements');
            if (elements && elements.models) {
                elements.models.forEach(function (childModel) {
                    result.push({
                        model: childModel,
                        children: getChildrenFromModel(childModel)
                    });
                });
            }
        } catch (e) {}
        return result;
    }

    function findContainerById(container, targetId) {
        if (!container) {
            return null;
        }

        if (container.model && container.model.get('id') === targetId) {
            return container;
        }

        var children = container.children;
        if (!children || !children.length) {
            return null;
        }

        for (var i = 0; i < children.length; i++) {
            var found = findContainerById(children[i], targetId);
            if (found) {
                return found;
            }
        }

        return null;
    }

    // ---------------------------------------------------------------
    // Étape 1 : Scan et affichage de la liste des widgets
    // ---------------------------------------------------------------

    /**
     * Extrait un aperçu texte depuis les champs d'un widget.
     * Concatène les valeurs non vides avec " | ".
     */
    function getFieldsPreview(fields, maxLen) {
        var parts = [];
        for (var key in fields) {
            if (fields.hasOwnProperty(key)) {
                var val = fields[key];
                if (val) {
                    var plain = val.replace(/<[^>]+>/g, '').replace(/&nbsp;/g, ' ').replace(/\s+/g, ' ').trim();
                    if (plain) parts.push(plain);
                }
            }
        }
        if (!parts.length) return '(vide)';
        var combined = parts.join(' | ');
        if (combined.length > maxLen) {
            return combined.substring(0, maxLen) + '...';
        }
        return combined;
    }

    /**
     * Construit et affiche la liste des widgets scannés avec checkboxes.
     */
    function renderWidgetList(widgets) {
        var $container = $('#aicf-widget-list-container');

        if (!widgets.length) {
            $container.empty();
            return;
        }

        var html = '<div id="aicf-widget-list">';

        // Header de la liste
        html += '<div class="aicf-wl-header">';
        html += '<span class="aicf-wl-count">' + widgets.length + ' widget' + (widgets.length > 1 ? 's' : '') + ' trouvé' + (widgets.length > 1 ? 's' : '') + '</span>';
        html += '<button type="button" id="aicf-select-all" class="aicf-wl-toggle-all" data-state="all">Tout désélect.</button>';
        html += '</div>';

        // Items
        html += '<div class="aicf-wl-items">';
        for (var i = 0; i < widgets.length; i++) {
            var w = widgets[i];
            var reg = WIDGET_REGISTRY[w.type];
            var typeLabel = reg ? reg.label : w.type;
            var cssClass = reg ? reg.cssClass : 'default';
            var preview = getFieldsPreview(w.fields, 40);
            var fieldCount = reg ? Object.keys(reg.fields).length : 0;
            var fieldBadge = fieldCount > 1 ? ' <span class="aicf-wl-field-count">' + fieldCount + ' champs</span>' : '';

            html += '<div class="aicf-wl-item" data-widget-id="' + w.id + '">';
            html += '<label class="aicf-wl-checkbox-label">';
            html += '<input type="checkbox" class="aicf-widget-checkbox" data-widget-id="' + w.id + '" checked />';
            html += '<span class="aicf-wl-checkmark"></span>';
            html += '</label>';
            html += '<div class="aicf-wl-info">';
            html += '<span class="aicf-wl-type aicf-wl-type-' + cssClass + '">' + typeLabel + fieldBadge + '</span>';
            html += '<span class="aicf-wl-preview">' + $('<span>').text(preview).html() + '</span>';
            html += '</div>';
            html += '<button type="button" class="aicf-widget-remove" data-widget-id="' + w.id + '" title="Exclure ce widget">&times;</button>';
            html += '</div>';
        }
        html += '</div>';
        html += '</div>';

        $container.html(html);
    }

    function getSelectedCount() {
        return $('.aicf-widget-checkbox:checked').length;
    }

    function updateGenerateButtonLabel() {
        var count = getSelectedCount();
        var $btn = $('#aicf-generate-btn');
        if (count === 0) {
            $btn.prop('disabled', true).text('Aucun widget sélectionné');
        } else {
            $btn.prop('disabled', false).html('&#10024; Générer (' + count + ' widget' + (count > 1 ? 's' : '') + ')');
        }
    }

    /**
     * Handler du clic sur le bouton "Scanner les widgets".
     */
    function onScanClick() {
        if (isGenerating) return;

        try {
            var prompt = $.trim($('#aicf-prompt').val());

            if (!prompt) {
                setStatus(config.i18n.empty_prompt, 'error');
                return;
            }

            if (typeof elementor === 'undefined') {
                setStatus('Elementor n\'est pas chargé.', 'error');
                return;
            }

            cachedPageId = 0;
            try {
                cachedPageId = elementor.config.document.id ||
                               elementor.config.initial_document.id ||
                               0;
            } catch (e) {
                var match = window.location.search.match(/post=(\d+)/);
                if (match) {
                    cachedPageId = parseInt(match[1], 10);
                }
            }

            if (!cachedPageId) {
                setStatus('Impossible de déterminer l\'ID de la page.', 'error');
                return;
            }

            cachedRootContainer = null;
            try {
                var currentDocument = elementor.documents.getCurrent();
                if (currentDocument && currentDocument.container) {
                    cachedRootContainer = currentDocument.container;
                }
            } catch (e) {
                console.warn('[AI Content Filler] Impossible d\'accéder au document courant:', e);
            }

            if (!cachedRootContainer) {
                try {
                    if (elementor.elements && elementor.elements.models) {
                        cachedRootContainer = {
                            model: null,
                            children: elementor.elements.models.map(function (m) {
                                return m.container || { model: m, children: getChildrenFromModel(m) };
                            })
                        };
                    }
                } catch (e) {
                    console.warn('[AI Content Filler] Fallback elementor.elements échoué:', e);
                }
            }

            if (!cachedRootContainer) {
                setStatus('Document Elementor non accessible. Essayez de recharger l\'éditeur.', 'error');
                return;
            }

            scannedWidgets = scanWidgets(cachedRootContainer);

            if (!scannedWidgets.length) {
                setStatus(config.i18n.no_widgets, 'error');
                return;
            }

            currentStep = 'select';
            renderWidgetList(scannedWidgets);

            $('#aicf-scan-btn').hide();
            $('#aicf-generate-btn').show();
            updateGenerateButtonLabel();

            setStatus('Sélectionnez les widgets à remplir, puis cliquez sur Générer.', 'idle');

        } catch (err) {
            console.error('[AI Content Filler] Erreur lors du scan:', err);
            setStatus(config.i18n.error + ' : ' + err.message, 'error');
        }
    }

    // ---------------------------------------------------------------
    // Interactions avec la liste de widgets
    // ---------------------------------------------------------------

    function onWidgetRemove(e) {
        e.preventDefault();
        var widgetId = $(this).data('widget-id');

        $('.aicf-wl-item[data-widget-id="' + widgetId + '"]').slideUp(200, function () {
            $(this).remove();
            updateWidgetCount();
            updateGenerateButtonLabel();

            if ($('.aicf-wl-item').length === 0) {
                onBackToPrompt();
                setStatus(config.i18n.no_widgets, 'error');
            }
        });

        scannedWidgets = scannedWidgets.filter(function (w) {
            return w.id !== widgetId;
        });
    }

    function updateWidgetCount() {
        var total = $('.aicf-wl-item').length;
        var selected = getSelectedCount();
        $('.aicf-wl-count').text(selected + '/' + total + ' widget' + (total > 1 ? 's' : '') + ' sélectionné' + (selected > 1 ? 's' : ''));
    }

    function onWidgetCheckboxChange() {
        updateWidgetCount();
        updateGenerateButtonLabel();
    }

    function onSelectAllToggle() {
        var $btn = $(this);
        var state = $btn.data('state');

        if (state === 'all') {
            $('.aicf-widget-checkbox').prop('checked', false);
            $btn.data('state', 'none').text('Tout sélect.');
        } else {
            $('.aicf-widget-checkbox').prop('checked', true);
            $btn.data('state', 'all').text('Tout désélect.');
        }

        updateWidgetCount();
        updateGenerateButtonLabel();
    }

    function onBackToPrompt() {
        currentStep = 'prompt';
        scannedWidgets = [];
        cachedRootContainer = null;
        cachedPageId = 0;

        $('#aicf-widget-list-container').empty();
        $('#aicf-generate-btn').hide();
        $('#aicf-scan-btn').show();
        setStatus(config.i18n.idle, 'idle');
    }

    // ---------------------------------------------------------------
    // Étape 2 : Génération du contenu
    // ---------------------------------------------------------------

    function onGenerateClick() {
        if (isGenerating) return;

        try {
            var prompt = $.trim($('#aicf-prompt').val());

            if (!prompt) {
                setStatus(config.i18n.empty_prompt, 'error');
                return;
            }

            var selectedIds = [];
            $('.aicf-widget-checkbox:checked').each(function () {
                selectedIds.push($(this).data('widget-id'));
            });

            if (!selectedIds.length) {
                setStatus('Aucun widget sélectionné. Cochez au moins un widget.', 'error');
                return;
            }

            var selectedWidgets = scannedWidgets.filter(function (w) {
                return selectedIds.indexOf(w.id) !== -1;
            });

            if (!selectedWidgets.length) {
                setStatus('Aucun widget sélectionné.', 'error');
                return;
            }

            isGenerating = true;
            currentStep = 'generating';
            setStatus(config.i18n.loading + ' (' + selectedWidgets.length + ' widget' + (selectedWidgets.length > 1 ? 's' : '') + ')', 'loading');
            $('#aicf-generate-btn').prop('disabled', true).html('&#10024; Génération...');
            $('#aicf-scan-btn').prop('disabled', true);
            $('.aicf-widget-checkbox, .aicf-widget-remove, #aicf-select-all').prop('disabled', true);

            var payload = {
                page_id: cachedPageId,
                user_prompt: prompt,
                widgets: selectedWidgets
            };

            fetch(config.restUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': config.nonce
                },
                credentials: 'same-origin',
                body: JSON.stringify(payload)
            })
            .then(function (response) {
                return response.json().then(function (data) {
                    return { status: response.status, data: data };
                });
            })
            .then(function (result) {
                if (result.status !== 200 || !result.data.success) {
                    var errorMsg = '';
                    if (result.data && result.data.message) {
                        errorMsg = result.data.message;
                    } else if (result.data && result.data.data && result.data.data.message) {
                        errorMsg = result.data.data.message;
                    } else if (result.data && result.data.code) {
                        errorMsg = result.data.code;
                    } else {
                        errorMsg = 'HTTP ' + result.status;
                    }
                    throw new Error(errorMsg);
                }

                var applied = applyGeneratedContent(result.data.widgets, cachedRootContainer);

                if (result.data.widgets) {
                    result.data.widgets.forEach(function (gw) {
                        $('.aicf-wl-item[data-widget-id="' + gw.id + '"]').addClass('aicf-wl-item-done');
                    });
                }

                setStatus(config.i18n.success + ' (' + applied + ' widget' + (applied > 1 ? 's' : '') + ' mis à jour) — ' + config.i18n.save_reminder, 'success');
            })
            .catch(function (err) {
                console.error('[AI Content Filler] Erreur:', err);
                setStatus(config.i18n.error + ' : ' + err.message, 'error');
            })
            .finally(function () {
                isGenerating = false;
                currentStep = 'select';
                $('#aicf-generate-btn').prop('disabled', false);
                $('#aicf-scan-btn').prop('disabled', false);
                $('.aicf-widget-checkbox, .aicf-widget-remove, #aicf-select-all').prop('disabled', false);
                updateGenerateButtonLabel();
            });

        } catch (err) {
            console.error('[AI Content Filler] Erreur inattendue:', err);
            setStatus(config.i18n.error + ' : ' + err.message, 'error');
            isGenerating = false;
            currentStep = 'select';
            $('#aicf-generate-btn').prop('disabled', false);
            $('#aicf-scan-btn').prop('disabled', false);
            $('.aicf-widget-checkbox, .aicf-widget-remove, #aicf-select-all').prop('disabled', false);
            updateGenerateButtonLabel();
        }
    }

    /**
     * Applique le contenu généré par Claude aux widgets Elementor.
     *
     * Supporte deux formats de réponse :
     * - Nouveau : content = { field_key: value, ... } (multi-champs)
     * - Ancien :  content = "string" (rétrocompatibilité, appliqué au 1er champ du registre)
     */
    function applyGeneratedContent(generatedWidgets, rootContainer) {
        if (!generatedWidgets || !generatedWidgets.length) {
            return 0;
        }

        var appliedCount = 0;

        generatedWidgets.forEach(function (gw) {
            try {
                var widgetContainer = findContainerById(rootContainer, gw.id);

                if (!widgetContainer) {
                    console.warn('[AI Content Filler] Widget non trouvé dans l\'arbre:', gw.id);
                    return;
                }

                var widgetType = widgetContainer.model.get('widgetType');
                var settingsToApply = {};

                if (typeof gw.content === 'object' && gw.content !== null) {
                    // Nouveau format multi-champs : { field_key: value }
                    settingsToApply = gw.content;
                } else if (typeof gw.content === 'string') {
                    // Ancien format : string → appliqué au premier champ du registre
                    var reg = WIDGET_REGISTRY[widgetType];
                    if (reg) {
                        var firstKey = Object.keys(reg.fields)[0];
                        settingsToApply[firstKey] = gw.content;
                    } else {
                        // Fallback absolu pour les types inconnus
                        var fallbackKey = (widgetType === 'heading') ? 'title' : 'editor';
                        settingsToApply[fallbackKey] = gw.content;
                    }
                }

                // Appliquer chaque champ au widget Elementor
                var widgetApplied = false;
                for (var settingKey in settingsToApply) {
                    if (!settingsToApply.hasOwnProperty(settingKey)) continue;

                    var value = settingsToApply[settingKey];
                    if (applySettingToWidget(widgetContainer, settingKey, value)) {
                        widgetApplied = true;
                    }
                }

                if (widgetApplied) {
                    appliedCount++;
                }
            } catch (e) {
                console.error('[AI Content Filler] Erreur lors de l\'application au widget ' + gw.id + ':', e);
            }
        });

        try {
            if (typeof elementor !== 'undefined' && elementor.channels) {
                elementor.channels.editor.trigger('change');
            }
        } catch (e) {}

        return appliedCount;
    }

    /**
     * Applique une valeur à un setting d'un widget Elementor.
     * Utilise $e.run en priorité pour la compatibilité undo/redo.
     */
    function applySettingToWidget(widgetContainer, settingKey, value) {
        try {
            if (typeof $e !== 'undefined' && $e.run) {
                var settings = {};
                settings[settingKey] = value;
                $e.run('document/elements/settings', {
                    container: widgetContainer,
                    settings: settings
                });
                return true;
            } else if (typeof widgetContainer.model.setSetting === 'function') {
                widgetContainer.model.setSetting(settingKey, value);
                return true;
            } else {
                var settingsObj = widgetContainer.model.get('settings');
                if (settingsObj && typeof settingsObj.set === 'function') {
                    settingsObj.set(settingKey, value);
                    return true;
                }
            }
        } catch (e) {
            console.error('[AI Content Filler] Erreur setSetting(' + settingKey + '):', e);
        }
        return false;
    }

    // ---------------------------------------------------------------
    // Initialisation
    // ---------------------------------------------------------------

    function waitForElementorAndInit() {
        if (typeof elementor !== 'undefined' && elementor.documents) {
            createPanel();
            return;
        }

        $(window).on('elementor:init', function () {
            setTimeout(createPanel, 500);
        });

        setTimeout(function () {
            if (!panelCreated) {
                createPanel();
            }
        }, 5000);
    }

    if (document.readyState === 'complete' || document.readyState === 'interactive') {
        waitForElementorAndInit();
    } else {
        $(document).ready(waitForElementorAndInit);
    }

})(jQuery);
