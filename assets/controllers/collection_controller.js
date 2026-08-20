import { Controller } from '@hotwired/stimulus';
import {
    ajouter as ajouterAuTampon,
    compter as compterLeTampon,
    creerGroupe,
    libellesEnAttente,
    rejouerGroupe,
    retirer as retirerDuTampon,
    vider as viderLeTampon,
} from './collection-tampon.js';

/**
 * @class CollectionController
 * @extends Controller
 * @description Gère un widget de formulaire de type collection.
 * Il est responsable de l'affichage de la liste, de l'ajout, de la modification
 * et de la suppression d'éléments via des boîtes de dialogue modales.
 */
export default class extends Controller {
    static targets = [
        "contentPanel",
        "listContainer",
        "addButtonContainer",
        "countBadge",
        "rowActions", // Cible pour les conteneurs d'actions de ligne
        "titleLoading",
        "titleContent",
        "totalValueDisplay"
    ];

    static values = {
        url: String,
        listUrl: String,
        itemFormUrl: String,
        itemSubmitUrl: String,
        itemDeleteUrl: String,
        itemTitleCreate: String,
        itemTitleEdit: String,
        parentFieldName: String,
        parentEntityId: Number,
        // Optionnel : si défini, le bouton « Ajouter » ouvre une boîte de SÉLECTION de
        // ressources existantes (ex. rattacher des clients à un portefeuille) au lieu du
        // formulaire de création. Rétro-compatible : absent → comportement historique.
        pickerUrl: String,
        disabled: Boolean,
        // MODE DIFFÉRÉ : le parent n'a pas encore d'id. La collection reste utilisable,
        // mais ses éléments attendent en mémoire au lieu d'être écrits (collection-tampon.js).
        differe: Boolean,
        entiteNom: String,
        idEntreprise: Number,
        idInvite: Number,
        context: Object,
        watchIds: { type: Array, default: [] },
    };

    /**
     * @property {Object} hideTimeouts - Stocke les minuteurs pour masquer les boutons.
     * @private
     */
    hideTimeouts = {};

    connect() {
        this.nomControleur = "Collection";
        // console.log(`${this.nomControleur} - Connecté.`);
        this.boundRefresh = this.refresh.bind(this);
        // Écoute l'événement de sauvegarde pour se rafraîchir
        document.addEventListener('app:list.refresh-request', this.boundRefresh);
        this.tooltipElement = null; // NOUVEAU : Propriété pour stocker l'infobulle
        // L'arbre des saisies en attente de CETTE collection. Il reste vide en mode
        // édition : là, chaque ajout part directement en base.
        this.groupe = creerGroupe(this.parentFieldNameValue);
        this.boundTampon = this._surDemandeDeTampon.bind(this);
        document.addEventListener('app:collection.tampon-request', this.boundTampon);
        this.load();
    }

    disconnect() {
        document.removeEventListener('app:list.refresh-request', this.boundRefresh);
        document.removeEventListener('app:collection.tampon-request', this.boundTampon);
        // NOUVEAU : S'assurer que l'infobulle est retirée si le contrôleur est déconnecté
        if (this.tooltipElement) {
            this.tooltipElement.remove();
        }
        // Nettoie le sélecteur de clients s'il est resté ouvert.
        this.closePicker();
    }

    /**
     * Charge ou recharge le contenu de la liste via AJAX.
     */
    async load() {
        // EN DIFFÉRÉ, LA LISTE EST LOCALE. Interroger le serveur avec un parentId à 0
        // ne rendrait jamais que du vide : la vérité est dans le tampon.
        if (this.differeValue) {
            this._rendreTampon();
            return;
        }
        if (this.disabledValue) {
            // console.log(`${this.nomControleur} - load() - Code: 1986 - disabledValue: `, this.disabledValue);
            this.listContainerTarget.innerHTML = '<div class="alert alert-warning">Commencez par enregistrer.</div>';
            return;
        }
        if (!this.listUrlValue) {
            // console.log(`${this.nomControleur} - load() - Code: 1986 - listUrlValue: `, this.listUrlValue);
            this.listContainerTarget.innerHTML = "<div class='alert alert-warning'>L'url de la liste n'est pas définie.</div>";
            return;
        }

        // NOUVEAU : Active l'état de chargement (squelette pour le titre et le contenu).
        this._toggleLoadingState(true);

        try {
            const dialogListUrl = this.listUrlValue + "/dialog";
            const response = await fetch(dialogListUrl);
            if (!response.ok) throw new Error(`Erreur serveur: ${response.statusText}`);

            const data = await response.json(); // NOUVEAU: On attend une réponse JSON

            // On injecte le HTML de la liste
            this.listContainerTarget.innerHTML = data.html;

            // On met à jour le total et le compteur
            this.updateTotal(data.totalValue, data.totalUnit);
            this.updateCount(data.itemCount);

            if (data.defaultCreated) {
                document.dispatchEvent(new CustomEvent('app:notification.show', {
                    detail: {
                        text: 'Une tranche par défaut (100 %) a été créée automatiquement. Vérifiez les détails et enregistrez si besoin.',
                        type: 'warning'
                    }
                }));

                // Ouvrir l'accordéon pour que la ligne soit visible après fermeture du dialogue
                if (!this.contentPanelTarget.classList.contains('is-open')) {
                    this.contentPanelTarget.classList.add('is-open');
                    const icon = this.titleContentTarget.querySelector('.toggle-icon');
                    if (icon) icon.textContent = '−';
                }

                // Ouvrir automatiquement le formulaire d'édition de la tranche créée
                if (data.defaultItemId) {
                    this.notifyCerveau('ui:toolbar.edit-request', {
                        selection: [{ entity: { id: data.defaultItemId } }],
                        formCanvas: {
                            parametres: {
                                titre_creation: this.itemTitleCreateValue,
                                titre_modification: this.itemTitleEditValue,
                                endpoint_form_url: this.itemFormUrlValue,
                                endpoint_submit_url: this.itemSubmitUrlValue,
                            }
                        },
                        context: { originatorId: this.element.id },
                        parentContext: {
                            id: this.parentEntityIdValue,
                            fieldName: this.parentFieldNameValue
                        }
                    });
                }
            }

        } catch (error) {
            this.listContainerTarget.innerHTML = `<div class="alert alert-danger">Impossible de charger la liste: ${error.message}</div>`;
            console.error(`${this.nomControleur} - Erreur lors du chargement de la collection:`, error, this.listUrlValue);
        } finally {
            // NOUVEAU : Désactive l'état de chargement dans tous les cas (succès ou erreur).
            this._toggleLoadingState(false);
        }
    }


