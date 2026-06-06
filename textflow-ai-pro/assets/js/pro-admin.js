/**
 * TextFlow AI Pro — JavaScript admin commun.
 * Gère les interactions CRUD pour Brand Voice et Custom Templates.
 *
 * Les pages Bulk Generator et Historique ont leur propre JS inline
 * (directement dans la méthode render_admin_page() de chaque classe).
 */
(function ($) {
    'use strict';

    var cfg   = window.txflowPro || {};
    var REST  = cfg.restUrl || '';
    var NONCE = cfg.restNonce || '';

    // =========================================================
    // Utilitaires
    // =========================================================

    function apiRequest(method, path, data) {
        return $.ajax({
            url: REST + path,
            method: method,
            contentType: 'application/json',
            headers: { 'X-WP-Nonce': NONCE },
            data: data ? JSON.stringify(data) : undefined,
        });
    }

    function flashBtn($btn, text, duration) {
        var original = $btn.text();
        $btn.text(text).prop('disabled', true);
        setTimeout(function () {
            $btn.text(original).prop('disabled', false);
        }, duration || 1500);
    }

    // =========================================================
    // Brand Voice
    // =========================================================

    var $bvForm   = $('#txflow-bv-form');
    var $bvList   = $('#txflow-bv-list');
    var $bvSubmit = $('#bv-submit-btn');
    var $bvCancel = $('#bv-cancel-btn');
    var $bvEditId = $('#bv-edit-id');

    if ($bvForm.length) {

        // Soumettre le formulaire (créer ou modifier)
        $bvForm.on('submit', function (e) {
            e.preventDefault();

            var id     = $bvEditId.val();
            var action = id ? 'PUT' : 'POST';
            var path   = id ? '/brand-voices/' + id : '/brand-voices';

            var payload = {
                name:     $('#bv-name').val().trim(),
                industry: $('#bv-industry').val().trim(),
                tone:     $('#bv-tone').val(),
                persona:  $('#bv-persona').val().trim(),
                notes:    $('#bv-notes').val().trim(),
            };

            $bvSubmit.text(cfg.i18n && cfg.i18n.saving || 'Enregistrement…').prop('disabled', true);

            apiRequest(action, path, payload)
                .done(function () {
                    location.reload();
                })
                .fail(function (xhr) {
                    var msg = xhr.responseJSON && xhr.responseJSON.message
                        ? xhr.responseJSON.message
                        : 'Erreur lors de l\'enregistrement.';
                    alert(msg);
                    $bvSubmit.text('Créer le profil').prop('disabled', false);
                });
        });

        // Éditer un profil existant
        $(document).on('click', '.txflow-bv-edit-btn', function () {
            var voice = JSON.parse($(this).attr('data-voice'));
            $bvEditId.val(voice.id);
            $('#bv-name').val(voice.name);
            $('#bv-industry').val(voice.industry || '');
            $('#bv-tone').val(voice.tone || '');
            $('#bv-persona').val(voice.persona || '');
            $('#bv-notes').val(voice.notes || '');
            $bvSubmit.text('Enregistrer les modifications');
            $bvCancel.show();
            $('html, body').animate({ scrollTop: $('#txflow-bv-form-card').offset().top - 40 }, 300);
        });

        // Annuler l'édition
        $bvCancel.on('click', function () {
            $bvForm[0].reset();
            $bvEditId.val('');
            $bvSubmit.text('Créer le profil');
            $bvCancel.hide();
        });

        // Activer un profil
        $(document).on('click', '.txflow-bv-activate-btn', function () {
            var $btn = $(this);
            var id   = $btn.data('id');
            flashBtn($btn, 'Activation…');
            apiRequest('POST', '/brand-voices/' + id + '/activate')
                .done(function () {
                    location.reload();
                });
        });

        // Supprimer un profil
        $(document).on('click', '.txflow-bv-delete-btn', function () {
            if (!confirm(cfg.i18n && cfg.i18n.confirm_del || 'Supprimer ce profil ?')) return;
            var $btn = $(this);
            var id   = $btn.data('id');
            flashBtn($btn, cfg.i18n && cfg.i18n.deleting || 'Suppression…');
            apiRequest('DELETE', '/brand-voices/' + id)
                .done(function () {
                    $btn.closest('.txflow-bv-item').fadeOut(300, function () { $(this).remove(); });
                });
        });
    }

    // =========================================================
    // Custom Templates
    // =========================================================

    var $tplForm   = $('#txflow-tpl-form');
    var $tplList   = $('#txflow-tpl-list');
    var $tplSubmit = $('#tpl-submit-btn');
    var $tplCancel = $('#tpl-cancel-btn');
    var $tplEditId = $('#tpl-edit-id');

    if ($tplForm.length) {

        // Soumettre (créer ou modifier)
        $tplForm.on('submit', function (e) {
            e.preventDefault();

            var id     = $tplEditId.val();
            var action = id ? 'PUT' : 'POST';
            var path   = id ? '/custom-templates/' + id : '/custom-templates';

            var payload = {
                label:  $('#tpl-label').val().trim(),
                prompt: $('#tpl-prompt').val().trim(),
            };

            $tplSubmit.text(cfg.i18n && cfg.i18n.saving || 'Enregistrement…').prop('disabled', true);

            apiRequest(action, path, payload)
                .done(function () {
                    location.reload();
                })
                .fail(function (xhr) {
                    var msg = xhr.responseJSON && xhr.responseJSON.message
                        ? xhr.responseJSON.message
                        : 'Erreur.';
                    alert(msg);
                    $tplSubmit.text('Créer le template').prop('disabled', false);
                });
        });

        // Éditer
        $(document).on('click', '.txflow-tpl-edit-btn', function () {
            var tpl = JSON.parse($(this).attr('data-tpl'));
            $tplEditId.val(tpl.id);
            $('#tpl-label').val(tpl.label);
            $('#tpl-prompt').val(tpl.prompt);
            $tplSubmit.text('Enregistrer les modifications');
            $tplCancel.show();
            $('html, body').animate({ scrollTop: $tplForm.offset().top - 40 }, 300);
        });

        // Annuler
        $tplCancel.on('click', function () {
            $tplForm[0].reset();
            $tplEditId.val('');
            $tplSubmit.text('Créer le template');
            $tplCancel.hide();
        });

        // Supprimer
        $(document).on('click', '.txflow-tpl-delete-btn', function () {
            if (!confirm(cfg.i18n && cfg.i18n.confirm_del || 'Supprimer ce template ?')) return;
            var $btn = $(this);
            var id   = $btn.data('id');
            flashBtn($btn, cfg.i18n && cfg.i18n.deleting || 'Suppression…');
            apiRequest('DELETE', '/custom-templates/' + id)
                .done(function () {
                    $btn.closest('tr').fadeOut(300, function () { $(this).remove(); });
                });
        });
    }

})(jQuery);
