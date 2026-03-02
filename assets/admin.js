/**
 * Social Posts Sync — Admin JavaScript
 *
 * Handles:
 *  - "Sync Now" AJAX button on the Sync tab
 *  - "Load Sources" AJAX button on the Sources tab (renders dynamic checkboxes)
 */

/* global scpsAdmin, jQuery */

(function ($) {
    'use strict';

    // -------------------------------------------------------------------------
    // Sync Now
    // -------------------------------------------------------------------------

    const $syncBtn    = $('#scps-sync-now');
    const $syncStatus = $('#scps-sync-status');

    /**
     * Set the button into "running" state (disabled with spinner).
     */
    function setSyncRunning() {
        $syncBtn.prop('disabled', true);
        $syncStatus.html(
            '<span class="spinner is-active scps-spinner-inline"></span> ' +
            escHtml(scpsAdmin.strings.syncing)
        );
    }

    /**
     * Set the button back to idle state.
     */
    function setSyncIdle() {
        $syncBtn.prop('disabled', false);
    }

    // Unlock button (shown when lock is stuck)
    var $unlockBtn = $('<button type="button" class="button scps-unlock-btn">🔓 ' + escHtml(scpsAdmin.strings.unlock) + '</button>').hide();
    if ($syncBtn.length) {
        $syncBtn.after($unlockBtn);
    }

    $unlockBtn.on('click', function () {
        $unlockBtn.prop('disabled', true);
        $.post(
            scpsAdmin.ajaxUrl,
            { action: 'scps_unlock_sync', nonce: scpsAdmin.nonce },
            function () {
                $unlockBtn.hide().prop('disabled', false);
                setSyncIdle();
                $syncStatus.html('<span class="scps-text-success">' + escHtml(scpsAdmin.strings.unlockDone) + '</span>');
            }
        );
    });

    if ($syncBtn.length) {
        // On page load, check if a sync is already running and lock the button
        $.post(
            scpsAdmin.ajaxUrl,
            { action: 'scps_sync_status', nonce: scpsAdmin.nonce },
            function (response) {
                if (response.success && response.data.running) {
                    setSyncRunning();
                    $unlockBtn.show();
                }
            }
        );

        $syncBtn.on('click', function () {
            setSyncRunning();
            $unlockBtn.hide();

            $.post(
                scpsAdmin.ajaxUrl,
                {
                    action : 'scps_sync_now',
                    nonce  : scpsAdmin.nonce,
                },
                function (response) {
                    if (response.success) {
                        setSyncIdle();
                        const log = response.data.log || {};
                        const msg = escHtml(scpsAdmin.strings.syncDone) +
                            ' (' +
                            escHtml(String(log.success || 0)) + ' succès, ' +
                            escHtml(String(log.errors  || 0)) + ' erreurs)';
                        $syncStatus.html('<span class="scps-text-success">' + msg + '</span>');
                    } else {
                        const isLocked = response.data && response.data.locked;
                        const errMsg   = (response.data && response.data.message)
                            ? escHtml(response.data.message)
                            : escHtml(scpsAdmin.strings.syncError);

                        if (isLocked) {
                            setSyncRunning();
                            $unlockBtn.show();
                        } else {
                            setSyncIdle();
                        }

                        const statusClass = isLocked ? 'scps-text-warning' : 'scps-text-error';
                        $syncStatus.html('<span class="' + statusClass + '">' + errMsg + '</span>');
                    }
                }
            ).fail(function () {
                setSyncIdle();
                $syncStatus.html('<span class="scps-text-error">' + escHtml(scpsAdmin.strings.syncError) + '</span>');
            });
        });
    }

    // -------------------------------------------------------------------------
    // Zone de danger (onglet Avancé)
    // -------------------------------------------------------------------------

    const $advancedStatus = $('#scps-advanced-status');

    function setAdvancedStatus(msg, cssClass) {
        $advancedStatus.html('<p class="' + escAttr(cssClass) + '">' + escHtml(msg) + '</p>');
    }

    // Re-sync complète (reset timestamps uniquement)
    $('#scps-reset-sync').on('click', function () {
        if (!confirm(scpsAdmin.strings.confirmReset)) { return; }
        $(this).prop('disabled', true);
        $advancedStatus.html('');

        $.post(scpsAdmin.ajaxUrl, { action: 'scps_reset_sync', nonce: scpsAdmin.nonce }, function (response) {
            $('#scps-reset-sync').prop('disabled', false);
            if (response.success) {
                setAdvancedStatus(response.data.message, 'scps-text-success');
            } else {
                setAdvancedStatus('Erreur lors de la réinitialisation.', 'scps-text-error');
            }
        }).fail(function () {
            $('#scps-reset-sync').prop('disabled', false);
            setAdvancedStatus('Erreur réseau.', 'scps-text-error');
        });
    });

    // Supprimer les publications uniquement
    $('#scps-purge-posts').on('click', function () {
        if (!confirm(scpsAdmin.strings.confirmPurgePosts)) { return; }
        $(this).prop('disabled', true);
        $advancedStatus.html('');

        $.post(scpsAdmin.ajaxUrl, { action: 'scps_purge_all', scope: 'posts', nonce: scpsAdmin.nonce }, function (response) {
            $('#scps-purge-posts').prop('disabled', false);
            if (response.success) {
                setAdvancedStatus(response.data.message, 'scps-text-success');
            } else {
                setAdvancedStatus('Erreur lors de la suppression.', 'scps-text-error');
            }
        }).fail(function () {
            $('#scps-purge-posts').prop('disabled', false);
            setAdvancedStatus('Erreur réseau.', 'scps-text-error');
        });
    });

    // Réinitialisation complète (tout supprimer)
    $('#scps-purge-all').on('click', function () {
        if (!confirm(scpsAdmin.strings.confirmPurgeAll)) { return; }
        if (!confirm(scpsAdmin.strings.confirmPurgeAllDouble)) { return; }
        $(this).prop('disabled', true);
        $advancedStatus.html('');

        $.post(scpsAdmin.ajaxUrl, { action: 'scps_purge_all', scope: 'all', nonce: scpsAdmin.nonce }, function (response) {
            $('#scps-purge-all').prop('disabled', false);
            if (response.success) {
                setAdvancedStatus(response.data.message + ' Le plugin a été réinitialisé.', 'scps-text-error');
            } else {
                setAdvancedStatus('Erreur lors de la réinitialisation complète.', 'scps-text-error');
            }
        }).fail(function () {
            $('#scps-purge-all').prop('disabled', false);
            setAdvancedStatus('Erreur réseau.', 'scps-text-error');
        });
    });

    // -------------------------------------------------------------------------
    // Sources — Ajouter / Valider / Supprimer
    // -------------------------------------------------------------------------

    const $sourcesList  = $('#scps-sources-list');
    const $sourcesForm  = $('#scps-sources-form');
    const $validateBtn  = $('#scps-validate-source');
    const $validateLoad = $('#scps-validate-loading');
    const $validateRes  = $('#scps-validate-result');
    const $identInput   = $('#scps-source-identifier');

    /**
     * In-memory state: sources currently in the list.
     * Initialised from PHP-rendered DOM on page load.
     * Structure: { facebook: [{id, name}, ...], instagram: [{id, name}, ...] }
     */
    var selectedSources = {
        facebook:  [],
        instagram: [],
    };

    // Initialise depuis le DOM PHP (li[data-platform][data-id])
    $sourcesList.find('li[data-platform]').each(function () {
        var platform = String($(this).data('platform') || '');
        var id       = String($(this).data('id')       || '');
        var name     = String($(this).data('name')     || '');
        if (platform && id && selectedSources[platform]) {
            selectedSources[platform].push({ id: id, name: name });
        }
    });

    /**
     * Inject a hidden input with the JSON-encoded sources before form submit.
     */
    function injectSourcesJson() {
        $sourcesForm.find('input[name="scps_sources_json"]').remove();
        $('<input type="hidden" name="scps_sources_json">')
            .val(JSON.stringify(selectedSources))
            .appendTo($sourcesForm);
    }

    if ($sourcesForm.length) {
        $sourcesForm.on('submit', function () {
            injectSourcesJson();
        });
    }

    /**
     * Add a source to the in-memory state and re-render the list.
     *
     * @param {string} platform  'facebook' | 'instagram'
     * @param {string} id        Page ID or IG username
     * @param {string} name      Display name
     */
    function addSource(platform, id, name) {
        // Eviter les doublons
        var exists = selectedSources[platform].some(function (s) { return s.id === id; });
        if (!exists) {
            selectedSources[platform].push({ id: id, name: name });
        }
        renderSourcesList();
    }

    /**
     * Remove a source from the in-memory state.
     *
     * @param {string} platform 'facebook' | 'instagram'
     * @param {string} id       Source ID
     */
    function removeSource(platform, id) {
        selectedSources[platform] = selectedSources[platform].filter(function (s) {
            return s.id !== id;
        });
    }

    /**
     * Rebuild the #scps-sources-list HTML from the in-memory state.
     */
    function renderSourcesList() {
        var fb  = selectedSources.facebook  || [];
        var ig  = selectedSources.instagram || [];

        if (fb.length === 0 && ig.length === 0) {
            $sourcesList.html(
                '<p class="description">Aucune source configurée. Utilisez le formulaire ci-dessus pour en ajouter.</p>'
            );
            return;
        }

        var html = '';

        if (fb.length > 0) {
            html += '<h4>Pages Facebook</h4><ul class="scps-source-list">';
            fb.forEach(function (s) {
                var id   = escHtml(s.id   || '');
                var name = escHtml(s.name || s.id || '');
                html += '<li data-platform="facebook" data-id="' + escAttr(s.id) + '" data-name="' + escAttr(s.name) + '">';
                html += '<strong>' + name + '</strong> <small class="scps-muted">(' + id + ')</small>';
                html += ' <button type="button" class="button button-small scps-remove-source">Supprimer</button>';
                html += '</li>';
            });
            html += '</ul>';
        }

        if (ig.length > 0) {
            html += '<h4>Comptes Instagram</h4><ul class="scps-source-list">';
            ig.forEach(function (s) {
                var id   = escHtml(s.id   || '');
                var name = escHtml(s.name || s.id || '');
                html += '<li data-platform="instagram" data-id="' + escAttr(s.id) + '" data-name="' + escAttr(s.name) + '">';
                html += '<span class="dashicons dashicons-instagram scps-inline-icon"></span>';
                html += '<strong>' + name + '</strong> <small class="scps-muted">@' + id + '</small>';
                html += ' <button type="button" class="button button-small scps-remove-source">Supprimer</button>';
                html += '</li>';
            });
            html += '</ul>';
        }

        $sourcesList.html(html);
    }

    // -------------------------------------------------------------------------
    // Importer depuis mon compte (scps_load_sources)
    // -------------------------------------------------------------------------

    const $loadBtn      = $('#scps-load-sources');
    const $loadSpinner  = $('#scps-sources-loading');
    const $importResult = $('#scps-import-result');

    if ($loadBtn.length) {
        $loadBtn.on('click', function () {
            $loadBtn.prop('disabled', true);
            $loadSpinner.show();
            $importResult.html('');

            $.post(
                scpsAdmin.ajaxUrl,
                { action: 'scps_load_sources', nonce: scpsAdmin.nonce },
                function (response) {
                    $loadBtn.prop('disabled', false);
                    $loadSpinner.hide();

                    if (!response.success) {
                        var errMsg = (response.data && response.data.message)
                            ? escHtml(response.data.message)
                            : 'Erreur lors du chargement.';
                        $importResult.html('<p class="scps-error">' + errMsg + '</p>');
                        return;
                    }

                    var pages    = response.data.pages    || [];
                    var accounts = response.data.accounts || [];

                    if (pages.length === 0 && accounts.length === 0) {
                        $importResult.html('<p class="description">Aucune page ou compte trouvé sur votre compte Meta.</p>');
                        return;
                    }

                    var html = '<p class="description">Cliquez sur <strong>+</strong> pour ajouter une source à la liste.</p>';

                    if (pages.length > 0) {
                        html += '<h4>Pages Facebook de votre compte</h4><ul class="scps-source-list">';
                        pages.forEach(function (page) {
                            var rawId   = String(page.id   || '');
                            var rawName = String(page.name || rawId);
                            var alreadyIn = selectedSources.facebook.some(function (s) { return s.id === rawId; });
                            var avatar = page.picture && page.picture.data && page.picture.data.url
                                ? '<img src="' + escAttr(page.picture.data.url) + '" class="scps-source-avatar" alt="">'
                                : '';
                            html += '<li>';
                            html += avatar;
                            html += '<strong>' + escHtml(rawName) + '</strong> <small class="scps-muted">(' + escHtml(rawId) + ')</small>';
                            if (alreadyIn) {
                                html += ' <span class="scps-muted">— déjà ajoutée</span>';
                            } else {
                                html += ' <button type="button" class="button button-small scps-import-source"'
                                    + ' data-platform="facebook"'
                                    + ' data-id="'   + escAttr(rawId)   + '"'
                                    + ' data-name="' + escAttr(rawName) + '">+</button>';
                            }
                            html += '</li>';
                        });
                        html += '</ul>';
                    }

                    if (accounts.length > 0) {
                        html += '<h4>Comptes Instagram de votre compte</h4><ul class="scps-source-list">';
                        accounts.forEach(function (account) {
                            var rawId   = String(account.id       || '');
                            var rawName = String(account.name     || account.username || rawId);
                            var rawUser = String(account.username || rawId);
                            // Pour les comptes propres on stocke l'ID numérique
                            var alreadyIn = selectedSources.instagram.some(function (s) { return s.id === rawId; });
                            html += '<li>';
                            html += '<span class="dashicons dashicons-instagram scps-inline-icon"></span>';
                            html += '<strong>' + escHtml(rawName) + '</strong>';
                            if (rawUser) { html += ' <small class="scps-muted">@' + escHtml(rawUser) + '</small>'; }
                            if (alreadyIn) {
                                html += ' <span class="scps-muted">— déjà ajouté</span>';
                            } else {
                                html += ' <button type="button" class="button button-small scps-import-source"'
                                    + ' data-platform="instagram"'
                                    + ' data-id="'   + escAttr(rawId)   + '"'
                                    + ' data-name="' + escAttr(rawName) + '">+</button>';
                            }
                            html += '</li>';
                        });
                        html += '</ul>';
                    }

                    $importResult.html(html);
                }
            ).fail(function () {
                $loadBtn.prop('disabled', false);
                $loadSpinner.hide();
                $importResult.html('<p class="scps-error">Erreur réseau.</p>');
            });
        });

        // Délégation pour les boutons "+" dans les résultats d'import
        $(document).on('click', '.scps-import-source', function () {
            var $btn     = $(this);
            var platform = String($btn.data('platform') || '');
            var id       = String($btn.data('id')       || '');
            var name     = String($btn.data('name')     || '');
            addSource(platform, id, name);
            $btn.replaceWith('<span class="scps-muted">— ajouté</span>');
        });
    }

    // Délégation pour le bouton Supprimer (DOM initial + re-renders)
    $(document).on('click', '.scps-remove-source', function () {
        var $li      = $(this).closest('li');
        var platform = String($li.data('platform') || '');
        var id       = String($li.data('id')       || '');
        removeSource(platform, id);
        $li.remove();

        // Si la liste est vide, afficher le message vide
        if (selectedSources.facebook.length === 0 && selectedSources.instagram.length === 0) {
            $sourcesList.html(
                '<p class="description">Aucune source configurée. Utilisez le formulaire ci-dessus pour en ajouter.</p>'
            );
        }
    });

    // Bouton Valider
    if ($validateBtn.length) {
        $validateBtn.on('click', function () {
            var identifier = $.trim($identInput.val());
            if (!identifier) {
                $validateRes.html('<p class="scps-error">Veuillez entrer un identifiant.</p>');
                return;
            }

            $validateBtn.prop('disabled', true);
            $validateLoad.show();
            $validateRes.html('');

            $.post(
                scpsAdmin.ajaxUrl,
                {
                    action     : 'scps_validate_source',
                    nonce      : scpsAdmin.nonce,
                    identifier : identifier,
                },
                function (response) {
                    $validateBtn.prop('disabled', false);
                    $validateLoad.hide();

                    if (!response.success) {
                        var errMsg = (response.data && response.data.message)
                            ? escHtml(response.data.message)
                            : escHtml(scpsAdmin.strings.validateError);
                        $validateRes.html('<p class="scps-error">' + errMsg + '</p>');
                        return;
                    }

                    var info     = response.data;
                    var avatar   = info.avatar
                        ? '<img src="' + escAttr(info.avatar) + '" class="scps-source-avatar" alt="">'
                        : '';
                    var label    = info.platform === 'instagram'
                        ? '@' + escHtml(info.id)
                        : escHtml(info.id);
                    var html     = '<div class="scps-validate-ok">'
                        + avatar
                        + '<span><strong>' + escHtml(info.name || info.id) + '</strong>'
                        + ' <small class="scps-muted">(' + label + ')</small></span>'
                        + ' <button type="button" id="scps-add-source-btn" class="button button-primary">'
                        + escHtml(scpsAdmin.strings.addSource)
                        + '</button></div>';

                    $validateRes.html(html);

                    $('#scps-add-source-btn').one('click', function () {
                        addSource(info.platform, String(info.id), String(info.name || info.id));
                        $identInput.val('');
                        $validateRes.html('');
                    });
                }
            ).fail(function () {
                $validateBtn.prop('disabled', false);
                $validateLoad.hide();
                $validateRes.html('<p class="scps-error">Erreur réseau.</p>');
            });
        });

        // Valider aussi sur Entrée dans le champ
        $identInput.on('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                $validateBtn.trigger('click');
            }
        });
    }

    // -------------------------------------------------------------------------
    // Utilities
    // -------------------------------------------------------------------------

    /**
     * Escape a string for safe insertion as HTML text content.
     *
     * @param  {string} str
     * @return {string}
     */
    function escHtml(str) {
        return String(str)
            .replace(/&/g,  '&amp;')
            .replace(/</g,  '&lt;')
            .replace(/>/g,  '&gt;')
            .replace(/"/g,  '&quot;')
            .replace(/'/g,  '&#039;');
    }

    /**
     * Escape a string for safe use as an HTML attribute value.
     *
     * @param  {string} str
     * @return {string}
     */
    function escAttr(str) {
        return String(str)
            .replace(/&/g,  '&amp;')
            .replace(/"/g,  '&quot;')
            .replace(/'/g,  '&#039;')
            .replace(/</g,  '&lt;')
            .replace(/>/g,  '&gt;');
    }

}(jQuery));
