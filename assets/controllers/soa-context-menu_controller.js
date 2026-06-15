import { Controller } from '@hotwired/stimulus';

/**
 * Infobulle de section partagée (singleton au niveau module, comme #db-meta-tip
 * du tableau de bord). Posée sur document.body car .soa-container a overflow:hidden
 * et clipperait une bulle interne. Réutilisée par tous les onglets SOA.
 */
let _soaTipEl = null;
function getSoaTip() {
    if (!_soaTipEl) {
        _soaTipEl = document.createElement('div');
        _soaTipEl.className = 'soa-section-tip';
        document.body.appendChild(_soaTipEl);
    }
    return _soaTipEl;
}

/**
 * Configuration des menus contextuels par section du SOA.
 * Chaque clé correspond à un attribut data-soa-section posé dans le template
 * soa_client_workspace.html.twig. Les actions s'appuient sur les endpoints API
 * génériques /admin/{entité}/api/get-form|submit|delete.
 */
const SECTIONS = {
    recap: {
        items: ['refresh', 'apercu'],
    },
    contacts: {
        items: ['refresh', 'apercu', 'create', 'edit', 'delete'],
        createLabel: 'Ajouter un contact',
        editLabel: 'Editer le contact',
        titres: { creation: 'Ajouter un contact', modification: 'Modifier le contact' },
        api: '/admin/contact/api',
        parentField: 'client', // pré-remplit et masque le champ client à la création
        deleteTitle: 'Supprimer le contact',
        deleteBody: 'Le contact sera définitivement supprimé. Cette action est irréversible.',
    },
    polices: {
        items: ['refresh', 'apercu', 'edit'],
        editLabel: 'Editer la police',
        titres: { modification: 'Modifier la police' },
        api: '/admin/avenant/api',
    },
    pistes: {
        items: ['refresh', 'apercu', 'edit', 'delete'],
        editLabel: 'Editer la piste',
        titres: { modification: 'Modifier la piste' },
        api: '/admin/piste/api',
        deleteTitle: 'Supprimer la piste',
        deleteBody: 'La piste sera définitivement supprimée. Cette action est irréversible.',
    },
    cotations: {
        items: ['refresh', 'apercu', 'edit', 'delete'],
        editLabel: 'Editer la cotation',
        titres: { modification: 'Modifier la cotation' },
        api: '/admin/cotation/api',
        deleteTitle: 'Supprimer la cotation',
        deleteBody: 'La cotation sera définitivement supprimée. Cette action est irréversible.',
    },
    tranches: {
        items: ['refresh', 'apercu', 'edit'],
        editLabel: 'Editer la tranche',
        titres: { modification: 'Modifier la tranche' },
        api: '/admin/tranche/api',
    },
    sinistres: {
        items: ['refresh', 'apercu', 'edit', 'delete'],
        editLabel: 'Editer la notification',
        titres: { modification: 'Modifier la notification de sinistre' },
        api: '/admin/notificationsinistre/api',
        deleteTitle: 'Supprimer le sinistre',
        deleteBody: 'Le sinistre sera définitivement supprimé. Cette action est irréversible.',
    },
    taches: {
        items: ['refresh', 'apercu', 'edit', 'delete'],
        editLabel: 'Editer la tâche',
        titres: { modification: 'Modifier la tâche' },
        api: '/admin/tache/api',
        deleteTitle: 'Supprimer la tâche',
        deleteBody: 'La tâche sera définitivement supprimée. Cette action est irréversible.',
    },
    'cross-nouveaux': {
        items: ['refresh', 'apercu', 'create'],
        createLabel: 'Créer la piste',
        titres: { creation: 'Créer une piste' },
        api: '/admin/piste/api',
        pisteCreate: true, // pré-remplissage ?idClient=&idRisque= côté serveur
    },
    'cross-relancer': {
        items: ['refresh', 'apercu', 'edit'],
        editLabel: 'Editer la piste',
        titres: { modification: 'Modifier la piste' },
        api: '/admin/piste/api',
    },
};

/**
 * @class SoaContextMenuController
 * @description Menu contextuel des tableaux de sections du SOA Workspace.
 * Une instance par onglet SOA (le contrôleur est posé sur .soa-preview-workspace).
 * Réutilise le design sombre .context-menu du tableau de bord et la plomberie
 * événementielle existante (boîte de dialogue, boîte de confirmation, reload d'onglet).
 */
