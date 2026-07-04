import './bootstrap.js';
/*
 * Welcome to your app's main JavaScript file!
 *
 * This file will be included onto the page via the importmap() Twig function,
 * which should already be in your base.html.twig.
 */
import './styles/app.css';
import './styles/interactive-menu.css';
import { Chart } from 'chart.js';
console.log('This log comes from assets/app.js - welcome to AssetMapper! 🎉');

/**
 * @param {string} url 
 * @param {htmlElement} elementHtml 
 * @param {string} texteAccompagnement 
 */
export function defineIcone(url, elementHtml, texteAccompagnement) {
    var iconeData = this.getIconeLocale(url);
    if (iconeData != null) {
        this.setIcone(iconeData, elementHtml, texteAccompagnement);
    } else {
        var data = url.split('/'); // var url = '/admin/entreprise/geticon/1/add/20';
        this.downloadIcone(elementHtml, " " + texteAccompagnement, data[4], data[5], data[6]);
    }
}

/**
 * @param {HTMLElement} htmlElement 
 * @param {string} valeurDisplay 
 * @param {string} icone 
 */
export function setIcone(icone, htmlElement, texteAccompagnement) {
    htmlElement.innerHTML = icone + " " + texteAccompagnement;
}

/**
 * 
 * @param {HTMLElement} elementHtml 
 * @param {int} inAction 
 * @param {string} icone 
 * @param {string} texteAAjouter 
 * @param {int} taille
 *  
 */
export function downloadIcone (elementHtml, texteAAjouter, inAction, icone, taille) {
    //Chargement de l'icones du bouton
    var url = this.getIconeUrl(inAction, icone, taille); //'/admin/entreprise/geticon/' + inAction + '/' + icone + '/' + taille;
    fetch(url) // L'URL de votre route Symfony
        .then(response => response.text())
        .then(htmlData => {
            this.updatTabIcones(url, htmlData);
            elementHtml.innerHTML = htmlData + texteAAjouter;
        })
        .catch(error => {
            console.error('Erreur lors du chargement du fragment:', error);
        });
}

/**
 * 
 * @param {string} url 
 * @param {HTMLElement} htmlData 
 */
export function updatTabIcones(url, htmlData) {
    if (this.getCookies(url) == null) {
        this.saveCookie(url, htmlData);
    }
}


/**
 * 
 * @param {string} nom 
*/
export function getCookies(nom) {
    const nomEQ = nom + "=9111986";
    const ca = document.cookie.split(';');
    for (let i = 0; i < ca.length; i++) {
        let c = ca[i];
        while (c.charAt(0) === ' ') {
            c = c.substring(1, c.length);
        }
        if (c.indexOf(nomEQ) === 0) {
            return c.substring(nomEQ.length, c.length);
        }
    }
    return null;
}

/**
 * 
 * @param {int} dossier 
 * @param {string} image 
 * @param {int} taille 
 * @returns {string}
 */
export function getIconeUrl(dossier, image, taille) {
    return '/admin/entreprise/geticon/' + dossier + '/' + image + '/' + taille;
}

/**
 * 
 * @param {string} url 
 */
export function getIconeLocale(url) {
    return this.getCookies(url);
}

/**
 * @param {string} nom 
 * @param {string} valeur 
 */
export function saveCookie(nom, valeur) {
    var dateExpiration = new Date();
    dateExpiration.setDate(dateExpiration.getDate() + 7)
    document.cookie = nom + "=9111986" + valeur + "; expires=" + dateExpiration.toUTCString + "; path=/";
}

// ── Filtrage des tâches du tableau de bord (toggles Urgentes/Prioritaires/Futures) ──
(function () {
    var _activeMode = 'urgente';

    function dbTaskApplyFilter() {
        var searchInput = document.querySelector('[aria-label="Filtrer les tâches"]');
        var q = searchInput ? searchInput.value.toLowerCase() : '';
        document.querySelectorAll('.db-task-item').forEach(function (el) {
            var matchMode   = el.dataset.urgency === _activeMode;
            var label       = el.querySelector('.db-task-label');
            var matchSearch = !q || (label && label.textContent.toLowerCase().indexOf(q) >= 0);
            el.style.display = (matchMode && matchSearch) ? '' : 'none';
        });
    }

    function dbTaskToggle(btn) {
        _activeMode = btn.dataset.mode;
        document.querySelectorAll('#dbTaskToggleBar .db-task-toggle').forEach(function (b) {
            b.classList.toggle('active', b === btn);
        });
        dbTaskApplyFilter();
    }

    function dbTaskSearch(input) {
        dbTaskApplyFilter();
    }

    function dbTaskNew(btn) {
        var eid = btn.dataset.entrepriseId;
        var iid = btn.dataset.inviteId;
        document.dispatchEvent(new CustomEvent('app:loading.start'));
        document.dispatchEvent(new CustomEvent('app:boite-dialogue:init-request', {
            detail: {
                entityFormCanvas: {
                    parametres: {
                        titre_creation:      'Nouvelle tâche',
                        endpoint_form_url:   '/admin/tache/api/get-form',
                        endpoint_submit_url: '/admin/tache/api/submit'
                    }
                },
                entity:         {},
                isCreationMode: true,
                context: { idEntreprise: parseInt(eid) || undefined, idInvite: parseInt(iid) || undefined, _dashboardReload: true }
            }
        }));
        setTimeout(function () {
            document.dispatchEvent(new CustomEvent('app:loading.stop'));
        }, 600);
    }

    function dbBlockAdd(btn, entityRouteName, entityTitle) {
        var eid = btn.dataset.entrepriseId;
        var iid = btn.dataset.inviteId;
        document.dispatchEvent(new CustomEvent('app:loading.start'));
        document.dispatchEvent(new CustomEvent('app:boite-dialogue:init-request', {
            detail: {
                entityFormCanvas: {
                    parametres: {
                        titre_creation:      entityTitle,
                        endpoint_form_url:   '/admin/' + entityRouteName + '/api/get-form',
                        endpoint_submit_url: '/admin/' + entityRouteName + '/api/submit'
                    }
                },
                entity:         {},
                isCreationMode: true,
                context: { idEntreprise: parseInt(eid) || undefined, idInvite: parseInt(iid) || undefined, _dashboardReload: true }
            }
        }));
        setTimeout(function () {
            document.dispatchEvent(new CustomEvent('app:loading.stop'));
        }, 600);
    }

    // Rechargement AJAX du sidebar après toute création depuis le tableau de bord
    document.addEventListener('cerveau:event', function (e) {
        if (e.detail.type !== 'app:entity.saved') return;
        var uc = e.detail.payload && e.detail.payload.userContext;
        if (!uc || !uc._dashboardReload) return;

        var container = document.getElementById('db-sidebar-content');
        if (!container) return;
        var url = container.dataset.workspaceUrl;
        if (!url) return;

        fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.text();
            })
            .then(function (html) {
                var tmp = document.createElement('div');
                tmp.innerHTML = html;
                var newSidebar = tmp.querySelector('#db-sidebar-content');
                if (!newSidebar) return;
                container.innerHTML = newSidebar.innerHTML;
                document.querySelectorAll('#dbTaskToggleBar .db-task-toggle').forEach(function (b) {
                    b.classList.toggle('active', b.dataset.mode === _activeMode);
                });
                setTimeout(dbTaskApplyFilter, 30);
                if (typeof window.initDbMetaTips === 'function') window.initDbMetaTips();
                if (typeof window.initDbFeedbacks === 'function') window.initDbFeedbacks();
                if (typeof window.initDbUsers === 'function') window.initDbUsers();
            })
            .catch(function (err) {
                console.warn('[dashboard] sidebar reload failed:', err);
            });
    });

    // Rechargement AJAX fiable via dispatch direct depuis document (double filet)
    document.addEventListener('app:dashboard.sidebar.reload', function () {
        var container = document.getElementById('db-sidebar-content');
        if (!container) return;
        var url = container.dataset.workspaceUrl;
        if (!url) return;
        fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.text();
            })
            .then(function (html) {
                var tmp = document.createElement('div');
                tmp.innerHTML = html;
                var ns = tmp.querySelector('#db-sidebar-content');
                if (!ns) return;
                container.innerHTML = ns.innerHTML;
                document.querySelectorAll('#dbTaskToggleBar .db-task-toggle').forEach(function (b) {
                    b.classList.toggle('active', b.dataset.mode === _activeMode);
                });
                setTimeout(dbTaskApplyFilter, 30);
                if (typeof window.initDbMetaTips === 'function') window.initDbMetaTips();
                if (typeof window.initDbFeedbacks === 'function') window.initDbFeedbacks();
                if (typeof window.initDbUsers === 'function') window.initDbUsers();
            })
            .catch(function (err) {
                console.warn('[dashboard] sidebar reload (direct) failed:', err);
            });
    });

    window.dbTaskToggle      = dbTaskToggle;
    window.dbTaskSearch      = dbTaskSearch;
    window.dbTaskApplyFilter = dbTaskApplyFilter;
    window.dbTaskNew         = dbTaskNew;
    window.dbBlockAdd        = dbBlockAdd;
}());

// ── Renouvellements à venir (toggles + recherche + menu contextuel) ──────────
(function () {
    var _activeRenewMode = 'j30';
    var _el  = null;
    var _aid = null;
    var _eid = null;
    var _pid = null;

    function dbRenewApplyFilter() {
        var searchInput = document.querySelector('[aria-label="Filtrer les renouvellements"]');
        var q = searchInput ? searchInput.value.toLowerCase() : '';
        document.querySelectorAll('.db-renew-item').forEach(function (el) {
            var matchMode   = el.dataset.group === _activeRenewMode;
            var matchSearch = !q || (el.dataset.searchText || '').indexOf(q) >= 0;
            el.style.display = (matchMode && matchSearch) ? '' : 'none';
        });
    }

    function dbRenewToggle(btn) {
        _activeRenewMode = btn.dataset.mode;
        document.querySelectorAll('#dbRenewToggleBar .db-task-toggle').forEach(function (b) {
            var isActive = b === btn;
            b.classList.toggle('active', isActive);
            b.setAttribute('aria-pressed', isActive ? 'true' : 'false');
        });
        dbRenewApplyFilter();
    }

    function dbRenewSearch() {
        dbRenewApplyFilter();
    }

    function dbRenewCtxOpen(event, el) {
        event.preventDefault();
        event.stopPropagation();
        _el  = el;
        _aid = el.dataset.avenantId;
        _eid = el.dataset.entrepriseId;
        _pid = el.dataset.pisteId;

        var menu = document.getElementById('dbRenewCtxMenu');
        if (!menu) return;
        var mw = 220, mh = 80;
        var left = (event.clientX + mw > window.innerWidth)  ? window.innerWidth  - mw - 6 : event.clientX;
        var top  = (event.clientY + mh > window.innerHeight) ? window.innerHeight - mh - 6 : event.clientY;
        menu.style.left    = left + 'px';
        menu.style.top     = top  + 'px';
        menu.style.display = 'block';

        var pisteLabel = document.getElementById('dbRenewCtxPisteLabel');
        if (pisteLabel) {
            pisteLabel.textContent = _pid ? 'Modifier la piste de renouvellement' : 'Créer une piste de renouvellement';
        }

        setTimeout(function () {
            document.addEventListener('click', function hide() {
                var m = document.getElementById('dbRenewCtxMenu');
                if (m) m.style.display = 'none';
                document.removeEventListener('click', hide);
            }, { once: true });
        }, 0);
    }

    function dbRenewCtxPiste() {
        var menu = document.getElementById('dbRenewCtxMenu');
        if (menu) menu.style.display = 'none';

        var hasPiste = !!_pid;
        document.dispatchEvent(new CustomEvent('app:loading.start'));
        document.dispatchEvent(new CustomEvent('app:boite-dialogue:init-request', {
            detail: {
                entityFormCanvas: {
                    parametres: hasPiste ? {
                        titre_modification:  'Modifier la piste de renouvellement',
                        endpoint_form_url:   '/admin/piste/api/get-form',
                        endpoint_submit_url: '/admin/piste/api/submit'
                    } : {
                        titre_creation:      'Nouvelle piste de renouvellement',
                        endpoint_form_url:   '/admin/piste/api/get-form',
                        endpoint_submit_url: '/admin/piste/api/submit'
                    }
                },
                entity:         hasPiste ? { id: parseInt(_pid) } : {},
                isCreationMode: !hasPiste,
                context: {
                    idEntreprise:     parseInt(_eid) || undefined,
                    idAvenant:        !hasPiste && _aid ? parseInt(_aid) : undefined,
                    _dashboardReload: true
                }
            }
        }));
        setTimeout(function () {
            document.dispatchEvent(new CustomEvent('app:loading.stop'));
        }, 600);
    }

    function dbRenewCtxNoRenewal() {
        var menu = document.getElementById('dbRenewCtxMenu');
        if (menu) menu.style.display = 'none';
        if (!_aid) return;

        var tipEl    = _el ? _el.querySelector('[data-renew-tip]') : null;
        var client   = tipEl ? esc(tipEl.textContent.trim())               : '—';
        var risque   = tipEl ? esc(tipEl.dataset.renewRisque   || '—')     : '—';
        var assureur = tipEl ? esc(tipEl.dataset.renewAssureur || '—')     : '—';
        var periode  = tipEl ? esc(tipEl.dataset.renewPeriode  || '—')     : '—';

        function esc(s) {
            return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        }

        var row = '<div class="d-flex gap-2 mb-1"><span class="text-muted" style="min-width:4.5rem;">';
        var bodyHtml =
            '<p class="mb-2">La piste liée à cet avenant sera marquée comme <strong>Temporaire non renouvellable</strong>. Elle disparaîtra du tableau des renouvellements à venir.</p>' +
            '<div class="p-2 rounded small" style="background:#f8f9fa;border:1px solid #dee2e6;">' +
                row + 'Client</span><strong>' + client + '</strong></div>' +
                row + 'Risque</span><span>' + risque + '</span></div>' +
                row + 'Assureur</span><span>' + assureur + '</span></div>' +
                '<div class="d-flex gap-2"><span class="text-muted" style="min-width:4.5rem;">Période</span><span>' + periode + '</span></div>' +
            '</div>';

        document.dispatchEvent(new CustomEvent('ui:confirmation.request', {
            detail: {
                title: 'Ne pas renouveler',
                body: bodyHtml,
                headerClass: 'bg-cobalt text-white',
                confirmClass: 'btn btn-cobalt',
                showIrreversible: false,
                onConfirm: {
                    type: 'renew:set-not-renewable',
                    payload: { avenantId: _aid }
                }
            }
        }));
    }

    window.dbRenewToggle         = dbRenewToggle;
    window.dbRenewSearch         = dbRenewSearch;
    window.dbRenewApplyFilter    = dbRenewApplyFilter;
    window.dbRenewCtxOpen        = dbRenewCtxOpen;
    window.dbRenewCtxPiste       = dbRenewCtxPiste;
    window.dbRenewCtxNoRenewal   = dbRenewCtxNoRenewal;
}());

// ── Dashboard request queue — séquentiel, déduplication, stagger 200 ms ─────
(function () {
    var _queue   = [];
    var _active  = false;
    var _pending = Object.create(null); // url → true

    function _run() {
        if (_active || !_queue.length) return;
        _active = true;
        var job = _queue.shift();
        delete _pending[job.url];
        fetch(job.url, job.opts)
            .then(function (r) { job.resolve(r); })
            .catch(function (e) { job.reject(e); })
            .finally(function () {
                _active = false;
                setTimeout(_run, 200);
            });
    }

    window.dbFetch = function (url, opts) {
        if (_pending[url]) return Promise.resolve(null); // déjà en file
        _pending[url] = true;
        return new Promise(function (resolve, reject) {
            _queue.push({ url: url, opts: opts || {}, resolve: resolve, reject: reject });
            _run();
        });
    };
}());

// ── Auto-refresh renouvellements (3 min) ─────────────────────────────────────
(function () {
    var _renewTimer    = null;
    var _renewObserver = null;

    function _renewPad(n) { return n < 10 ? '0' + n : '' + n; }

    function _renewSetStatus(txt) {
        var el = document.getElementById('db-renew-last-update');
        if (el) el.textContent = txt;
    }

    function refreshRenouvellements() {
        var list = document.getElementById('db-renew-list');
        if (!list) return;
        var details = list.closest('details');
        if (details && !details.open) return;
        _renewSetStatus('Mise à jour en cours…');
        dbFetch(list.dataset.renewalsUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) {
                if (!r) return;
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.text();
            })
            .then(function (html) {
                if (!html) return;
                list.innerHTML = html;
                var now = new Date();
                _renewSetStatus('Dernière mise à jour à ' + _renewPad(now.getHours()) + ':' + _renewPad(now.getMinutes()));
                window.dbRenewApplyFilter();
            })
            .catch(function (err) {
                _renewSetStatus('Erreur de mise à jour');
                console.warn('[dashboard] renouvellements refresh failed:', err);
            });
    }

    function initDbRenouvellements() {
        var list = document.getElementById('db-renew-list');
        if (!list) return;
        var now = new Date();
        _renewSetStatus('Dernière mise à jour à ' + _renewPad(now.getHours()) + ':' + _renewPad(now.getMinutes()));
        if (_renewTimer) clearInterval(_renewTimer);
        _renewTimer = setInterval(refreshRenouvellements, 180000);
    }

    function _renewObserve() {
        if (document.getElementById('db-renew-list')) { initDbRenouvellements(); return; }
        if (_renewObserver) _renewObserver.disconnect();
        _renewObserver = new MutationObserver(function (mutations, obs) {
            if (document.getElementById('db-renew-list')) {
                obs.disconnect();
                _renewObserver = null;
                initDbRenouvellements();
            }
        });
        _renewObserver.observe(document.body, { childList: true, subtree: true });
    }
    _renewObserve();

    window.refreshRenouvellements = refreshRenouvellements;
}());

// ── Derniers encaissements (toggles + recherche) ─────────────────────────────
(function () {
    var _activeCashMode = 'j30';

    function dbCashApplyFilter() {
        var searchInput = document.querySelector('[aria-label="Filtrer les encaissements"]');
        var q = searchInput ? searchInput.value.toLowerCase() : '';
        document.querySelectorAll('.db-enc-item').forEach(function (el) {
            var matchMode   = el.dataset.group === _activeCashMode;
            var matchSearch = !q || (el.dataset.searchText || '').indexOf(q) >= 0;
            el.style.display = (matchMode && matchSearch) ? '' : 'none';
        });
    }

    function dbCashToggle(btn) {
        _activeCashMode = btn.dataset.mode;
        document.querySelectorAll('#dbCashToggleBar .db-task-toggle').forEach(function (b) {
            var isActive = b === btn;
            b.classList.toggle('active', isActive);
            b.setAttribute('aria-pressed', isActive ? 'true' : 'false');
        });
        dbCashApplyFilter();
    }

    function dbCashSearch() { dbCashApplyFilter(); }

    window.dbCashToggle      = dbCashToggle;
    window.dbCashSearch      = dbCashSearch;
    window.dbCashApplyFilter = dbCashApplyFilter;
}());

