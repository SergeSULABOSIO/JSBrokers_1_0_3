// assets/controllers/search-bar_controller.js
import BaseController from './base_controller.js';
import { clesARetirer, criteresApresRetrait, criteresDeLaBarreSimple, filtresActifs } from './search-bar-filtres.js';

export default class extends BaseController {
    static targets = [
        "simpleSearchInput",
        "simpleSearchCriterion", // NOUVEAU
        // La ligne des filtres actifs (refonte de la colonne 3, C2), rendue par le serveur :
        "ligne",        // la ligne entière, masquée quand rien ne filtre
        "compte",       // « 3 filtres »
        "liste",        // les filtres, clonés depuis le modèle
        "toutRetirer",
        "modeleFiltre", // <template> d'un filtre : texte, « par défaut », ×
        "annonce",      // statut poli, masqué : la ligne lue aux lecteurs d'écran quand elle change
    ];

    static values = {
        criteria: Array,
        defaultCriterion: Object,
        nomEntite: String, // NOUVEAU : pour recevoir le nom de l'entité
        autocompleteUrl: String, // Endpoint générique d'autocomplétion des critères « relation »
        // « Tout retirer » retire « Mon portefeuille » seulement pour un administrateur du
        // cabinet (règle du chantier 6) — le serveur le dit.
        portefeuilleRetirable: Boolean,
    }

    // La barre de recherche devient "stateless". Elle ne stocke que l'état actuel reçu du cerveau pour le rendu.
    currentCriteria = {};

    connect() {
        this.nomControleur = "SEARCH_BAR";
        const workspacePanel = this.element.closest('[data-tab-id]');
        this.workspaceTabId = workspacePanel ? workspacePanel.dataset.tabId : null;

        this.boundHandleContextChanged = this.handleContextChanged.bind(this);
        document.addEventListener('app:context.changed', this.boundHandleContextChanged);
        // Le « Réinitialiser » de l'état vide, relayé par le cerveau : la barre est seule à
        // connaître la règle (ce que la ligne affiche, le droit d'administrateur).
        this.boundReinitialiser = (event) => {
            if (this.workspaceTabId && event.detail?.workspaceTabId !== this.workspaceTabId) return;
            this.resetAllFilters();
        };
        document.addEventListener('app:search.reset-requested', this.boundReinitialiser);

        // L'initialisation est minimale. Le rendu se fait via handleContextChanged.
        this.populateSimpleSearchSelector();
        this.updateSimpleSearchPlaceholder();
    }