export default class extends Controller {
    static targets = ['menu', 'createLabel', 'editLabel'];
    static values = {
        idEntreprise: Number,
        idInvite: Number,
        clientId: Number,
        clientNom: String,
        apercuUrl: String,
    };

    connect() {
        this.nomControleur = 'SOA-CONTEXT-MENU';
        // Identifiant d'instance : garde anti double-traitement quand plusieurs onglets SOA sont ouverts.
        this.instanceId = `soa-ctx-${Date.now()}-${Math.random().toString(36).slice(2)}`;
        this.current = null;
        this._previousFocus = null;

        this._onContextMenu = this._onContextMenu.bind(this);
        this._onDocPointerDown = this._onDocPointerDown.bind(this);
        this._onKeydown = this._onKeydown.bind(this);
        this._onScrollOrResize = this._onScrollOrResize.bind(this);
        this._onCerveauEvent = this._onCerveauEvent.bind(this);
        this._onOtherMenuOpen = this._onOtherMenuOpen.bind(this);

        this.element.addEventListener('contextmenu', this._onContextMenu);
        document.addEventListener('cerveau:event', this._onCerveauEvent);
        document.addEventListener('soa:context-menu.open', this._onOtherMenuOpen);

        // Accordéon des sections (un partial injecté dynamiquement n'exécute pas
        // les balises <script> : la logique doit vivre ici, dans le contrôleur Stimulus).
        this._initAccordion();
        this._initTooltips();
    }

    disconnect() {
        this._close();
        this.element.removeEventListener('contextmenu', this._onContextMenu);
        document.removeEventListener('cerveau:event', this._onCerveauEvent);
        document.removeEventListener('soa:context-menu.open', this._onOtherMenuOpen);
        this._removeTransientListeners();

        // Infobulles : retire les écouteurs et masque la bulle partagée.
        if (this._onTipOver) this.element.removeEventListener('mouseover', this._onTipOver);
        if (this._onTipMove) this.element.removeEventListener('mousemove', this._onTipMove);
        if (this._onTipOut) this.element.removeEventListener('mouseout', this._onTipOut);
        if (_soaTipEl) _soaTipEl.style.display = 'none';
    }

    // ── Accordéon des sections ─────────────────────────────────────────────────