// ── Auto-refresh encaissements (3 min) ──────────────────────────────────────
(function () {
    var _cashTimer    = null;
    var _cashObserver = null;

    function _cashPad(n) { return n < 10 ? '0' + n : '' + n; }

    function _cashSetStatus(txt) {
        var el = document.getElementById('db-encaiss-last-update');
        if (el) el.textContent = txt;
    }

    function refreshEncaissements() {
        var list = document.getElementById('db-enc-list');
        if (!list) return;
        var details = list.closest('details');
        if (details && !details.open) return;
        _cashSetStatus('Mise à jour en cours…');
        dbFetch(list.dataset.encaissementsUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) {
                if (!r) return;
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.text();
            })
            .then(function (html) {
                if (!html) return;
                list.innerHTML = html;
                var now = new Date();
                _cashSetStatus('Dernière mise à jour à ' + _cashPad(now.getHours()) + ':' + _cashPad(now.getMinutes()));
                window.dbCashApplyFilter();
            })
            .catch(function (err) {
                _cashSetStatus('Erreur de mise à jour');
                console.warn('[dashboard] encaissements refresh failed:', err);
            });
    }

    function initDbCash() {
        var list = document.getElementById('db-enc-list');
        if (!list) return;
        var now = new Date();
        _cashSetStatus('Dernière mise à jour à ' + _cashPad(now.getHours()) + ':' + _cashPad(now.getMinutes()));
        if (_cashTimer) clearInterval(_cashTimer);
        _cashTimer = setInterval(refreshEncaissements, 180000);
    }

    function _cashObserve() {
        if (document.getElementById('db-enc-list')) { initDbCash(); return; }
        if (_cashObserver) _cashObserver.disconnect();
        _cashObserver = new MutationObserver(function (mutations, obs) {
            if (document.getElementById('db-enc-list')) {
                obs.disconnect();
                _cashObserver = null;
                initDbCash();
            }
        });
        _cashObserver.observe(document.body, { childList: true, subtree: true });
    }
    _cashObserve();

    window.refreshEncaissements = refreshEncaissements;
}());

// ── Dernières dépenses (toggles + recherche) — miroir des encaissements ──────
(function () {
    var _activeDepMode = 'j30';

    function dbDepApplyFilter() {
        var searchInput = document.querySelector('[aria-label="Filtrer les dépenses"]');
        var q = searchInput ? searchInput.value.toLowerCase() : '';
        document.querySelectorAll('.db-dep-item').forEach(function (el) {
            var matchMode   = el.dataset.group === _activeDepMode;
            var matchSearch = !q || (el.dataset.searchText || '').indexOf(q) >= 0;
            el.style.display = (matchMode && matchSearch) ? '' : 'none';
        });
    }

    function dbDepToggle(btn) {
        _activeDepMode = btn.dataset.mode;
        document.querySelectorAll('#dbDepToggleBar .db-task-toggle').forEach(function (b) {
            var isActive = b === btn;
            b.classList.toggle('active', isActive);
            b.setAttribute('aria-pressed', isActive ? 'true' : 'false');
        });
        dbDepApplyFilter();
    }

    function dbDepSearch() { dbDepApplyFilter(); }

    window.dbDepToggle      = dbDepToggle;
    window.dbDepSearch      = dbDepSearch;
    window.dbDepApplyFilter = dbDepApplyFilter;
}());

// ── Auto-refresh dépenses (3 min) — miroir des encaissements ─────────────────
(function () {
    var _depTimer    = null;
    var _depObserver = null;

    function _depPad(n) { return n < 10 ? '0' + n : '' + n; }

    function _depSetStatus(txt) {
        var el = document.getElementById('db-depenses-last-update');
        if (el) el.textContent = txt;
    }

    function refreshDepenses() {
        var list = document.getElementById('db-dep-list');
        if (!list) return;
        var details = list.closest('details');
        if (details && !details.open) return;
        _depSetStatus('Mise à jour en cours…');
        dbFetch(list.dataset.depensesUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) {
                if (!r) return;
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.text();
            })
            .then(function (html) {
                if (!html) return;
                list.innerHTML = html;
                var now = new Date();
                _depSetStatus('Dernière mise à jour à ' + _depPad(now.getHours()) + ':' + _depPad(now.getMinutes()));
                window.dbDepApplyFilter();
            })
            .catch(function (err) {
                _depSetStatus('Erreur de mise à jour');
                console.warn('[dashboard] dépenses refresh failed:', err);
            });
    }

    function initDbDep() {
        var list = document.getElementById('db-dep-list');
        if (!list) return;
        var now = new Date();
        _depSetStatus('Dernière mise à jour à ' + _depPad(now.getHours()) + ':' + _depPad(now.getMinutes()));
        if (_depTimer) clearInterval(_depTimer);
        _depTimer = setInterval(refreshDepenses, 180000);
    }

    function _depObserve() {
        if (document.getElementById('db-dep-list')) { initDbDep(); return; }
        if (_depObserver) _depObserver.disconnect();
        _depObserver = new MutationObserver(function (mutations, obs) {
            if (document.getElementById('db-dep-list')) {
                obs.disconnect();
                _depObserver = null;
                initDbDep();
            }
        });
        _depObserver.observe(document.body, { childList: true, subtree: true });
    }
    _depObserve();

    window.refreshDepenses = refreshDepenses;
}());

// ── Tooltip dépenses — data-dep-tip (suit la souris) — miroir data-cash-tip ──
(function () {
    var _tip = null;
    var _active = false;

    function getOrCreate() {
        if (!_tip) {
            _tip = document.createElement('div');
            _tip.id = 'db-dep-tip';
            document.body.appendChild(_tip);
        }
        return _tip;
    }

    function buildContent(el) {
        var charge = el.dataset.depTipCharge || '—';
        var tiers  = el.dataset.depTipTiers  || '';
        var compte = el.dataset.depTipCompte || '';
        var ttc    = el.dataset.depTipTtc    || '—';
        var ht     = el.dataset.depTipHt     || '—';
        var tva    = el.dataset.depTipTva    || '—';
        var statut = el.dataset.depTipStatut || '—';
        var sColor = el.dataset.depTipStatutColor || '#adb5bd';
        var moyen  = el.dataset.depTipMoyen  || '—';
        var date   = el.dataset.depTipDate   || '—';
        var ref    = el.dataset.depTipRef    || '';

        var rows = '<tr><td colspan="2" class="tip-section">Dépense</td></tr>' +
            '<tr><td>Charge</td><td>' + charge + '</td></tr>';
        if (tiers)  { rows += '<tr><td>Tiers</td><td>' + tiers + '</td></tr>'; }
        if (compte) { rows += '<tr><td>Compte</td><td>' + compte + '</td></tr>'; }

        rows += '<tr><td colspan="2" class="tip-section">Montants</td></tr>' +
            '<tr><td>TTC</td><td class="tip-ttc">' + ttc + '</td></tr>' +
            '<tr><td>HT</td><td>' + ht + '</td></tr>' +
            '<tr><td>TVA déd.</td><td>' + tva + '</td></tr>' +
            '<tr><td colspan="2" class="tip-section">Suivi</td></tr>' +
            '<tr><td>Statut</td><td class="tip-statut" style="color:' + sColor + '">' + statut + '</td></tr>' +
            '<tr><td>Moyen</td><td>' + moyen + '</td></tr>' +
            '<tr><td>Date</td><td>' + date + '</td></tr>';
        if (ref && ref !== '—') { rows += '<tr><td>Réf.</td><td>' + ref + '</td></tr>'; }

        return '<table>' + rows + '</table>';
    }

    function positionTip(mx, my) {
        var t = _tip;
        if (!t) return;
        var offset = 10;
        var left = mx - t.offsetWidth - offset;
        var top  = my - t.offsetHeight - offset;
        if (left < 8) left = mx + offset;
        if (top < 8) top = my + offset;
        t.style.left = left + 'px';
        t.style.top  = top  + 'px';
    }

    document.addEventListener('mouseover', function (e) {
        var el = e.target.closest ? e.target.closest('[data-dep-tip]') : null;
        if (!el) return;
        var details = el.closest('details');
        if (details && details.open) return;
        var t = getOrCreate();
        t.innerHTML = buildContent(el);
        t.style.display = 'block';
        _active = true;
    });
    document.addEventListener('mouseout', function (e) {
        var el = e.target.closest ? e.target.closest('[data-dep-tip]') : null;
        if (el && !el.contains(e.relatedTarget)) {
            if (_tip) _tip.style.display = 'none';
            _active = false;
        }
    });
    document.addEventListener('mousemove', function (e) {
        if (_active) positionTip(e.clientX, e.clientY);
    });
}());

// ── Menu contextuel dépenses — dbDepCtxOpen (ajouter / modifier / supprimer) ──
// Miroir strict du menu contextuel des notes (dbNoteCtxOpen).
(function () {
    var _depEl          = null; // details courant
    var _did            = null; // dépense id
    var _deid           = null; // entreprise id
    var _diid           = null; // invite id
    var _depPendingDel  = null;

    function dbDepCtxOpen(event, el) {
        event.preventDefault();
        event.stopPropagation();
        _depEl = el;
        _did   = el.dataset.depId;
        _deid  = el.dataset.entrepriseId;
        _diid  = el.dataset.inviteId;

        var menu = document.getElementById('dbDepCtxMenu');
        if (!menu) return;
        var mw = 220, mh = 150;
        var left = (event.clientX + mw > window.innerWidth)  ? window.innerWidth  - mw - 6 : event.clientX;
        var top  = (event.clientY + mh > window.innerHeight) ? window.innerHeight - mh - 6 : event.clientY;
        menu.style.left    = left + 'px';
        menu.style.top     = top  + 'px';
        menu.style.display = 'block';

        var addItem    = document.getElementById('dbDepCtxAddItem');
        var editItem   = document.getElementById('dbDepCtxEditItem');
        var deleteItem = document.getElementById('dbDepCtxDeleteItem');

        if (addItem)    addItem.onclick    = dbDepCtxAdd;
        if (editItem)   editItem.onclick   = dbDepCtxEdit;
        if (deleteItem) deleteItem.onclick = dbDepCtxDelete;

        setTimeout(function () {
            document.addEventListener('click', function hide() {
                var m = document.getElementById('dbDepCtxMenu');
                if (m) m.style.display = 'none';
                document.removeEventListener('click', hide);
            }, { once: true });
        }, 0);
    }

    function dbDepCtxAdd() {
        var menu = document.getElementById('dbDepCtxMenu');
        if (menu) menu.style.display = 'none';
        // Contexte pris sur l'item d'ajout (entreprise/invité courants), robuste même
        // si aucune ligne n'a encore été survolée.
        var addItem = document.getElementById('dbDepCtxAddItem');
        var eid = (addItem && addItem.dataset.entrepriseId) || _deid;
        var iid = (addItem && addItem.dataset.inviteId)     || _diid;
        document.dispatchEvent(new CustomEvent('app:loading.start'));
        document.dispatchEvent(new CustomEvent('app:boite-dialogue:init-request', {
            detail: {
                entityFormCanvas: {
                    parametres: {
                        titre_creation:      'Nouvelle Dépense',
                        endpoint_form_url:   '/admin/depensecourtier/api/get-form',
                        endpoint_submit_url: '/admin/depensecourtier/api/submit'
                    }
                },
                isCreationMode: true,
                context: {
                    idEntreprise:     parseInt(eid) || undefined,
                    idInvite:         parseInt(iid) || undefined,
                    _dashboardReload: true
                }
            }
        }));
        setTimeout(function () { document.dispatchEvent(new CustomEvent('app:loading.stop')); }, 600);
    }

    function dbDepCtxEdit() {
        var menu = document.getElementById('dbDepCtxMenu');
        if (menu) menu.style.display = 'none';
        if (!_did) return;
        document.dispatchEvent(new CustomEvent('app:loading.start'));
        document.dispatchEvent(new CustomEvent('app:boite-dialogue:init-request', {
            detail: {
                entityFormCanvas: {
                    parametres: {
                        titre_modification:  'Modifier la dépense',
                        endpoint_form_url:   '/admin/depensecourtier/api/get-form',
                        endpoint_submit_url: '/admin/depensecourtier/api/submit'
                    }
                },
                entity:         { id: parseInt(_did) },
                isCreationMode: false,
                context: {
                    idEntreprise:     parseInt(_deid) || undefined,
                    idInvite:         parseInt(_diid) || undefined,
                    _dashboardReload: true
                }
            }
        }));
        setTimeout(function () { document.dispatchEvent(new CustomEvent('app:loading.stop')); }, 600);
    }

    function dbDepCtxDelete() {
        var menu = document.getElementById('dbDepCtxMenu');
        if (menu) menu.style.display = 'none';
        if (!_depEl || !_did) return;

        var nom = (_depEl.dataset.depNom || ('Dépense #' + _did));
        _depPendingDel = _depEl;

        document.dispatchEvent(new CustomEvent('ui:confirmation.request', {
            detail: {
                title: 'Supprimer la dépense',
                body: 'La dépense sera définitivement supprimée. Cette action est irréversible.',
                itemDescriptions: [nom],
                showIrreversible: true,
                onConfirm: {
                    type: 'app:db-dep.delete-execute',
                    payload: { id: _did }
                }
            }
        }));
    }

    document.addEventListener('cerveau:event', function (e) {
        if (e.detail.type !== 'app:db-dep.delete-execute') return;
        var p = e.detail.payload;
        var did = p && p.id;
        if (!did) return;

        var elToRemove = _depPendingDel;
        var csrf = document.getElementById('db-dep-csrf');
        var csrfToken = csrf ? csrf.content : '';
        document.dispatchEvent(new CustomEvent('app:loading.start'));

        fetch('/admin/depensecourtier/api/delete/' + did, {
            method:  'DELETE',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN':     csrfToken
            }
        })
        .then(function (r) { return r.json(); })
        .then(function () {
            document.dispatchEvent(new CustomEvent('app:loading.stop'));
            document.dispatchEvent(new CustomEvent('ui:confirmation.close'));
            if (elToRemove && elToRemove.parentNode) {
                elToRemove.parentNode.removeChild(elToRemove);
            }
            _depPendingDel = null;
        })
        .catch(function () {
            document.dispatchEvent(new CustomEvent('app:loading.stop'));
            document.dispatchEvent(new CustomEvent('ui:confirmation.error', {
                detail: { error: 'La suppression a échoué.' }
            }));
            _depPendingDel = null;
        });
    });

    window.dbDepCtxOpen = dbDepCtxOpen;
}());

// ── Tooltip encaissements — data-cash-tip (suit la souris) ──────────────────
(function () {
    var _tip = null;
    var _active = false;

    function getOrCreate() {
        if (!_tip) {
            _tip = document.createElement('div');
            _tip.id = 'db-cash-tip';
            document.body.appendChild(_tip);
        }
        return _tip;
    }

    function buildContent(el) {
        var ref      = el.dataset.cashRef      || '—';
        var montant  = el.dataset.cashMontant  || '—';
        var date     = el.dataset.cashDate     || '—';
        var libelle  = el.dataset.cashLibelle  || '';
        var noteNom  = el.dataset.cashNoteNom  || '';
        var facture  = el.dataset.cashFacture  || '';
        var solde    = el.dataset.cashSolde    || '';
        var soldeDue = el.dataset.cashSoldeNegatif === 'true';
        var sentAt   = el.dataset.cashSentAt   || '';

        var rows = '<tr><td colspan="2" class="tip-section">Encaissement</td></tr>' +
            '<tr><td>Référence</td><td>' + ref + '</td></tr>' +
            '<tr><td>Montant</td><td>' + montant + '</td></tr>' +
            '<tr><td>Encaissé le</td><td>' + date + '</td></tr>';

        if (libelle) {
            rows += '<tr><td colspan="2" class="tip-libelle">' + libelle + '</td></tr>';
        }

        if (noteNom) {
            rows += '<tr><td colspan="2" class="tip-section">Note liée</td></tr>' +
                '<tr><td>Note</td><td>' + noteNom + '</td></tr>';
            if (facture) {
                rows += '<tr><td>Facturé</td><td>' + facture + '</td></tr>';
            }
            if (solde) {
                var soldeClass = soldeDue ? 'tip-solde-due' : 'tip-solde-ok';
                rows += '<tr><td>Solde dû</td><td class="' + soldeClass + '">' + solde + '</td></tr>';
            }
            if (sentAt) {
                rows += '<tr><td>Facturé le</td><td>' + sentAt + '</td></tr>';
            }
        } else {
            rows += '<tr><td colspan="2" class="tip-section">Note liée</td></tr>' +
                '<tr><td colspan="2" class="tip-note"><span class="tip-sinistre">✘ Paiement sinistre — aucune note</span></td></tr>';
        }

        return '<table>' + rows + '</table>';
    }

    function positionTip(mx, my) {
        var t = _tip;
        if (!t) return;
        var offset = 10;
        var left = mx - t.offsetWidth - offset;
        var top  = my - t.offsetHeight - offset;
        if (left < 8) left = mx + offset;
        if (top < 8) top = my + offset;
        t.style.left = left + 'px';
        t.style.top  = top  + 'px';
    }

    document.addEventListener('mouseover', function (e) {
        var el = e.target.closest ? e.target.closest('[data-cash-tip]') : null;
        if (!el) return;
        var details = el.closest('details');
        if (details && details.open) return;
        var t = getOrCreate();
        t.innerHTML = buildContent(el);
        t.style.display = 'block';
        _active = true;
    });
    document.addEventListener('mouseout', function (e) {
        var el = e.target.closest ? e.target.closest('[data-cash-tip]') : null;
        if (el && !el.contains(e.relatedTarget)) {
            if (_tip) _tip.style.display = 'none';
            _active = false;
        }
    });
    document.addEventListener('mousemove', function (e) {
        if (_active) positionTip(e.clientX, e.clientY);
    });
}());