    /**
     * Affiche ou masque le contenu de l'accordéon.
     */
    toggleAccordion() {
        // On ne permet pas d'ouvrir l'accordéon s'il est désactivé
        if (this.disabledValue) {
            return;
        }

        this.contentPanelTarget.classList.toggle('is-open');
        // CORRECTION : On cible l'icône dans le conteneur du titre visible (titleContentTarget),
        // et non la première icône trouvée dans le widget, qui pouvait être celle du squelette de chargement.
        const icon = this.titleContentTarget.querySelector('.toggle-icon');
        if (icon) {
            // On met à jour l'icône en fonction de l'état ouvert/fermé.
            icon.textContent = this.contentPanelTarget.classList.contains('is-open') ? '−' : '+';
        }
    }

    /**
     * Affiche un message dans la console lorsque la souris entre dans la zone du titre.
     * Ne fait rien si le widget est désactivé (mode création).
     */
    logMouseEnter() {
        // console.log(`${this.nomControleur} - Souris entrée sur le titre de l'accordéon (mode édition).`, this.addButtonContainerTarget);
        if (!this.disabledValue) {
            if (this.hasAddButtonContainerTarget) {
                this.addButtonContainerTarget.style.opacity = '1';
            }
        }
    }

    /**
     * Affiche un message dans la console lorsque la souris quitte la zone du titre.
     * Ne fait rien si le widget est désactivé (mode création).
     */
    logMouseLeave() {
        // console.log(`${this.nomControleur} - Souris sortie du titre de l'accordéon (mode édition).`, this.addButtonContainerTarget);
        if (!this.disabledValue) {
            if (this.hasAddButtonContainerTarget) {
                this.addButtonContainerTarget.style.opacity = '0';
            }
        }
    }

    /**
     * Affiche les boutons d'action pour une ligne survolée.
     * @param {MouseEvent} event
     */
    showRowActions(event) {
        const row = event.currentTarget;
        const actionsContainer = row.querySelector('[data-collection-target="rowActions"]');

        if (actionsContainer) {
            // Annule tout minuteur de masquage existant pour cette ligne
            if (this.hideTimeouts[row.id]) {
                clearTimeout(this.hideTimeouts[row.id]);
            }
            actionsContainer.classList.add('visible');
        }
    }

    /**
     * Masque les boutons d'action après un délai lorsque la souris quitte une ligne.
     * @param {MouseEvent} event
     */
    hideRowActions(event) {
        const row = event.currentTarget;
        const actionsContainer = row.querySelector('[data-collection-target="rowActions"]');

        if (actionsContainer) {
            // Lance un minuteur pour masquer les boutons après 3 secondes
            this.hideTimeouts[row.id] = setTimeout(() => {
                actionsContainer.classList.remove('visible');
            }, 800);
        }
    }

    /**
     * NOUVEAU : Affiche une infobulle personnalisée lors du survol.
     * @param {MouseEvent} event
     */
    showTooltip(event) {
        const target = event.currentTarget;
        const tooltipText = target.dataset.tooltipTextValue;

        if (!tooltipText) return;

        // Créer l'élément d'infobulle
        this.tooltipElement = document.createElement('div');
        this.tooltipElement.className = 'canvas-tooltip';
        this.tooltipElement.textContent = tooltipText;
        document.body.appendChild(this.tooltipElement);

        // Positionner l'infobulle près du curseur
        this.tooltipElement.style.left = `${event.pageX + 15}px`;
        this.tooltipElement.style.top = `${event.pageY + 15}px`;

        // Forcer un reflow pour que la transition CSS s'applique
        void this.tooltipElement.offsetWidth;

        // Rendre visible avec la classe qui déclenche l'animation
        this.tooltipElement.classList.add('is-visible');
    }

