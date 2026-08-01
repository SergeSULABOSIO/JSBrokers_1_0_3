// assets/controllers/search-bar_controller.js
import BaseController from './base_controller.js';
import { construireResumeFiltres } from './search-bar-filtres.js';
import { positionnerMenu, indexApresTouche } from './menu-flottant.js';

/** Identifiants uniques du volet : plusieurs barres coexistent (une par rubrique/onglet). */
let compteurInstances = 0;

export default class extends BaseController {
    static targets = [
        "simpleSearchInput",
        "simpleSearchCriterion", // NOUVEAU
        "summaryContainer",
        "summary",
        "popover",     // volet flottant listant les critères actifs
        "popoverList"  // <ul> peuplé à chaque mise à jour du résumé
    ];

    static values = {
        criteria: Array,
        defaultCriterion: Object,
        nomEntite: String, // NOUVEAU : pour recevoir le nom de l'entité
        autocompleteUrl: String // Endpoint générique d'autocomplétion des critères « relation »
    }

    // La barre de recherche devient "stateless". Elle ne stocke que l'état actuel reçu du cerveau pour le rendu.
    currentCriteria = {};

    connect() {
        this.nomControleur = "SEARCH_BAR";
        const workspacePanel = this.element.closest('[data-tab-id]');
        this.workspaceTabId = workspacePanel ? workspacePanel.dataset.tabId : null;

        this.boundHandleContextChanged = this.handleContextChanged.bind(this);
        document.addEventListener('app:context.changed', this.boundHandleContextChanged);

        // Volet des filtres : identifiant unique (aria-controls) + écouteurs de fermeture
        // liés une seule fois, posés/retirés à l'ouverture/fermeture.
        this.popoverId = `jsb-filtres-volet-${++compteurInstances}`;
        if (this.hasPopoverTarget) {
            this.popoverTarget.id = this.popoverId;
        }
        this.voletOuvert = false;
        this.boundPointerHorsVolet = this.pointerHorsVolet.bind(this);
        this.boundFermerVolet = () => this.fermerVolet();

        // L'initialisation est minimale. Le rendu se fait via handleContextChanged.
        this.populateSimpleSearchSelector();
        this.updateSimpleSearchPlaceholder();
    }

    disconnect() {
        document.removeEventListener('app:context.changed', this.boundHandleContextChanged);
        this.retirerEcouteursVolet();
    }

    /**
     * Retire l'attribut `readonly` du champ de recherche dès que l'utilisateur lui donne
     * le focus. Le champ est rendu `readonly` côté serveur pour empêcher l'autofill du
     * navigateur (qui ignore autocomplete="off" et y injecte l'email de connexion) ;
     * on le rend éditable uniquement quand l'utilisateur veut réellement saisir.
     */
    enableEditing() {
        if (this.hasSimpleSearchInputTarget && this.simpleSearchInputTarget.hasAttribute('readonly')) {
            this.simpleSearchInputTarget.removeAttribute('readonly');
        }
    }

    openAdvancedSearch() {
        // La barre ne construit plus de HTML : elle transmet les DÉFINITIONS de critères
        // et les filtres actifs au dialogue, qui se charge du rendu et de la collecte.
        // Le dialogue gagne ainsi la maîtrise des champs (Tom Select pour les relations,
        // modes texte, presets de dates…) et supprime le fragile re-parsing du DOM.
        this.notifyCerveau('dialog:search.open-request', {
            criteria: this.criteriaValue,
            activeFilters: this.currentCriteria,
            entiteNom: this.nomEntiteValue,
            autocompleteUrl: this.hasAutocompleteUrlValue ? this.autocompleteUrlValue : ''
        });
    }