// ── Tooltip sinistres — data-sin-tip (suit la souris) ───────────────────────
(function () {
    var _tip = null;
    var _active = false;

    function getOrCreate() {
        if (!_tip) {
            _tip = document.createElement('div');
            _tip.id = 'db-sin-tip';
            document.body.appendChild(_tip);
        }
        return _tip;
    }

    function buildContent(el) {
        var ref       = el.dataset.sinRef       || '—';
        var pol       = el.dataset.sinPol       || '';
        var dateNotif = el.dataset.sinDateNotif || '';
        var dateOccur = el.dataset.sinDateOccur || '';
        var assure    = el.dataset.sinAssure    || '';
        var assureur  = el.dataset.sinAssureur  || '';
        var risque    = el.dataset.sinRisque    || '';
        var lieu      = el.dataset.sinLieu      || '';
        var desc      = el.dataset.sinDesc      || '';
        var dommage   = el.dataset.sinDommage   || '';
        var evalVal   = el.dataset.sinEval      || '';
        var indemnise = el.dataset.sinIndemnise || '';
        var solde     = el.dataset.sinSolde     || '';
        var soldeOk   = el.dataset.sinSoldeOk   === 'true';

        var rows = '<tr><td colspan="2" class="tip-section">Sinistre</td></tr>' +
            '<tr><td>Référence</td><td>' + ref + (pol ? ' / ' + pol : '') + '</td></tr>';

        if (dateNotif) rows += '<tr><td>Notifié le</td><td>' + dateNotif + '</td></tr>';
        if (dateOccur) rows += '<tr><td>Survenu le</td><td>' + dateOccur + '</td></tr>';

        if (assure || assureur || risque) {
            rows += '<tr><td colspan="2" class="tip-section">Parties</td></tr>';
            if (assure)   rows += '<tr><td>Assuré</td><td>' + assure + '</td></tr>';
            if (assureur) rows += '<tr><td>Assureur</td><td>' + assureur + '</td></tr>';
            if (risque)   rows += '<tr><td>Risque</td><td>' + risque + '</td></tr>';
        }

        if (lieu) rows += '<tr><td colspan="2" class="tip-libelle">' + lieu + '</td></tr>';
        if (desc) rows += '<tr><td colspan="2" class="tip-libelle">' + desc + '</td></tr>';

        if (dommage || evalVal || indemnise || solde) {
            rows += '<tr><td colspan="2" class="tip-section">Financier</td></tr>';
            if (dommage)   rows += '<tr><td>Dommage</td><td style="color:#f87171;">' + dommage + '</td></tr>';
            if (evalVal)   rows += '<tr><td>Évaluation</td><td>' + evalVal + '</td></tr>';
            if (indemnise) rows += '<tr><td>Indemnisé</td><td style="color:#4ade80;">' + indemnise + '</td></tr>';
            if (solde) {
                var soldeClass = soldeOk ? 'tip-solde-ok' : 'tip-solde-due';
                rows += '<tr><td>Solde</td><td class="' + soldeClass + '">' + solde + '</td></tr>';
            }
        }

        return '<table>' + rows + '</table>';
    }

    function positionTip(mx, my) {
        var t = _tip;
        if (!t) return;
        var offset = 10;
        var left = mx - t.offsetWidth - offset;
        var top  = my - t.offsetHeight - offset;
        if (left < 8) left = mx + offset;
        if (top < 8)  top  = my + offset;
        t.style.left = left + 'px';
        t.style.top  = top  + 'px';
    }

    document.addEventListener('mouseover', function (e) {
        var el = e.target.closest ? e.target.closest('[data-sin-tip]') : null;
        if (!el) return;
        var details = el.closest('details');
        if (details && details.open) return;
        var t = getOrCreate();
        t.innerHTML = buildContent(el);
        t.style.display = 'block';
        _active = true;
    });
    document.addEventListener('mouseout', function (e) {
        var el = e.target.closest ? e.target.closest('[data-sin-tip]') : null;
        if (el && !el.contains(e.relatedTarget)) {
            if (_tip) _tip.style.display = 'none';
            _active = false;
        }
    });
    document.addEventListener('mousemove', function (e) {
        if (_active) positionTip(e.clientX, e.clientY);
    });
}());

// ── Derniers sinistres (toggles + recherche) ─────────────────────────────
(function () {
    var _activeSinMode = 'j30';

    function dbSinistreApplyFilter() {
        var searchInput = document.querySelector('[aria-label="Filtrer les sinistres"]');
        var q = searchInput ? searchInput.value.toLowerCase() : '';
        document.querySelectorAll('.db-sin-item').forEach(function (el) {
            var matchMode   = el.dataset.group === _activeSinMode;
            var matchSearch = !q || (el.dataset.searchText || '').indexOf(q) >= 0;
            el.style.display = (matchMode && matchSearch) ? '' : 'none';
        });
    }

    function dbSinistreToggle(btn) {
        _activeSinMode = btn.dataset.mode;
        document.querySelectorAll('#dbSinToggleBar .db-task-toggle').forEach(function (b) {
            var isActive = b === btn;
            b.classList.toggle('active', isActive);
            b.setAttribute('aria-pressed', isActive ? 'true' : 'false');
        });
        dbSinistreApplyFilter();
    }

    function dbSinistreNew(btn) {
        var eid = btn.dataset.entrepriseId;
        var iid = btn.dataset.inviteId;
        document.dispatchEvent(new CustomEvent('app:loading.start'));
        document.dispatchEvent(new CustomEvent('app:boite-dialogue:init-request', {
            detail: {
                entityFormCanvas: {
                    parametres: {
                        titre_creation:      'Nouveau sinistre',
                        endpoint_form_url:   '/admin/notificationsinistre/api/get-form',
                        endpoint_submit_url: '/admin/notificationsinistre/api/submit'
                    }
                },
                isCreationMode: true,
                context: {
                    idEntreprise:     parseInt(eid) || undefined,
                    idInvite:         parseInt(iid) || undefined,
                    _dashboardReload: true
                }
            }
        }));
        setTimeout(function () { document.dispatchEvent(new CustomEvent('app:loading.stop')); }, 600);
    }

    window.dbSinistreToggle      = dbSinistreToggle;
    window.dbSinistreSearch      = function () { dbSinistreApplyFilter(); };
    window.dbSinistreApplyFilter = dbSinistreApplyFilter;
    window.dbSinistreNew         = dbSinistreNew;
}());

// ── Auto-refresh sinistres (3 min) ───────────────────────────────────────
(function () {
    var _sinTimer    = null;
    var _sinObserver = null;

    function _sinPad(n) { return n < 10 ? '0' + n : '' + n; }

    function _sinSetStatus(txt) {
        var el = document.getElementById('db-sin-last-update');
        if (el) el.textContent = txt;
    }

    function refreshSinistres() {
        var list = document.getElementById('db-sin-list');
        if (!list) return;
        var details = list.closest('details');
        if (details && !details.open) return;
        _sinSetStatus('Mise à jour en cours…');
        dbFetch(list.dataset.sinistresUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) {
                if (!r) return;
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.text();
            })
            .then(function (html) {
                if (!html) return;
                list.innerHTML = html;
                var now = new Date();
                _sinSetStatus('Dernière mise à jour à ' + _sinPad(now.getHours()) + ':' + _sinPad(now.getMinutes()));
                window.dbSinistreApplyFilter();
            })
            .catch(function (err) {
                _sinSetStatus('Erreur de mise à jour');
                console.warn('[dashboard] sinistres refresh failed:', err);
            });
    }

    function initDbSinistres() {
        var list = document.getElementById('db-sin-list');
        if (!list) return;
        var now = new Date();
        _sinSetStatus('Dernière mise à jour à ' + _sinPad(now.getHours()) + ':' + _sinPad(now.getMinutes()));
        if (_sinTimer) clearInterval(_sinTimer);
        _sinTimer = setInterval(refreshSinistres, 180000);
    }

    function _sinObserve() {
        if (document.getElementById('db-sin-list')) { initDbSinistres(); return; }
        if (_sinObserver) _sinObserver.disconnect();
        _sinObserver = new MutationObserver(function (mutations, obs) {
            if (document.getElementById('db-sin-list')) {
                obs.disconnect();
                _sinObserver = null;
                initDbSinistres();
            }
        });
        _sinObserver.observe(document.body, { childList: true, subtree: true });
    }
    _sinObserve();

    window.refreshSinistres = refreshSinistres;
}());

// ── Menu contextuel sinistres (clic droit) ──────────────────────────────────
(function () {
    var _sinEl         = null;
    var _sid           = null;
    var _seid          = null;
    var _siid          = null;
    var _sinPendingDel = null;

    window.dbSinCtxOpen = function (event, el) {
        event.preventDefault();
        event.stopPropagation();
        _sinEl = el;
        _sid   = el.dataset.sinId;
        _seid  = el.dataset.entrepriseId;
        _siid  = el.dataset.inviteId;

        var menu = document.getElementById('dbSinCtxMenu');
        if (!menu) return;
        var mw = 220, mh = 120;
        var left = (event.clientX + mw > window.innerWidth)  ? window.innerWidth  - mw - 6 : event.clientX;
        var top  = (event.clientY + mh > window.innerHeight) ? window.innerHeight - mh - 6 : event.clientY;
        menu.style.left    = left + 'px';
        menu.style.top     = top  + 'px';
        menu.style.display = 'block';

        var editItem   = document.getElementById('dbSinCtxEditItem');
        var deleteItem = document.getElementById('dbSinCtxDeleteItem');
        if (editItem)   editItem.onclick   = dbSinCtxEdit;
        if (deleteItem) deleteItem.onclick = dbSinCtxDelete;

        setTimeout(function () {
            document.addEventListener('click', function hide() {
                var m = document.getElementById('dbSinCtxMenu');
                if (m) m.style.display = 'none';
                document.removeEventListener('click', hide);
            }, { once: true });
        }, 0);
    };

    function dbSinCtxEdit() {
        var menu = document.getElementById('dbSinCtxMenu');
        if (menu) menu.style.display = 'none';
        if (!_sid) return;
        document.dispatchEvent(new CustomEvent('app:loading.start'));
        document.dispatchEvent(new CustomEvent('app:boite-dialogue:init-request', {
            detail: {
                entityFormCanvas: {
                    parametres: {
                        titre_modification:  'Modifier le sinistre',
                        endpoint_form_url:   '/admin/notificationsinistre/api/get-form',
                        endpoint_submit_url: '/admin/notificationsinistre/api/submit'
                    }
                },
                entity:         { id: parseInt(_sid) },
                isCreationMode: false,
                context: {
                    idEntreprise:     parseInt(_seid) || undefined,
                    idInvite:         parseInt(_siid) || undefined,
                    _dashboardReload: true
                }
            }
        }));
        setTimeout(function () { document.dispatchEvent(new CustomEvent('app:loading.stop')); }, 600);
    }

    function dbSinCtxDelete() {
        var menu = document.getElementById('dbSinCtxMenu');
        if (menu) menu.style.display = 'none';
        if (!_sinEl || !_sid) return;

        var ref    = _sinEl.dataset.sinRef    || ('Sinistre #' + _sid);
        var assure = _sinEl.dataset.sinAssure || '';
        _sinPendingDel = _sinEl;

        document.dispatchEvent(new CustomEvent('ui:confirmation.request', {
            detail: {
                title: 'Supprimer le sinistre',
                body: 'Le sinistre sera définitivement supprimé. Cette action est irréversible.',
                itemDescriptions: [ref + (assure ? ' — ' + assure : '')],
                showIrreversible: true,
                onConfirm: {
                    type: 'app:db-sin.delete-execute',
                    payload: { id: _sid }
                }
            }
        }));
    }

    document.addEventListener('cerveau:event', function (e) {
        if (e.detail.type !== 'app:db-sin.delete-execute') return;
        var p = e.detail.payload;
        if (!p || !p.id) return;

        var elToRemove = _sinPendingDel;
        document.dispatchEvent(new CustomEvent('app:loading.start'));

        fetch('/admin/notificationsinistre/api/delete/' + p.id, {
            method:  'DELETE',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function (r) { return r.json(); })
        .then(function () {
            document.dispatchEvent(new CustomEvent('app:loading.stop'));
            document.dispatchEvent(new CustomEvent('ui:confirmation.close'));
            if (elToRemove && elToRemove.parentNode) {
                elToRemove.parentNode.removeChild(elToRemove);
            }
            _sinPendingDel = null;
        })
        .catch(function () {
            document.dispatchEvent(new CustomEvent('app:loading.stop'));
            document.dispatchEvent(new CustomEvent('ui:confirmation.error', {
                detail: { error: 'La suppression a échoué.' }
            }));
            _sinPendingDel = null;
        });
    });
}());

// ── Feedbacks récents : auto-refresh toutes les 60 s ──────────────────────
(function () {
    var _fbTimer = null;
    var _fbObserver = null;

    function _fbPad(n) { return n < 10 ? '0' + n : '' + n; }

    function _fbSetStatus(text) {
        var el = document.getElementById('db-fb-last-update');
        if (el) el.textContent = text;
    }

    function refreshFeedbacks() {
        var list = document.getElementById('db-fb-list');
        if (!list) return;
        var url = list.dataset.feedbacksUrl;
        if (!url) return;

        _fbSetStatus('Mise à jour en cours…');

        dbFetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) {
                if (!r) return;
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.text();
            })
            .then(function (html) {
                if (!html) return;
                list.innerHTML = html;
                var now = new Date();
                _fbSetStatus('Dernière mise à jour à ' + _fbPad(now.getHours()) + ':' + _fbPad(now.getMinutes()));
            })
            .catch(function (err) {
                _fbSetStatus('Erreur de mise à jour');
                console.warn('[dashboard] feedbacks refresh failed:', err);
            });
    }

    function _fbInitSelection(list) {
        list.addEventListener('click', function (e) {
            var item = e.target.closest('.db-fb-item');
            if (!item) return;
            list.querySelectorAll('.db-fb-item--selected').forEach(function (el) {
                el.classList.remove('db-fb-item--selected');
            });
            item.classList.add('db-fb-item--selected');
        });
        list.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter' && e.key !== ' ') return;
            var item = e.target.closest('.db-fb-item');
            if (!item) return;
            e.preventDefault();
            list.querySelectorAll('.db-fb-item--selected').forEach(function (el) {
                el.classList.remove('db-fb-item--selected');
            });
            item.classList.add('db-fb-item--selected');
        });
    }

    function initDbFeedbacks() {
        var list = document.getElementById('db-fb-list');
        if (!list) return;
        var now = new Date();
        _fbSetStatus('Dernière mise à jour à ' + _fbPad(now.getHours()) + ':' + _fbPad(now.getMinutes()));
        if (_fbTimer) { clearInterval(_fbTimer); }
        _fbTimer = setInterval(refreshFeedbacks, 60000);
        _fbInitSelection(list);
    }

    function _fbObserve() {
        if (document.getElementById('db-fb-list')) {
            initDbFeedbacks();
            return;
        }
        if (_fbObserver) { _fbObserver.disconnect(); }
        _fbObserver = new MutationObserver(function (mutations, obs) {
            if (document.getElementById('db-fb-list')) {
                obs.disconnect();
                _fbObserver = null;
                initDbFeedbacks();
            }
        });
        _fbObserver.observe(document.body, { childList: true, subtree: true });
    }

    _fbObserve();

    window.initDbFeedbacks = initDbFeedbacks;
}());

// ── Hero : sélection des tuiles de ventilation ─────────────────────────────
(function () {
    function _heroInitSelection(details) {
        details.addEventListener('click', function (e) {
            var item = e.target.closest('.db-hero-metric');
            if (!item) return;
            details.querySelectorAll('.db-hero-metric--selected').forEach(function (el) {
                el.classList.remove('db-hero-metric--selected');
            });
            item.classList.add('db-hero-metric--selected');
        });
        details.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter' && e.key !== ' ') return;
            var item = e.target.closest('.db-hero-metric');
            if (!item) return;
            e.preventDefault();
            details.querySelectorAll('.db-hero-metric--selected').forEach(function (el) {
                el.classList.remove('db-hero-metric--selected');
            });
            item.classList.add('db-hero-metric--selected');
        });
    }

    function initHeroMetrics() {
        var details = document.querySelector('details.db-hero');
        if (!details) return;
        _heroInitSelection(details);
    }

    var _heroObserver = null;
    function _heroObserve() {
        if (document.querySelector('details.db-hero')) {
            initHeroMetrics();
            return;
        }
        if (_heroObserver) { _heroObserver.disconnect(); }
        _heroObserver = new MutationObserver(function (mutations, obs) {
            if (document.querySelector('details.db-hero')) {
                obs.disconnect();
                _heroObserver = null;
                initHeroMetrics();
            }
        });
        _heroObserver.observe(document.body, { childList: true, subtree: true });
    }

    _heroObserve();
}());

