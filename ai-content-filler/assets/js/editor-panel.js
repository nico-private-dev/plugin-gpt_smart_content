/**
 * AI Content Filler — Panneau injecté dans l'éditeur Elementor.
 *
 * Ce script crée un panneau flottant en bas à gauche de l'éditeur,
 * scanne les widgets Heading et Text Editor de la page,
 * et envoie le tout à l'API REST du plugin pour génération via Claude.
 */
(function ($) {
    'use strict';

    // Vérification que la config est disponible (injectée via wp_localize_script)
    if (typeof aicfConfig === 'undefined') {
        return;
    }

    var config = aicfConfig;
    var isGenerating = false;

    /**
     * Crée et injecte le panneau HTML dans l'éditeur.
     */
    function createPanel() {
        var panelHTML =
            '<div id="aicf-panel">' +
                '<div id="aicf-panel-header">' +
                    '<span class="aicf-panel-title">AI Content Filler</span>' +
                    '<button id="aicf-panel-toggle" type="button" title="Réduire/Agrandir">&#9660;</button>' +
                '</div>' +
                '<div id="aicf-panel-body">' +
                    '<textarea id="aicf-prompt" placeholder="Décrivez l\'objectif de cette page..." rows="3"></textarea>' +
                    '<button id="aicf-generate-btn" type="button">&#10024; Générer le contenu</button>' +
                    '<div id="aicf-status" class="aicf-status-idle">' + config.i18n.idle + '</div>' +
                '</div>' +
            '</div>';

        // Injection dans le body de l'éditeur Elementor (window parent si iframe)
        $('body').append(panelHTML);

        // Événements
        $('#aicf-generate-btn').on('click', onGenerateClick);
        $('#aicf-panel-toggle').on('click', onTogglePanel);
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
     *
     * @param {string} message  Texte à afficher.
     * @param {string} type     'idle' | 'loading' | 'success' | 'error'
     */
    function setStatus(message, type) {
        var $status = $('#aicf-status');
        $status
            .removeClass('aicf-status-idle aicf-status-loading aicf-status-success aicf-status-error')
            .addClass('aicf-status-' + type)
            .text(message);
    }

    /**
     * Parcourt récursivement les containers Elementor pour trouver
     * les widgets heading et text-editor.
     *
     * @param {Object} container  Container Elementor (section, colonne, widget…).
     * @returns {Array}           Liste de { id, type, current_text }.
     */
    function scanWidgets(container) {
        var widgets = [];

        if (!container || !container.children) {
            return widgets;
        }

        container.children.forEach(function (child) {
            var elType = child.model.get('elType');
            var widgetType = child.model.get('widgetType');

            if (elType === 'widget') {
                if (widgetType === 'heading') {
                    widgets.push({
                        id: child.model.get('id'),
                        type: 'heading',
                        current_text: child.model.getSetting('title') || ''
                    });
                } else if (widgetType === 'text-editor') {
                    widgets.push({
                        id: child.model.get('id'),
                        type: 'text-editor',
                        current_text: child.model.getSetting('editor') || ''
                    });
                }
            }

            // Descente récursive dans les sections, colonnes, containers internes
            if (child.children && child.children.length) {
                widgets = widgets.concat(scanWidgets(child));
            }
        });

        return widgets;
    }

    /**
     * Retrouve un container widget par son ID de modèle, récursivement.
     *
     * @param {Object} container  Container racine.
     * @param {string} widgetId   ID du widget recherché.
     * @returns {Object|null}     Le container du widget trouvé, ou null.
     */
    function findWidgetById(container, widgetId) {
        if (!container || !container.children) {
            return null;
        }

        for (var i = 0; i < container.children.length; i++) {
            var child = container.children.models ? container.children.models[i] : container.children[i];

            // Normaliser l'accès : parfois c'est une collection Backbone
            var actualChild = child;
            if (container.children.models) {
                // C'est une collection Backbone, récupérer le container view
                actualChild = container.children._views
                    ? container.children._views[child.cid]
                    : null;
            }

            if (!actualChild) continue;

            if (actualChild.model && actualChild.model.get('id') === widgetId) {
                return actualChild;
            }

            var found = findWidgetById(actualChild, widgetId);
            if (found) return found;
        }

        return null;
    }

    /**
     * Handler du clic sur le bouton "Générer le contenu".
     */
    function onGenerateClick() {
        if (isGenerating) {
            return;
        }

        var prompt = $.trim($('#aicf-prompt').val());

        if (!prompt) {
            setStatus(config.i18n.empty_prompt, 'error');
            return;
        }

        // Récupérer l'ID de la page courante dans Elementor
        var pageId = 0;
        if (typeof elementor !== 'undefined' && elementor.config && elementor.config.document) {
            pageId = elementor.config.document.id;
        }

        if (!pageId) {
            setStatus('Impossible de déterminer l\'ID de la page.', 'error');
            return;
        }

        // Scanner les widgets de la page
        var currentDocument = elementor.documents.getCurrent();
        if (!currentDocument || !currentDocument.container) {
            setStatus('Document Elementor non accessible.', 'error');
            return;
        }

        var widgets = scanWidgets(currentDocument.container);

        if (!widgets.length) {
            setStatus(config.i18n.no_widgets, 'error');
            return;
        }

        // Lancer la génération
        isGenerating = true;
        setStatus(config.i18n.loading, 'loading');
        $('#aicf-generate-btn').prop('disabled', true);

        var payload = {
            page_id: pageId,
            user_prompt: prompt,
            widgets: widgets
        };

        fetch(config.restUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': config.nonce
            },
            body: JSON.stringify(payload)
        })
        .then(function (response) {
            return response.json().then(function (data) {
                return { status: response.status, data: data };
            });
        })
        .then(function (result) {
            if (result.status !== 200 || !result.data.success) {
                var errorMsg = result.data.message || result.data.data?.message || config.i18n.error;
                throw new Error(errorMsg);
            }

            // Appliquer le contenu généré à chaque widget
            applyGeneratedContent(result.data.widgets, currentDocument.container);

            setStatus(config.i18n.success + ' ' + config.i18n.save_reminder, 'success');
        })
        .catch(function (err) {
            setStatus(config.i18n.error + ' : ' + err.message, 'error');
        })
        .finally(function () {
            isGenerating = false;
            $('#aicf-generate-btn').prop('disabled', false);
        });
    }

    /**
     * Applique le contenu généré par Claude aux widgets Elementor.
     *
     * Utilise $e.run('document/elements/settings') pour modifier les settings
     * de façon compatible avec l'historique d'undo d'Elementor.
     *
     * @param {Array}  generatedWidgets  [ { id, content }, ... ]
     * @param {Object} rootContainer     Container racine du document Elementor.
     */
    function applyGeneratedContent(generatedWidgets, rootContainer) {
        if (!generatedWidgets || !generatedWidgets.length) {
            return;
        }

        generatedWidgets.forEach(function (gw) {
            // Retrouver le container du widget dans l'arbre Elementor
            var widgetContainer = findContainerById(rootContainer, gw.id);

            if (!widgetContainer) {
                // Widget non trouvé, on l'ignore sans écraser de contenu
                return;
            }

            var widgetType = widgetContainer.model.get('widgetType');
            var settingKey = (widgetType === 'heading') ? 'title' : 'editor';

            // Utilisation de la commande Elementor pour modifier le setting
            // Cela s'intègre avec le système d'undo/redo natif
            if (typeof $e !== 'undefined' && $e.run) {
                $e.run('document/elements/settings', {
                    container: widgetContainer,
                    settings: createSettingsObject(settingKey, gw.content)
                });
            } else {
                // Fallback : modification directe du modèle
                widgetContainer.model.setSetting(settingKey, gw.content);
            }
        });
    }

    /**
     * Crée un objet settings dynamique pour la commande Elementor.
     */
    function createSettingsObject(key, value) {
        var obj = {};
        obj[key] = value;
        return obj;
    }

    /**
     * Recherche récursive d'un container par l'ID de son modèle.
     * Compatible avec la structure container.children d'Elementor.
     *
     * @param {Object} container  Container parent.
     * @param {string} targetId   ID du modèle recherché.
     * @returns {Object|null}
     */
    function findContainerById(container, targetId) {
        if (!container) {
            return null;
        }

        // Vérifier le container courant
        if (container.model && container.model.get('id') === targetId) {
            return container;
        }

        // Parcourir les enfants
        var children = container.children;
        if (!children) {
            return null;
        }

        // Gérer les différents formats de children (array, collection Backbone)
        var childList = children.models || children;
        if (typeof childList.forEach !== 'function') {
            return null;
        }

        for (var i = 0; i < childList.length; i++) {
            var child = childList[i];

            // Si c'est un modèle Backbone, chercher la vue/container correspondante
            var childContainer = child.container || child;

            var found = findContainerById(childContainer, targetId);
            if (found) {
                return found;
            }
        }

        return null;
    }

    // Initialisation : attendre que l'éditeur Elementor soit prêt
    $(window).on('elementor:init', function () {
        // Petit délai pour s'assurer que l'UI Elementor est complètement chargée
        setTimeout(createPanel, 1000);
    });

    // Fallback si l'événement elementor:init est déjà passé
    if (typeof elementor !== 'undefined') {
        $(document).ready(function () {
            setTimeout(createPanel, 1500);
        });
    }

})(jQuery);