    /**
     * Rend chaque titre de section cliquable (toggle ouverture/fermeture avec
     * animation max-height). Seule la première section (Récapitulatif Global)
     * reste ouverte au chargement. Le contenu de chaque section est enveloppé
     * dans un div interne porteur du padding : ainsi max-height:0 referme la
     * section à 0px exact, sans espace résiduel dû au padding.
     */
    _initAccordion() {
        const sections = this.element.querySelectorAll('.soa-container > section');
        sections.forEach((section, idx) => {
            const title = section.querySelector('.soa-section-title');
            const body = section.querySelector('.soa-section-body');
            if (!title || !body) return;

            // Enveloppe le contenu existant dans un div interne (porte le padding).
            const inner = document.createElement('div');
            inner.className = 'soa-section-body-inner';
            while (body.firstChild) inner.appendChild(body.firstChild);
            body.appendChild(inner);

            // Chevron indicateur d'état.
            const chevron = document.createElement('span');
            chevron.className = 'soa-toggle-chevron';
            chevron.setAttribute('aria-hidden', 'true');
            chevron.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path fill-rule="evenodd" d="M1.646 4.646a.5.5 0 0 1 .708 0L8 10.293l5.646-5.647a.5.5 0 0 1 .708.708l-6 6a.5.5 0 0 1-.708 0l-6-6a.5.5 0 0 1 0-.708z"/></svg>';
            title.appendChild(chevron);

            title.setAttribute('role', 'button');
            title.setAttribute('tabindex', '0');

            // État initial instantané : 1re section ouverte, les autres fermées.
            body.style.transition = 'none';
            if (idx === 0) {
                body.style.maxHeight = `${body.scrollHeight}px`;
                title.classList.add('soa-open');
                title.setAttribute('aria-expanded', 'true');
            } else {
                body.style.maxHeight = '0';
                title.setAttribute('aria-expanded', 'false');
            }
            body.offsetHeight; // reflow : fige l'état avant de réactiver la transition
            body.style.transition = '';

            const toggle = () => {
                if (title.classList.contains('soa-open')) {
                    body.style.maxHeight = `${body.scrollHeight}px`;
                    body.offsetHeight;
                    body.style.maxHeight = '0';
                    title.classList.remove('soa-open');
                    title.setAttribute('aria-expanded', 'false');
                } else {
                    body.style.maxHeight = `${body.scrollHeight}px`;
                    title.classList.add('soa-open');
                    title.setAttribute('aria-expanded', 'true');
                    const onEnd = (e) => {
                        if (e.propertyName !== 'max-height') return;
                        if (title.classList.contains('soa-open')) body.style.maxHeight = 'none';
                        body.removeEventListener('transitionend', onEnd);
                    };
                    body.addEventListener('transitionend', onEnd);
                }
            };

            title.addEventListener('click', toggle);
            title.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggle(); }
            });
        });
    }

    // ── Infobulles de section ──────────────────────────────────────────────────

    /**
     * Affiche une infobulle (titre en gras + description) au survol de la barre
     * de titre d'une section. Le titre est dérivé du <h2>, la description vient
     * de l'attribut data-soa-tip. Délégation sur l'élément du contrôleur : la
     * bulle suit le curseur et disparaît quand il quitte la barre.
     */
    _initTooltips() {
        this._onTipOver = (event) => {
            const title = event.target.closest('.soa-section-title[data-soa-tip]');
            if (!title || !this.element.contains(title)) return;
            const heading = title.querySelector('h2');
            const titleText = heading ? heading.textContent.trim() : '';
            const descText = title.dataset.soaTip || '';
            const tip = getSoaTip();
            tip.innerHTML = '';
            if (titleText) {
                const t = document.createElement('span');
                t.className = 'soa-tip-title';
                t.textContent = titleText;
                tip.appendChild(t);
            }
            const d = document.createElement('span');
            d.className = 'soa-tip-desc';
            d.textContent = descText;
            tip.appendChild(d);
            tip.style.display = 'block';
            this._positionTip(event.clientX, event.clientY);
        };
        this._onTipMove = (event) => {
            if (_soaTipEl && _soaTipEl.style.display === 'block') {
                this._positionTip(event.clientX, event.clientY);
            }
        };
        this._onTipOut = (event) => {
            const title = event.target.closest('.soa-section-title[data-soa-tip]');
            if (!title) return;
            if (!title.contains(event.relatedTarget) && _soaTipEl) {
                _soaTipEl.style.display = 'none';
            }
        };

        this.element.addEventListener('mouseover', this._onTipOver);
        this.element.addEventListener('mousemove', this._onTipMove);
        this.element.addEventListener('mouseout', this._onTipOut);
    }

    /** Positionne la bulle au-dessus du curseur, bornée au viewport. */
    _positionTip(mx, my) {
        const tip = _soaTipEl;
        if (!tip) return;
        const offset = 14;
        let left = mx - tip.offsetWidth / 2;
        let top = my - tip.offsetHeight - offset;
        left = Math.max(8, Math.min(left, window.innerWidth - tip.offsetWidth - 8));
        if (top < 8) top = my + offset; // pas de place au-dessus → sous le curseur
        tip.style.left = `${left}px`;
        tip.style.top = `${top}px`;
    }

    // ── Ouverture ────────────────────────────────────────────────────────────

    _onContextMenu(event) {
        this._close();

        // En dehors des zones marquées, le menu natif du navigateur reste disponible.
        const zone = event.target.closest('[data-soa-section]');
        if (!zone) return;

        const section = zone.dataset.soaSection;
        const config = SECTIONS[section];
        if (!config) return;

        event.preventDefault();
        event.stopPropagation();

        const row = event.target.closest('tr[data-id]');
        const hasRow = !!(row && row.dataset.id);
        const inTable = !!event.target.closest('table');
        this.current = { config, row: hasRow ? row : null, section };

        // Calcul des items visibles : les actions de ligne exigent une ligne valide,
        // les actions de création exigent d'être dans le tableau de la section.
        const visible = config.items.filter((key) => {
            if (key === 'edit' || key === 'delete') return hasRow;
            if (key === 'create') return config.pisteCreate ? hasRow : inTable;
            return true;
        });

        if (this.hasCreateLabelTarget && config.createLabel) {
            this.createLabelTarget.textContent = config.createLabel;
        }
        if (this.hasEditLabelTarget && config.editLabel) {
            this.editLabelTarget.textContent = config.editLabel;
        }

        const menu = this.menuTarget;
        // display:'' rend la main au CSS (.context-menu [role="menuitem"] → flex).
        menu.querySelectorAll('[role="menuitem"]').forEach((li) => {
            li.style.display = visible.includes(li.dataset.menuKey) ? '' : 'none';
        });
        const hasActions = visible.includes('create') || visible.includes('edit');
        const sepActions = menu.querySelector('[data-menu-key="sep-actions"]');
        if (sepActions) sepActions.style.display = hasActions ? '' : 'none';
        const sepDanger = menu.querySelector('[data-menu-key="sep-danger"]');
        if (sepDanger) sepDanger.style.display = visible.includes('delete') ? '' : 'none';

        // Un seul menu SOA ouvert à la fois (multi-onglets).
        document.dispatchEvent(new CustomEvent('soa:context-menu.open', {
            detail: { ownerId: this.instanceId },
        }));

        // Positionnement mesuré, borné au viewport.
        menu.style.visibility = 'hidden';
        menu.style.display = 'block';
        const left = Math.max(6, Math.min(event.clientX, window.innerWidth - menu.offsetWidth - 6));
        const top = Math.max(6, Math.min(event.clientY, window.innerHeight - menu.offsetHeight - 6));
        menu.style.left = `${left}px`;
        menu.style.top = `${top}px`;
        menu.style.visibility = '';

        // Focus sur le conteneur (pas sur un item) : aucune option ne doit
        // apparaître présélectionnée à l'ouverture. Les flèches atteignent
        // ensuite le premier/dernier item via _onKeydown.
        this._previousFocus = document.activeElement;
        menu.focus();

        this._addTransientListeners();
    }

    // ── Fermeture ────────────────────────────────────────────────────────────

    _close() {
        if (!this.hasMenuTarget) return;
        const wasOpen = this.menuTarget.style.display !== 'none';
        this.menuTarget.style.display = 'none';
        this._removeTransientListeners();
        if (wasOpen && this._previousFocus && document.contains(this._previousFocus)) {
            this._previousFocus.focus();
        }
        this._previousFocus = null;
    }

    _addTransientListeners() {
        document.addEventListener('pointerdown', this._onDocPointerDown, true);
        document.addEventListener('keydown', this._onKeydown, true);
        window.addEventListener('scroll', this._onScrollOrResize, true);
        window.addEventListener('resize', this._onScrollOrResize);
    }

    _removeTransientListeners() {
        document.removeEventListener('pointerdown', this._onDocPointerDown, true);
        document.removeEventListener('keydown', this._onKeydown, true);
        window.removeEventListener('scroll', this._onScrollOrResize, true);
        window.removeEventListener('resize', this._onScrollOrResize);
    }

    _onDocPointerDown(event) {
        if (this.hasMenuTarget && this.menuTarget.contains(event.target)) return;
        this._close();
    }

    _onScrollOrResize() {
        this._close();
    }

    // ── Navigation clavier (WCAG : Escape, flèches, Home/End, Enter/Espace) ──

    _onKeydown(event) {
        if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            this._close();
            return;
        }

        const items = this._visibleItems();
        if (!items.length) return;
        const currentIndex = items.indexOf(document.activeElement);

        switch (event.key) {
            case 'ArrowDown':
                event.preventDefault();
                items[(currentIndex + 1) % items.length].focus();
                break;
            case 'ArrowUp':
                event.preventDefault();
                items[currentIndex <= 0 ? items.length - 1 : currentIndex - 1].focus();
                break;
            case 'Home':
                event.preventDefault();
                items[0].focus();
                break;
            case 'End':
                event.preventDefault();
                items[items.length - 1].focus();
                break;
            case 'Enter':
            case ' ':
                if (currentIndex >= 0) {
                    event.preventDefault();
                    items[currentIndex].click();
                }
                break;
        }
    }

    _visibleItems() {
        if (!this.hasMenuTarget) return [];
        return Array.from(this.menuTarget.querySelectorAll('[role="menuitem"]'))
            .filter((li) => li.style.display !== 'none');
    }

    // ── Actions ──────────────────────────────────────────────────────────────

    onItemClick(event) {
        const key = event.currentTarget.dataset.menuKey;
        const { config, row } = this.current || {};
        this._close();
        if (!config) return;

        switch (key) {
            case 'refresh':
                document.dispatchEvent(new CustomEvent('app:workspace.reload-active-tab'));
                break;
            case 'apercu':
                window.open(this.apercuUrlValue, '_blank', 'noopener');
                break;
            case 'create':
                if (config.pisteCreate) {
                    // Pré-remplissage serveur : client courant + risque de la ligne.
                    // Le titre rappelle le produit ciblé pour lever toute ambiguïté
                    // entre produits aux noms proches du catalogue.
                    this._openDialog(config, null, {
                        formUrl: `${config.api}/get-form?idClient=${this.clientIdValue}&idRisque=${row.dataset.id}`,
                        titreCreation: row.dataset.label ? `Créer la piste — ${row.dataset.label}` : undefined,
                    });
                } else {
                    // Mécanisme générique : champ parent pré-rempli et masqué côté serveur.
                    this._openDialog(config, null, {
                        parentContext: { id: this.clientIdValue, fieldName: config.parentField },
                    });
                }
                break;
            case 'edit':
                this._openDialog(config, parseInt(row.dataset.id, 10));
                break;
            case 'delete':
                this._requestDelete(config, row);
                break;
        }
    }

    /**
     * Ouvre la boîte de dialogue de formulaire avec un canvas minimal inline
     * (même précédent que les menus contextuels du tableau de bord).
     * Le flag _soaReload déclenche le rechargement de l'onglet SOA après sauvegarde.
     */
    _openDialog(config, id, extra = {}) {
        document.dispatchEvent(new CustomEvent('app:boite-dialogue:init-request', {
            detail: {
                entityFormCanvas: {
                    parametres: {
                        titre_creation: extra.titreCreation || config.titres?.creation,
                        titre_modification: config.titres?.modification,
                        endpoint_form_url: extra.formUrl || `${config.api}/get-form`,
                        endpoint_submit_url: `${config.api}/submit`,
                    },
                },
                entity: id ? { id } : {},
                isCreationMode: !id,
                context: {
                    idEntreprise: this.idEntrepriseValue || undefined,
                    idInvite: this.idInviteValue || undefined,
                    _soaReload: true,
                },
                ...(extra.parentContext ? { parentContext: extra.parentContext } : {}),
            },
        }));
    }

    /**
     * Demande de suppression via la boîte de confirmation personnalisée existante.
     * L'exécution revient à cette instance via cerveau:event (garde ownerId).
     */
    _requestDelete(config, row) {
        const id = row.dataset.id;
        document.dispatchEvent(new CustomEvent('ui:confirmation.request', {
            detail: {
                title: config.deleteTitle,
                body: config.deleteBody,
                itemDescriptions: [row.dataset.label || `Élément #${id}`],
                showIrreversible: true,
                onConfirm: {
                    type: 'app:soa-ctx.delete-execute',
                    payload: { url: `${config.api}/delete/${id}`, ownerId: this.instanceId },
                },
            },
        }));
    }

    _onCerveauEvent(event) {
        if (event.detail?.type !== 'app:soa-ctx.delete-execute') return;
        const payload = event.detail.payload;
        // Seule l'instance à l'origine de la demande traite l'exécution.
        if (!payload || payload.ownerId !== this.instanceId) return;

        fetch(payload.url, {
            method: 'DELETE',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then((response) => {
                if (!response.ok) throw new Error(`HTTP ${response.status}`);
                return response.json().catch(() => ({}));
            })
            .then(() => {
                document.dispatchEvent(new CustomEvent('ui:confirmation.close'));
                document.dispatchEvent(new CustomEvent('app:workspace.reload-active-tab'));
            })
            .catch(() => {
                document.dispatchEvent(new CustomEvent('ui:confirmation.error', {
                    detail: { error: 'La suppression a échoué.' },
                }));
            });
    }

    _onOtherMenuOpen(event) {
        if (event.detail?.ownerId !== this.instanceId) {
            this._close();
        }
    }
}