// ── Menu contextuel tâches du tableau de bord ──────────────────────────────
(function () {
    var _el  = null;
    var _id  = null;
    var _eid = null;
    var _iid = null;
    var _pendingDeleteEl = null;

    function dbTaskCtxOpen(event, el) {
        event.preventDefault();
        event.stopPropagation();
        _el  = el;
        _id  = el.dataset.tacheId;
        _eid = el.dataset.entrepriseId;
        _iid = el.dataset.inviteId;

        var menu = document.getElementById('dbTaskCtxMenu');
        if (!menu) return;
        var mw = 220, mh = 90;
        var left = (event.clientX + mw > window.innerWidth)  ? window.innerWidth  - mw - 6 : event.clientX;
        var top  = (event.clientY + mh > window.innerHeight) ? window.innerHeight - mh - 6 : event.clientY;
        menu.style.left    = left + 'px';
        menu.style.top     = top  + 'px';
        menu.style.display = 'block';

        var isCreator = el.dataset.isCreator === '1';

        var deleteItem = document.getElementById('dbTaskCtxDeleteItem');
        if (deleteItem) {
            if (isCreator) {
                deleteItem.style.opacity = '';
                deleteItem.style.cursor  = 'pointer';
                deleteItem.onclick = dbTaskCtxDelete;
            } else {
                deleteItem.style.opacity = '0.38';
                deleteItem.style.cursor  = 'not-allowed';
                deleteItem.onclick = null;
            }
        }

        var editItem = document.getElementById('dbTaskCtxEditItem');
        if (editItem) {
            if (isCreator) {
                editItem.style.opacity = '';
                editItem.style.cursor  = 'pointer';
                editItem.onclick = dbTaskCtxEdit;
            } else {
                editItem.style.opacity = '0.38';
                editItem.style.cursor  = 'not-allowed';
                editItem.onclick = null;
            }
        }

        setTimeout(function () {
            document.addEventListener('click', function hide() {
                var m = document.getElementById('dbTaskCtxMenu');
                if (m) m.style.display = 'none';
                document.removeEventListener('click', hide);
            }, { once: true });
        }, 0);
    }

    function dbTaskCtxFeedback() {
        var menu = document.getElementById('dbTaskCtxMenu');
        if (menu) menu.style.display = 'none';
        if (!_id) return;

        var labelEl = _el && _el.querySelector('.db-task-label');
        var taskName = labelEl ? labelEl.textContent.trim() : ('Tâche #' + _id);

        document.dispatchEvent(new CustomEvent('app:loading.start'));

        document.dispatchEvent(new CustomEvent('app:boite-dialogue:init-request', {
            detail: {
                entityFormCanvas: {
                    parametres: {
                        titre_creation:      'Nouveau feedback — ' + taskName,
                        endpoint_form_url:   '/admin/feedback/api/get-form',
                        endpoint_submit_url: '/admin/feedback/api/submit'
                    }
                },
                entity:         {},
                isCreationMode: true,
                parentContext:  { id: parseInt(_id), fieldName: 'tache' },
                context:        { idEntreprise: parseInt(_eid), idInvite: parseInt(_iid) || undefined, _dbTaskId: _id, _dashboardReload: true }
            }
        }));

        setTimeout(function () {
            document.dispatchEvent(new CustomEvent('app:loading.stop'));
        }, 600);
    }

    function dbTaskCtxClose() {
        var menu = document.getElementById('dbTaskCtxMenu');
        if (menu) menu.style.display = 'none';
        if (!_el || !_id) return;

        var el   = _el;
        var tid  = _id;
        var csrf = '';
        var csrfMeta = document.getElementById('db-task-csrf');
        if (csrfMeta) csrf = csrfMeta.content;

        document.dispatchEvent(new CustomEvent('app:loading.start'));

        fetch('/admin/tache/api/close/' + tid, {
            method:  'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Content-Type':     'application/json',
                'X-CSRF-Token':     csrf
            }
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            document.dispatchEvent(new CustomEvent('app:loading.stop'));
            if (data.success) {
                el.style.transition    = 'opacity .25s ease, max-height .3s ease';
                el.style.overflow      = 'hidden';
                el.style.maxHeight     = el.scrollHeight + 'px';
                el.style.opacity       = '1';
                requestAnimationFrame(function () {
                    el.style.opacity       = '0';
                    el.style.maxHeight     = '0';
                    el.style.marginBottom  = '0';
                    el.style.paddingBottom = '0';
                    setTimeout(function () { el.remove(); }, 320);
                });
            }
        })
        .catch(function () {
            document.dispatchEvent(new CustomEvent('app:loading.stop'));
        });
    }

    // Mise à jour du compteur feedbacks (contexte tableau de bord uniquement)
    // Le Cerveau ne re-broadcaste pas app:entity.saved sur document ;
    // on intercepte cerveau:event qui, lui, bulle jusqu'au document.
    // Le champ _dbTaskId dans userContext identifie le contexte "tâche du tableau de bord"
    // et distingue ce cas de l'ouverture du même formulaire depuis la rubrique Feedbacks.
    document.addEventListener('cerveau:event', function (e) {
        if (e.detail.type !== 'app:entity.saved') return;
        var payload = e.detail.payload;
        var tacheId = payload && payload.userContext && payload.userContext._dbTaskId;
        if (!tacheId) return;
        var span = document.querySelector('[data-fb-count="' + tacheId + '"]');
        if (!span) return;
        span.childNodes.forEach(function (n) {
            if (n.nodeType === Node.TEXT_NODE && n.textContent.trim() !== '') {
                var current = parseInt(n.textContent.trim()) || 0;
                n.textContent = ' ' + (current + 1) + ' ';
                if (span.dataset.metaTip !== undefined) {
                    span.dataset.metaTip = (current + 1) + ' feedback(s)';
                }
            }
        });
    });

    function dbTaskCtxDelete() {
        var menu = document.getElementById('dbTaskCtxMenu');
        if (menu) menu.style.display = 'none';
        if (!_el || !_id) return;

        var el = _el, tid = _id;
        var labelEl = el.querySelector('.db-task-label');
        var taskName = labelEl ? labelEl.textContent.trim() : ('Tâche #' + tid);
        _pendingDeleteEl = el;

        document.dispatchEvent(new CustomEvent('ui:confirmation.request', {
            detail: {
                title: 'Supprimer la tâche',
                body: 'Cette action est irréversible. La tâche et tous ses feedbacks seront définitivement supprimés.',
                itemDescriptions: [taskName],
                onConfirm: {
                    type: 'app:db-task.delete-execute',
                    payload: { id: tid }
                }
            }
        }));
    }

    // Exécution réelle de la suppression après confirmation de l'utilisateur
    document.addEventListener('cerveau:event', function (e) {
        if (e.detail.type !== 'app:db-task.delete-execute') return;
        var p = e.detail.payload;
        var tid = p && p.id;
        if (!tid) return;

        var el = _pendingDeleteEl;
        document.dispatchEvent(new CustomEvent('app:loading.start'));

        fetch('/admin/tache/api/delete/' + tid, {
            method: 'DELETE',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/json' }
        })
        .then(function (r) { return r.json(); })
        .then(function () {
            document.dispatchEvent(new CustomEvent('app:loading.stop'));
            document.dispatchEvent(new CustomEvent('ui:confirmation.close'));
            _pendingDeleteEl = null;
            if (!el) return;
            el.style.transition    = 'opacity .25s ease, max-height .3s ease';
            el.style.overflow      = 'hidden';
            el.style.maxHeight     = el.scrollHeight + 'px';
            el.style.opacity       = '1';
            requestAnimationFrame(function () {
                el.style.opacity       = '0';
                el.style.maxHeight     = '0';
                el.style.marginBottom  = '0';
                el.style.paddingBottom = '0';
                setTimeout(function () { el.remove(); }, 320);
            });
        })
        .catch(function () {
            document.dispatchEvent(new CustomEvent('app:loading.stop'));
            document.dispatchEvent(new CustomEvent('ui:confirmation.error', {
                detail: { error: 'La suppression a échoué.' }
            }));
            _pendingDeleteEl = null;
        });
    });

    function dbTaskCtxEdit() {
        var menu = document.getElementById('dbTaskCtxMenu');
        if (menu) menu.style.display = 'none';
        if (!_id) return;

        document.dispatchEvent(new CustomEvent('app:loading.start'));
        document.dispatchEvent(new CustomEvent('app:boite-dialogue:init-request', {
            detail: {
                entityFormCanvas: {
                    parametres: {
                        titre_modification:  'Modifier la tâche',
                        endpoint_form_url:   '/admin/tache/api/get-form',
                        endpoint_submit_url: '/admin/tache/api/submit'
                    }
                },
                entity:         { id: parseInt(_id) },
                isCreationMode: false,
                context: {
                    idEntreprise:     parseInt(_eid),
                    idInvite:         parseInt(_iid) || undefined,
                    _dashboardReload: true
                }
            }
        }));
        setTimeout(function () {
            document.dispatchEvent(new CustomEvent('app:loading.stop'));
        }, 600);
    }

    window.dbTaskCtxOpen     = dbTaskCtxOpen;
    window.dbTaskCtxFeedback = dbTaskCtxFeedback;
    window.dbTaskCtxClose    = dbTaskCtxClose;
    window.dbTaskCtxDelete   = dbTaskCtxDelete;
    window.dbTaskCtxEdit     = dbTaskCtxEdit;
}());

// ── Infobulles flottantes — tout élément portant data-meta-tip (délégation document) ──
(function () {
    var _tip = null;

    function getOrCreateTip() {
        if (!_tip) {
            _tip = document.createElement('div');
            _tip.id = 'db-meta-tip';
            document.body.appendChild(_tip);
        }
        return _tip;
    }

    function escapeHtml(s) {
        return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function showDbMetaTip(chip) {
        var txt = chip.dataset.metaTip;
        if (!txt) return;
        var tip = getOrCreateTip();
        if (txt.indexOf('[PLUS]') !== -1) {
            var parts = txt.split('[PLUS]');
            tip.innerHTML = escapeHtml(parts[0]) + '<b>Pour plus d\'infos, consultez la liste des feedbacks</b>';
        } else {
            tip.textContent = txt;
        }
        tip.style.display = 'block';
        var r = chip.getBoundingClientRect();
        tip.style.left = (r.left + r.width / 2) + 'px';
        tip.style.top  = r.top + 'px';
        tip.style.transform = 'translateX(-50%) translateY(-100%)';
        tip.style.marginTop = '-6px';
        var tr = tip.getBoundingClientRect();
        if (tr.right > window.innerWidth - 8) tip.style.left = (window.innerWidth - tr.width - 8) + 'px';
        if (tr.left < 8) tip.style.left = (tr.width / 2 + 8) + 'px';
        if (tr.top < 8) {
            tip.style.top = (r.bottom + 6) + 'px';
            tip.style.transform = 'translateX(-50%)';
            tip.style.marginTop = '0';
        }
    }

    function hideDbMetaTip() {
        if (_tip) _tip.style.display = 'none';
    }

    document.addEventListener('mouseover', function (e) {
        var chip = e.target.closest ? e.target.closest('[data-meta-tip]') : null;
        if (!chip) return;
        showDbMetaTip(chip);
    });

    document.addEventListener('mouseout', function (e) {
        var chip = e.target.closest ? e.target.closest('[data-meta-tip]') : null;
        if (!chip) return;
        if (!chip.contains(e.relatedTarget)) hideDbMetaTip();
    });

    window.initDbMetaTips = function () {};
}());

// ── Tooltip financier renouvellements — data-renew-tip (suit la souris) ──
(function () {
    var _tip = null;
    var _active = false;

    function getOrCreate() {
        if (!_tip) {
            _tip = document.createElement('div');
            _tip.id = 'db-renew-tip';
            document.body.appendChild(_tip);
        }
        return _tip;
    }

    function buildContent(el) {
        var prime    = el.dataset.renewPrime    || '—';
        var comm     = el.dataset.renewComm     || '—';
        var retro    = el.dataset.renewRetro    || '—';
        var reserve  = el.dataset.renewReserve  || '—';
        var periode  = el.dataset.renewPeriode  || '—';
        var risque   = el.dataset.renewRisque   || '—';
        var assureur = el.dataset.renewAssureur || '—';
        var piste    = el.dataset.renewPiste;
        var pisteHtml = piste
            ? '<span class="tip-ok">✔ ' + piste + '</span>'
            : '<span class="tip-nok">✘ Aucune piste en cours</span>';
        return '<table>' +
            '<tr><td colspan="2" class="tip-section">Couverture</td></tr>' +
            '<tr><td>Période</td><td>' + periode + '</td></tr>' +
            '<tr><td>Risque</td><td class="tip-risque">' + risque + '</td></tr>' +
            '<tr><td>Assureur</td><td>' + assureur + '</td></tr>' +
            '<tr><td colspan="2" class="tip-section">Indicateurs</td></tr>' +
            '<tr><td>Prime</td><td>' + prime + '</td></tr>' +
            '<tr><td>Commission</td><td>' + comm + '</td></tr>' +
            '<tr><td>Rétrocommission</td><td>' + retro + '</td></tr>' +
            '<tr><td>Réserve</td><td>' + reserve + '</td></tr>' +
            '<tr><td colspan="2" class="tip-section">Renouvellement</td></tr>' +
            '<tr><td colspan="2" class="tip-piste">' + pisteHtml + '</td></tr>' +
            '</table>';
    }

    function positionTip(mx, my) {
        var t = _tip;
        if (!t) return;
        var offset = 10;
        var left = mx - t.offsetWidth - offset;
        var top  = my - t.offsetHeight - offset;
        if (left < 8) left = mx + offset;
        if (top < 8) top = my + offset;
        t.style.left = left + 'px';
        t.style.top  = top  + 'px';
    }

    document.addEventListener('mouseover', function (e) {
        var el = e.target.closest ? e.target.closest('[data-renew-tip]') : null;
        if (!el) return;
        var details = el.closest('details');
        if (details && details.open) return;
        var t = getOrCreate();
        t.innerHTML = buildContent(el);
        t.style.display = 'block';
        _active = true;
    });
    document.addEventListener('mouseout', function (e) {
        var el = e.target.closest ? e.target.closest('[data-renew-tip]') : null;
        if (el && !el.contains(e.relatedTarget)) {
            if (_tip) _tip.style.display = 'none';
            _active = false;
        }
    });
    document.addEventListener('mousemove', function (e) {
        if (_active) positionTip(e.clientX, e.clientY);
    });
}());

// ── Tooltip KPIs comptables — data-compta-tip (suit la souris) ──────────────
// Calqué à 100 % sur l'infobulle « Renouvellements » (data-renew-tip) : élément
// sombre flottant ajouté au <body>, positionné au curseur via mousemove. Contenu :
// deux paragraphes — ce que l'indicateur représente (intro) puis son mode de
// calcul (section « Calcul »).
(function () {
    var _tip = null;
    var _active = false;

    function getOrCreate() {
        if (!_tip) {
            _tip = document.createElement('div');
            _tip.id = 'db-compta-tip';
            document.body.appendChild(_tip);
        }
        return _tip;
    }

    function escapeHtml(s) {
        return (s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function buildContent(el) {
        var label  = el.dataset.tipLabel  || '';
        var intro  = el.dataset.tipIntro  || '';
        var calcul = el.dataset.tipCalcul || '';
        return (label ? '<div class="tip-title">' + escapeHtml(label) + '</div>' : '') +
            '<p class="tip-intro">' + escapeHtml(intro) + '</p>' +
            '<div class="tip-section">Calcul</div>' +
            '<p class="tip-calcul">' + escapeHtml(calcul) + '</p>';
    }

    function positionTip(mx, my) {
        var t = _tip;
        if (!t) return;
        var offset = 10;
        var left = mx - t.offsetWidth - offset;
        var top  = my - t.offsetHeight - offset;
        if (left < 8) left = mx + offset;
        if (top < 8) top = my + offset;
        t.style.left = left + 'px';
        t.style.top  = top  + 'px';
    }

    document.addEventListener('mouseover', function (e) {
        var el = e.target.closest ? e.target.closest('[data-compta-tip]') : null;
        if (!el) return;
        var t = getOrCreate();
        t.innerHTML = buildContent(el);
        t.style.display = 'block';
        _active = true;
    });
    document.addEventListener('mouseout', function (e) {
        var el = e.target.closest ? e.target.closest('[data-compta-tip]') : null;
        if (el && !el.contains(e.relatedTarget)) {
            if (_tip) _tip.style.display = 'none';
            _active = false;
        }
    });
    document.addEventListener('mousemove', function (e) {
        if (_active) positionTip(e.clientX, e.clientY);
    });
}());

// ── Pistes en cours : toggles, recherche, tooltip, menu contextuel, auto-refresh ──
(function () {
    var _activePisteMode  = 'j30';
    var _pisteTimer       = null;
    var _pisteObserver    = null;
    var _el  = null;
    var _pid = null;
    var _eid = null;
    var _iid = null;
    var _pendingDeleteEl  = null;
    var _tipEl = null;
    var _tipActive = false;

    // ── Filtrage (mode + recherche) ──────────────────────────────────────────
    function dbPisteApplyFilter() {
        var searchInput = document.querySelector('[aria-label="Filtrer les pistes"]');
        var q = searchInput ? searchInput.value.toLowerCase() : '';
        document.querySelectorAll('.db-piste-item').forEach(function (el) {
            var matchMode   = el.dataset.group === _activePisteMode;
            var matchSearch = !q || (el.dataset.searchText || '').indexOf(q) >= 0;
            el.style.display = (matchMode && matchSearch) ? '' : 'none';
        });
    }

    function dbPisteToggle(btn) {
        _activePisteMode = btn.dataset.mode;
        document.querySelectorAll('#dbPisteToggleBar .db-task-toggle').forEach(function (b) {
            var isActive = b === btn;
            b.classList.toggle('active', isActive);
            b.setAttribute('aria-pressed', isActive ? 'true' : 'false');
        });
        dbPisteApplyFilter();
    }

    function dbPisteSearch() { dbPisteApplyFilter(); }

    // ── Auto-refresh toutes les 3 minutes ────────────────────────────────────
    function _pistePad(n) { return n < 10 ? '0' + n : '' + n; }

    function _pisteSetStatus(text) {
        var el = document.getElementById('db-piste-last-update');
        if (el) el.textContent = text;
    }

    function refreshPistes() {
        var list = document.getElementById('db-piste-list');
        if (!list) return;
        var details = list.closest('details');
        if (details && !details.open) return;
        var url = list.dataset.pistesUrl;
        if (!url) return;
        _pisteSetStatus('Mise à jour en cours…');
        dbFetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) {
                if (!r) return;
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.text();
            })
            .then(function (html) {
                if (!html) return;
                list.innerHTML = html;
                var now = new Date();
                _pisteSetStatus('Dernière mise à jour à ' + _pistePad(now.getHours()) + ':' + _pistePad(now.getMinutes()));
                dbPisteApplyFilter();
            })
            .catch(function (err) {
                _pisteSetStatus('Erreur de mise à jour');
                console.warn('[dashboard] pistes refresh failed:', err);
            });
    }

    function initDbPistes() {
        var list = document.getElementById('db-piste-list');
        if (!list) return;
        var now = new Date();
        _pisteSetStatus('Dernière mise à jour à ' + _pistePad(now.getHours()) + ':' + _pistePad(now.getMinutes()));
        if (_pisteTimer) clearInterval(_pisteTimer);
        _pisteTimer = setInterval(refreshPistes, 180000);
    }

    function _pisteObserve() {
        if (document.getElementById('db-piste-list')) { initDbPistes(); return; }
        if (_pisteObserver) _pisteObserver.disconnect();
        _pisteObserver = new MutationObserver(function (mutations, obs) {
            if (document.getElementById('db-piste-list')) {
                obs.disconnect();
                _pisteObserver = null;
                initDbPistes();
            }
        });
        _pisteObserver.observe(document.body, { childList: true, subtree: true });
    }
    _pisteObserve();

    // ── Tooltip data-piste-tip (suit la souris) ──────────────────────────────
    function _getOrCreateTip() {
        if (!_tipEl) {
            _tipEl = document.createElement('div');
            _tipEl.id = 'db-piste-tip';
            document.body.appendChild(_tipEl);
        }
        return _tipEl;
    }

    function _buildTipContent(details) {
        var nom         = details.dataset.pisteNom         || '—';
        var client      = details.dataset.pisteClient      || '—';
        var risque      = details.dataset.pisteRisque      || '—';
        var description = details.dataset.pisteDescription || '';
        var statut      = details.dataset.pisteStatut      || '—';
        var days        = details.dataset.pisteDays        || '—';
        var invite      = details.dataset.pisteInvite      || '—';
        var prime       = details.dataset.pistePrime       || '—';
        var commission  = details.dataset.pisteCommission  || '—';

        var rows = '<tr><td colspan="2" class="tip-section">Piste</td></tr>' +
            '<tr><td>Nom</td><td><strong>' + nom + '</strong></td></tr>' +
            '<tr><td>Client</td><td>' + client + '</td></tr>' +
            '<tr><td>Risque</td><td class="tip-risque">' + risque + '</td></tr>' +
            '<tr><td>Type</td><td>' + statut + '</td></tr>' +
            '<tr><td>Âge</td><td>' + days + ' jour(s)</td></tr>';
        if (description) {
            rows += '<tr><td colspan="2" class="tip-libelle">' + description + '</td></tr>';
        }
        rows += '<tr><td colspan="2" class="tip-section">Potentiel</td></tr>' +
            '<tr><td>Prime</td><td>' + prime + '</td></tr>' +
            '<tr><td>Commission</td><td>' + commission + '</td></tr>' +
            '<tr><td colspan="2" class="tip-section">Gestion</td></tr>' +
            '<tr><td>Gestionnaire</td><td>' + invite + '</td></tr>';
        return '<table>' + rows + '</table>';
    }

    function _positionTip(mx, my) {
        var t = _tipEl;
        if (!t) return;
        var offset = 10;
        var left = mx - t.offsetWidth - offset;
        var top  = my - t.offsetHeight - offset;
        if (left < 8) left = mx + offset;
        if (top < 8) top = my + offset;
        t.style.left = left + 'px';
        t.style.top  = top  + 'px';
    }

    document.addEventListener('mouseover', function (e) {
        var el = e.target.closest ? e.target.closest('[data-piste-tip]') : null;
        if (!el) return;
        var details = el.closest('details');
        if (details && details.open) return;
        if (!details) return;
        var t = _getOrCreateTip();
        t.innerHTML = _buildTipContent(details);
        t.style.display = 'block';
        _tipActive = true;
    });
    document.addEventListener('mouseout', function (e) {
        var el = e.target.closest ? e.target.closest('[data-piste-tip]') : null;
        if (el && !el.contains(e.relatedTarget)) {
            if (_tipEl) _tipEl.style.display = 'none';
            _tipActive = false;
        }
    });
    document.addEventListener('mousemove', function (e) {
        if (_tipActive) _positionTip(e.clientX, e.clientY);
    });

    // ── Menu contextuel ───────────────────────────────────────────────────────
    function dbPisteCtxOpen(event, el) {
        event.preventDefault();
        event.stopPropagation();
        _el  = el;
        _pid = el.dataset.pisteId;
        _eid = el.dataset.entrepriseId;
        _iid = el.dataset.inviteId;

        var menu = document.getElementById('dbPisteCtxMenu');
        if (!menu) return;
        var mw = 220, mh = 110;
        var left = (event.clientX + mw > window.innerWidth)  ? window.innerWidth  - mw - 6 : event.clientX;
        var top  = (event.clientY + mh > window.innerHeight) ? window.innerHeight - mh - 6 : event.clientY;
        menu.style.left    = left + 'px';
        menu.style.top     = top  + 'px';
        menu.style.display = 'block';

        var isCreator = el.dataset.isCreator === '1';

        ['dbPisteCtxCloseItem', 'dbPisteCtxDeleteItem'].forEach(function (itemId) {
            var item = document.getElementById(itemId);
            if (!item) return;
            if (isCreator) {
                item.style.opacity = '';
                item.style.cursor  = 'pointer';
                item.onclick = itemId === 'dbPisteCtxCloseItem' ? dbPisteCtxClose : dbPisteCtxDelete;
            } else {
                item.style.opacity = '0.38';
                item.style.cursor  = 'not-allowed';
                item.onclick = null;
            }
        });

        var editItem = document.getElementById('dbPisteCtxEditItem');
        if (editItem) editItem.onclick = dbPisteCtxEdit;

        setTimeout(function () {
            document.addEventListener('click', function hide() {
                var m = document.getElementById('dbPisteCtxMenu');
                if (m) m.style.display = 'none';
                document.removeEventListener('click', hide);
            }, { once: true });
        }, 0);
    }

    function dbPisteCtxEdit() {
        var menu = document.getElementById('dbPisteCtxMenu');
        if (menu) menu.style.display = 'none';
        if (!_pid) return;
        document.dispatchEvent(new CustomEvent('app:loading.start'));
        document.dispatchEvent(new CustomEvent('app:boite-dialogue:init-request', {
            detail: {
                entityFormCanvas: {
                    parametres: {
                        titre_modification:  'Modifier la piste',
                        endpoint_form_url:   '/admin/piste/api/get-form',
                        endpoint_submit_url: '/admin/piste/api/submit'
                    }
                },
                entity:         { id: parseInt(_pid) },
                isCreationMode: false,
                context: {
                    idEntreprise:     parseInt(_eid) || undefined,
                    idInvite:         parseInt(_iid) || undefined,
                    _dashboardReload: true
                }
            }
        }));
        setTimeout(function () { document.dispatchEvent(new CustomEvent('app:loading.stop')); }, 600);
    }

    var _pendingCloseEl = null;

    function dbPisteCtxClose() {
        var menu = document.getElementById('dbPisteCtxMenu');
        if (menu) menu.style.display = 'none';
        if (!_el || !_pid) return;

        var nom = _el.dataset.pisteNom || ('Piste #' + _pid);
        _pendingCloseEl = _el;

        document.dispatchEvent(new CustomEvent('ui:confirmation.request', {
            detail: {
                title: 'Clôturer la piste',
                body: 'La piste sera marquée comme clôturée et n\'apparaîtra plus dans le tableau de bord.',
                itemDescriptions: [nom],
                onConfirm: {
                    type: 'app:db-piste.close-execute',
                    payload: { id: _pid }
                }
            }
        }));
    }

    document.addEventListener('cerveau:event', function (e) {
        if (e.detail.type !== 'app:db-piste.close-execute') return;
        var p = e.detail.payload;
        var pid = p && p.id;
        if (!pid) return;

        var el = _pendingCloseEl;
        var csrf = '';
        var csrfMeta = document.getElementById('db-piste-csrf');
        if (csrfMeta) csrf = csrfMeta.content;

        document.dispatchEvent(new CustomEvent('app:loading.start'));

        fetch('/admin/piste/api/close/' + pid, {
            method:  'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Content-Type':     'application/json',
                'X-CSRF-Token':     csrf
            }
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            document.dispatchEvent(new CustomEvent('app:loading.stop'));
            document.dispatchEvent(new CustomEvent('ui:confirmation.close'));
            _pendingCloseEl = null;
            if (data.success && el) {
                el.style.transition    = 'opacity .25s ease, max-height .3s ease';
                el.style.overflow      = 'hidden';
                el.style.maxHeight     = el.scrollHeight + 'px';
                el.style.opacity       = '1';
                requestAnimationFrame(function () {
                    el.style.opacity       = '0';
                    el.style.maxHeight     = '0';
                    el.style.marginBottom  = '0';
                    el.style.paddingBottom = '0';
                    setTimeout(function () { el.remove(); }, 320);
                });
            }
        })
        .catch(function () {
            document.dispatchEvent(new CustomEvent('app:loading.stop'));
            document.dispatchEvent(new CustomEvent('ui:confirmation.error', {
                detail: { message: 'Une erreur est survenue.' }
            }));
        });
    });

    function dbPisteCtxDelete() {
        var menu = document.getElementById('dbPisteCtxMenu');
        if (menu) menu.style.display = 'none';
        if (!_el || !_pid) return;

        var nom  = _el.dataset.pisteNom || ('Piste #' + _pid);
        _pendingDeleteEl = _el;

        document.dispatchEvent(new CustomEvent('ui:confirmation.request', {
            detail: {
                title: 'Supprimer la piste',
                body: 'Cette action est irréversible. La piste et toutes ses données seront définitivement supprimées.',
                itemDescriptions: [nom],
                onConfirm: {
                    type: 'app:db-piste.delete-execute',
                    payload: { id: _pid }
                }
            }
        }));
    }

    document.addEventListener('cerveau:event', function (e) {
        if (e.detail.type !== 'app:db-piste.delete-execute') return;
        var p = e.detail.payload;
        var pid = p && p.id;
        if (!pid) return;

        var el = _pendingDeleteEl;
        document.dispatchEvent(new CustomEvent('app:loading.start'));

        fetch('/admin/piste/api/delete/' + pid, {
            method: 'DELETE',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/json' }
        })
        .then(function (r) { return r.json(); })
        .then(function () {
            document.dispatchEvent(new CustomEvent('app:loading.stop'));
            document.dispatchEvent(new CustomEvent('ui:confirmation.close'));
            _pendingDeleteEl = null;
            if (!el) return;
            el.style.transition    = 'opacity .25s ease, max-height .3s ease';
            el.style.overflow      = 'hidden';
            el.style.maxHeight     = el.scrollHeight + 'px';
            el.style.opacity       = '1';
            requestAnimationFrame(function () {
                el.style.opacity       = '0';
                el.style.maxHeight     = '0';
                el.style.marginBottom  = '0';
                el.style.paddingBottom = '0';
                setTimeout(function () { el.remove(); }, 320);
            });
        })
        .catch(function () {
            document.dispatchEvent(new CustomEvent('app:loading.stop'));
            document.dispatchEvent(new CustomEvent('ui:confirmation.error', {
                detail: { error: 'La suppression a échoué.' }
            }));
            _pendingDeleteEl = null;
        });
    });

    // ── Bouton "+" nouvelle piste ─────────────────────────────────────────────
    function dbPisteNew(btn) {
        var eid = btn.dataset.entrepriseId;
        var iid = btn.dataset.inviteId;
        document.dispatchEvent(new CustomEvent('app:loading.start'));
        document.dispatchEvent(new CustomEvent('app:boite-dialogue:init-request', {
            detail: {
                entityFormCanvas: {
                    parametres: {
                        titre_creation:      'Nouvelle piste',
                        endpoint_form_url:   '/admin/piste/api/get-form',
                        endpoint_submit_url: '/admin/piste/api/submit'
                    }
                },
                entity:         {},
                isCreationMode: true,
                context: {
                    idEntreprise:     parseInt(eid) || undefined,
                    idInvite:         parseInt(iid) || undefined,
                    _dashboardReload: true
                }
            }
        }));
        setTimeout(function () { document.dispatchEvent(new CustomEvent('app:loading.stop')); }, 600);
    }

    window.dbPisteToggle  = dbPisteToggle;
    window.dbPisteSearch  = dbPisteSearch;
    window.dbPisteCtxOpen = dbPisteCtxOpen;
    window.dbPisteNew     = dbPisteNew;
}());