    /**
     * NOUVEAU : Point d'entrée unique pour la mise à jour de l'UI de la barre de recherche.
     * Est appelé à chaque fois que le contexte de l'application (et donc les filtres) change.
     * @param {CustomEvent} event 
     */
    handleContextChanged(event) {
        if (this.workspaceTabId && event.detail.workspaceTabId !== this.workspaceTabId) return;
        const { searchCriteria, isTabSwitch, searchCanvas, entiteNom } = event.detail;

        // Recontextualisation : si le cerveau fournit le canvas de recherche de
        // l'onglet actif (entité principale OU collection), la barre bascule ses
        // critères dessus. `null` = inconnu (onglet en cours de chargement) : on
        // conserve les critères courants en attendant 'ui:tab.initialized'.
        if (Array.isArray(searchCanvas) && searchCanvas.length > 0) {
            this.criteriaValue = searchCanvas;
            if (entiteNom) {
                this.nomEntiteValue = entiteNom;
            }
            this.populateSimpleSearchSelector();
            this.updateSimpleSearchPlaceholder();
        }

        // On met à jour notre copie locale des critères
        this.currentCriteria = searchCriteria || {};

        // On met à jour l'UI pour refléter l'état reçu du cerveau.
        // Si c'est un changement d'onglet, on s'assure que le critère simple est bien synchronisé.
        if (isTabSwitch) {
            const simpleSearchKey = this.simpleSearchCriterionTarget.value;
            if (this.currentCriteria[simpleSearchKey] && this.currentCriteria[simpleSearchKey].value) {
                this.simpleSearchInputTarget.value = this.currentCriteria[simpleSearchKey].value;
            } else {
                this.simpleSearchInputTarget.value = '';
            }
        }
        this.updateSummary(this.currentCriteria);
    }

    /**
     * NOUVEAU : Remplit le sélecteur de recherche simple avec les critères de type 'Text'.
     */
    populateSimpleSearchSelector() {
        // La recherche simple accepte les critères texte ET les relations directes
        // (recherchées en texte libre via LIKE sur leur champ d'affichage). Les critères
        // à chemin (ex. « portefeuille.gestionnaire ») et les autres types (nombre, date,
        // booléen) restent réservés à la recherche avancée.
        const textCriteria = this.criteriaValue.filter(
            c => (c.Type === 'Text' || c.Type === 'Relation')
                && !String(c.Nom).includes('.')
                && !String(c.Nom).startsWith('__') // critères synthétiques (ex. « Mon portefeuille »)
        );
        this.simpleSearchCriterionTarget.innerHTML = ''; // On vide le sélecteur

        textCriteria.forEach(criterion => {
            const option = document.createElement('option');
            option.value = criterion.Nom;
            option.textContent = criterion.Display;
            this.simpleSearchCriterionTarget.appendChild(option);
        });
    }

    /**
     * NOUVEAU : Met à jour le placeholder du champ de recherche simple.
     */
    updateSimpleSearchPlaceholder() {
        const selectedOption = this.simpleSearchCriterionTarget.options[this.simpleSearchCriterionTarget.selectedIndex];
        if (selectedOption) {
            this.simpleSearchInputTarget.placeholder = `Rechercher dans "${selectedOption.textContent}"...`;
        }
    }

    /**
     * NOUVEAU : Réinitialise tous les filtres et notifie le cerveau.
     */
    resetAllFilters(event) {
        // Déclenché depuis le volet : celui-ci se referme, donc le bouton cliqué
        // disparaît → on redonnera le focus au champ de recherche.
        if (event?.currentTarget?.closest?.('.jsb-search-bar__popover')) {
            this.replacerFocusApresRetrait = true;
        }
        this.fermerVolet();
        this.simpleSearchInputTarget.value = '';
        this.notifyCerveau('ui:search.submitted', { criteria: {} });
    }

    submitSimpleSearch(event) {
        event.preventDefault();

        // Garde anti-déclenchement parasite : on ne lance la recherche que si le champ
        // de recherche a réellement le focus (= vraie frappe clavier de l'utilisateur).
        // Bloque les faux « Enter » injectés par l'autofill du navigateur ou un gestionnaire
        // de mots de passe lors du premier geste utilisateur (clic sur une ligne), qui
        // déclenchaient une recherche/recharge complète non désirée de la liste.
        if (document.activeElement !== this.simpleSearchInputTarget) {
            return;
        }

        const inputValue = this.simpleSearchInputTarget.value.trim();
        const criterionName = this.simpleSearchCriterionTarget.value;
        const criterionDef = this.criteriaValue.find(c => c.Nom === criterionName);

        if (!criterionDef) return;

        // On part d'une copie des filtres actuels pour ne pas écraser les filtres avancés
        const newCriteria = { ...this.currentCriteria };

        if (inputValue) {
            // On construit le filtre avec la structure attendue par le backend
            const filter = {
                operator: 'LIKE',
                value: inputValue,
                // On ajoute le targetField si c'est une relation
                ...(criterionDef.targetField && { targetField: criterionDef.targetField })
            };
            newCriteria[criterionName] = filter;
        } else {
            delete newCriteria[criterionName];
        }
        this.notifyCerveau('ui:search.submitted', { criteria: newCriteria });
    }