    disconnect() {
        document.removeEventListener('app:context.changed', this.boundHandleContextChanged);
        document.removeEventListener('app:search.reset-requested', this.boundReinitialiser);
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
            // Son « Réinitialiser » suit la MÊME règle que le × de la recherche (chantier 6) :
            // la barre, seule à la connaître, lui dit ce qui doit rester.
            apresReinitialisation: this.criteresApresToutRetirer(),
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

        // L'ONGLET INTERNE concerné (principal, ou une collection contextuelle) : c'est SA liste
        // qui déclare les chips et les défauts. Sa visibilité ne suffit pas : le contexte est
        // publié avant que son panneau soit ré-affiché.
        if (event.detail.tabId) this.ongletActif = event.detail.tabId;

        // On met à jour notre copie locale des critères
        const avant = this.currentCriteria || {};
        this.currentCriteria = searchCriteria || {};
        // Le terme de la recherche simple a été RETIRÉ ailleurs (« Réinitialiser » de la recherche
        // avancée, Ket) : le champ se vide avec lui. Seulement s'il était posé : une saisie en
        // cours, pas encore envoyée, n'est jamais effacée.
        const cleSimple = this.simpleSearchCriterionTarget.value;
        if (cleSimple && avant[cleSimple] && !this.currentCriteria[cleSimple]) this.simpleSearchInputTarget.value = '';

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
        // Le choix et l'ordre : module pur (critère de recherche rapide en tête).
        const textCriteria = criteresDeLaBarreSimple(this.criteriaValue);
        this.simpleSearchCriterionTarget.replaceChildren(); // On vide le sélecteur

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
     * Le × DE LA RECHERCHE : comme « Tout retirer », la règle du chantier 6 — tous les filtres
     * partent, « Mon portefeuille » seulement pour un administrateur du cabinet. Il vidait
     * jusqu'ici TOUT, portefeuille compris, pour chacun.
     */
    resetAllFilters() {
        this.simpleSearchInputTarget.value = '';
        this.notifyCerveau('ui:search.submitted', { criteria: this.criteresApresToutRetirer(), workspaceTabId: this.workspaceTabId });
    }

    /**
     * Les critères QUI RESTENT après « tout retirer » — la seule écriture de la règle du chantier 6
     * côté écran, servie aussi au « Réinitialiser » de la recherche avancée et à celui de l'état
     * vide : part ce que la ligne AFFICHE comme retirable (jamais la maille de lecture ni ce que
     * le serveur impose), « Mon portefeuille » seulement pour un administrateur du cabinet.
     */
    criteresApresToutRetirer() {
        return criteresApresRetrait(this.currentCriteria, this.filtresAffiches, this.portefeuilleRetirableValue);
    }

    /** « Tout retirer », sur la ligne des filtres actifs. */
    retirerTout() {
        // Le bouton disparaît avec la ligne : le focus ira au champ de recherche.
        this.replacerFocusApresRetrait = true;
        this.resetAllFilters();
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
        this.notifyCerveau('ui:search.submitted', { criteria: newCriteria, workspaceTabId: this.workspaceTabId });
    }

    removeFilter(event) {
        const keyToRemove = event.currentTarget.dataset.filterKey;
        // Le terme de la recherche simple : le champ se vide avec son filtre.
        if (keyToRemove === this.simpleSearchCriterionTarget.value) this.simpleSearchInputTarget.value = '';

        const newCriteria = { ...this.currentCriteria };
        delete newCriteria[keyToRemove];

        // Le bouton cliqué va disparaître au ré-affichage : sans cela le focus
        // retomberait sur <body> (Bastien & Scapin > Guidage ; WCAG 2.4.3). On
        // note qu'il faudra le replacer une fois le nouveau résumé rendu.
        this.replacerFocusApresRetrait = true;

        this.notifyCerveau('ui:search.submitted', { criteria: newCriteria, workspaceTabId: this.workspaceTabId });
    }

    /**
     * LA LIGNE DES FILTRES ACTIFS : la décision vient du module pur (filtresActifs) ; ici, on
     * lit ce que la liste de l'onglet actif déclare (ses chips, ses défauts), puis on CLONE le
     * modèle rendu par le serveur et l'on pose les textes par `textContent`.
     */
    updateSummary(criteria) {
        const liste = this._listeActive();
        const filtres = filtresActifs(criteria, {
            definitions: this.criteriaValue,
            groupes: this._groupesDeChips(liste),
            defauts: this._lireJson(liste?.dataset.listManagerInitialCriteriaValue, {}),
            imposes: this._lireJson(liste?.dataset.criteresImposes, []),
        });
        this.filtresAffiches = filtres;

        this.listeTarget.replaceChildren(...filtres.map((filtre) => this._filtre(filtre)));
        this.compteTarget.textContent = filtres.length > 1 ? `${filtres.length} filtres` : '';
        // « Tout retirer » n'a de sens que s'il reste quelque chose qu'il retirerait.
        this.toutRetirerTarget.hidden = clesARetirer(filtres, this.portefeuilleRetirableValue).length === 0;
        this.ligneTarget.hidden = filtres.length === 0;

        // L'ANNONCE : un filtre posé par un chip, par Ket ou par un retrait se dit aux lecteurs
        // d'écran — une seule fois, quand la ligne change vraiment.
        const annonce = filtres.length
            ? `Filtres actifs : ${filtres.map((f) => f.texte + (f.parDefaut ? ' (par défaut)' : '')).join(', ')}`
            : 'Aucun filtre actif';
        if (this.hasAnnonceTarget && this.annonceTarget.textContent !== annonce) this.annonceTarget.textContent = annonce;

        this.replacerFocus();
    }

    /** Un filtre, cloné du modèle. @private */
    _filtre({ cle, texte, parDefaut, retirable }) {
        const element = this.modeleFiltreTarget.content.firstElementChild.cloneNode(true);
        element.querySelector('.jsb-filtre-actif__texte').textContent = texte;
        element.querySelector('.jsb-filtre-actif__defaut').hidden = !parDefaut;
        const bouton = element.querySelector('.jsb-filtre-actif__retirer');
        if (retirable) {
            bouton.dataset.filterKey = cle;
            bouton.setAttribute('aria-label', `Retirer le filtre ${texte}`);
        } else {
            // Un défaut que le serveur applique toujours : le retirer ne changerait rien.
            bouton.remove();
        }

        return element;
    }

    /**
     * La liste de l'onglet ACTIF de la rubrique (principal ou contextuel) : c'est elle qui
     * déclare les chips et les défauts. @private
     */
    _listeActive() {
        const vue = this.element.closest('[data-controller~="view-manager"]');
        if (!vue) return null;
        const listes = [...vue.querySelectorAll('.jsb-list-manager')];

        return listes.find((l) => l.id === this.ongletActif)
            || listes.find((l) => l.getClientRects().length > 0)
            || null;
    }

    /** Les groupes de chips, lus dans la liste (rendue par le serveur). @private */
    _groupesDeChips(liste) {
        if (!liste) return [];

        return [...liste.querySelectorAll('.jsb-preset-filters[data-preset-criterion]')].map((groupe) => {
            const options = {};
            let defaut;
            groupe.querySelectorAll('.jsb-preset-chip[data-criterion-value]').forEach((chip) => {
                const libelle = chip.querySelector(':scope > span:not(.jsb-preset-chip__icon)');
                options[chip.dataset.criterionValue] = (libelle ? libelle.textContent : chip.textContent).trim();
                if (chip.dataset.criterionDefaut === 'true') defaut = chip.dataset.criterionValue;
            });
            // Un groupe AVEC « Toutes » n'a pas de défaut imposé : seul un groupe sans option vide
            // en déclare un (ChipsCoherenceContratTest).
            if (Object.prototype.hasOwnProperty.call(options, '')) defaut = undefined;

            return {
                cle: groupe.dataset.presetCriterion,
                // Le titre est SORTI du groupe par chips-defilement.js (il ne défile pas avec les
                // valeurs) : on le cherche dans l'enveloppe, à défaut le nom accessible du groupe.
                titre: ((groupe.closest('.jsb-preset-filters-wrap') || groupe).querySelector('.jsb-preset-filters__titre')?.textContent
                    || groupe.getAttribute('aria-label') || groupe.dataset.presetCriterion).trim(),
                options,
                defaut,
                presentation: groupe.dataset.presetPresentation === 'true',
            };
        });
    }

    /** Une déclaration JSON du serveur (défauts, critères imposés) ; illisible : le repli. @private */
    _lireJson(texte, repli) {
        try {
            return texte ? (JSON.parse(texte) ?? repli) : repli;
        } catch {
            return repli;
        }
    }

    /**
     * Après un retrait, le bouton cliqué n'existe plus : le focus va au × suivant de la ligne,
     * sinon au champ de recherche — jamais sur la page (WCAG 2.4.3).
     */
    replacerFocus() {
        if (!this.replacerFocusApresRetrait) return;
        this.replacerFocusApresRetrait = false;

        const cible = this.ligneTarget.hidden ? null : this.listeTarget.querySelector('.jsb-filtre-actif__retirer');
        (cible || this.simpleSearchInputTarget).focus();
    }
}