// ── Bloc Bordereaux ────────────────────────────────────────────────────────────
(function () {
    var _activeBordMode  = 'j30';
    var _bordEl          = null;  // details element courant
    var _bid             = null;  // bordereau id
    var _beid            = null;  // entreprise id
    var _biid            = null;  // invite id
    var _bordTipEl       = null;
    var _bordTipActive   = false;
    var _bordPendingDel  = null;
    var _bordTimer       = null;
    var _bordObserver    = null;

    // ── Filtre toggle + recherche ──────────────────────────────────────────────
    function dbBordApplyFilter() {
        var searchInput = document.querySelector('#db-bord-list input[type="search"]') ||
                          document.querySelector('input[aria-label="Filtrer les bordereaux"]');
        var q = searchInput ? searchInput.value.toLowerCase() : '';
        document.querySelectorAll('.db-bord-item').forEach(function (el) {
            var matchMode   = el.dataset.group === _activeBordMode;
            var matchSearch = !q || (el.dataset.searchText || '').indexOf(q) >= 0;
            el.style.display = (matchMode && matchSearch) ? '' : 'none';
        });
    }

    function dbBordToggle(btn) {
        _activeBordMode = btn.dataset.mode;
        document.querySelectorAll('#dbBordToggleBar .db-task-toggle').forEach(function (b) {
            var isActive = b === btn;
            b.classList.toggle('active', isActive);
            b.setAttribute('aria-pressed', isActive ? 'true' : 'false');
        });
        dbBordApplyFilter();
    }

    function dbBordSearch() { dbBordApplyFilter(); }

    // ── Auto-refresh toutes les 3 minutes ─────────────────────────────────────
    function _bordPad(n) { return n < 10 ? '0' + n : '' + n; }

    function _bordSetStatus(text) {
        var el = document.getElementById('db-bord-last-update');
        if (el) el.textContent = text;
    }

    function refreshBordereaux() {
        var list = document.getElementById('db-bord-list');
        if (!list) return;
        var details = list.closest('details');
        if (details && !details.open) return;
        var url = list.dataset.bordereauUrl;
        if (!url) return;

        _bordSetStatus('Mise à jour en cours…');

        dbFetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) {
                if (!r) return;
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.text();
            })
            .then(function (html) {
                if (!html) return;
                list.innerHTML = html;
                var now = new Date();
                _bordSetStatus('Dernière mise à jour à ' + _bordPad(now.getHours()) + ':' + _bordPad(now.getMinutes()));
                dbBordApplyFilter();
            })
            .catch(function (err) {
                _bordSetStatus('Erreur de mise à jour');
                console.warn('[dashboard] bordereaux refresh failed:', err);
            });
    }

    function initDbBordereaux() {
        var list = document.getElementById('db-bord-list');
        if (!list) return;
        var now = new Date();
        _bordSetStatus('Dernière mise à jour à ' + _bordPad(now.getHours()) + ':' + _bordPad(now.getMinutes()));
        if (_bordTimer) { clearInterval(_bordTimer); }
        _bordTimer = setInterval(refreshBordereaux, 180000);
        dbBordApplyFilter();
    }

    function _bordObserve() {
        if (document.getElementById('db-bord-list')) {
            initDbBordereaux();
            return;
        }
        if (_bordObserver) { _bordObserver.disconnect(); }
        _bordObserver = new MutationObserver(function (mutations, obs) {
            if (document.getElementById('db-bord-list')) {
                obs.disconnect();
                _bordObserver = null;
                initDbBordereaux();
            }
        });
        _bordObserver.observe(document.body, { childList: true, subtree: true });
    }
    _bordObserve();

    window.refreshBordereaux = refreshBordereaux;

    // ── Tooltip data-bord-tip (suit la souris) ────────────────────────────────
    function _getOrCreateBordTip() {
        if (!_bordTipEl) {
            _bordTipEl = document.createElement('div');
            _bordTipEl.id = 'db-bord-tip';
            document.body.appendChild(_bordTipEl);
        }
        return _bordTipEl;
    }

    function _buildBordTipContent(span) {
        var ref     = span.dataset.bordTipRef      || '—';
        var assur   = span.dataset.bordTipAssureur || '—';
        var statut  = span.dataset.bordTipStatut   || '—';
        var sColor  = span.dataset.bordTipStatutColor || '#adb5bd';
        var periode = span.dataset.bordTipPeriode  || '—';
        var recu    = span.dataset.bordTipRecu     || '—';
        var days    = span.dataset.bordTipDays     || '—';

        return '<table>' +
            '<tr><td colspan="2" class="tip-section">Bordereau</td></tr>' +
            '<tr><td>Référence</td><td><strong>' + ref + '</strong></td></tr>' +
            '<tr><td>Assureur</td><td>' + assur + '</td></tr>' +
            '<tr><td>Statut</td><td class="tip-statut" style="color:' + sColor + '">' + statut + '</td></tr>' +
            '<tr><td colspan="2" class="tip-section">Période</td></tr>' +
            '<tr><td>Couverture</td><td>' + periode + '</td></tr>' +
            '<tr><td>Reçu le</td><td>' + recu + '</td></tr>' +
            '<tr><td>Âge</td><td>' + days + ' jour(s)</td></tr>' +
            '</table>';
    }

    function _positionBordTip(mx, my) {
        var t = _bordTipEl;
        if (!t) return;
        var offset = 10;
        var left = mx - t.offsetWidth - offset;
        var top  = my - t.offsetHeight - offset;
        if (left < 8) left = mx + offset;
        if (top < 8) top = my + offset;
        t.style.left = left + 'px';
        t.style.top  = top  + 'px';
    }

    document.addEventListener('mouseover', function (e) {
        var el = e.target.closest ? e.target.closest('[data-bord-tip]') : null;
        if (!el) return;
        var details = el.closest('details');
        if (details && details.open) return;
        var t = _getOrCreateBordTip();
        t.innerHTML = _buildBordTipContent(el);
        t.style.display = 'block';
        _bordTipActive = true;
    });
    document.addEventListener('mouseout', function (e) {
        var el = e.target.closest ? e.target.closest('[data-bord-tip]') : null;
        if (el && !el.contains(e.relatedTarget)) {
            if (_bordTipEl) _bordTipEl.style.display = 'none';
            _bordTipActive = false;
        }
    });
    document.addEventListener('mousemove', function (e) {
        if (_bordTipActive) _positionBordTip(e.clientX, e.clientY);
    });

    // ── Menu contextuel ────────────────────────────────────────────────────────
    function dbBordCtxOpen(event, el) {
        event.preventDefault();
        event.stopPropagation();
        _bordEl = el;
        _bid    = el.dataset.bordId;
        _beid   = el.dataset.entrepriseId;
        _biid   = el.dataset.inviteId;

        var menu = document.getElementById('dbBordCtxMenu');
        if (!menu) return;
        var mw = 220, mh = 160;
        var left = (event.clientX + mw > window.innerWidth)  ? window.innerWidth  - mw - 6 : event.clientX;
        var top  = (event.clientY + mh > window.innerHeight) ? window.innerHeight - mh - 6 : event.clientY;
        menu.style.left    = left + 'px';
        menu.style.top     = top  + 'px';
        menu.style.display = 'block';

        var isCreator = el.dataset.isCreator === '1';
        var hasNote   = el.dataset.hasNote   === '1';

        var editItem   = document.getElementById('dbBordCtxEditItem');
        var analyseItem = document.getElementById('dbBordCtxAnalyseItem');
        var noteItem   = document.getElementById('dbBordCtxNoteItem');
        var noteSep    = document.getElementById('dbBordCtxNoteSeparator');
        var deleteItem = document.getElementById('dbBordCtxDeleteItem');

        if (editItem)   editItem.onclick   = dbBordCtxEdit;
        if (analyseItem) analyseItem.onclick = dbBordCtxAnalyse;

        if (noteItem && noteSep) {
            if (hasNote) {
                noteItem.style.display  = '';
                noteSep.style.display   = '';
                noteItem.onclick = dbBordCtxNote;
            } else {
                noteItem.style.display  = 'none';
                noteSep.style.display   = 'none';
                noteItem.onclick = null;
            }
        }

        if (deleteItem) {
            if (isCreator) {
                deleteItem.style.opacity = '';
                deleteItem.style.cursor  = 'pointer';
                deleteItem.onclick = dbBordCtxDelete;
            } else {
                deleteItem.style.opacity = '0.38';
                deleteItem.style.cursor  = 'not-allowed';
                deleteItem.onclick = null;
            }
        }

        setTimeout(function () {
            document.addEventListener('click', function hide() {
                var m = document.getElementById('dbBordCtxMenu');
                if (m) m.style.display = 'none';
                document.removeEventListener('click', hide);
            }, { once: true });
        }, 0);
    }

    function dbBordCtxEdit() {
        var menu = document.getElementById('dbBordCtxMenu');
        if (menu) menu.style.display = 'none';
        if (!_bid) return;
        document.dispatchEvent(new CustomEvent('app:loading.start'));
        document.dispatchEvent(new CustomEvent('app:boite-dialogue:init-request', {
            detail: {
                entityFormCanvas: {
                    parametres: {
                        titre_modification:  'Modifier le bordereau',
                        endpoint_form_url:   '/admin/bordereau/api/get-form',
                        endpoint_submit_url: '/admin/bordereau/api/submit'
                    }
                },
                entity:         { id: parseInt(_bid) },
                isCreationMode: false,
                context: {
                    idEntreprise:     parseInt(_beid) || undefined,
                    idInvite:         parseInt(_biid) || undefined,
                    _dashboardReload: true
                }
            }
        }));
        setTimeout(function () { document.dispatchEvent(new CustomEvent('app:loading.stop')); }, 600);
    }

    async function dbBordCtxAnalyse() {
        var menu = document.getElementById('dbBordCtxMenu');
        if (menu) menu.style.display = 'none';
        if (!_bid) return;
        try {
            document.dispatchEvent(new CustomEvent('app:loading.start'));
            const response = await fetch('/admin/bordereau/workspace-apercu/' + _bid);
            if (!response.ok) return;
            const { html, title } = await response.json();
            document.dispatchEvent(new CustomEvent('app:workspace.inject-html', {
                bubbles: true,
                detail: { html, title, iconAlias: 'bordereau', tabKey: 'bordereau-analyse-' + _bid, loadUrl: '/admin/bordereau/workspace-apercu/' + _bid }
            }));
        } catch (e) {
            console.error('[Analyser bordereau]', e);
        } finally {
            document.dispatchEvent(new CustomEvent('app:loading.stop'));
        }
    }

    function dbBordCtxNote() {
        var menu = document.getElementById('dbBordCtxMenu');
        if (menu) menu.style.display = 'none';
        if (!_bid) return;
        document.dispatchEvent(new CustomEvent('app:loading.start'));
        fetch('/admin/bordereau/api/get-linked-note-preview-url/' + _bid, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            document.dispatchEvent(new CustomEvent('app:loading.stop'));
            if (data && data.previewUrl) {
                window.location.href = data.previewUrl;
            }
        })
        .catch(function () {
            document.dispatchEvent(new CustomEvent('app:loading.stop'));
        });
    }

    function dbBordCtxDelete() {
        var menu = document.getElementById('dbBordCtxMenu');
        if (menu) menu.style.display = 'none';
        if (!_bordEl || !_bid) return;

        var nom = (_bordEl.dataset.bordNom || ('Bordereau #' + _bid));
        _bordPendingDel = _bordEl;

        document.dispatchEvent(new CustomEvent('ui:confirmation.request', {
            detail: {
                title: 'Supprimer le bordereau',
                body: 'Le bordereau sera définitivement supprimé. Cette action est irréversible.',
                itemDescriptions: [nom],
                showIrreversible: true,
                onConfirm: {
                    type: 'app:db-bord.delete-execute',
                    payload: { id: _bid }
                }
            }
        }));
    }

    document.addEventListener('cerveau:event', function (e) {
        if (e.detail.type !== 'app:db-bord.delete-execute') return;
        var p = e.detail.payload;
        var bid = p && p.id;
        if (!bid) return;

        var elToRemove = _bordPendingDel;
        document.dispatchEvent(new CustomEvent('app:loading.start'));

        fetch('/admin/bordereau/api/delete/' + bid, {
            method:  'DELETE',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function (r) { return r.json(); })
        .then(function () {
            document.dispatchEvent(new CustomEvent('app:loading.stop'));
            document.dispatchEvent(new CustomEvent('ui:confirmation.close'));
            if (elToRemove && elToRemove.parentNode) {
                elToRemove.parentNode.removeChild(elToRemove);
            }
            _bordPendingDel = null;
        })
        .catch(function () {
            document.dispatchEvent(new CustomEvent('app:loading.stop'));
            document.dispatchEvent(new CustomEvent('ui:confirmation.error', {
                detail: { error: 'La suppression a échoué.' }
            }));
            _bordPendingDel = null;
        });
    });

    // ── Bouton "+" nouveau bordereau ──────────────────────────────────────────
    function dbBordNew(btn) {
        var eid = btn.dataset.entrepriseId;
        var iid = btn.dataset.inviteId;
        document.dispatchEvent(new CustomEvent('app:loading.start'));
        document.dispatchEvent(new CustomEvent('app:boite-dialogue:init-request', {
            detail: {
                entityFormCanvas: {
                    parametres: {
                        titre_creation:      'Nouveau Bordereau',
                        endpoint_form_url:   '/admin/bordereau/api/get-form',
                        endpoint_submit_url: '/admin/bordereau/api/submit'
                    }
                },
                isCreationMode: true,
                context: {
                    idEntreprise:     parseInt(eid) || undefined,
                    idInvite:         parseInt(iid) || undefined,
                    _dashboardReload: true
                }
            }
        }));
        setTimeout(function () { document.dispatchEvent(new CustomEvent('app:loading.stop')); }, 600);
    }

    window.dbBordToggle  = dbBordToggle;
    window.dbBordSearch  = dbBordSearch;
    window.dbBordCtxOpen = dbBordCtxOpen;
    window.dbBordNew     = dbBordNew;
}());