    removeFilter(event) {
        const keyToRemove = event.currentTarget.dataset.filterKey;

        const newCriteria = { ...this.currentCriteria };
        delete newCriteria[keyToRemove];

        // Le bouton cliqué va disparaître au ré-affichage : sans cela le focus
        // retomberait sur <body> (Bastien & Scapin > Guidage ; WCAG 2.4.3). On
        // note qu'il faudra le replacer une fois le nouveau résumé rendu.
        this.replacerFocusApresRetrait = true;

        this.notifyCerveau('ui:search.submitted', { criteria: newCriteria });
    }

    /**
     * Rend les filtres actifs SUR LA LIGNE de la barre : un seul filtre s'affiche
     * en clair, plusieurs se replient derrière une pastille compteur ouvrant le
     * volet. La hauteur de la barre reste donc constante (aucune 2ᵉ ligne).
     * La décision (mode / libellés) vient du module pur `search-bar-filtres.js`.
     */
    updateSummary(criteria) {
        const resume = construireResumeFiltres(criteria, this.criteriaValue);

        // `summary` doit rester un div VIDE quand il n'y a aucun filtre : c'est
        // ce que détecte `.jsb-search-bar__filters:has(div:empty)` pour masquer la zone.
        this.summaryTarget.innerHTML = resume.mode === 'vide' ? '' : this.htmlResume(resume);
        this.remplirVolet(resume);

        if (resume.mode !== 'compteur') {
            // Plus de pastille → plus rien à ancrer.
            this.fermerVolet();
        } else if (this.voletOuvert) {
            this.positionnerVolet(); // le nombre de lignes a changé
        }

        this.replacerFocus(resume);
    }

    /** Contenu de la zone « filtres » de la ligne : badge en clair ou pastille compteur. */
    htmlResume(resume) {
        if (resume.mode === 'badge') {
            const { cle, libelle, texte } = resume.filtres[0];
            return `
            <span class="jsb-filter-badge">
                <span class="jsb-filter-badge__text" title="${this.escapeHtml(texte)}">${this.escapeHtml(texte)}</span>
                <button type="button"
                    class="jsb-filter-badge__remove"
                    aria-label="Retirer le filtre ${this.escapeHtml(libelle)}"
                    data-action="click->search-bar#removeFilter"
                    data-filter-key="${this.escapeHtml(cle)}">×</button>
            </span>`;
        }

        // Signifiance : le libellé « Filtres » + le nombre disent l'état sans jargon,
        // et le détail reste à un clic (Nielsen #1 visibilité de l'état du système).
        return `
            <button type="button"
                class="jsb-filters-chip"
                data-action="click->search-bar#basculerVolet keydown->search-bar#toucheChip"
                aria-haspopup="true"
                aria-expanded="${this.voletOuvert ? 'true' : 'false'}"
                aria-controls="${this.popoverId}"
                title="${resume.nombre} critères actifs — voir le détail">
                <span>Filtres</span>
                <span class="jsb-filters-chip__count">${resume.nombre}</span>
            </button>`;
    }

    /** Peuple le volet : une ligne par critère, avec son bouton de retrait. */
    remplirVolet(resume) {
        if (!this.hasPopoverListTarget) return;

        this.popoverListTarget.innerHTML = resume.filtres.map(({ cle, libelle, texte }) => `
            <li class="jsb-search-bar__popover-item">
                <span class="jsb-search-bar__popover-text">${this.escapeHtml(texte)}</span>
                <button type="button"
                    class="jsb-filter-badge__remove"
                    aria-label="Retirer le filtre ${this.escapeHtml(libelle)}"
                    data-action="click->search-bar#removeFilter"
                    data-filter-key="${this.escapeHtml(cle)}">×</button>
            </li>`).join('');
    }

    /** Élément pastille courant (re-rendu à chaque mise à jour du résumé). */
    get chip() {
        return this.summaryTarget.querySelector('.jsb-filters-chip');
    }

