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
        console.error('[AI Content Filler] aicfConfig non trouvé. Le script ne peut pas démarrer.');
        return;
    }

    var config = aicfConfig;
    var isGenerating = false;
    var panelCreated = false; // Garde contre la double création

    /**
     * Crée et injecte le panneau HTML dans l'éditeur.
     * Protégé contre les appels multiples.
     */
    function createPanel() {
        // Empêcher la création en double
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
                    '<button id="aicf-generate-btn" type="button">&#10024; Générer le contenu</button>' +
                    '<div id="aicf-status" class="aicf-status-idle">' + config.i18n.idle + '</div>' +
                '</div>' +
            '</div>';

        $('body').append(panelHTML);

        // Binding des événements via délégation pour éviter tout problème de timing
        $(document).on('click', '#aicf-generate-btn', onGenerateClick);
        $(document).on('click', '#aicf-panel-toggle', onTogglePanel);
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
     * Compatible avec les différentes versions d'Elementor (Container API + ancien layout).
     *
     * @param {Object} container  Container Elementor (section, colonne, widget…).
     * @returns {Array}           Liste de { id, type, current_text }.
     */
    function scanWidgets(container) {
        var widgets = [];

        if (!container) {
            return widgets;
        }

        // Récupérer les enfants — structure variable selon la version d'Elementor
        var children = null;

        if (container.children && container.children.length > 0) {
            // Container API (Elementor 3.x+) — children est un tableau de Containers
            children = container.children;
        } else if (container.model && container.model.get && container.model.get('elements')) {
            // Ancien format : les éléments enfants sont dans le modèle
            var elements = container.model.get('elements');
            if (elements && elements.models) {
                // C'est une collection Backbone, on itère sur les modèles
                // et on essaie de retrouver leurs containers
                children = [];
                elements.models.forEach(function (childModel) {
                    if (childModel.container) {
                        children.push(childModel.container);
                    } else {
                        // Créer un pseudo-container pour parcourir récursivement
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
                        // Récupérer le titre — tester les deux méthodes possibles
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

            // Descente récursive dans les sections, colonnes, containers internes
            var childWidgets = scanWidgets(child);
            if (childWidgets.length > 0) {
                widgets = widgets.concat(childWidgets);
            }
        }

        return widgets;
    }

    /**
     * Récupère la valeur d'un setting d'un modèle Elementor.
     * Tente plusieurs méthodes selon la version d'Elementor.
     *
     * @param {Object} model      Modèle Backbone du widget.
     * @param {string} settingKey Clé du setting ('title', 'editor').
     * @returns {string}
     */
    function getSettingValue(model, settingKey) {
        // Méthode 1 : getSetting() (disponible sur la plupart des versions)
        if (typeof model.getSetting === 'function') {
            try {
                var val = model.getSetting(settingKey);
                if (val) return val;
            } catch (e) {}
        }

        // Méthode 2 : accès via l'objet settings du modèle
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

    /**
     * Handler du clic sur le bouton "Générer le contenu".
     */
    function onGenerateClick() {
        if (isGenerating) {
            return;
        }

        try {
            var prompt = $.trim($('#aicf-prompt').val());

            if (!prompt) {
                setStatus(config.i18n.empty_prompt, 'error');
                return;
            }

            // Vérifier que l'objet Elementor est disponible
            if (typeof elementor === 'undefined') {
                setStatus('Elementor n\'est pas chargé.', 'error');
                return;
            }

            // Récupérer l'ID de la page courante dans Elementor
            var pageId = 0;
            try {
                pageId = elementor.config.document.id ||
                         elementor.config.initial_document.id ||
                         0;
            } catch (e) {
                // Fallback : tenter de le lire depuis l'URL
                var match = window.location.search.match(/post=(\d+)/);
                if (match) {
                    pageId = parseInt(match[1], 10);
                }
            }

            if (!pageId) {
                setStatus('Impossible de déterminer l\'ID de la page.', 'error');
                return;
            }

            // Scanner les widgets de la page
            var rootContainer = null;
            try {
                var currentDocument = elementor.documents.getCurrent();
                if (currentDocument && currentDocument.container) {
                    rootContainer = currentDocument.container;
                }
            } catch (e) {
                console.warn('[AI Content Filler] Impossible d\'accéder au document courant:', e);
            }

            // Fallback : essayer via elementor.elements (ancienne API)
            if (!rootContainer) {
                try {
                    if (elementor.elements && elementor.elements.models) {
                        rootContainer = {
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

            if (!rootContainer) {
                setStatus('Document Elementor non accessible. Essayez de recharger l\'éditeur.', 'error');
                return;
            }

            var widgets = scanWidgets(rootContainer);

            if (!widgets.length) {
                setStatus(config.i18n.no_widgets, 'error');
                return;
            }

            // Lancer la génération
            isGenerating = true;
            setStatus(config.i18n.loading + ' (' + widgets.length + ' widgets détectés)', 'loading');
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
                    // Extraire le message d'erreur depuis les différents formats WP REST
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

                // Appliquer le contenu généré à chaque widget
                var applied = applyGeneratedContent(result.data.widgets, rootContainer);

                setStatus(config.i18n.success + ' (' + applied + ' widgets mis à jour) — ' + config.i18n.save_reminder, 'success');
            })
            .catch(function (err) {
                console.error('[AI Content Filler] Erreur:', err);
                setStatus(config.i18n.error + ' : ' + err.message, 'error');
            })
            .finally(function () {
                isGenerating = false;
                $('#aicf-generate-btn').prop('disabled', false);
            });

        } catch (err) {
            console.error('[AI Content Filler] Erreur inattendue:', err);
            setStatus(config.i18n.error + ' : ' + err.message, 'error');
            isGenerating = false;
            $('#aicf-generate-btn').prop('disabled', false);
        }
    }

    /**
     * Applique le contenu généré par Claude aux widgets Elementor.
     *
     * Utilise $e.run('document/elements/settings') pour modifier les settings
     * de façon compatible avec l'historique d'undo d'Elementor.
     *
     * @param {Array}  generatedWidgets  [ { id, content }, ... ]
     * @param {Object} rootContainer     Container racine du document Elementor.
     * @returns {number}                 Nombre de widgets mis à jour.
     */
    function applyGeneratedContent(generatedWidgets, rootContainer) {
        if (!generatedWidgets || !generatedWidgets.length) {
            return 0;
        }

        var appliedCount = 0;

        generatedWidgets.forEach(function (gw) {
            try {
                // Retrouver le container du widget dans l'arbre Elementor
                var widgetContainer = findContainerById(rootContainer, gw.id);

                if (!widgetContainer) {
                    console.warn('[AI Content Filler] Widget non trouvé dans l\'arbre:', gw.id);
                    return;
                }

                var widgetType = widgetContainer.model.get('widgetType');
                var settingKey = (widgetType === 'heading') ? 'title' : 'editor';

                // Méthode 1 : commande $e.run (compatible undo/redo, Elementor 3+)
                if (typeof $e !== 'undefined' && $e.run) {
                    var settings = {};
                    settings[settingKey] = gw.content;
                    $e.run('document/elements/settings', {
                        container: widgetContainer,
                        settings: settings
                    });
                    appliedCount++;
                }
                // Méthode 2 : modification directe du modèle settings
                else if (typeof widgetContainer.model.setSetting === 'function') {
                    widgetContainer.model.setSetting(settingKey, gw.content);
                    appliedCount++;
                }
                // Méthode 3 : accès bas niveau via l'objet settings
                else {
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

        // Forcer le rafraîchissement de la prévisualisation
        try {
            if (typeof elementor !== 'undefined' && elementor.channels) {
                elementor.channels.editor.trigger('change');
            }
        } catch (e) {}

        return appliedCount;
    }

    // ---------------------------------------------------------------
    // Initialisation — un seul chemin d'entrée, avec attente d'Elementor
    // ---------------------------------------------------------------

    function waitForElementorAndInit() {
        // Si Elementor est déjà prêt
        if (typeof elementor !== 'undefined' && elementor.documents) {
            createPanel();
            return;
        }

        // Sinon, écouter l'événement d'initialisation
        $(window).on('elementor:init', function () {
            setTimeout(createPanel, 500);
        });

        // Sécurité : si rien ne s'est passé après 5 secondes, forcer la création
        setTimeout(function () {
            if (!panelCreated) {
                createPanel();
            }
        }, 5000);
    }

    // Lancement
    if (document.readyState === 'complete' || document.readyState === 'interactive') {
        waitForElementorAndInit();
    } else {
        $(document).ready(waitForElementorAndInit);
    }

})(jQuery);