// ── Bloc Notes ─────────────────────────────────────────────────────────────────
(function () {
    var _activeNoteMode = 'j30';
    var _noteEl         = null;  // details element courant
    var _nid            = null;  // note id
    var _neid           = null;  // entreprise id
    var _niid           = null;  // invite id
    var _noteTipEl      = null;
    var _noteTipActive  = false;
    var _notePendingDel = null;
    var _noteTimer      = null;
    var _noteObserver   = null;

    // ── Filtre toggle + recherche ──────────────────────────────────────────────
    function dbNoteApplyFilter() {
        var searchInput = document.querySelector('#db-note-list input[type="search"]') ||
                          document.querySelector('input[aria-label="Filtrer les notes"]');
        var q = searchInput ? searchInput.value.toLowerCase() : '';
        document.querySelectorAll('.db-note-item').forEach(function (el) {
            var matchMode   = el.dataset.group === _activeNoteMode;
            var matchSearch = !q || (el.dataset.searchText || '').indexOf(q) >= 0;
            el.style.display = (matchMode && matchSearch) ? '' : 'none';
        });
    }

    function dbNoteToggle(btn) {
        _activeNoteMode = btn.dataset.mode;
        document.querySelectorAll('#dbNoteToggleBar .db-task-toggle').forEach(function (b) {
            var isActive = b === btn;
            b.classList.toggle('active', isActive);
            b.setAttribute('aria-pressed', isActive ? 'true' : 'false');
        });
        dbNoteApplyFilter();
    }

    function dbNoteSearch() { dbNoteApplyFilter(); }

    // ── Tooltip data-note-tip ─────────────────────────────────────────────────
    function _getOrCreateNoteTip() {
        if (!_noteTipEl) {
            _noteTipEl = document.createElement('div');
            _noteTipEl.id = 'db-note-tip';
            document.body.appendChild(_noteTipEl);
        }
        return _noteTipEl;
    }

    function _buildNoteTipContent(span) {
        var ref    = span.dataset.noteTipRef    || '—';
        var dest   = span.dataset.noteTipDest   || '—';
        var type   = span.dataset.noteTipType   || '—';
        var statut = span.dataset.noteTipStatut || '—';
        var sColor = span.dataset.noteTipStatutColor || '#adb5bd';
        var mnt    = span.dataset.noteTipMontant || '—';
        var solde  = span.dataset.noteTipSolde  || '—';
        var date   = span.dataset.noteTipDate   || '—';
        var days   = span.dataset.noteTipDays   || '—';

        return '<table>' +
            '<tr><td colspan="2" class="tip-section">Note</td></tr>' +
            '<tr><td>Référence</td><td><strong>' + ref + '</strong></td></tr>' +
            '<tr><td>Type</td><td>' + type + '</td></tr>' +
            '<tr><td>Destinataire</td><td>' + dest + '</td></tr>' +
            '<tr><td>Statut</td><td class="tip-statut" style="color:' + sColor + '">' + statut + '</td></tr>' +
            '<tr><td colspan="2" class="tip-section">Finances</td></tr>' +
            '<tr><td>Total</td><td><strong>' + mnt + '</strong></td></tr>' +
            '<tr><td>Solde</td><td>' + solde + '</td></tr>' +
            '<tr><td colspan="2" class="tip-section">Date</td></tr>' +
            '<tr><td>Date</td><td>' + date + '</td></tr>' +
            '<tr><td>Âge</td><td>' + days + ' jour(s)</td></tr>' +
            '</table>';
    }

    function _positionNoteTip(mx, my) {
        var t = _noteTipEl;
        if (!t) return;
        var offset = 10;
        var left = mx - t.offsetWidth - offset;
        var top  = my - t.offsetHeight - offset;
        if (left < 8) left = mx + offset;
        if (top < 8) top = my + offset;
        t.style.left = left + 'px';
        t.style.top  = top  + 'px';
    }

    document.addEventListener('mouseover', function (e) {
        var el = e.target.closest ? e.target.closest('[data-note-tip]') : null;
        if (!el) return;
        var details = el.closest('details');
        if (details && details.open) return;
        var t = _getOrCreateNoteTip();
        t.innerHTML = _buildNoteTipContent(el);
        t.style.display = 'block';
        _noteTipActive = true;
    });
    document.addEventListener('mouseout', function (e) {
        var el = e.target.closest ? e.target.closest('[data-note-tip]') : null;
        if (el && !el.contains(e.relatedTarget)) {
            if (_noteTipEl) _noteTipEl.style.display = 'none';
            _noteTipActive = false;
        }
    });
    document.addEventListener('mousemove', function (e) {
        if (_noteTipActive) _positionNoteTip(e.clientX, e.clientY);
    });

    // ── Menu contextuel ────────────────────────────────────────────────────────
    function dbNoteCtxOpen(event, el) {
        event.preventDefault();
        event.stopPropagation();
        _noteEl = el;
        _nid    = el.dataset.noteId;
        _neid   = el.dataset.entrepriseId;
        _niid   = el.dataset.inviteId;

        var menu = document.getElementById('dbNoteCtxMenu');
        if (!menu) return;
        var mw = 220, mh = 200;
        var left = (event.clientX + mw > window.innerWidth)  ? window.innerWidth  - mw - 6 : event.clientX;
        var top  = (event.clientY + mh > window.innerHeight) ? window.innerHeight - mh - 6 : event.clientY;
        menu.style.left    = left + 'px';
        menu.style.top     = top  + 'px';
        menu.style.display = 'block';

        var editItem       = document.getElementById('dbNoteCtxEditItem');
        var previewItem    = document.getElementById('dbNoteCtxPreviewItem');
        var downloadItem   = document.getElementById('dbNoteCtxDownloadItem');
        var addPaiItem     = document.getElementById('dbNoteCtxAddPaiementItem');
        var deleteItem     = document.getElementById('dbNoteCtxDeleteItem');

        if (editItem)     editItem.onclick     = dbNoteCtxEdit;
        if (previewItem)  previewItem.onclick  = dbNoteCtxPreview;
        if (downloadItem) downloadItem.onclick = dbNoteCtxDownload;
        if (addPaiItem)   addPaiItem.onclick   = dbNoteCtxAddPaiement;
        if (deleteItem)   deleteItem.onclick   = dbNoteCtxDelete;

        setTimeout(function () {
            document.addEventListener('click', function hide() {
                var m = document.getElementById('dbNoteCtxMenu');
                if (m) m.style.display = 'none';
                document.removeEventListener('click', hide);
            }, { once: true });
        }, 0);
    }

    function dbNoteCtxEdit() {
        var menu = document.getElementById('dbNoteCtxMenu');
        if (menu) menu.style.display = 'none';
        if (!_nid) return;
        document.dispatchEvent(new CustomEvent('app:loading.start'));
        document.dispatchEvent(new CustomEvent('app:boite-dialogue:init-request', {
            detail: {
                entityFormCanvas: {
                    parametres: {
                        titre_modification:  'Modifier la note',
                        endpoint_form_url:   '/admin/note/api/get-form',
                        endpoint_submit_url: '/admin/note/api/submit'
                    }
                },
                entity:         { id: parseInt(_nid) },
                isCreationMode: false,
                context: {
                    idEntreprise:     parseInt(_neid) || undefined,
                    idInvite:         parseInt(_niid) || undefined,
                    _dashboardReload: true
                }
            }
        }));
        setTimeout(function () { document.dispatchEvent(new CustomEvent('app:loading.stop')); }, 600);
    }

    function dbNoteCtxPreview() {
        var menu = document.getElementById('dbNoteCtxMenu');
        if (menu) menu.style.display = 'none';
        if (!_nid) return;
        document.dispatchEvent(new CustomEvent('app:loading.start'));
        fetch('/admin/note/api/get-preview-url/' + _nid, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            document.dispatchEvent(new CustomEvent('app:loading.stop'));
            if (data && data.previewUrl) {
                window.location.href = data.previewUrl;
            }
        })
        .catch(function () {
            document.dispatchEvent(new CustomEvent('app:loading.stop'));
        });
    }

    function dbNoteCtxDownload() {
        var menu = document.getElementById('dbNoteCtxMenu');
        if (menu) menu.style.display = 'none';
        if (!_nid) return;
        window.location.href = '/admin/note/download-pdf/' + _nid;
    }

    function dbNoteCtxAddPaiement() {
        var menu = document.getElementById('dbNoteCtxMenu');
        if (menu) menu.style.display = 'none';
        if (!_nid) return;
        document.dispatchEvent(new CustomEvent('app:loading.start'));
        document.dispatchEvent(new CustomEvent('app:boite-dialogue:init-request', {
            detail: {
                entityFormCanvas: {
                    parametres: {
                        titre_creation:      'Ajouter un paiement',
                        endpoint_form_url:   '/admin/paiement/api/get-form',
                        endpoint_submit_url: '/admin/paiement/api/submit'
                    }
                },
                isCreationMode: true,
                parentContext: {
                    id:        parseInt(_nid),
                    fieldName: 'note'
                },
                context: {
                    idEntreprise:     parseInt(_neid) || undefined,
                    _dashboardReload: true
                }
            }
        }));
        setTimeout(function () { document.dispatchEvent(new CustomEvent('app:loading.stop')); }, 600);
    }

    function dbNoteCtxDelete() {
        var menu = document.getElementById('dbNoteCtxMenu');
        if (menu) menu.style.display = 'none';
        if (!_noteEl || !_nid) return;

        var nom = (_noteEl.dataset.noteNom || ('Note #' + _nid));
        _notePendingDel = _noteEl;

        document.dispatchEvent(new CustomEvent('ui:confirmation.request', {
            detail: {
                title: 'Supprimer la note',
                body: 'La note sera définitivement supprimée. Cette action est irréversible.',
                itemDescriptions: [nom],
                showIrreversible: true,
                onConfirm: {
                    type: 'app:db-note.delete-execute',
                    payload: { id: _nid }
                }
            }
        }));
    }

    document.addEventListener('cerveau:event', function (e) {
        if (e.detail.type !== 'app:db-note.delete-execute') return;
        var p = e.detail.payload;
        var nid = p && p.id;
        if (!nid) return;

        var elToRemove = _notePendingDel;
        var csrf = document.getElementById('db-note-csrf');
        var csrfToken = csrf ? csrf.content : '';
        document.dispatchEvent(new CustomEvent('app:loading.start'));

        fetch('/admin/note/api/delete/' + nid, {
            method:  'DELETE',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN':     csrfToken
            }
        })
        .then(function (r) { return r.json(); })
        .then(function () {
            document.dispatchEvent(new CustomEvent('app:loading.stop'));
            document.dispatchEvent(new CustomEvent('ui:confirmation.close'));
            if (elToRemove && elToRemove.parentNode) {
                elToRemove.parentNode.removeChild(elToRemove);
            }
            _notePendingDel = null;
        })
        .catch(function () {
            document.dispatchEvent(new CustomEvent('app:loading.stop'));
            document.dispatchEvent(new CustomEvent('ui:confirmation.error', {
                detail: { error: 'La suppression a échoué.' }
            }));
            _notePendingDel = null;
        });
    });

    // ── Bouton "+" nouvelle note ───────────────────────────────────────────────
    function dbNoteNew(btn) {
        var eid = btn.dataset.entrepriseId;
        var iid = btn.dataset.inviteId;
        document.dispatchEvent(new CustomEvent('app:loading.start'));
        document.dispatchEvent(new CustomEvent('app:boite-dialogue:init-request', {
            detail: {
                entityFormCanvas: {
                    parametres: {
                        titre_creation:      'Nouvelle Note',
                        endpoint_form_url:   '/admin/note/api/get-form',
                        endpoint_submit_url: '/admin/note/api/submit'
                    }
                },
                isCreationMode: true,
                context: {
                    idEntreprise:     parseInt(eid) || undefined,
                    idInvite:         parseInt(iid) || undefined,
                    _dashboardReload: true
                }
            }
        }));
        setTimeout(function () { document.dispatchEvent(new CustomEvent('app:loading.stop')); }, 600);
    }

    // ── Auto-refresh toutes les 5 minutes ─────────────────────────────────────
    function _notePad(n) { return n < 10 ? '0' + n : '' + n; }

    function _noteSetStatus(text) {
        var el = document.getElementById('db-note-last-update');
        if (el) el.textContent = text;
    }

    function refreshNotes() {
        var list = document.getElementById('db-note-list');
        if (!list) return;
        var details = list.closest('details');
        if (details && !details.open) return;
        var url = list.dataset.notesUrl;
        if (!url) return;

        _noteSetStatus('Mise à jour en cours…');

        dbFetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) {
                if (!r) return;
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.text();
            })
            .then(function (html) {
                if (!html) return;
                list.innerHTML = html;
                var now = new Date();
                _noteSetStatus('Dernière mise à jour à ' + _notePad(now.getHours()) + ':' + _notePad(now.getMinutes()));
                dbNoteApplyFilter();
            })
            .catch(function (err) {
                _noteSetStatus('Erreur de mise à jour');
                console.warn('[dashboard] notes refresh failed:', err);
            });
    }

    function initDbNotes() {
        var list = document.getElementById('db-note-list');
        if (!list) return;
        var now = new Date();
        _noteSetStatus('Dernière mise à jour à ' + _notePad(now.getHours()) + ':' + _notePad(now.getMinutes()));
        if (_noteTimer) { clearInterval(_noteTimer); }
        _noteTimer = setInterval(refreshNotes, 300000);
        dbNoteApplyFilter();
    }

    function _noteObserve() {
        if (document.getElementById('db-note-list')) {
            initDbNotes();
            return;
        }
        if (_noteObserver) { _noteObserver.disconnect(); }
        _noteObserver = new MutationObserver(function (mutations, obs) {
            if (document.getElementById('db-note-list')) {
                obs.disconnect();
                _noteObserver = null;
                initDbNotes();
            }
        });
        _noteObserver.observe(document.body, { childList: true, subtree: true });
    }
    _noteObserve();

    window.refreshNotes  = refreshNotes;
    window.dbNoteToggle  = dbNoteToggle;
    window.dbNoteSearch  = dbNoteSearch;
    window.dbNoteCtxOpen = dbNoteCtxOpen;
    window.dbNoteNew     = dbNoteNew;
}());