    basculerVolet() {
        if (this.voletOuvert) {
            this.fermerVolet();
        } else {
            this.ouvrirVolet();
        }
    }

    ouvrirVolet() {
        if (!this.hasPopoverTarget || !this.chip) return;
        this.popoverTarget.hidden = false;
        this.voletOuvert = true;
        this.chip.setAttribute('aria-expanded', 'true');
        this.positionnerVolet();

        document.addEventListener('pointerdown', this.boundPointerHorsVolet, true);
        window.addEventListener('resize', this.boundFermerVolet);
    }

    fermerVolet() {
        if (this.hasPopoverTarget) {
            this.popoverTarget.hidden = true;
        }
        this.voletOuvert = false;
        this.chip?.setAttribute('aria-expanded', 'false');
        this.retirerEcouteursVolet();
    }

    retirerEcouteursVolet() {
        if (!this.boundPointerHorsVolet) return;
        document.removeEventListener('pointerdown', this.boundPointerHorsVolet, true);
        window.removeEventListener('resize', this.boundFermerVolet);
    }

    /**
     * Position `fixed` du volet, calculée par la géométrie PURE partagée avec le
     * menu de bulle du chat (`menu-flottant.js`) : ancré sous la pastille, basculé
     * au-dessus s'il déborderait, puis écrêté aux marges du viewport.
     */
    positionnerVolet() {
        const chip = this.chip;
        if (!chip || !this.hasPopoverTarget) return;

        const volet = this.popoverTarget;
        volet.style.visibility = 'hidden'; // mesurable sans clignotement
        const { left, top } = positionnerMenu({
            ancre: chip.getBoundingClientRect(),
            menu: { largeur: volet.offsetWidth, hauteur: volet.offsetHeight },
            viewport: { largeur: window.innerWidth, hauteur: window.innerHeight },
        });
        volet.style.left = `${left}px`;
        volet.style.top = `${top}px`;
        volet.style.visibility = 'visible';
    }

    pointerHorsVolet(event) {
        if (!this.voletOuvert) return;
        const dansVolet = this.hasPopoverTarget && this.popoverTarget.contains(event.target);
        const surChip = this.chip?.contains(event.target);
        if (!dansVolet && !surChip) this.fermerVolet();
    }

    /** Sur la pastille : ↓ ouvre et entre dans le volet, Échap referme. */
    toucheChip(event) {
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            if (!this.voletOuvert) this.ouvrirVolet();
            this.itemsVolet()[0]?.focus();
            return;
        }
        if (event.key === 'Escape' && this.voletOuvert) {
            event.preventDefault();
            this.fermerVolet();
        }
    }

    /** Flèches / Home / End dans le volet ; Échap ferme et rend le focus à la pastille. */
    naviguerPopover(event) {
        if (event.key === 'Escape') {
            event.preventDefault();
            const chip = this.chip;
            this.fermerVolet();
            chip?.focus();
            return;
        }
        const items = this.itemsVolet();
        const suivant = indexApresTouche(event.key, items.indexOf(document.activeElement), items.length);
        if (suivant === null) return;
        event.preventDefault();
        items[suivant].focus();
    }

    /** Boutons navigables du volet : les retraits puis « Tout effacer ». */
    itemsVolet() {
        if (!this.hasPopoverTarget) return [];
        return Array.from(this.popoverTarget.querySelectorAll('button'));
    }

    /**
     * Après un retrait, le bouton cliqué n'existe plus : on replace le focus sur
     * l'élément le plus proche encore présent (pastille, badge restant, sinon le
     * champ de recherche) pour ne jamais perdre le repère clavier.
     */
    replacerFocus(resume) {
        if (!this.replacerFocusApresRetrait) return;
        this.replacerFocusApresRetrait = false;

        const cible = resume.mode === 'compteur'
            ? this.chip
            : (this.summaryTarget.querySelector('.jsb-filter-badge__remove') || this.simpleSearchInputTarget);
        cible?.focus();
    }

    /**
     * Échappe une chaîne pour une insertion sûre dans du HTML.
     * @param {*} value
     * @returns {string}
     */
    escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = value == null ? '' : String(value);
        return div.innerHTML;
    }
}
