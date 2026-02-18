/**
 * AI Content Filler — Panneau injecté dans l'éditeur Elementor.
 *
 * Ce script crée un panneau flottant en bas à gauche de l'éditeur,
 * scanne les widgets Heading et Text Editor de la page,
 * permet à l'utilisateur de sélectionner/désélectionner les widgets,
 * et envoie le tout à l'API REST du plugin pour génération via Claude.
 */
(function ($) {
    'use strict';

    // Vérification que la config est disponible (injectée via wp_localize_script)
    if (typeof aicfConfig === 'undefined') {
        console.error('[AI Content Filler] aicfConfig non trouvé. Le script ne peut pas démarrer.');
        return;
    }

    var config = aicfConfig;
    var isGenerating = false;
    var panelCreated = false;

    // État du flux en 2 étapes
    var currentStep = 'prompt';  // 'prompt' | 'select' | 'generating'
    var scannedWidgets = [];     // Résultat du dernier scan
    var cachedRootContainer = null;
    var cachedPageId = 0;

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

        // Binding des événements
        $(document).on('click', '#aicf-scan-btn', onScanClick);
        $(document).on('click', '#aicf-generate-btn', onGenerateClick);
        $(document).on('click', '#aicf-panel-toggle', onTogglePanel);
        $(document).on('click', '.aicf-widget-remove', onWidgetRemove);
        $(document).on('change', '.aicf-widget-checkbox', onWidgetCheckboxChange);
        $(document).on('click', '#aicf-select-all', onSelectAllToggle);
        $(document).on('click', '#aicf-back-to-prompt', onBackToPrompt);
    }

    /**
     * Bascule la visibilité du corps du panneau.
     */
    function onTogglePanel() {
        var $body = $('#aicf-panel-body');
        var $btn = $('#aicf-panel-toggle');
        $body.toggleClass('aicf-collapsed');
        $btn.html($body.hasClass('aicf-collapsed') ? '&#9650;' : '&#9660;');
    }

    /**
     * Met à jour la zone de statut du panneau.
     */
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
     * les widgets heading et text-editor.
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

                if (elType === 'widget') {
                    var widgetId = child.model.get('id');
                    var currentText = '';

                    if (widgetType === 'heading') {
                        currentText = getSettingValue(child.model, 'title');
                        widgets.push({
                            id: widgetId,
                            type: 'heading',
                            current_text: currentText || ''
                        });
                    } else if (widgetType === 'text-editor') {
                        currentText = getSettingValue(child.model, 'editor');
                        widgets.push({
                            id: widgetId,
                            type: 'text-editor',
                            current_text: currentText || ''
                        });
                    }
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

    /**
     * Récupère la valeur d'un setting d'un modèle Elementor.
     */
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

    /**
     * Tente de récupérer les enfants depuis un modèle Backbone (fallback).
     */
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

    /**
     * Recherche récursive d'un container par l'ID de son modèle.
     */
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
     * Extrait un aperçu texte court depuis du HTML ou du texte brut.
     */
    function getTextPreview(text, maxLen) {
        if (!text) return '(vide)';
        // Retirer les balises HTML
        var plain = text.replace(/<[^>]+>/g, '').replace(/&nbsp;/g, ' ').replace(/\s+/g, ' ').trim();
        if (!plain) return '(vide)';
        if (plain.length > maxLen) {
            return plain.substring(0, maxLen) + '...';
        }
        return plain;
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
            var typeLabel = w.type === 'heading' ? 'Titre' : 'Texte';
            var preview = getTextPreview(w.current_text, 40);

            html += '<div class="aicf-wl-item" data-widget-id="' + w.id + '">';
            html += '<label class="aicf-wl-checkbox-label">';
            html += '<input type="checkbox" class="aicf-widget-checkbox" data-widget-id="' + w.id + '" checked />';
            html += '<span class="aicf-wl-checkmark"></span>';
            html += '</label>';
            html += '<div class="aicf-wl-info">';
            html += '<span class="aicf-wl-type aicf-wl-type-' + w.type + '">' + typeLabel + '</span>';
            html += '<span class="aicf-wl-preview">' + $('<span>').text(preview).html() + '</span>';
            html += '</div>';
            html += '<button type="button" class="aicf-widget-remove" data-widget-id="' + w.id + '" title="Exclure ce widget">&times;</button>';
            html += '</div>';
        }
        html += '</div>';
        html += '</div>';

        $container.html(html);
    }

    /**
     * Retourne le nombre de widgets actuellement cochés dans la liste.
     */
    function getSelectedCount() {
        return $('.aicf-widget-checkbox:checked').length;
    }

    /**
     * Met à jour le texte du bouton Générer avec le compte de widgets sélectionnés.
     */
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

            // Récupérer l'ID de la page
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

            // Récupérer le root container
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

            // Scanner les widgets
            scannedWidgets = scanWidgets(cachedRootContainer);

            if (!scannedWidgets.length) {
                setStatus(config.i18n.no_widgets, 'error');
                return;
            }

            // Passer à l'étape de sélection
            currentStep = 'select';
            renderWidgetList(scannedWidgets);

            // Cacher le bouton scan, afficher le bouton generate + retour
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

    /**
     * Retrait d'un widget de la liste (bouton ×).
     */
    function onWidgetRemove(e) {
        e.preventDefault();
        var widgetId = $(this).data('widget-id');

        // Retirer du DOM
        $('.aicf-wl-item[data-widget-id="' + widgetId + '"]').slideUp(200, function () {
            $(this).remove();
            updateWidgetCount();
            updateGenerateButtonLabel();

            // Si plus aucun widget, revenir à l'étape prompt
            if ($('.aicf-wl-item').length === 0) {
                onBackToPrompt();
                setStatus(config.i18n.no_widgets, 'error');
            }
        });

        // Retirer du tableau interne
        scannedWidgets = scannedWidgets.filter(function (w) {
            return w.id !== widgetId;
        });
    }

    /**
     * Met à jour le compteur de widgets dans le header de la liste.
     */
    function updateWidgetCount() {
        var total = $('.aicf-wl-item').length;
        var selected = getSelectedCount();
        $('.aicf-wl-count').text(selected + '/' + total + ' widget' + (total > 1 ? 's' : '') + ' sélectionné' + (selected > 1 ? 's' : ''));
    }

    /**
     * Handler de changement de checkbox d'un widget.
     */
    function onWidgetCheckboxChange() {
        updateWidgetCount();
        updateGenerateButtonLabel();
    }

    /**
     * Tout sélectionner / tout désélectionner.
     */
    function onSelectAllToggle() {
        var $btn = $(this);
        var state = $btn.data('state');

        if (state === 'all') {
            // Tout désélectionner
            $('.aicf-widget-checkbox').prop('checked', false);
            $btn.data('state', 'none').text('Tout sélect.');
        } else {
            // Tout sélectionner
            $('.aicf-widget-checkbox').prop('checked', true);
            $btn.data('state', 'all').text('Tout désélect.');
        }

        updateWidgetCount();
        updateGenerateButtonLabel();
    }

    /**
     * Retour à l'étape prompt (annuler le scan).
     */
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

    /**
     * Handler du clic sur le bouton "Générer le contenu".
     */
    function onGenerateClick() {
        if (isGenerating) return;

        try {
            var prompt = $.trim($('#aicf-prompt').val());

            if (!prompt) {
                setStatus(config.i18n.empty_prompt, 'error');
                return;
            }

            // Collecter uniquement les widgets cochés
            var selectedIds = [];
            $('.aicf-widget-checkbox:checked').each(function () {
                selectedIds.push($(this).data('widget-id'));
            });

            if (!selectedIds.length) {
                setStatus('Aucun widget sélectionné. Cochez au moins un widget.', 'error');
                return;
            }

            // Filtrer les widgets scannés pour ne garder que les sélectionnés
            var selectedWidgets = scannedWidgets.filter(function (w) {
                return selectedIds.indexOf(w.id) !== -1;
            });

            if (!selectedWidgets.length) {
                setStatus('Aucun widget sélectionné.', 'error');
                return;
            }

            // Lancer la génération
            isGenerating = true;
            currentStep = 'generating';
            setStatus(config.i18n.loading + ' (' + selectedWidgets.length + ' widget' + (selectedWidgets.length > 1 ? 's' : '') + ')', 'loading');
            $('#aicf-generate-btn').prop('disabled', true).html('&#10024; Génération...');
            $('#aicf-scan-btn').prop('disabled', true);

            // Désactiver les checkboxes et boutons remove pendant la génération
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

                // Marquer les widgets appliqués visuellement
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
                var settingKey = (widgetType === 'heading') ? 'title' : 'editor';

                if (typeof $e !== 'undefined' && $e.run) {
                    var settings = {};
                    settings[settingKey] = gw.content;
                    $e.run('document/elements/settings', {
                        container: widgetContainer,
                        settings: settings
                    });
                    appliedCount++;
                } else if (typeof widgetContainer.model.setSetting === 'function') {
                    widgetContainer.model.setSetting(settingKey, gw.content);
                    appliedCount++;
                } else {
                    var settingsObj = widgetContainer.model.get('settings');
                    if (settingsObj && typeof settingsObj.set === 'function') {
                        settingsObj.set(settingKey, gw.content);
                        appliedCount++;
                    }
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