/* ══════════════════════════════════════════════════════
   BLOC PRODUCTION — histogramme mensuel (Chart.js)
   ══════════════════════════════════════════════════════ */
(function () {
    var _prodChart     = null;
    var _prodMode      = 'bar';
    var _prodCurrency  = '€';
    var _prodTimer     = null;
    var _prodObserver  = null;
    var _prodTipEl     = null;
    var _prodTableData      = null;
    var _prodTableUrl       = null;
    var _prodTableEl        = null;
    var _prodGroupData      = null;
    var _prodAssureurChart  = null;
    var _prodPartenaireChart = null;
    var _prodRisqueChart     = null;

    var MONTHS = ['Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre'];
    var MONTHS_SHORT = ['Jan','Fév','Mar','Avr','Mai','Jun','Jul','Aoû','Sep','Oct','Nov','Déc'];

    function _prodPad(n) { return n < 10 ? '0' + n : '' + n; }

    function _prodSetStatus(txt) {
        var el = document.getElementById('db-prod-last-update');
        if (el) el.textContent = txt;
    }

    function _prodFmt(val) {
        var n = parseFloat(val) || 0;
        return _prodCurrency + ' ' + n.toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function _getOrCreateProdTip() {
        if (!_prodTipEl) {
            _prodTipEl = document.createElement('div');
            _prodTipEl.className = 'db-prod-tip';
            document.body.appendChild(_prodTipEl);
        }
        return _prodTipEl;
    }

    function _prodExternalTooltip(context) {
        var tooltip = context.tooltip;
        var tip = _getOrCreateProdTip();

        if (tooltip.opacity === 0) {
            tip.style.display = 'none';
            return;
        }

        var idx = tooltip.dataPoints && tooltip.dataPoints[0] ? tooltip.dataPoints[0].dataIndex : -1;
        if (idx < 0) { tip.style.display = 'none'; return; }

        var val = tooltip.dataPoints[0].raw;
        var monthName = MONTHS[idx];
        var year = (context.chart.canvas.closest('[data-prod-year]') || document.getElementById('db-prod-chart-wrapper') || {}).dataset
            ? (document.getElementById('db-prod-chart-wrapper') || {}).dataset.prodYear || ''
            : '';

        tip.innerHTML =
            '<div style="font-weight:600;color:#0047AB;margin-bottom:2px;">' + monthName + (year ? ' ' + year : '') + '</div>' +
            '<div style="font-size:.82rem;">Encaissé : <strong>' + _prodFmt(val) + '</strong></div>';

        var chartPos = context.chart.canvas.getBoundingClientRect();
        var x = chartPos.left + window.scrollX + tooltip.caretX;
        var y = chartPos.top  + window.scrollY + tooltip.caretY;

        var offset = 12;
        var left = x - tip.offsetWidth - offset;
        var top  = y - tip.offsetHeight - offset;
        if (left < 8) left = x + offset;
        if (top  < 8) top  = y + offset;
        tip.style.left    = left + 'px';
        tip.style.top     = top  + 'px';
        tip.style.display = 'block';
    }

    function _buildChartConfig(data, mode) {
        var isLine = mode === 'line';
        return {
            type: mode,
            data: {
                labels: MONTHS_SHORT,
                datasets: [{
                    label: 'Encaissements',
                    data: data,
                    backgroundColor: isLine ? 'rgba(0,71,171,.12)' : 'rgba(0,71,171,.75)',
                    borderColor: '#0047AB',
                    borderWidth: isLine ? 2 : 1,
                    borderRadius: isLine ? 0 : 5,
                    pointBackgroundColor: '#0047AB',
                    pointRadius: isLine ? 4 : 0,
                    pointHoverRadius: isLine ? 6 : 0,
                    hoverBackgroundColor: isLine ? 'rgba(0,71,171,.25)' : 'rgba(0,71,171,1)',
                    fill: isLine,
                    tension: isLine ? 0.35 : 0,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: {
                    duration: 800,
                    easing: 'easeOutQuart',
                    delay: function (ctx) {
                        return ctx.type === 'data' ? ctx.dataIndex * 55 : 0;
                    }
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        enabled: false,
                        external: _prodExternalTooltip,
                        mode: 'index',
                        intersect: false,
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { font: { size: 11 }, color: '#6c757d' }
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: 'rgba(0,0,0,.05)' },
                        ticks: {
                            font: { size: 11 },
                            color: '#6c757d',
                            callback: function (v) {
                                if (v >= 1000) return _prodCurrency + ' ' + (v / 1000).toLocaleString('fr-FR') + 'k';
                                return _prodCurrency + ' ' + v;
                            }
                        }
                    }
                },
                onHover: function (event, elements, chart) {
                    chart.canvas.style.cursor = elements.length ? 'pointer' : 'default';
                }
            }
        };
    }

    function _createChart(data, mode) {
        var canvas = document.getElementById('db-prod-chart');
        if (!canvas) return;
        if (_prodChart) { _prodChart.destroy(); _prodChart = null; }
        _prodChart = new Chart(canvas, _buildChartConfig(data, mode));
    }

    function initDbProduction() {
        var wrapper = document.getElementById('db-prod-chart-wrapper');
        if (!wrapper) return;

        _prodCurrency = wrapper.dataset.prodCurrency || '€';
        _prodTableUrl = wrapper.dataset.prodTableUrl || null;
        var rawData   = JSON.parse(wrapper.dataset.prodMonthly || '[]');

        _createChart(rawData, _prodMode);
        _showProdGroup(_prodMode);

        var now = new Date();
        _prodSetStatus('Dernière mise à jour à ' + _prodPad(now.getHours()) + ':' + _prodPad(now.getMinutes()));

        if (_prodTimer) clearInterval(_prodTimer);
        _prodTimer = setInterval(refreshProduction, 180000);
    }

    function refreshProduction() {
        var wrapper = document.getElementById('db-prod-chart-wrapper');
        if (!wrapper || !_prodChart) return;
        var details = wrapper.closest('details');
        if (details && !details.open) return;
        var url = wrapper.dataset.prodUrl;
        if (!url) return;

        _prodSetStatus('Mise à jour en cours…');

        dbFetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) {
                if (!r) return;
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            })
            .then(function (json) {
                if (!json) return;
                _prodCurrency = json.currency || _prodCurrency;
                _prodChart.data.datasets[0].data = json.monthly;
                _prodChart.update('active');
                var now = new Date();
                _prodSetStatus('Dernière mise à jour à ' + _prodPad(now.getHours()) + ':' + _prodPad(now.getMinutes()));
            })
            .catch(function (err) {
                _prodSetStatus('Erreur de mise à jour');
                console.warn('[dashboard] production refresh failed:', err);
            });

        if (_prodMode === 'table' && _prodTableUrl && _prodTableEl) {
            dbFetch(_prodTableUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) {
                    if (!r) return;
                    if (!r.ok) throw new Error('HTTP ' + r.status);
                    return r.json();
                })
                .then(function (data) {
                    if (!data) return;
                    _prodTableData = data;
                    _prodCurrency  = data.currency || _prodCurrency;
                    _renderProdTable(data);
                })
                .catch(function (err) {
                    console.warn('[dashboard] production table refresh failed:', err);
                });
        }

        _prodGroupData = null;
        _showProdGroup(_prodMode);
    }

    function dbProdToggleMode(btn) {
        if (!btn) return;
        var mode = btn.dataset.mode;
        if (mode === _prodMode) return;
        _prodMode = mode;

        var bar   = document.querySelector('.db-task-toggle[data-mode="bar"]');
        var line  = document.querySelector('.db-task-toggle[data-mode="line"]');
        var tbl   = document.querySelector('.db-task-toggle[data-mode="table"]');
        if (bar)  { bar.classList.toggle('active',   mode === 'bar');   bar.setAttribute('aria-pressed',   mode === 'bar'   ? 'true' : 'false'); }
        if (line) { line.classList.toggle('active',  mode === 'line');  line.setAttribute('aria-pressed',  mode === 'line'  ? 'true' : 'false'); }
        if (tbl)  { tbl.classList.toggle('active',   mode === 'table'); tbl.setAttribute('aria-pressed',   mode === 'table' ? 'true' : 'false'); }

        var canvas  = document.getElementById('db-prod-chart');
        var wrapper = document.getElementById('db-prod-chart-wrapper');

        if (mode === 'table') {
            if (canvas) canvas.style.display = 'none';

            if (!_prodTableEl) {
                _prodTableEl = document.createElement('div');
                _prodTableEl.id = 'db-prod-table-container';
                _prodTableEl.className = 'db-prod-table-wrap';
                if (canvas) canvas.parentNode.insertBefore(_prodTableEl, canvas.nextSibling);
                else if (wrapper) wrapper.appendChild(_prodTableEl);
            }
            _prodTableEl.style.display = '';

            if (_prodTableData) {
                _renderProdTable(_prodTableData);
            } else {
                _prodTableEl.innerHTML = '<div class="db-prod-table-spinner"><div class="spinner-border spinner-border-sm" style="color:#0047AB;"></div><span>Chargement…</span></div>';
                if (_prodTableUrl) {
                    dbFetch(_prodTableUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                        .then(function (r) {
                            if (!r) return;
                            if (!r.ok) throw new Error('HTTP ' + r.status);
                            return r.json();
                        })
                        .then(function (data) {
                            if (!data) return;
                            _prodTableData = data;
                            _prodCurrency  = data.currency || _prodCurrency;
                            _renderProdTable(data);
                        })
                        .catch(function (err) {
                            _prodTableEl.innerHTML = '<div class="text-center py-4 text-danger small">Erreur de chargement du tableau</div>';
                            console.warn('[dashboard] production table failed:', err);
                        });
                }
            }
        } else {
            if (_prodTableEl) _prodTableEl.style.display = 'none';
            if (canvas) canvas.style.display = '';

            var data = _prodChart ? _prodChart.data.datasets[0].data
                                  : (wrapper ? JSON.parse(wrapper.dataset.prodMonthly || '[]') : []);
            _createChart(data, mode);
        }

        _showProdGroup(mode);
    }

    var MONTHS_SHORT_FR = ['janv.','févr.','mars','avr.','mai','juin','juil.','août','sept.','oct.','nov.','déc.'];

    function _renderProdTable(data) {
        if (!_prodTableEl) return;
        var rows        = data.rows        || {};
        var monthTotals = data.monthTotals || {};
        var grand       = data.grandTotals || {};
        function _fmtTaxLabel(nom, taux) {
            if (!taux || taux <= 0) return nom;
            var t = (taux % 1 === 0) ? Math.round(taux) : parseFloat(taux.toFixed(1));
            return nom + ' (' + t + '%)';
        }
        var taxCou = _fmtTaxLabel(data.taxeCourtierNom || 'Taxe courtier', data.taxeCourtierTaux);
        var taxAss = _fmtTaxLabel(data.taxeAssureurNom || 'Taxe assureur', data.taxeAssureurTaux);

        function fmtCells(obj) {
            return '<td>' + _prodFmt(obj.primeTtc          || 0) + '</td>'
                 + '<td>' + _prodFmt(obj.commissionPure    || 0) + '</td>'
                 + '<td>' + _prodFmt(obj.retrocommission   || 0) + '</td>'
                 + '<td>' + _prodFmt(obj.taxeCourtier      || 0) + '</td>'
                 + '<td>' + _prodFmt(obj.taxeAssureur      || 0) + '</td>'
                 + '<td>' + _prodFmt(obj.commissionTtc     || 0) + '</td>'
                 + '<td>' + _prodFmt(obj.encaissements     || 0) + '</td>'
                 + '<td>' + _prodFmt(obj.solde             || 0) + '</td>';
        }

        var html = '<table class="db-prod-table">'
                 + '<thead><tr>'
                 + '<th style="min-width:110px;text-align:left">Libellé</th>'
                 + '<th>Prime TTC</th>'
                 + '<th>Commission pure</th>'
                 + '<th>Rétrocommission</th>'
                 + '<th>' + taxCou + '</th>'
                 + '<th>' + taxAss + '</th>'
                 + '<th>Commission totale</th>'
                 + '<th>Encaissements</th>'
                 + '<th>Solde dû</th>'
                 + '</tr></thead><tbody>';

        var months = Object.keys(rows).map(Number).sort(function (a, b) { return a - b; });
        months.forEach(function (m) {
            var mLabel = MONTHS_SHORT_FR[m - 1] || ('M' + m);
            var tot    = monthTotals[m] || {};
            var assureurs = rows[m] || {};

            html += '<tr class="db-prod-tr-month" data-month="' + m + '" onclick="dbProdToggleMonth(this,' + m + ')">'
                  + '<td><span class="db-prod-chevron">▼</span>' + mLabel + '</td>'
                  + fmtCells(tot)
                  + '</tr>';

            Object.keys(assureurs).forEach(function (assId) {
                var ass = assureurs[assId];
                html += '<tr class="db-prod-tr-assureur" data-month="' + m + '">'
                      + '<td>' + (ass.nom || '—') + '</td>'
                      + fmtCells(ass)
                      + '</tr>';
            });
        });

        html += '</tbody>'
              + '<tfoot><tr class="db-prod-tr-grand">'
              + '<td>Total général</td>'
              + fmtCells(grand)
              + '</tr></tfoot></table>';

        _prodTableEl.innerHTML = html;
    }

    function dbProdToggleMonth(trEl, month) {
        if (!trEl) return;
        var isCollapsed = trEl.classList.toggle('collapsed');
        var subs = document.querySelectorAll('.db-prod-tr-assureur[data-month="' + month + '"]');
        subs.forEach(function (r) { r.hidden = isCollapsed; });
    }

    function _loadProdGroupData(cb) {
        if (_prodGroupData) { cb(_prodGroupData); return; }
        var wrapper = document.getElementById('db-prod-chart-wrapper');
        if (!wrapper || !wrapper.dataset.prodGroupUrl) return;
        dbFetch(wrapper.dataset.prodGroupUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) {
                if (!r) return;
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            })
            .then(function (data) { if (data) { _prodGroupData = data; cb(data); } })
            .catch(function (err) { console.warn('[dashboard] production group failed:', err); });
    }

    function _createGroupChart(canvasId, items, mode, color) {
        var canvas = document.getElementById(canvasId);
        if (!canvas) return null;
        var existing = Chart.getChart(canvas);
        if (existing) existing.destroy();
        var isLine = mode === 'line';
        return new Chart(canvas, {
            type: mode,
            data: {
                labels: items.map(function (i) { return i.nom; }),
                datasets: [{
                    label: 'Encaissements',
                    data: items.map(function (i) { return i.encaissements; }),
                    backgroundColor: isLine ? (color + '22') : (color + 'BF'),
                    borderColor: color,
                    borderWidth: isLine ? 2 : 1,
                    borderRadius: isLine ? 0 : 4,
                    pointBackgroundColor: color,
                    pointRadius: isLine ? 4 : 0,
                    pointHoverRadius: isLine ? 6 : 0,
                    fill: isLine,
                    tension: isLine ? 0.35 : 0,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { grid: { display: false }, ticks: { font: { size: 10 }, color: '#6c757d', maxRotation: 30 } },
                    y: {
                        beginAtZero: true,
                        grid: { color: 'rgba(0,0,0,.05)' },
                        ticks: {
                            font: { size: 10 }, color: '#6c757d',
                            callback: function (v) {
                                if (v >= 1000) return _prodCurrency + ' ' + (v / 1000).toLocaleString('fr-FR') + 'k';
                                return _prodCurrency + ' ' + v;
                            }
                        }
                    }
                }
            }
        });
    }

    function _createRisqueGroupChart(canvasId, items, mode) {
        var canvas = document.getElementById(canvasId);
        if (!canvas) return null;
        var existing = Chart.getChart(canvas);
        if (existing) existing.destroy();
        var isLine  = mode === 'line';
        var COLORS  = ['#0047AB', '#0d6efd', '#198754', '#e69500', '#003380', '#0a58ca'];
        var bgColors = items.map(function (_, i) { return COLORS[i % COLORS.length] + (isLine ? '22' : 'BF'); });
        var bdColors = items.map(function (_, i) { return COLORS[i % COLORS.length]; });
        var tipLabels = items.map(function (i) { return i.label || i.nom; });
        return new Chart(canvas, {
            type: mode,
            data: {
                labels: items.map(function (i) { return i.nom; }),
                datasets: [{
                    label: 'Encaissements',
                    data: items.map(function (i) { return i.encaissements; }),
                    backgroundColor: bgColors,
                    borderColor: bdColors,
                    borderWidth: isLine ? 2 : 1,
                    borderRadius: isLine ? 0 : 4,
                    pointBackgroundColor: bdColors,
                    pointRadius: isLine ? 4 : 0,
                    pointHoverRadius: isLine ? 6 : 0,
                    fill: isLine,
                    tension: isLine ? 0.35 : 0,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            title: function (ctx) { return tipLabels[ctx[0].dataIndex]; }
                        }
                    }
                },
                scales: {
                    x: { grid: { display: false }, ticks: { font: { size: 10 }, color: '#6c757d', maxRotation: 30 } },
                    y: {
                        beginAtZero: true,
                        grid: { color: 'rgba(0,0,0,.05)' },
                        ticks: {
                            font: { size: 10 }, color: '#6c757d',
                            callback: function (v) {
                                if (v >= 1000) return _prodCurrency + ' ' + (v / 1000).toLocaleString('fr-FR') + 'k';
                                return _prodCurrency + ' ' + v;
                            }
                        }
                    }
                }
            }
        });
    }

    function _renderGroupTable(containerId, items, groupData) {
        var el = document.getElementById(containerId);
        if (!el) return;
        if (!items || items.length === 0) {
            el.innerHTML = '<p class="text-muted small text-center py-3 mb-0">Aucune donnée</p>';
            return;
        }
        function _fmtTaxLabel(nom, taux) {
            if (!taux || taux <= 0) return nom;
            var t = (taux % 1 === 0) ? Math.round(taux) : parseFloat(taux.toFixed(1));
            return nom + ' (' + t + '%)';
        }
        var taxCou = _fmtTaxLabel((groupData && groupData.taxeCourtierNom) || 'Taxe courtier', (groupData && groupData.taxeCourtierTaux) || 0);
        var taxAss = _fmtTaxLabel((groupData && groupData.taxeAssureurNom) || 'Taxe assureur', (groupData && groupData.taxeAssureurTaux) || 0);
        var _fields = ['primeTtc','commissionPure','retrocommission','taxeCourtier','taxeAssureur','commissionTtc','encaissements','solde'];
        var grand   = {};
        _fields.forEach(function(f) { grand[f] = 0; });

        function fmtCells(obj) {
            return _fields.map(function(f) { return '<td>' + _prodFmt(obj[f] || 0) + '</td>'; }).join('');
        }

        var rows = items.map(function (item) {
            _fields.forEach(function(f) { grand[f] += item[f] || 0; });
            return '<tr class="db-prod-tr-assureur"><td>' + (item.nom || '—') + '</td>' + fmtCells(item) + '</tr>';
        }).join('');

        el.innerHTML = '<table class="db-prod-table">'
            + '<thead><tr>'
            + '<th style="min-width:110px;text-align:left;">Libellé</th>'
            + '<th>Prime TTC</th><th>Commission pure</th><th>Rétrocommission</th>'
            + '<th>' + taxCou + '</th><th>' + taxAss + '</th>'
            + '<th>Commission totale</th><th>Encaissements</th><th>Solde dû</th>'
            + '</tr></thead><tbody>' + rows + '</tbody>'
            + '<tfoot><tr class="db-prod-tr-grand"><td>Total général</td>' + fmtCells(grand) + '</tr></tfoot>'
            + '</table>';
    }

    function _renderAssureurDetailTable(containerId, items, groupData) {
        var el = document.getElementById(containerId);
        if (!el) return;
        if (!items || items.length === 0) {
            el.innerHTML = '<p class="text-muted small text-center py-3 mb-0">Aucune donnée</p>';
            return;
        }
        function _fmtTaxLabel(nom, taux) {
            if (!taux || taux <= 0) return nom;
            var t = (taux % 1 === 0) ? Math.round(taux) : parseFloat(taux.toFixed(1));
            return nom + ' (' + t + '%)';
        }
        var taxCou = _fmtTaxLabel((groupData && groupData.taxeCourtierNom) || 'Taxe courtier', (groupData && groupData.taxeCourtierTaux) || 0);
        var taxAss = _fmtTaxLabel((groupData && groupData.taxeAssureurNom) || 'Taxe assureur', (groupData && groupData.taxeAssureurTaux) || 0);
        var _fields = ['primeTtc','commissionPure','retrocommission','taxeCourtier','taxeAssureur','commissionTtc','encaissements','solde'];
        var monthly = (groupData && groupData.byAssureurMonthly) || {};
        var grand   = {};
        _fields.forEach(function(f) { grand[f] = 0; });

        function fmtCells(obj) {
            return _fields.map(function(f) { return '<td>' + _prodFmt(obj[f] || 0) + '</td>'; }).join('');
        }

        var rows = '';
        items.forEach(function (item) {
            var assId = item.id;
            _fields.forEach(function(f) { grand[f] += item[f] || 0; });
            rows += '<tr class="db-prod-tr-month db-prod-tr-ass-head" data-ass-id="' + assId + '"'
                  + ' onclick="dbProdToggleAssureur(this,' + assId + ')">'
                  + '<td><span class="db-prod-chevron">▼</span>' + (item.nom || '—') + '</td>'
                  + fmtCells(item) + '</tr>';

            var monthData = monthly[assId] || {};
            var sortedMonths = Object.keys(monthData).map(Number).sort(function(a,b){ return a-b; });
            sortedMonths.forEach(function(m) {
                var mLabel = MONTHS_SHORT_FR[m - 1] || ('M' + m);
                rows += '<tr class="db-prod-tr-assureur db-prod-tr-ass-month" data-ass-id="' + assId + '">'
                      + '<td style="padding-left:1.5rem;">' + mLabel + '</td>'
                      + fmtCells(monthData[m])
                      + '</tr>';
            });
        });

        el.innerHTML = '<table class="db-prod-table">'
            + '<thead><tr>'
            + '<th style="min-width:130px;text-align:left;">Libellé</th>'
            + '<th>Prime TTC</th><th>Commission pure</th><th>Rétrocommission</th>'
            + '<th>' + taxCou + '</th><th>' + taxAss + '</th>'
            + '<th>Commission totale</th><th>Encaissements</th><th>Solde dû</th>'
            + '</tr></thead><tbody>' + rows + '</tbody>'
            + '<tfoot><tr class="db-prod-tr-grand"><td>Total général</td>' + fmtCells(grand) + '</tr></tfoot>'
            + '</table>';
    }

    function dbProdToggleAssureur(trEl, assId) {
        if (!trEl) return;
        var isCollapsed = trEl.classList.toggle('collapsed');
        var subs = document.querySelectorAll('.db-prod-tr-ass-month[data-ass-id="' + assId + '"]');
        subs.forEach(function (r) { r.hidden = isCollapsed; });
    }

    function dbProdToggleRisque(trEl, risId) {
        if (!trEl) return;
        var isCollapsed = trEl.classList.toggle('collapsed');
        document.querySelectorAll('.db-prod-tr-ris-month[data-ris-id="' + risId + '"]')
            .forEach(function (r) { r.hidden = isCollapsed; });
    }
    window.dbProdToggleRisque = dbProdToggleRisque;

    function _renderRisqueDetailTable(containerId, items, groupData) {
        var el = document.getElementById(containerId);
        if (!el) return;
        if (!items || items.length === 0) {
            el.innerHTML = '<p class="text-muted small text-center py-3 mb-0">Aucune donnée</p>';
            return;
        }
        function _fmtTaxLabelR(nom, taux) {
            if (!taux || taux <= 0) return nom;
            var t = (taux % 1 === 0) ? Math.round(taux) : parseFloat(taux.toFixed(1));
            return nom + ' (' + t + '%)';
        }
        var taxCou  = _fmtTaxLabelR((groupData && groupData.taxeCourtierNom) || 'Taxe courtier', (groupData && groupData.taxeCourtierTaux) || 0);
        var taxAss  = _fmtTaxLabelR((groupData && groupData.taxeAssureurNom) || 'Taxe assureur', (groupData && groupData.taxeAssureurTaux) || 0);
        var _fields = ['primeTtc','commissionPure','retrocommission','taxeCourtier','taxeAssureur','commissionTtc','encaissements','solde'];
        var monthly = (groupData && groupData.byRisqueMonthly) || {};
        var grand   = {};
        _fields.forEach(function(f) { grand[f] = 0; });

        function fmtCells(obj) {
            return _fields.map(function(f) { return '<td>' + _prodFmt(obj[f] || 0) + '</td>'; }).join('');
        }

        var rows = '';
        items.forEach(function (item) {
            var risId = item.id;
            _fields.forEach(function(f) { grand[f] += item[f] || 0; });
            rows += '<tr class="db-prod-tr-month db-prod-tr-ass-head" data-ris-id="' + risId + '"'
                  + ' onclick="dbProdToggleRisque(this,\'' + risId + '\')">'
                  + '<td><span class="db-prod-chevron">▼</span>' + (item.nom || '—') + '</td>'
                  + fmtCells(item) + '</tr>';

            var monthData = monthly[risId] || {};
            var sortedMonths = Object.keys(monthData).map(Number).sort(function(a,b){ return a-b; });
            sortedMonths.forEach(function(m) {
                var mLabel = MONTHS_SHORT_FR[m - 1] || ('M' + m);
                rows += '<tr class="db-prod-tr-assureur db-prod-tr-ris-month" data-ris-id="' + risId + '">'
                      + '<td style="padding-left:1.5rem;">' + mLabel + '</td>'
                      + fmtCells(monthData[m])
                      + '</tr>';
            });
        });

        el.innerHTML = '<table class="db-prod-table">'
            + '<thead><tr>'
            + '<th style="min-width:130px;text-align:left;">Libellé</th>'
            + '<th>Prime TTC</th><th>Commission pure</th><th>Rétrocommission</th>'
            + '<th>' + taxCou + '</th><th>' + taxAss + '</th>'
            + '<th>Commission totale</th><th>Encaissements</th><th>Solde dû</th>'
            + '</tr></thead><tbody>' + rows + '</tbody>'
            + '<tfoot><tr class="db-prod-tr-grand"><td>Total général</td>' + fmtCells(grand) + '</tr></tfoot>'
            + '</table>';
    }

    function _showProdGroup(mode) {
        var wrapper = document.getElementById('db-prod-chart-wrapper');
        if (!wrapper || !wrapper.dataset.prodGroupUrl) return;

        _loadProdGroupData(function (data) {
            var isChart   = mode === 'bar' || mode === 'line';
            var assWrap   = document.getElementById('db-prod-assureur-wrap');
            var parWrap   = document.getElementById('db-prod-partenaire-wrap');
            var risWrap   = document.getElementById('db-prod-risque-wrap');
            var assCanvas = document.getElementById('db-prod-chart-assureur');
            var parCanvas = document.getElementById('db-prod-chart-partenaire');
            var risCanvas = document.getElementById('db-prod-chart-risque');
            var assTbl    = document.getElementById('db-prod-table-assureur');
            var parTbl    = document.getElementById('db-prod-table-partenaire');
            var risTbl    = document.getElementById('db-prod-table-risque');
            var parMsg    = document.getElementById('db-prod-partenaire-msg');

            // ── Assureur ──
            if (assWrap)   assWrap.style.display   = '';
            if (assCanvas) assCanvas.style.display = isChart ? '' : 'none';
            if (assTbl)    assTbl.style.display    = isChart ? 'none' : '';
            if (isChart) {
                _prodAssureurChart = _createGroupChart('db-prod-chart-assureur', data.byAssureur || [], mode, '#198754');
            } else {
                _renderAssureurDetailTable('db-prod-table-assureur', data.byAssureur || [], data);
            }

            // ── Partenaire ──
            if (parWrap) parWrap.style.display = '';
            var byPar = data.byPartenaire || [];
            var realPar = byPar.filter(function (i) { return i.nom !== 'Sans partenaire'; });
            var allNone = realPar.length === 0;

            if (allNone) {
                if (parCanvas) parCanvas.style.display = 'none';
                if (parTbl)    parTbl.style.display    = 'none';
                if (parMsg)    parMsg.style.display    = '';
            } else {
                if (parMsg)    parMsg.style.display    = 'none';
                if (parCanvas) parCanvas.style.display = isChart ? '' : 'none';
                if (parTbl)    parTbl.style.display    = isChart ? 'none' : '';
                if (isChart) {
                    _prodPartenaireChart = _createGroupChart('db-prod-chart-partenaire', realPar, mode, '#e69500');
                } else {
                    _renderGroupTable('db-prod-table-partenaire', realPar, data);
                }
            }

            // ── Risque ──
            if (risWrap)   risWrap.style.display   = '';
            if (risCanvas) risCanvas.style.display = isChart ? '' : 'none';
            if (risTbl)    risTbl.style.display    = isChart ? 'none' : '';
            if (isChart) {
                _prodRisqueChart = _createRisqueGroupChart('db-prod-chart-risque', data.byRisque || [], mode);
            } else {
                _renderRisqueDetailTable('db-prod-table-risque', data.byRisque || [], data);
            }
        });
    }

    function _prodObserveDOM() {
        if (document.getElementById('db-prod-chart-wrapper')) {
            initDbProduction();
            return;
        }
        if (_prodObserver) { _prodObserver.disconnect(); }
        _prodObserver = new MutationObserver(function (mutations, obs) {
            if (document.getElementById('db-prod-chart-wrapper')) {
                obs.disconnect();
                _prodObserver = null;
                initDbProduction();
            }
        });
        _prodObserver.observe(document.body, { childList: true, subtree: true });
    }

    document.addEventListener('mouseleave', function (e) {
        if (_prodTipEl && e.target && e.target.closest && e.target.closest('#db-prod-chart')) {
            _prodTipEl.style.display = 'none';
        }
    }, true);

    _prodObserveDOM();

    window.refreshProduction      = refreshProduction;
    window.dbProdToggleMode       = dbProdToggleMode;
    window.dbProdToggleMonth      = dbProdToggleMonth;
    window.dbProdToggleAssureur   = dbProdToggleAssureur;
}());

