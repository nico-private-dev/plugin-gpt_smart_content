/**
 * AI Content Filler — Panneau injecté dans l'éditeur Elementor.
 *
 * Scanne les widgets de la page (heading, text-editor, button, icon-box,
 * carrousels de témoignages, diapositives, etc.),
 * permet à l'utilisateur de sélectionner/désélectionner les widgets,
 * et envoie le tout à l'API REST du plugin pour génération via Claude.
 */
(function ($) {
    'use strict';

    if (typeof aicfConfig === 'undefined') {
        console.error('[AI Content Filler] aicfConfig non trouvé.');
        return;
    }

    var config = aicfConfig;
    var isGenerating = false;
    var panelCreated = false;
    var isPro = !!config.isPro;
    var dailyRemaining = config.dailyRemaining;

    var currentStep = 'prompt';
    var scannedWidgets = [];
    var cachedRootContainer = null;
    var cachedPageId = 0;

    // Historique du contenu par widget (pour le revert)
    var widgetHistory = {};

    // ---------------------------------------------------------------
    // Prompt templates
    // ---------------------------------------------------------------
    var PROMPT_TEMPLATES = {
        landing: {
            label: 'Landing',
            prompt: 'Page d\'atterrissage pour [votre offre]. Objectif : convertir les visiteurs en clients. Messages clairs, arguments percutants, appels à l\'action forts.'
        },
        service: {
            label: 'Service',
            prompt: 'Page de service présentant [vos services]. Mettez en avant les avantages, le processus et les résultats concrets pour le client.'
        },
        about: {
            label: 'À propos',
            prompt: 'Page à propos de l\'entreprise. Présentez l\'histoire, les valeurs, la mission et l\'équipe. Ton authentique et engageant.'
        },
        home: {
            label: 'Accueil',
            prompt: 'Page d\'accueil du site. Présentez l\'activité, les services phares et les avantages concurrentiels. Donnez envie d\'explorer le site.'
        },
        blog: {
            label: 'Blog',
            prompt: 'Article de blog sur [sujet]. Contenu informatif, structuré avec des sous-titres, engageant et optimisé SEO.'
        }
    };

    // ---------------------------------------------------------------
    // Registre des types de widgets supportés
    // - fields    : champs texte directs
    // - repeaters : listes d'items (repeater Elementor)
    // ---------------------------------------------------------------
    var WIDGET_REGISTRY = {
        // --- Elementor Free ---
        'heading': {
            label: 'Titre', cssClass: 'heading',
            fields: { 'title': 'Titre' }
        },
        'text-editor': {
            label: 'Texte', cssClass: 'text',
            fields: { 'editor': 'Contenu HTML' }
        },
        'button': {
            label: 'Bouton', cssClass: 'button',
            fields: { 'text': 'Texte du bouton' }
        },
        'icon-box': {
            label: 'Boîte icône', cssClass: 'box',
            fields: { 'title_text': 'Titre', 'description_text': 'Description' }
        },
        'image-box': {
            label: 'Boîte image', cssClass: 'box',
            fields: { 'title_text': 'Titre', 'description_text': 'Description' }
        },
        'testimonial': {
            label: 'Témoignage', cssClass: 'testimonial',
            fields: { 'testimonial_content': 'Témoignage', 'testimonial_name': 'Nom', 'testimonial_job': 'Poste' }
        },
        'counter': {
            label: 'Compteur', cssClass: 'data',
            fields: { 'title': 'Titre' }
        },
        'progress': {
            label: 'Progression', cssClass: 'data',
            fields: { 'title': 'Titre' }
        },
        'alert': {
            label: 'Alerte', cssClass: 'alert',
            fields: { 'alert_title': 'Titre', 'alert_description': 'Description' }
        },
        'star-rating': {
            label: 'Étoiles', cssClass: 'data',
            fields: { 'title': 'Titre' }
        },

        // --- Elementor Pro (champs directs) ---
        'call-to-action': {
            label: 'CTA', cssClass: 'cta',
            fields: { 'title': 'Titre', 'description': 'Description', 'button_text': 'Bouton' }
        },
        'animated-headline': {
            label: 'Titre animé', cssClass: 'heading',
            fields: { 'before_text': 'Texte avant', 'highlighted_text': 'Texte surligné', 'rotating_text': 'Texte rotatif', 'after_text': 'Texte après' }
        },
        'flip-box': {
            label: 'Flip Box', cssClass: 'box',
            fields: { 'title_text_a': 'Titre avant', 'description_text_a': 'Desc. avant', 'title_text_b': 'Titre arrière', 'description_text_b': 'Desc. arrière' }
        },
        'price-table': {
            label: 'Table de prix', cssClass: 'data',
            fields: { 'heading': 'Titre', 'sub_heading': 'Sous-titre', 'period': 'Période', 'footer_additional_info': 'Info complémentaire', 'button_text': 'Bouton' }
        },
        'blockquote': {
            label: 'Citation', cssClass: 'testimonial',
            fields: { 'blockquote_content': 'Citation' }
        },

        // --- Elementor Pro (widgets avec repeaters) ---
        'testimonial-carousel': {
            label: 'Carrousel avis', cssClass: 'testimonial',
            repeaters: {
                'slides': { fields: { 'content': 'Témoignage', 'name': 'Nom', 'title': 'Poste' } }
            }
        },
        'reviews': {
            label: 'Avis', cssClass: 'testimonial',
            repeaters: {
                'slides': { fields: { 'review_content': 'Avis', 'reviewer_name': 'Nom', 'reviewer_title': 'Poste' } }
            }
        },
        'slides': {
            label: 'Diapositives', cssClass: 'cta',
            repeaters: {
                'slides': { fields: { 'heading': 'Titre', 'description': 'Description', 'button_text': 'Bouton' } }
            }
        },
        'price-list': {
            label: 'Liste de prix', cssClass: 'data',
            repeaters: {
                'price_list': { fields: { 'title': 'Titre', 'item_description': 'Description', 'price': 'Prix' } }
            }
        },

        'icon-list': {
            label: 'Liste d\'icônes', cssClass: 'data',
            repeaters: {
                'icon_list': { fields: { 'text': 'Texte' } }
            }
        },

        // --- Elementor Free (widgets avec repeaters) ---
        'accordion': {
            label: 'Accordéon', cssClass: 'text',
            repeaters: {
                'tabs': { fields: { 'tab_title': 'Titre', 'tab_content': 'Contenu' } }
            }
        },
        'toggle': {
            label: 'Toggle', cssClass: 'text',
            repeaters: {
                'tabs': { fields: { 'tab_title': 'Titre', 'tab_content': 'Contenu' } }
            }
        },
        'tabs': {
            label: 'Onglets', cssClass: 'text',
            repeaters: {
                'tabs': { fields: { 'tab_title': 'Titre', 'tab_content': 'Contenu' } }
            }
        },

        // --- Elementor nested widgets (3.15+) ---
        'nested-accordion': {
            label: 'Accordéon', cssClass: 'text',
            repeaters: {
                'items': { fields: { 'item_title': 'Titre' } }
            }
        },
        'nested-tabs': {
            label: 'Onglets', cssClass: 'text',
            repeaters: {
                'items': { fields: { 'item_title': 'Titre' } }
            }
        }
    };

    // ---------------------------------------------------------------
    // Création du panneau
    // ---------------------------------------------------------------

    function createPanel() {
        if (panelCreated || document.getElementById('aicf-panel')) return;
        panelCreated = true;

        // Build template buttons (gate Pro templates)
        var freeTemplates = config.freeTemplates || [];
        var tplButtons = '';
        for (var tplKey in PROMPT_TEMPLATES) {
            if (PROMPT_TEMPLATES.hasOwnProperty(tplKey)) {
                var isLocked = !isPro && freeTemplates.length > 0 && freeTemplates.indexOf(tplKey) === -1;
                var lockClass = isLocked ? ' aicf-tpl-locked' : '';
                var lockIcon = isLocked ? ' &#128274;' : '';
                tplButtons += '<button type="button" class="aicf-tpl-btn' + lockClass + '" data-tpl="' + tplKey + '" data-locked="' + (isLocked ? '1' : '0') + '">' + PROMPT_TEMPLATES[tplKey].label + lockIcon + '</button>';
            }
        }

        // Daily limit indicator for free users
        var limitHtml = '';
        if (!isPro && dailyRemaining >= 0) {
            limitHtml = '<div id="aicf-daily-limit" class="aicf-daily-limit">' +
                '&#9889; <span id="aicf-daily-count">' + dailyRemaining + '</span>/' + config.dailyLimit + ' ' + 'générations restantes' +
                '</div>';
        }

        // Plan badge
        var planBadge = isPro
            ? '<span class="aicf-plan-badge aicf-plan-pro">PRO</span>'
            : '<span class="aicf-plan-badge aicf-plan-free">FREE</span>';

        var panelHTML =
            '<div id="aicf-panel">' +
                '<div id="aicf-panel-header">' +
                    '<span class="aicf-panel-title">AI Content Filler ' + planBadge + '</span>' +
                    '<button id="aicf-panel-toggle" type="button" title="Réduire/Agrandir">&#9660;</button>' +
                '</div>' +
                '<div id="aicf-panel-body">' +
                    limitHtml +
                    // Prompt templates
                    '<div id="aicf-templates" class="aicf-templates">' +
                        '<span class="aicf-templates-label">Modèles :</span>' +
                        tplButtons +
                    '</div>' +
                    '<textarea id="aicf-prompt" placeholder="Décrivez l\'objectif de cette page..." rows="3"></textarea>' +
                    '<div id="aicf-widget-list-container"></div>' +
                    '<div id="aicf-cost-estimate" class="aicf-cost-estimate" style="display:none;"></div>' +
                    '<button id="aicf-scan-btn" type="button">&#128269; Scanner les widgets</button>' +
                    '<div id="aicf-action-bar" style="display:none;">' +
                        '<button id="aicf-generate-btn" type="button">&#10024; Générer le contenu</button>' +
                        '<button id="aicf-rescan-btn" type="button" title="Rescanner les widgets">&#128260;</button>' +
                        '<button id="aicf-back-to-prompt" type="button" title="Retour au prompt">&#9998;</button>' +
                    '</div>' +
                    '<div id="aicf-status" class="aicf-status-idle">' + config.i18n.idle + '</div>' +
                '</div>' +
            '</div>';

        $('body').append(panelHTML);

        $(document).on('click', '#aicf-scan-btn', onScanClick);
        $(document).on('click', '#aicf-rescan-btn', onScanClick);
        $(document).on('click', '#aicf-generate-btn', onGenerateClick);
        $(document).on('click', '#aicf-panel-toggle', onTogglePanel);
        $(document).on('click', '.aicf-widget-remove', onWidgetRemove);
        $(document).on('change', '.aicf-widget-checkbox', onWidgetCheckboxChange);
        $(document).on('click', '#aicf-select-all', onSelectAllToggle);
        $(document).on('click', '#aicf-back-to-prompt', onBackToPrompt);
        $(document).on('click', '.aicf-tpl-btn', onTemplateClick);
        $(document).on('click', '.aicf-widget-regen', onRegenerateWidget);
        $(document).on('click', '.aicf-widget-revert', onRevertWidget);
    }

    function onTogglePanel() {
        var $body = $('#aicf-panel-body');
        var $btn = $('#aicf-panel-toggle');
        $body.toggleClass('aicf-collapsed');
        $btn.html($body.hasClass('aicf-collapsed') ? '&#9650;' : '&#9660;');
    }

    function setStatus(message, type) {
        $('#aicf-status')
            .removeClass('aicf-status-idle aicf-status-loading aicf-status-success aicf-status-error')
            .addClass('aicf-status-' + type)
            .text(message);
    }

    // ---------------------------------------------------------------
    // Template click handler
    // ---------------------------------------------------------------

    function onTemplateClick() {
        var $btn = $(this);

        // Bloquer les templates Pro en version gratuite
        if ($btn.data('locked') === 1 || $btn.data('locked') === '1') {
            setStatus(config.i18n.pro_feature + ' — ' + config.i18n.upgrade, 'error');
            return;
        }

        var tplKey = $btn.data('tpl');
        var tpl = PROMPT_TEMPLATES[tplKey];
        if (tpl) {
            $('#aicf-prompt').val(tpl.prompt).focus();
            // Highlight active template
            $('.aicf-tpl-btn').removeClass('aicf-tpl-active');
            $btn.addClass('aicf-tpl-active');
        }
    }

    // ---------------------------------------------------------------
    // Helpers Elementor
    // ---------------------------------------------------------------

    function getSettingValue(model, settingKey) {
        if (typeof model.getSetting === 'function') {
            try { var v = model.getSetting(settingKey); if (v) return v; } catch (e) {}
        }
        try {
            var s = model.get('settings');
            if (s && typeof s.get === 'function') { var v2 = s.get(settingKey); if (v2) return v2; }
        } catch (e) {}
        return '';
    }

    /**
     * Lit les données d'un repeater Elementor (Backbone Collection ou tableau).
     */
    function getRepeaterValue(model, repeaterKey) {
        try {
            var settings = model.get('settings');
            if (!settings || typeof settings.get !== 'function') return [];
            var repeater = settings.get(repeaterKey);
            if (!repeater) return [];
            if (repeater.models) {
                return repeater.models.map(function (m) {
                    return m.attributes ? JSON.parse(JSON.stringify(m.attributes)) : {};
                });
            }
            if (Array.isArray(repeater)) return repeater;
            if (typeof repeater.toJSON === 'function') return repeater.toJSON();
        } catch (e) {
            console.warn('[AI Content Filler] Erreur lecture repeater "' + repeaterKey + '":', e);
        }
        return [];
    }

    function getChildrenFromModel(model) {
        var result = [];
        try {
            var elements = model.get('elements');
            if (elements && elements.models) {
                elements.models.forEach(function (childModel) {
                    result.push({ model: childModel, children: getChildrenFromModel(childModel) });
                });
            }
        } catch (e) {}
        return result;
    }

    function findContainerById(container, targetId) {
        if (!container) return null;
        if (container.model && container.model.get('id') === targetId) return container;

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

        if (!children || !children.length) return null;
        for (var i = 0; i < children.length; i++) {
            var found = findContainerById(children[i], targetId);
            if (found) return found;
        }
        return null;
    }

    // ---------------------------------------------------------------
    // Scan des widgets
    // ---------------------------------------------------------------

    function scanWidgets(container) {
        var widgets = [];
        if (!container) return widgets;

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

        if (!children || children.length === 0) return widgets;

        for (var i = 0; i < children.length; i++) {
            var child = children[i];
            if (!child || !child.model) continue;

            try {
                var elType = child.model.get('elType');
                var widgetType = child.model.get('widgetType');

                if (elType === 'widget' && widgetType && WIDGET_REGISTRY[widgetType]) {
                    var reg = WIDGET_REGISTRY[widgetType];
                    var widgetId = child.model.get('id');
                    var fields = {};

                    // Champs directs
                    if (reg.fields) {
                        for (var key in reg.fields) {
                            if (reg.fields.hasOwnProperty(key)) {
                                fields[key] = getSettingValue(child.model, key) || '';
                            }
                        }
                    }

                    // Champs repeaters (notation pointée : repKey.index.field)
                    if (reg.repeaters) {
                        for (var repKey in reg.repeaters) {
                            if (!reg.repeaters.hasOwnProperty(repKey)) continue;
                            var repDef = reg.repeaters[repKey];
                            var repData = getRepeaterValue(child.model, repKey);

                            if (repData && repData.length) {
                                for (var ri = 0; ri < repData.length; ri++) {
                                    var item = repData[ri];
                                    for (var fk in repDef.fields) {
                                        if (!repDef.fields.hasOwnProperty(fk)) continue;
                                        fields[repKey + '.' + ri + '.' + fk] = (item && item[fk]) || '';
                                    }
                                }
                            }
                        }
                    }

                    if (Object.keys(fields).length > 0) {
                        widgets.push({ id: widgetId, type: widgetType, fields: fields });
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

    // ---------------------------------------------------------------
    // Affichage de la liste
    // ---------------------------------------------------------------

    function getFieldsPreview(fields, maxLen) {
        var parts = [];
        for (var key in fields) {
            if (!fields.hasOwnProperty(key)) continue;
            var val = fields[key];
            if (val) {
                var plain = val.replace(/<[^>]+>/g, '').replace(/&nbsp;/g, ' ').replace(/\s+/g, ' ').trim();
                if (plain) parts.push(plain);
            }
        }
        if (!parts.length) return '(vide)';
        var combined = parts.join(' | ');
        return combined.length > maxLen ? combined.substring(0, maxLen) + '...' : combined;
    }

    function countRepeaterItems(fields) {
        var indices = {};
        for (var key in fields) {
            if (!fields.hasOwnProperty(key)) continue;
            var parts = key.split('.');
            if (parts.length === 3) indices[parts[0] + '.' + parts[1]] = true;
        }
        return Object.keys(indices).length;
    }

    /**
     * Vérifie si un type de widget est autorisé dans le plan actuel.
     */
    function isWidgetAllowed(widgetType) {
        if (isPro) return true;
        var freeWidgets = config.freeWidgets || [];
        if (!freeWidgets.length) return true; // Pas de restriction
        return freeWidgets.indexOf(widgetType) !== -1;
    }

    function renderWidgetList(widgets) {
        var $container = $('#aicf-widget-list-container');
        if (!widgets.length) { $container.empty(); return; }

        var html = '<div id="aicf-widget-list">';
        html += '<div class="aicf-wl-header">';
        html += '<span class="aicf-wl-count">' + widgets.length + ' widget' + (widgets.length > 1 ? 's' : '') + ' trouvé' + (widgets.length > 1 ? 's' : '') + '</span>';
        html += '<button type="button" id="aicf-select-all" class="aicf-wl-toggle-all" data-state="all">Tout désélect.</button>';
        html += '</div>';

        html += '<div class="aicf-wl-items">';
        for (var i = 0; i < widgets.length; i++) {
            var w = widgets[i];
            var reg = WIDGET_REGISTRY[w.type];
            var typeLabel = reg ? reg.label : w.type;
            var cssClass = reg ? reg.cssClass : 'default';
            var preview = getFieldsPreview(w.fields, 40);
            var allowed = isWidgetAllowed(w.type);

            var infoBadge = '';
            if (reg && reg.repeaters) {
                var itemCount = countRepeaterItems(w.fields);
                if (itemCount > 0) infoBadge = ' <span class="aicf-wl-field-count">' + itemCount + ' item' + (itemCount > 1 ? 's' : '') + '</span>';
            } else if (reg && reg.fields) {
                var fc = Object.keys(reg.fields).length;
                if (fc > 1) infoBadge = ' <span class="aicf-wl-field-count">' + fc + ' champs</span>';
            }

            // Pro badge for locked widgets
            if (!allowed) {
                infoBadge += ' <span class="aicf-wl-pro-badge">PRO</span>';
            }

            var hasHistory = widgetHistory[w.id] ? true : false;
            var lockedClass = allowed ? '' : ' aicf-wl-item-locked';

            html += '<div class="aicf-wl-item' + (hasHistory ? ' aicf-wl-item-done' : '') + lockedClass + '" data-widget-id="' + w.id + '">';
            html += '<label class="aicf-wl-checkbox-label"><input type="checkbox" class="aicf-widget-checkbox" data-widget-id="' + w.id + '"' + (allowed ? ' checked' : ' disabled') + ' /><span class="aicf-wl-checkmark"></span></label>';
            html += '<div class="aicf-wl-info">';
            html += '<span class="aicf-wl-type aicf-wl-type-' + cssClass + '">' + typeLabel + infoBadge + '</span>';
            html += '<span class="aicf-wl-preview">' + (allowed ? $('<span>').text(preview).html() : config.i18n.pro_widget) + '</span>';
            html += '</div>';
            html += '<div class="aicf-wl-actions">';
            if (isPro) {
                html += '<button type="button" class="aicf-widget-regen" data-widget-id="' + w.id + '" title="Régénérer ce widget">&#x21bb;</button>';
                html += '<button type="button" class="aicf-widget-revert" data-widget-id="' + w.id + '" title="Restaurer le contenu précédent" style="' + (hasHistory ? '' : 'display:none;') + '">&#x21a9;</button>';
            }
            html += '<button type="button" class="aicf-widget-remove" data-widget-id="' + w.id + '" title="Exclure ce widget">&times;</button>';
            html += '</div>';
            html += '</div>';
        }
        html += '</div></div>';

        $container.html(html);
    }

    function getSelectedCount() { return $('.aicf-widget-checkbox:checked').length; }

    function updateGenerateButtonLabel() {
        var count = getSelectedCount();
        var $btn = $('#aicf-generate-btn');
        if (count === 0) {
            $btn.prop('disabled', true).text('Aucun widget sélectionné');
        } else {
            $btn.prop('disabled', false).html('&#10024; Générer (' + count + ' widget' + (count > 1 ? 's' : '') + ')');
        }
    }

    function updateWidgetCount() {
        var total = $('.aicf-wl-item').length;
        var selected = getSelectedCount();
        $('.aicf-wl-count').text(selected + '/' + total + ' widget' + (total > 1 ? 's' : '') + ' sélectionné' + (selected > 1 ? 's' : ''));
    }

    // ---------------------------------------------------------------
    // Cost estimation
    // ---------------------------------------------------------------

    function updateCostEstimate() {
        // Cost estimate is Pro-only
        if (!isPro) {
            $('#aicf-cost-estimate').hide();
            return;
        }

        var selectedIds = [];
        $('.aicf-widget-checkbox:checked').each(function () { selectedIds.push($(this).data('widget-id')); });

        if (!selectedIds.length) {
            $('#aicf-cost-estimate').hide();
            return;
        }

        var totalFields = 0;
        scannedWidgets.forEach(function (w) {
            if (selectedIds.indexOf(w.id) !== -1) {
                totalFields += Object.keys(w.fields).length;
            }
        });

        // Estimation : ~500 tokens base system prompt + ~30 tokens par champ en entrée + ~150 tokens par champ en sortie
        var inputTokens = 500 + (totalFields * 30);
        var outputTokens = totalFields * 150;
        var totalTokens = inputTokens + outputTokens;

        $('#aicf-cost-estimate')
            .html('&#x2248; ' + totalTokens + ' tokens &middot; ' + selectedIds.length + ' widget' + (selectedIds.length > 1 ? 's' : '') + ' &middot; ' + totalFields + ' champ' + (totalFields > 1 ? 's' : ''))
            .show();
    }

    /**
     * Met à jour l'affichage du compteur quotidien.
     */
    function updateDailyCounter(remaining) {
        dailyRemaining = remaining;
        var $el = $('#aicf-daily-count');
        if ($el.length) {
            $el.text(remaining);
        }
        var $container = $('#aicf-daily-limit');
        if ($container.length) {
            if (remaining <= 0) {
                $container.addClass('aicf-daily-limit-reached');
            } else if (remaining <= 3) {
                $container.addClass('aicf-daily-limit-low');
            }
        }
    }

    // ---------------------------------------------------------------
    // Helper : obtenir le root container Elementor (frais)
    // ---------------------------------------------------------------

    function refreshRootContainer() {
        cachedRootContainer = null;
        try {
            var currentDocument = elementor.documents.getCurrent();
            if (currentDocument && currentDocument.container) {
                cachedRootContainer = currentDocument.container;
            }
        } catch (e) {}

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
            } catch (e) {}
        }

        return cachedRootContainer;
    }

    // ---------------------------------------------------------------
    // Sauvegarde de l'historique (pour le revert)
    // ---------------------------------------------------------------

    function saveWidgetToHistory(widgetId, rootContainer) {
        var container = findContainerById(rootContainer, widgetId);
        if (!container) return;

        var widgetType = container.model.get('widgetType');
        var reg = WIDGET_REGISTRY[widgetType];
        if (!reg) return;

        var currentFields = {};
        if (reg.fields) {
            for (var key in reg.fields) {
                if (reg.fields.hasOwnProperty(key)) {
                    currentFields[key] = getSettingValue(container.model, key) || '';
                }
            }
        }
        if (reg.repeaters) {
            for (var repKey in reg.repeaters) {
                if (!reg.repeaters.hasOwnProperty(repKey)) continue;
                var repDef = reg.repeaters[repKey];
                var repData = getRepeaterValue(container.model, repKey);
                if (repData && repData.length) {
                    for (var ri = 0; ri < repData.length; ri++) {
                        for (var fk in repDef.fields) {
                            if (!repDef.fields.hasOwnProperty(fk)) continue;
                            currentFields[repKey + '.' + ri + '.' + fk] = (repData[ri] && repData[ri][fk]) || '';
                        }
                    }
                }
            }
        }
        widgetHistory[widgetId] = currentFields;
    }

    function saveAllToHistory(widgetIds, rootContainer) {
        widgetIds.forEach(function (id) {
            saveWidgetToHistory(id, rootContainer);
        });
    }

    // ---------------------------------------------------------------
    // Étape 1 : Scan
    // ---------------------------------------------------------------

    function onScanClick() {
        if (isGenerating) return;
        try {
            var prompt = $.trim($('#aicf-prompt').val());
            if (!prompt) { setStatus(config.i18n.empty_prompt, 'error'); return; }
            if (typeof elementor === 'undefined') { setStatus('Elementor n\'est pas chargé.', 'error'); return; }

            cachedPageId = 0;
            try {
                cachedPageId = elementor.config.document.id || elementor.config.initial_document.id || 0;
            } catch (e) {
                var match = window.location.search.match(/post=(\d+)/);
                if (match) cachedPageId = parseInt(match[1], 10);
            }
            if (!cachedPageId) { setStatus('Impossible de déterminer l\'ID de la page.', 'error'); return; }

            refreshRootContainer();
            if (!cachedRootContainer) { setStatus('Document Elementor non accessible.', 'error'); return; }

            scannedWidgets = scanWidgets(cachedRootContainer);
            if (!scannedWidgets.length) { setStatus(config.i18n.no_widgets, 'error'); return; }

            currentStep = 'select';
            renderWidgetList(scannedWidgets);
            $('#aicf-scan-btn').hide();
            $('#aicf-action-bar').show();
            updateGenerateButtonLabel();
            updateCostEstimate();
            setStatus('Sélectionnez les widgets à remplir, puis cliquez sur Générer.', 'idle');
        } catch (err) {
            console.error('[AI Content Filler] Erreur scan:', err);
            setStatus(config.i18n.error + ' : ' + err.message, 'error');
        }
    }

    // ---------------------------------------------------------------
    // Interactions liste
    // ---------------------------------------------------------------

    function onWidgetRemove(e) {
        e.preventDefault();
        var widgetId = $(this).data('widget-id');
        $('.aicf-wl-item[data-widget-id="' + widgetId + '"]').slideUp(200, function () {
            $(this).remove();
            updateWidgetCount();
            updateGenerateButtonLabel();
            updateCostEstimate();
            if ($('.aicf-wl-item').length === 0) { onBackToPrompt(); setStatus(config.i18n.no_widgets, 'error'); }
        });
        scannedWidgets = scannedWidgets.filter(function (w) { return w.id !== widgetId; });
    }

    function onWidgetCheckboxChange() {
        updateWidgetCount();
        updateGenerateButtonLabel();
        updateCostEstimate();
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
        updateCostEstimate();
    }

    function onBackToPrompt() {
        currentStep = 'prompt';
        scannedWidgets = [];
        cachedRootContainer = null;
        cachedPageId = 0;
        $('#aicf-widget-list-container').empty();
        $('#aicf-action-bar').hide();
        $('#aicf-cost-estimate').hide();
        $('#aicf-scan-btn').show();
        setStatus(config.i18n.idle, 'idle');
    }

    // ---------------------------------------------------------------
    // Régénération d'un seul widget
    // ---------------------------------------------------------------

    function onRegenerateWidget(e) {
        e.preventDefault();
        e.stopPropagation();
        if (isGenerating) return;

        // Regen is Pro-only
        if (!isPro) {
            setStatus(config.i18n.pro_feature + ' — ' + config.i18n.upgrade, 'error');
            return;
        }

        var widgetId = $(this).data('widget-id');
        var widget = null;
        for (var i = 0; i < scannedWidgets.length; i++) {
            if (scannedWidgets[i].id === widgetId) { widget = scannedWidgets[i]; break; }
        }
        if (!widget) return;

        var prompt = $.trim($('#aicf-prompt').val());
        if (!prompt) { setStatus(config.i18n.empty_prompt, 'error'); return; }

        isGenerating = true;
        var $btn = $(this);
        $btn.prop('disabled', true).addClass('aicf-spinning');
        setStatus('Régénération d\'un widget...', 'loading');

        // Sauvegarder l'état actuel avant régénération
        refreshRootContainer();
        saveWidgetToHistory(widgetId, cachedRootContainer);

        fetch(config.restUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
            credentials: 'same-origin',
            body: JSON.stringify({
                page_id: cachedPageId,
                user_prompt: prompt,
                widgets: [widget]
            })
        })
        .then(function (response) {
            return response.json().then(function (data) { return { status: response.status, data: data }; });
        })
        .then(function (result) {
            if (result.status !== 200 || !result.data.success) {
                var errorMsg = '';
                if (result.data && result.data.message) errorMsg = result.data.message;
                else if (result.data && result.data.data && result.data.data.message) errorMsg = result.data.data.message;
                else errorMsg = 'Erreur';
                throw new Error(errorMsg);
            }

            refreshRootContainer();
            applyGeneratedContent(result.data.widgets, cachedRootContainer);

            var $item = $('.aicf-wl-item[data-widget-id="' + widgetId + '"]');
            $item.addClass('aicf-wl-item-done');
            $item.find('.aicf-widget-revert').show();

            // Mettre à jour le compteur quotidien
            if (typeof result.data.dailyRemaining !== 'undefined') {
                updateDailyCounter(result.data.dailyRemaining);
            }

            setStatus('Widget régénéré ! — ' + config.i18n.save_reminder, 'success');
        })
        .catch(function (err) {
            console.error('[AI Content Filler] Erreur régénération:', err);
            setStatus(config.i18n.error + ' : ' + err.message, 'error');
        })
        .finally(function () {
            isGenerating = false;
            $btn.prop('disabled', false).removeClass('aicf-spinning');
        });
    }

    // ---------------------------------------------------------------
    // Revert (restaurer le contenu précédent)
    // ---------------------------------------------------------------

    function onRevertWidget(e) {
        e.preventDefault();
        e.stopPropagation();

        // Revert is Pro-only
        if (!isPro) {
            setStatus(config.i18n.pro_feature + ' — ' + config.i18n.upgrade, 'error');
            return;
        }

        var widgetId = $(this).data('widget-id');

        if (!widgetHistory[widgetId]) {
            setStatus('Pas d\'historique disponible.', 'error');
            return;
        }

        refreshRootContainer();
        var container = findContainerById(cachedRootContainer, widgetId);
        if (!container) {
            setStatus('Widget non trouvé.', 'error');
            return;
        }

        var previousContent = widgetHistory[widgetId];

        // Séparer champs directs / repeater
        var directSettings = {};
        var repeaterUpdates = {};
        for (var key in previousContent) {
            if (!previousContent.hasOwnProperty(key)) continue;
            var parts = key.split('.');
            if (parts.length === 3) {
                var rKey = parts[0], rIdx = parseInt(parts[1], 10), rField = parts[2];
                if (!repeaterUpdates[rKey]) repeaterUpdates[rKey] = {};
                if (!repeaterUpdates[rKey][rIdx]) repeaterUpdates[rKey][rIdx] = {};
                repeaterUpdates[rKey][rIdx][rField] = previousContent[key];
            } else {
                directSettings[key] = previousContent[key];
            }
        }

        for (var dk in directSettings) {
            if (directSettings.hasOwnProperty(dk)) {
                applySettingToWidget(container, dk, directSettings[dk]);
            }
        }
        for (var rk in repeaterUpdates) {
            if (repeaterUpdates.hasOwnProperty(rk)) {
                applyRepeaterUpdate(container, rk, repeaterUpdates[rk]);
            }
        }

        delete widgetHistory[widgetId];
        var $item = $('.aicf-wl-item[data-widget-id="' + widgetId + '"]');
        $item.removeClass('aicf-wl-item-done');
        $item.find('.aicf-widget-revert').hide();

        setStatus('Contenu précédent restauré.', 'success');
    }

    // ---------------------------------------------------------------
    // Étape 2 : Génération
    // ---------------------------------------------------------------

    function onGenerateClick() {
        if (isGenerating) return;
        try {
            // Vérifier la limite quotidienne côté client
            if (!isPro && dailyRemaining <= 0) {
                setStatus(config.i18n.daily_limit, 'error');
                return;
            }

            var prompt = $.trim($('#aicf-prompt').val());
            if (!prompt) { setStatus(config.i18n.empty_prompt, 'error'); return; }

            var selectedIds = [];
            $('.aicf-widget-checkbox:checked').each(function () { selectedIds.push($(this).data('widget-id')); });
            if (!selectedIds.length) { setStatus('Aucun widget sélectionné.', 'error'); return; }

            var selectedWidgets = scannedWidgets.filter(function (w) { return selectedIds.indexOf(w.id) !== -1; });
            if (!selectedWidgets.length) { setStatus('Aucun widget sélectionné.', 'error'); return; }

            isGenerating = true;
            currentStep = 'generating';
            setStatus(config.i18n.loading + ' (' + selectedWidgets.length + ' widget' + (selectedWidgets.length > 1 ? 's' : '') + ')', 'loading');
            $('#aicf-generate-btn').prop('disabled', true).html('&#10024; Génération...');
            $('#aicf-rescan-btn').prop('disabled', true);
            $('#aicf-back-to-prompt').prop('disabled', true);
            $('.aicf-widget-checkbox, .aicf-widget-remove, .aicf-widget-regen, .aicf-widget-revert, #aicf-select-all').prop('disabled', true);

            // Sauvegarder l'état actuel de tous les widgets sélectionnés
            refreshRootContainer();
            saveAllToHistory(selectedIds, cachedRootContainer);

            fetch(config.restUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
                credentials: 'same-origin',
                body: JSON.stringify({
                    page_id: cachedPageId,
                    user_prompt: prompt,
                    widgets: selectedWidgets
                })
            })
            .then(function (response) {
                return response.json().then(function (data) { return { status: response.status, data: data }; });
            })
            .then(function (result) {
                if (result.status !== 200 || !result.data.success) {
                    var errorMsg = '';
                    if (result.data && result.data.message) errorMsg = result.data.message;
                    else if (result.data && result.data.data && result.data.data.message) errorMsg = result.data.data.message;
                    else if (result.data && result.data.code) errorMsg = result.data.code;
                    else errorMsg = 'HTTP ' + result.status;
                    throw new Error(errorMsg);
                }

                // Rafraîchir le container pour avoir des références à jour
                refreshRootContainer();

                var applied = applyGeneratedContent(result.data.widgets, cachedRootContainer);

                if (result.data.widgets) {
                    result.data.widgets.forEach(function (gw) {
                        var $item = $('.aicf-wl-item[data-widget-id="' + gw.id + '"]');
                        $item.addClass('aicf-wl-item-done');
                        if (isPro) {
                            $item.find('.aicf-widget-revert').show();
                        }
                    });
                }

                // Mettre à jour le compteur quotidien
                if (typeof result.data.dailyRemaining !== 'undefined') {
                    updateDailyCounter(result.data.dailyRemaining);
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
                $('#aicf-rescan-btn').prop('disabled', false);
                $('#aicf-back-to-prompt').prop('disabled', false);
                $('.aicf-widget-checkbox, .aicf-widget-remove, .aicf-widget-regen, .aicf-widget-revert, #aicf-select-all').prop('disabled', false);
                updateGenerateButtonLabel();
            });

        } catch (err) {
            console.error('[AI Content Filler] Erreur inattendue:', err);
            setStatus(config.i18n.error + ' : ' + err.message, 'error');
            isGenerating = false;
            currentStep = 'select';
            $('#aicf-generate-btn').prop('disabled', false);
            $('#aicf-rescan-btn').prop('disabled', false);
            $('#aicf-back-to-prompt').prop('disabled', false);
            $('.aicf-widget-checkbox, .aicf-widget-remove, .aicf-widget-regen, .aicf-widget-revert, #aicf-select-all').prop('disabled', false);
            updateGenerateButtonLabel();
        }
    }

    // ---------------------------------------------------------------
    // Application du contenu généré
    // ---------------------------------------------------------------

    /**
     * Applique le contenu généré aux widgets Elementor.
     * Gère les champs directs ET les champs repeater (notation pointée).
     */
    function applyGeneratedContent(generatedWidgets, rootContainer) {
        if (!generatedWidgets || !generatedWidgets.length) return 0;

        var appliedCount = 0;

        generatedWidgets.forEach(function (gw) {
            try {
                var widgetContainer = findContainerById(rootContainer, gw.id);
                if (!widgetContainer) { console.warn('[AI Content Filler] Widget non trouvé:', gw.id); return; }

                var widgetType = widgetContainer.model.get('widgetType');
                var settingsToApply = {};

                if (typeof gw.content === 'object' && gw.content !== null) {
                    settingsToApply = gw.content;
                } else if (typeof gw.content === 'string') {
                    var reg = WIDGET_REGISTRY[widgetType];
                    if (reg && reg.fields) {
                        settingsToApply[Object.keys(reg.fields)[0]] = gw.content;
                    } else {
                        settingsToApply[(widgetType === 'heading') ? 'title' : 'editor'] = gw.content;
                    }
                }

                // Séparer champs directs / champs repeater
                var directSettings = {};
                var repeaterUpdates = {};

                for (var key in settingsToApply) {
                    if (!settingsToApply.hasOwnProperty(key)) continue;
                    var parts = key.split('.');
                    if (parts.length === 3) {
                        var rKey = parts[0], rIdx = parseInt(parts[1], 10), rField = parts[2];
                        if (!repeaterUpdates[rKey]) repeaterUpdates[rKey] = {};
                        if (!repeaterUpdates[rKey][rIdx]) repeaterUpdates[rKey][rIdx] = {};
                        repeaterUpdates[rKey][rIdx][rField] = settingsToApply[key];
                    } else {
                        directSettings[key] = settingsToApply[key];
                    }
                }

                var widgetApplied = false;

                for (var dk in directSettings) {
                    if (!directSettings.hasOwnProperty(dk)) continue;
                    if (applySettingToWidget(widgetContainer, dk, directSettings[dk])) widgetApplied = true;
                }

                for (var repK in repeaterUpdates) {
                    if (!repeaterUpdates.hasOwnProperty(repK)) continue;
                    if (applyRepeaterUpdate(widgetContainer, repK, repeaterUpdates[repK])) widgetApplied = true;
                }

                if (widgetApplied) appliedCount++;
            } catch (e) {
                console.error('[AI Content Filler] Erreur application widget ' + gw.id + ':', e);
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
     * Applique une valeur à un setting direct.
     */
    function applySettingToWidget(widgetContainer, settingKey, value) {
        try {
            if (typeof $e !== 'undefined' && $e.run) {
                var s = {};
                s[settingKey] = value;
                $e.run('document/elements/settings', { container: widgetContainer, settings: s });
                return true;
            } else if (typeof widgetContainer.model.setSetting === 'function') {
                widgetContainer.model.setSetting(settingKey, value);
                return true;
            } else {
                var sObj = widgetContainer.model.get('settings');
                if (sObj && typeof sObj.set === 'function') { sObj.set(settingKey, value); return true; }
            }
        } catch (e) {
            console.error('[AI Content Filler] Erreur setSetting(' + settingKey + '):', e);
        }
        return false;
    }

    /**
     * Met à jour les champs texte d'un repeater Elementor.
     * Clone les données, applique les modifications, puis injecte via $e.run
     * pour déclencher le re-rendu du preview dans le builder.
     *
     * @param {Object} widgetContainer  Container du widget
     * @param {string} repeaterKey      Clé du repeater (ex: 'slides')
     * @param {Object} updates          { index: { field: value } }
     */
    function applyRepeaterUpdate(widgetContainer, repeaterKey, updates) {
        try {
            var settings = widgetContainer.model.get('settings');
            if (!settings || typeof settings.get !== 'function') return false;

            var repeater = settings.get(repeaterKey);
            if (!repeater) return false;

            // Cloner les données du repeater en tableau JSON brut
            var repeaterData;
            if (repeater.models) {
                repeaterData = repeater.models.map(function (m) {
                    return m.attributes ? JSON.parse(JSON.stringify(m.attributes)) : {};
                });
            } else if (Array.isArray(repeater)) {
                repeaterData = JSON.parse(JSON.stringify(repeater));
            } else if (typeof repeater.toJSON === 'function') {
                repeaterData = repeater.toJSON();
            } else {
                return false;
            }

            // Appliquer les modifications sur le clone
            var applied = false;
            for (var idx in updates) {
                if (!updates.hasOwnProperty(idx)) continue;
                var itemIdx = parseInt(idx, 10);
                if (itemIdx >= repeaterData.length) continue;

                var fields = updates[idx];
                for (var field in fields) {
                    if (fields.hasOwnProperty(field)) {
                        repeaterData[itemIdx][field] = fields[field];
                    }
                }
                applied = true;
            }

            // Injecter via $e.run → déclenche le re-rendu du preview
            if (applied) {
                applySettingToWidget(widgetContainer, repeaterKey, repeaterData);
            }

            return applied;
        } catch (e) {
            console.error('[AI Content Filler] Erreur repeater "' + repeaterKey + '":', e);
            return false;
        }
    }

    // ---------------------------------------------------------------
    // Initialisation
    // ---------------------------------------------------------------

    function waitForElementorAndInit() {
        if (typeof elementor !== 'undefined' && elementor.documents) { createPanel(); return; }
        $(window).on('elementor:init', function () { setTimeout(createPanel, 500); });
        setTimeout(function () { if (!panelCreated) createPanel(); }, 5000);
    }

    if (document.readyState === 'complete' || document.readyState === 'interactive') {
        waitForElementorAndInit();
    } else {
        $(document).ready(waitForElementorAndInit);
    }

})(jQuery);
