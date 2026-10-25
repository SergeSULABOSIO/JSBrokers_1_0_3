import { Controller } from '@hotwired/stimulus';
import { MenuContextuel, gesteDOuverture } from './menu-contextuel.js';

/**
 * Configuration des menus contextuels par section du SOA.
 * Chaque clé correspond à un attribut data-soa-section posé dans le template
 * soa_client_workspace.html.twig. Les actions s'appuient sur les endpoints API
 * génériques /admin/{entité}/api/get-form|submit|delete.
 */
const SECTIONS = {
    recap: {
        items: ['refresh', 'apercu', 'send', 'copylink'],
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
        items: ['refresh', 'apercu', 'documents', 'edit'],
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
 * Ouverture, clavier, fermetures et « un seul menu ouvert » (plusieurs onglets SOA) : la
 * coquille commune (menu-contextuel.js, chantier 3).
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
        this.coquille = new MenuContextuel(this.menuTarget);

        this._onContextMenu = this._onContextMenu.bind(this);
        this._onCerveauEvent = this._onCerveauEvent.bind(this);

        this.element.addEventListener('contextmenu', this._onContextMenu);
        document.addEventListener('cerveau:event', this._onCerveauEvent);

        // Accordéon des sections (un partial injecté dynamiquement n'exécute pas
        // les balises <script> : la logique doit vivre ici, dans le contrôleur Stimulus).
        this._initAccordion();
        // Les aides de section : l'infobulle unique (data-infobulle, chantier 3).
    }

    disconnect() {
        this._close();
        this.element.removeEventListener('contextmenu', this._onContextMenu);
        document.removeEventListener('cerveau:event', this._onCerveauEvent);
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
            if (key === 'edit' || key === 'delete' || key === 'documents') return hasRow;
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

        // Touche Menu / Maj+F10 : sous la ligne (ou la section), première entrée.
        this.coquille.ouvrir(gesteDOuverture(event, row || zone));
    }

    // ── Fermeture ────────────────────────────────────────────────────────────

    _close() {
        if (this.coquille) this.coquille.fermer();
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
            case 'send':
                // Même circuit que l'action « Envoyer le SOA par e-mail » de la fiche
                // client : le cerveau ouvre le picker de destinataire (soa-envoi-picker).
                this._dispatchCerveau('ui:soa.send-request', {
                    url: `/admin/soa/client/${this.clientIdValue}/envoi-picker`,
                });
                break;
            case 'copylink':
                // Copie le lien PUBLIC tokenisé (crée/prolonge le jeton côté serveur).
                this._dispatchCerveau('ui:soa.copy-link-request', {
                    url: `/admin/soa/api/client/${this.clientIdValue}/lien-public`,
                });
                break;
            case 'documents':
                // Picker des documents de la police (tous niveaux du pipe).
                this._dispatchCerveau('ui:soa.docs-picker-request', {
                    url: `/admin/soa/api/police/${row.dataset.id}/documents`,
                });
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

    /** Émet un événement à destination du cerveau (écouté sur document). */
    _dispatchCerveau(type, payload = {}) {
        document.dispatchEvent(new CustomEvent('cerveau:event', {
            detail: { type, source: this.nomControleur, payload, timestamp: Date.now() },
        }));
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

}