// ── Menu contextuel invités du tableau de bord ──────────────────────────────
(function () {
    var _el              = null;
    var _id              = null;  // id de l'invite ciblé
    var _eid             = null;  // id de l'entreprise
    var _iid             = null;  // id de l'invite du user connecté
    var _pendingDeleteEl = null;

    function dbInviteCtxOpen(event, el) {
        event.preventDefault();
        event.stopPropagation();
        _el  = el;
        _id  = el.dataset.inviteId;
        _eid = el.dataset.entrepriseId;
        _iid = el.dataset.currentInviteId;

        var menu = document.getElementById('dbInviteCtxMenu');
        if (!menu) return;
        var mw = 230, mh = 140;
        var left = (event.clientX + mw > window.innerWidth)  ? window.innerWidth  - mw - 6 : event.clientX;
        var top  = (event.clientY + mh > window.innerHeight) ? window.innerHeight - mh - 6 : event.clientY;
        menu.style.left    = left + 'px';
        menu.style.top     = top  + 'px';
        menu.style.display = 'block';

        setTimeout(function () {
            document.addEventListener('click', function hide() {
                var m = document.getElementById('dbInviteCtxMenu');
                if (m) m.style.display = 'none';
                document.removeEventListener('click', hide);
            }, { once: true });
        }, 0);
    }

    function dbInviteCtxEdit() {
        var menu = document.getElementById('dbInviteCtxMenu');
        if (menu) menu.style.display = 'none';
        if (!_id) return;

        document.dispatchEvent(new CustomEvent('app:loading.start'));
        document.dispatchEvent(new CustomEvent('app:boite-dialogue:init-request', {
            detail: {
                entityFormCanvas: {
                    parametres: {
                        titre_modification:  'Modifier l\'invité',
                        endpoint_form_url:   '/admin/invite/api/get-form',
                        endpoint_submit_url: '/admin/invite/api/submit'
                    }
                },
                entity:         { id: parseInt(_id) },
                isCreationMode: false,
                context: {
                    idEntreprise:     parseInt(_eid),
                    idInvite:         parseInt(_iid) || undefined,
                    _dashboardReload: true
                }
            }
        }));
        setTimeout(function () {
            document.dispatchEvent(new CustomEvent('app:loading.stop'));
        }, 600);
    }

    function dbInviteCtxResend() {
        var menu = document.getElementById('dbInviteCtxMenu');
        if (menu) menu.style.display = 'none';
        if (!_id) return;

        document.dispatchEvent(new CustomEvent('app:loading.start'));
        fetch('/admin/invite/api/resend-invitation/' + _id, {
            method:  'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/json' }
        })
        .then(function (r) { return r.json(); })
        .then(function () {
            document.dispatchEvent(new CustomEvent('app:loading.stop'));
        })
        .catch(function () {
            document.dispatchEvent(new CustomEvent('app:loading.stop'));
        });
    }

    function dbInviteCtxDelete() {
        var menu = document.getElementById('dbInviteCtxMenu');
        if (menu) menu.style.display = 'none';
        if (!_el || !_id) return;

        var el = _el;
        var nameEl = el.querySelector('.db-invite-name');
        var inviteName = nameEl ? nameEl.textContent.trim() : ('Invité #' + _id);
        _pendingDeleteEl = el;

        document.dispatchEvent(new CustomEvent('ui:confirmation.request', {
            detail: {
                title: 'Supprimer l\'invité',
                body: 'Cette action est irréversible. L\'invité sera définitivement supprimé.',
                itemDescriptions: [inviteName],
                onConfirm: {
                    type: 'app:db-invite.delete-execute',
                    payload: { id: _id }
                }
            }
        }));
    }

    document.addEventListener('cerveau:event', function (e) {
        if (e.detail.type !== 'app:db-invite.delete-execute') return;
        var p   = e.detail.payload;
        var iid = p && p.id;
        if (!iid) return;

        var el = _pendingDeleteEl;
        document.dispatchEvent(new CustomEvent('app:loading.start'));

        fetch('/admin/invite/api/delete/' + iid, {
            method:  'DELETE',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/json' }
        })
        .then(function (r) { return r.json(); })
        .then(function () {
            document.dispatchEvent(new CustomEvent('app:loading.stop'));
            document.dispatchEvent(new CustomEvent('ui:confirmation.close'));
            _pendingDeleteEl = null;
            document.dispatchEvent(new CustomEvent('app:dashboard.sidebar.reload'));
        })
        .catch(function () {
            document.dispatchEvent(new CustomEvent('app:loading.stop'));
            document.dispatchEvent(new CustomEvent('ui:confirmation.error', {
                detail: { error: 'La suppression a échoué.' }
            }));
            _pendingDeleteEl = null;
        });
    });

    window.dbInviteCtxOpen   = dbInviteCtxOpen;
    window.dbInviteCtxEdit   = dbInviteCtxEdit;
    window.dbInviteCtxResend = dbInviteCtxResend;
    window.dbInviteCtxDelete = dbInviteCtxDelete;
}());

// ── Équipe (users) : auto-refresh toutes les 3 minutes ──────────────────────
(function () {
    var _usersTimer    = null;
    var _usersObserver = null;

    function _usersPad(n) { return n < 10 ? '0' + n : '' + n; }

    function _usersSetStatus(text) {
        var el = document.getElementById('db-users-last-update');
        if (el) el.textContent = text;
    }

    function refreshUsers() {
        var list = document.getElementById('db-users-list');
        if (!list) return;
        var url = list.dataset.usersUrl;
        if (!url) return;
        _usersSetStatus('Mise à jour en cours…');
        fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.text();
            })
            .then(function (html) {
                list.innerHTML = html;
                var now = new Date();
                _usersSetStatus('Dernière mise à jour à ' + _usersPad(now.getHours()) + ':' + _usersPad(now.getMinutes()));
            })
            .catch(function (err) {
                _usersSetStatus('Erreur de mise à jour');
                console.warn('[dashboard] users refresh failed:', err);
            });
    }

    function initDbUsers() {
        var list = document.getElementById('db-users-list');
        if (!list) return;
        var now = new Date();
        _usersSetStatus('Dernière mise à jour à ' + _usersPad(now.getHours()) + ':' + _usersPad(now.getMinutes()));
        if (_usersTimer) clearInterval(_usersTimer);
        _usersTimer = setInterval(refreshUsers, 180000);
    }

    function _usersObserve() {
        if (document.getElementById('db-users-list')) { initDbUsers(); return; }
        if (_usersObserver) _usersObserver.disconnect();
        _usersObserver = new MutationObserver(function (mutations, obs) {
            if (document.getElementById('db-users-list')) {
                obs.disconnect();
                _usersObserver = null;
                initDbUsers();
            }
        });
        _usersObserver.observe(document.body, { childList: true, subtree: true });
    }
    _usersObserve();

    window.initDbUsers  = initDbUsers;
    window.refreshUsers = refreshUsers;
}());