    /**
     * NOUVEAU : Masque et détruit l'infobulle.
     */
    hideTooltip() {
        if (this.tooltipElement) {
            // On retire simplement l'élément. La transition de sortie n'est pas gérée
            // pour éviter les "race conditions" si l'utilisateur bouge la souris rapidement.
            // L'animation d'entrée est conservée.
            this.tooltipElement.remove();
            this.tooltipElement = null;
        }
    }

    /**
     * NOUVEAU : Met à jour l'affichage du montant total dans le titre de l'accordéon.
     * @param {number|null} totalValue 
     * @param {string|null} totalUnit 
     */
    updateTotal(totalValue, totalUnit) {
        if (!this.hasTotalValueDisplayTarget) {
            return;
        }

        if (totalValue !== null && totalValue !== undefined) {
            const formattedValue = totalValue.toLocaleString('fr-FR', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });

            this.totalValueDisplayTarget.textContent = `${formattedValue} ${totalUnit || ''}`.trim();
            this.totalValueDisplayTarget.style.display = 'inline-block';
        } else {
            this.totalValueDisplayTarget.style.display = 'none';
        }
    }

    /**
     * Rafraîchit la liste si l'événement de sauvegarde concerne cette collection.
     * @param {CustomEvent} event
     */
    refresh(event) {
        // L'ID 'originatorId' est l'ID de l'élément HTML du contrôleur collection
        // qui a initié l'action. On ne rafraîchit que si c'est nous.
        if (event.detail.originatorId === this.element.id) {
            this.load();
        } else if (this.watchIdsValue.length > 0 && this.watchIdsValue.includes(event.detail.originatorId)) {
            this.load();
        }
    }

    /**
     * Met à jour le badge affichant le nombre d'éléments.
     */
    updateCount(count = null) {
        if (this.hasCountBadgeTarget) {
            const itemCount = count !== null ? count : this.listContainerTarget.querySelectorAll('[data-item-id]').length;
            this.countBadgeTarget.textContent = itemCount;
            this.countBadgeTarget.style.display = itemCount > 0 ? 'inline-block' : 'none';
        }
    }

    /**
     * Déclenche l'ouverture de la boîte de dialogue pour ajouter un nouvel élément.
     */
    addItem(event) {
        // ce qui évite de déclencher l'action 'toggleAccordion' du titre.
        event.stopPropagation();

        // Mode SÉLECTION : rattacher des ressources existantes (ex. clients d'un
        // portefeuille) au lieu d'ouvrir un formulaire de création.
        if (this.hasPickerUrlValue && this.pickerUrlValue) {
            this.openPicker(event.currentTarget);
            return;
        }

        // Le "formCanvas" pour l'élément à créer (ex: un Contact).
        // C'est la configuration pour le dialogue.
        const formCanvas = {
            parametres: {
                titre_creation: this.itemTitleCreateValue,
                titre_modification: this.itemTitleEditValue,
                endpoint_form_url: this.itemFormUrlValue,
                endpoint_submit_url: this.itemSubmitUrlValue,
            }
        };
 
        // Le contexte pour le nouvel élément.
        const context = {
            originatorId: this.element.id, // On s'identifie pour le rafraîchissement
        }; //
 
        // Le contexte parent pour lier le nouvel élément.
        const parentContext = {
            id: this.parentEntityIdValue,
            fieldName: this.parentFieldNameValue,
            // Ce drapeau voyage jusqu'au dialogue enfant : c'est lui qui décide s'il
            // ÉCRIT ou s'il met en attente — et, accessoirement, si son bouton de pied
            // dit « Enregistrer » ou « Ajouter ».
            differe: this.differeValue,
            collectionId: this.element.id
        };
 
        // On utilise le même événement que la barre d'outils pour une logique unifiée dans le cerveau.
        this.notifyCerveau('ui:toolbar.add-request', {
            formCanvas: formCanvas,
            context: context,
            parentContext: parentContext // On passe le contexte parent pour le lien
        });
    }

    /**
     * Ouvre la boîte de SÉLECTION de ressources existantes (mode picker) : récupère son
     * HTML depuis pickerUrl, l'injecte au-dessus de la fiche et branche recherche,
     * fermeture (bouton / clic hors modale / Échap), ajout et retrait.
     */
    async openPicker(triggerButton = null) {
        // Garde anti double-ouverture (clics répétés / requête en vol).
        if (this.pickerElement || this.pickerOpening) return;
        this.pickerOpening = true;
        // Feedback immédiat sur le bouton déclencheur (évite l'impression d'hésitation
        // le temps du chargement de la liste).
        if (triggerButton) {
            triggerButton.disabled = true;
            triggerButton.dataset.prevHtml = triggerButton.innerHTML;
            triggerButton.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> <span>Ouverture…</span>';
        }
        try {
            const response = await fetch(this.pickerUrlValue, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            if (!response.ok) throw new Error(`Erreur serveur: ${response.status}`);
            const html = await response.text();

            const holder = document.createElement('div');
            holder.innerHTML = html.trim();
            this.pickerElement = holder.firstElementChild;
            if (!this.pickerElement) throw new Error('Contenu vide.');

            // On rattache le picker DANS la modale parente (et non au <body>) : la modale
            // Bootstrap piège le focus dans son propre sous-arbre ; un overlay hors modale
            // ne pourrait pas recevoir la saisie clavier (champ de recherche inutilisable).
            const host = this.element.closest('.modal') || document.body;
            host.appendChild(this.pickerElement);
            this.pickerBase = this.pickerElement.dataset.pickerBase || '';
            this.pickerPreviousFocus = document.activeElement;

            // Fermeture au clavier (Échap) — Nielsen : sortie d'urgence / contrôle utilisateur.
            this.boundPickerKeydown = (e) => { if (e.key === 'Escape') this.closePicker(); };
            document.addEventListener('keydown', this.boundPickerKeydown);

            // Délégation des clics (fermeture, ajout, retrait).
            this.pickerElement.addEventListener('click', (e) => this._onPickerClick(e));

            // Recherche : filtrage des lignes (input + saisie clavier, robustesse navigateurs).
            const searchInput = this.pickerElement.querySelector('[data-picker-search]');
            if (searchInput) {
                const handler = () => this._filterPicker(searchInput.value);
                searchInput.addEventListener('input', handler);
                searchInput.addEventListener('keyup', handler);
            }

            // EN DIFFÉRÉ, le serveur ignore tout de la sélection en cours : elle vit dans
            // le tampon. Sans ce report, rouvrir le sélecteur montrerait « non ciblé » sur
            // des risques déjà choisis, et l'utilisateur les ajouterait deux fois.
            if (this.differeValue) this._pickerRefleterLeTampon();

            // Focus initial (accessibilité : le focus entre dans la modale).
            const focusTarget = searchInput || this.pickerElement.querySelector('[role="dialog"]');
            if (focusTarget) focusTarget.focus();
        } catch (error) {
            this._pickerNotify("Impossible d'ouvrir la liste de sélection.", 'error');
        } finally {
            this.pickerOpening = false;
            if (triggerButton) {
                triggerButton.disabled = false;
                if (triggerButton.dataset.prevHtml !== undefined) {
                    triggerButton.innerHTML = triggerButton.dataset.prevHtml;
                    delete triggerButton.dataset.prevHtml;
                }
            }
        }
    }

    /** Ferme le picker et restaure le focus sur l'élément déclencheur. */
    closePicker() {
        if (this.boundPickerKeydown) {
            document.removeEventListener('keydown', this.boundPickerKeydown);
            this.boundPickerKeydown = null;
        }
        if (this.pickerElement) {
            this.pickerElement.remove();
            this.pickerElement = null;
        }
        if (this.pickerPreviousFocus && typeof this.pickerPreviousFocus.focus === 'function') {
            this.pickerPreviousFocus.focus();
        }
    }

    _onPickerClick(event) {
        // Fermeture : bouton dédié ou clic sur l'arrière-plan.
        if (event.target.closest('[data-picker-close]') || event.target.hasAttribute('data-picker-overlay')) {
            this.closePicker();
            return;
        }
        const attachBtn = event.target.closest('[data-picker-attach]');
        if (attachBtn) { this._pickerAction(attachBtn, 'attach'); return; }
        const detachBtn = event.target.closest('[data-picker-detach]');
        if (detachBtn) { this._pickerAction(detachBtn, 'detach'); }
    }

    /**
     * Ajoute (attach, PUT) ou retire (detach, DELETE) un client depuis le picker, avec
     * barre de progression, mise à jour de la ligne et rafraîchissement de la collection.
     */
    async _pickerAction(button, mode) {
        const row = button.closest('[data-picker-row]');
        // `data-picker-id` est générique ; `data-client-id` reste lu pour le sélecteur de
        // clients d'un portefeuille, qui existait avant que ce code serve à deux entités.
        const cibleId = row?.dataset.pickerId ?? row?.dataset.clientId;
        if (!cibleId || !this.pickerBase) return;

        const isAttach = mode === 'attach';
        // Les noms d'action se DÉDUISENT du nom de l'entité rattachée, au lieu d'être
        // écrits en dur : le même code sert les clients d'un portefeuille et les risques
        // ciblés d'une condition de partage, sans savoir lequel il sert.
        const quoi = this._pickerNomDeLaCible();
        const action = `${isAttach ? 'attach' : 'detach'}-${quoi}`;

        // EN DIFFÉRÉ, ON NE RATTACHE RIEN TOUT DE SUITE : le parent n'existe pas encore,
        // et l'URL /admin/x/api/0/attach-y ne mènerait nulle part. On retient le choix,
        // et le rattachement se fera au rejeu, une fois le parent né.
        if (this.differeValue) {
            if (isAttach) {
                ajouterAuTampon(this.groupe, {
                    nature: 'rattachement',
                    libelle: row.querySelector('.jsb-picker-client-nom')?.textContent?.trim() || `#${cibleId}`,
                    idCible: cibleId,
                    pickerBase: this.pickerBase,
                    action,
                });
            } else {
                const inscrit = this.groupe.noeuds.find((n) => String(n.idCible) === String(cibleId));
                if (inscrit) retirerDuTampon(this.groupe, inscrit.cle);
            }
            this._pickerSetRowState(row, isAttach ? 'current' : 'free');
            this._pickerUpdateCount(isAttach ? 1 : -1);
            this._pickerNotify(isAttach ? 'Ajouté — sera enregistré avec la fiche.' : 'Retiré de la sélection.', 'success');
            this._rendreTampon();
            return;
        }

        const url = `${this.pickerBase}/${action}/${cibleId}`;

        button.disabled = true;
        this._pickerProgress(true);
        try {
            const response = await fetch(url, {
                method: isAttach ? 'PUT' : 'DELETE',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(data.message || `Erreur serveur: ${response.status}`);

            this._pickerSetRowState(row, isAttach ? 'current' : 'free');
            this._pickerUpdateCount(isAttach ? 1 : -1); // met à jour le compteur d'entête
            // Le message vient du SERVEUR, qui seul sait de quoi il parle : « Client ajouté
            // au portefeuille » ou « Risque ajouté aux risques ciblés ».
            this._pickerNotify(data.message || (isAttach ? 'Élément ajouté.' : 'Élément retiré.'), 'success');
            this.load(); // rafraîchit la collection derrière la modale
        } catch (error) {
            button.disabled = false;
            this._pickerNotify(error.message || "Action impossible.", 'error');
        } finally {
            this._pickerProgress(false);
        }
    }

    /**
     * Le nom d'entité qui compose les actions du sélecteur (`attach-client`, `attach-risque`).
     *
     * Il se lit dans l'URL du sélecteur, que le provider a déjà fournie
     * (`/admin/conditionpartage/api/%parentId%/risque-picker`) : une source de moins à tenir
     * synchronisée avec les routes du contrôleur.
     * @private
     */
    _pickerNomDeLaCible() {
        const trouve = /\/([a-z-]+)-picker(?:\?|$)/.exec(this.pickerUrlValue || '');

        return trouve ? trouve[1] : 'client';
    }

    /**
     * Met à jour une ligne selon son nouvel état, SANS reconstruire les boutons : on
     * bascule seulement la visibilité des boutons « Ajouter »/« Retirer » (déjà rendus
     * côté serveur avec leurs icônes) et on rafraîchit la pastille de statut. Ainsi le
     * bouton de retrait garde exactement l'icône de suppression standard de l'application.
     */
    _pickerSetRowState(row, state) {
        if (!row) return;
        row.dataset.pickerState = state;
        const statusCell = row.querySelector('[data-picker-status]');
        const attachBtn = row.querySelector('[data-picker-attach]');
        const detachBtn = row.querySelector('[data-picker-detach]');
        const isCurrent = state === 'current';

        if (statusCell) {
            statusCell.innerHTML = isCurrent
                ? '<span class="jsb-picker-chip jsb-picker-chip--current">Dans ce portefeuille</span>'
                : '<span class="jsb-picker-chip jsb-picker-chip--free">Sans portefeuille</span>';
        }
        if (attachBtn) { attachBtn.hidden = isCurrent; attachBtn.disabled = false; }
        if (detachBtn) { detachBtn.hidden = !isCurrent; detachBtn.disabled = false; }
    }

    _pickerProgress(active) {
        const bar = this.pickerElement?.querySelector('[data-picker-progress]');
        if (bar) bar.classList.toggle('is-active', !!active);
    }

    /** Met à jour en direct le compteur « N dans ce portefeuille ». */
    _pickerUpdateCount(delta) {
        const el = this.pickerElement?.querySelector('[data-picker-count-current]');
        if (!el) return;
        const current = parseInt(el.textContent, 10) || 0;
        el.textContent = Math.max(0, current + delta);
    }

    _filterPicker(query) {
        if (!this.pickerElement) return;
        const raw = (query || '').trim();
        const q = raw.toLowerCase();
        let visible = 0;
        this.pickerElement.querySelectorAll('[data-picker-row]').forEach((row) => {
            const match = q === '' || (row.dataset.search || '').includes(q);
            row.style.display = match ? '' : 'none';
            if (match) {
                visible++;
                // Surligne le mot-clé dans le nom et l'e-mail des lignes affichées.
                row.querySelectorAll('.jsb-picker-client-nom, .jsb-picker-client-mail').forEach((el) => {
                    if (el.dataset.orig === undefined) el.dataset.orig = el.textContent;
                    this._highlightInto(el, el.dataset.orig, raw);
                });
            }
        });
        const empty = this.pickerElement.querySelector('[data-picker-empty]');
        if (empty) empty.hidden = visible !== 0;
        // Compteur « affiché(s) » = nombre de lignes visibles après filtrage.
        const shown = this.pickerElement.querySelector('[data-picker-count-shown]');
        if (shown) shown.textContent = visible;
    }

    /**
     * Réécrit le contenu de `el` à partir du texte original, en enveloppant chaque
     * occurrence de `query` dans un <mark> (via des nœuds DOM : aucun risque d'injection).
     */
    _highlightInto(el, original, query) {
        const q = (query || '').trim();
        if (!q) { el.textContent = original; return; }

        el.textContent = '';
        const lower = original.toLowerCase();
        const qlower = q.toLowerCase();
        let i = 0;
        let idx;
        while ((idx = lower.indexOf(qlower, i)) !== -1) {
            if (idx > i) el.appendChild(document.createTextNode(original.slice(i, idx)));
            const mark = document.createElement('mark');
            mark.className = 'jsb-picker-hl';
            mark.textContent = original.slice(idx, idx + q.length);
            el.appendChild(mark);
            i = idx + q.length;
        }
        if (i < original.length) el.appendChild(document.createTextNode(original.slice(i)));
    }

    _pickerNotify(text, type) {
        document.dispatchEvent(new CustomEvent('app:notification.show', {
            detail: { text, type: type === 'error' ? 'error' : 'success' }
        }));
    }

    /**
     * Déclenche l'ouverture de la boîte de dialogue pour modifier un élément existant.
     * @param {MouseEvent} event
     */
    editItem(event) {
        event.stopPropagation();
        // CORRECTION : On cherche l'ID sur la ligne parente (tr) la plus proche.
        const row = event.currentTarget.closest('tr');
        if (!row || !row.dataset.itemId) return;
        const itemId = row.dataset.itemId;
 
        const formCanvas = {
            parametres: {
                titre_creation: this.itemTitleCreateValue,
                titre_modification: this.itemTitleEditValue,
                endpoint_form_url: this.itemFormUrlValue,
                endpoint_submit_url: this.itemSubmitUrlValue,
            }
        };
 
        const context = {
            originatorId: this.element.id, // On s'identifie pour le rafraîchissement
        };
 
        const parentContext = {
            id: this.parentEntityIdValue,
            fieldName: this.parentFieldNameValue
        };
 
        // On utilise le même événement que la barre d'outils.
        this.notifyCerveau('ui:toolbar.edit-request', {
            // La barre d'outils envoie un tableau `selection`. On imite cette structure.
            selection: [{ entity: { id: itemId } }],
            formCanvas: formCanvas,
            context: context,
            parentContext: parentContext
        }); //
    }

    /**
     * Demande la confirmation avant de supprimer un élément.
     * @param {MouseEvent} event
     */
    deleteItem(event) {
        event.stopPropagation();
        // CORRECTION : On cherche l'ID sur la ligne parente (tr) la plus proche.
        const row = event.currentTarget.closest('tr');
        if (!row || !row.dataset.itemId) return;
        const itemId = row.dataset.itemId;

        // LIGNE EN ATTENTE : rien n'est en base, il n'y a donc rien à supprimer ni à
        // faire confirmer. On retire du tampon — avec tout son sous-arbre, car ses
        // propres éléments n'avaient d'existence que par elle.
        if (this.differeValue) {
            retirerDuTampon(this.groupe, Number(itemId));
            this._rendreTampon();
            return;
        }

        // On notifie le cerveau. C'est lui qui construira la demande de confirmation.
        // C'est le cerveau qui construira la demande de confirmation complexe.
        this.notifyCerveau('app:delete-request', {
            selection: [{ id: itemId }], // Simule un selecto pour être compatible avec la logique du cerveau
            formCanvas: {
                parametres: {
                    // On fournit juste l'URL de suppression, c'est tout ce dont le cerveau a besoin.
                    endpoint_delete_url: this.itemDeleteUrlValue,
                }
            },
            // CRUCIAL : On s'identifie pour que le cerveau sache qui rafraîchir.
            context: {
                originatorId: this.element.id
            }
        });
    }

    /**
     * Méthode centralisée pour envoyer un événement au Cerveau.
     * @param {string} type
     * @param {object} payload
     */
    // ───────────────────────── LE MODE DIFFÉRÉ ─────────────────────────
    // Tant que la fiche parente n'a pas d'id, les éléments de cette collection attendent
    // ici, en mémoire. Ils sont créés d'un coup après l'enregistrement de l'ancêtre, chacun
    // recevant l'id que le niveau du dessus vient d'obtenir (cf. collection-tampon.js).

    /**
     * Un dialogue enfant a validé sa saisie sans l'écrire : on la range.
     *
     * L'événement est diffusé à tout le document — chaque widget vérifie donc qu'il en est
     * bien le destinataire. Même discipline que `app:list.refresh-request`.
     * @private
     */
    _surDemandeDeTampon(event) {
        const detail = event.detail || {};
        if (!this.differeValue || detail.collectionId !== this.element.id) return;

        ajouterAuTampon(this.groupe, {
            nature: detail.nature || 'creation',
            libelle: detail.libelle,
            formData: detail.formData,
            submitUrl: detail.submitUrl,
            idCible: detail.idCible,
            pickerBase: detail.pickerBase,
            action: detail.action,
            // Le sous-arbre que l'enfant portait lui-même : c'est ce qui fait tenir la
            // généalogie sur toute sa profondeur.
            enfants: detail.enfants || {},
            // Le balisage rendu par le serveur : c'est LUI qu'on affichera, pas une
            // reconstruction locale qui finirait par en différer.
            ligne: detail.ligne,
        });

        this._rendreTampon();
    }

    /** Nombre d'éléments en attente ici, descendants compris — le chiffre du garde-fou. */
    compterEnAttente() {
        return this.differeValue ? compterLeTampon(this.groupe) : 0;
    }

    /** Ce qui serait perdu à l'abandon, nommé — jamais un simple nombre. */
    libellesEnAttente() {
        return this.differeValue ? libellesEnAttente(this.groupe) : [];
    }

    /** L'abandon assumé, une fois confirmé. */
    viderTampon() {
        viderLeTampon(this.groupe);
        if (this.differeValue) this._rendreTampon();
    }

    /**
     * Rejoue l'arbre en attente, maintenant que le parent a un id.
     * @returns {Promise<Array<{chemin: string[], erreur: any}>>} les échecs, vide si tout passe
     */
    async rejouer(parentId) {
        if (!this.differeValue) return [];

        return rejouerGroupe(this.groupe, parentId, (noeud, charge, idParent) =>
            noeud.nature === 'creation'
                ? this._creerEnBase(noeud, charge)
                : this._rattacherEnBase(noeud, idParent));
    }

    /** POST de la saisie mise en attente — exactement celui qu'aurait fait le dialogue. */
    async _creerEnBase(noeud, charge) {
        try {
            const reponse = await fetch(noeud.submitUrl, { method: 'POST', body: charge });
            const resultat = await reponse.json().catch(() => ({}));
            if (!reponse.ok) return { ok: false, erreur: resultat };

            return { ok: true, id: resultat.entity?.id };
        } catch (error) {
            return { ok: false, erreur: error.message };
        }
    }

    /** Rattachement d'une entité existante, une fois le parent né. */
    async _rattacherEnBase(noeud, parentId) {
        // pickerBase porte encore le parentId de la saisie (0) : on le remplace par le vrai.
        const base = String(noeud.pickerBase).replace(/\/0(\/|$)/, `/${parentId}$1`);
        try {
            const reponse = await fetch(`${base}/${noeud.action}/${noeud.idCible}`, {
                method: 'PUT',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            const resultat = await reponse.json().catch(() => ({}));

            return reponse.ok ? { ok: true, id: noeud.idCible } : { ok: false, erreur: resultat };
        } catch (error) {
            return { ok: false, erreur: error.message };
        }
    }

    /**
     * Rend la collection en attente — ENTIÈREMENT AVEC LE BALISAGE DU SERVEUR.
     *
     * Rien n'est fabriqué ici : le cadre (tableau, entêtes, état vide) est demandé au
     * serveur comme en mode édition, et chaque ligne a été rendue par
     * `components/_list_row.html.twig` au moment de sa validation. Reconstruire tout cela
     * côté navigateur donnerait un second habillage, qui s'écarterait du premier dès que le
     * gabarit changerait — et la fiche n'aurait pas le même visage selon qu'on la crée ou
     * qu'on la modifie.
     *
     * Ne s'ajoutent que les deux marques que le serveur ne peut pas connaître : la clé du
     * tampon (la ligne n'a pas d'id) et la pastille « à enregistrer », sans laquelle
     * l'utilisateur croirait sa saisie déjà enregistrée.
     * @private
     */
    async _rendreTampon() {
        if (!this.hasListContainerTarget) return;

        const noeuds = this.groupe?.noeuds ?? [];
        const total = compterLeTampon(this.groupe);
        this.updateCount(total);

        // Les entités RATTACHÉES existent déjà : le serveur sait les rendre, il lui suffit
        // de leurs identifiants. Les entités CRÉÉES, elles, n'existent nulle part — leurs
        // lignes voyagent avec le tampon depuis leur validation.
        const idsChoisis = noeuds.filter((n) => n.nature === 'rattachement').map((n) => n.idCible);
        const lignesCreees = noeuds.filter((n) => n.nature === 'creation');

        await this._chargerLeCadre(idsChoisis);
        this._injecterLesLignes(lignesCreees);
        this._poserLePiedEnAttente(total);
    }

    /**
     * Le cadre de la liste, demandé au serveur exactement comme en mode édition.
     * @private
     */
    async _chargerLeCadre(idsChoisis) {
        try {
            const url = new URL(`${this.listUrlValue}/dialog`, window.location.origin);
            if (idsChoisis.length > 0) url.searchParams.set('ids', idsChoisis.join(','));

            const reponse = await fetch(url);
            if (!reponse.ok) throw new Error(reponse.statusText);
            const data = await reponse.json();
            this.listContainerTarget.innerHTML = data.html;
        } catch (error) {
            // Le tampon reste intact : c'est l'affichage qui manque, pas la saisie.
            this.listContainerTarget.innerHTML =
                `<div class="alert alert-warning">Impossible d'afficher la liste : ${error.message}</div>`;
        }
    }

    /**
     * Glisse les lignes en attente dans le tableau rendu par le serveur.
     * @private
     */
    _injecterLesLignes(noeuds) {
        if (noeuds.length === 0) return;

        const corps = this.listContainerTarget.querySelector('tbody');
        if (!corps) return;

        // Des lignes existent : l'état vide n'a plus lieu d'être.
        this.listContainerTarget.querySelector('[data-list-manager-target="emptyStateContainer"]')
            ?.classList.add('d-none');
        // Le conteneur du tableau s'appelle « listContainer » dans _list_manager : c'est
        // lui que le contrôleur de liste masque quand il croit la collection vide.
        this.listContainerTarget.querySelector('[data-list-manager-target="listContainer"]')
            ?.classList.remove('d-none');

        noeuds.forEach((noeud) => {
            const gabarit = document.createElement('tbody');
            gabarit.innerHTML = (noeud.ligne || '').trim();
            const ligne = gabarit.firstElementChild;
            if (!ligne) return;

            // L'entité n'a pas d'id : la ligne est repérée par sa clé de tampon, celle que
            // deleteItem() lira pour la retirer.
            ligne.dataset.itemId = String(noeud.cle);
            ligne.classList.add('collection-ligne-en-attente');
            this._marquerEnAttente(ligne, noeud);
            corps.appendChild(ligne);
        });
    }

    /** La pastille qui distingue une ligne en attente d'une ligne enregistrée. @private */
    _marquerEnAttente(ligne, noeud) {
        const descendants = compterLeTampon({ noeuds: [noeud] }) - 1;
        const marque = document.createElement('span');
        marque.className = 'collection-chip-attente ms-2';
        marque.textContent = descendants > 0 ? `à enregistrer · +${descendants}` : 'à enregistrer';

        // Auprès du texte principal, là où l'œil arrive — et non en bout de ligne, où la
        // mention se perdrait derrière les colonnes.
        (ligne.querySelector('.list-row-primary') ?? ligne.querySelector('td'))?.appendChild(marque);
    }

    /** Le rappel de ce qui sera écrit à l'enregistrement. @private */
    _poserLePiedEnAttente(total) {
        if (total === 0) return;

        const pied = document.createElement('p');
        pied.className = 'collection-pied-attente';
        pied.setAttribute('role', 'status');
        pied.textContent = `${total} élément(s) seront créés à l'enregistrement de la fiche.`;
        this.listContainerTarget.appendChild(pied);
    }

    /**
     * Reporte sur le sélecteur ce qui attend déjà dans le tampon.
     *
     * Le serveur rend le catalogue sans rien savoir de la sélection en cours — elle n'existe
     * qu'ici. Sans ce report, rouvrir le sélecteur afficherait « non ciblé » sur des risques
     * déjà choisis, et l'utilisateur les ajouterait une seconde fois.
     * @private
     */
    _pickerRefleterLeTampon() {
        const dejaChoisis = new Set((this.groupe?.noeuds ?? [])
            .filter((n) => n.nature === 'rattachement')
            .map((n) => String(n.idCible)));

        this.pickerElement?.querySelectorAll('[data-picker-row]').forEach((ligne) => {
            const id = ligne.dataset.pickerId ?? ligne.dataset.clientId;
            if (dejaChoisis.has(String(id))) this._pickerSetRowState(ligne, 'current');
        });
    }

    /** Le libellé vient d'une saisie utilisateur : il ne doit jamais être interprété. */
    _echapper(texte) {
        const noeud = document.createElement('span');
        noeud.textContent = texte ?? '';

        return noeud.innerHTML;
    }

    notifyCerveau(type, payload = {}) {
        const event = new CustomEvent('cerveau:event', {
            bubbles: true,
            detail: { type, source: this.nomControleur, payload, timestamp: Date.now() }
        });
        this.element.dispatchEvent(event);
    }

    /**
     * NOUVEAU : Génère le HTML pour un squelette de chargement de la liste de collection.
     * @returns {string} Le HTML du squelette.
     * @private
     */
    _getSkeletonHtml() {
        // Le squelette reflète la structure d'une collection embarquée (dialog) :
        // colonne principale + colonne d'actions, SANS colonne Id./case à cocher.
        const skeletonRow = `
            <tr>
                <td class="p-2">
                    <div class="skeleton-line" style="width: 70%; height: 16px;"></div>
                    <div class="skeleton-line" style="width: 50%; height: 12px; margin-top: 8px;"></div>
                </td>
                <td class="text-end pe-3">
                    <div class="skeleton-line" style="width: 65px; height: 34px; border-radius: var(--bs-border-radius);"></div>
                </td>
            </tr>
        `;
        // On retourne une table avec quelques lignes de squelette pour un meilleur effet visuel.
        // La structure de la table est nécessaire pour que les <tr> s'affichent correctement.
        return `<div class="table-responsive"><table class="table table-hover table-sm"><tbody>
            ${skeletonRow.repeat(3)}
        </tbody></table></div>`;
    }

    /**
     * NOUVEAU : Affiche ou masque l'état de chargement du widget.
     * @param {boolean} isLoading 
     * @private
     */
    _toggleLoadingState(isLoading) {
        // Gère l'affichage du squelette dans le titre de l'accordéon.
        if (this.hasTitleLoadingTarget && this.hasTitleContentTarget) {
            this.titleLoadingTarget.style.display = isLoading ? 'flex' : 'none';
            // On utilise 'flex' pour correspondre au style CSS et assurer un alignement correct des éléments du titre.
            this.titleContentTarget.style.display = isLoading ? 'none' : 'flex';
        }
        
        // Gère l'affichage du squelette dans le contenu de l'accordéon.
        if (isLoading) {
            this.listContainerTarget.innerHTML = this._getSkeletonHtml();
        }
    }
}
