import BaseController from './base_controller.js';
import { Toast, Modal } from 'bootstrap';
/**
 * @class BordereauAnalysisController
 * @description Gère l'interface de l'analyse de bordereau en deux étapes.
 * Etape 1: Sélection de la feuille Excel.
 * Etape 2: Mappage des colonnes de la feuille sélectionnée.
 */
export default class extends BaseController { // NOUVEAU : Ajout du bouton de retour
    static targets = [ // NOUVEAU : Ajout de la cible pour le bouton de retour
        "sheetSelection", "step2", "mappingContainer", "mappingStatusFeedback", "toastBody", "toastContainer", "mappingForm",
        "mappingSelect", "analysisResult", "submitButton", "columnNameText", "step1", "step3", "analysisResultsList", "progressBar", "progressBarContainer", "analysisSummary", "validateButton",
        "backToMappingButton", "actionsBlock", "optionsMenu", "exportPdfItem", "bulkCreateItem", "bulkUpdateItem", "bulkDivider",
        "toolbarTitleIconPrepare", "toolbarTitleIconSuccess",
        "reanalyzeItem",
        "toolbarBadgeStep",
        "toolbarBadgeStatus",
        "searchBlock",       // conteneur global de la barre de recherche (affiché uniquement étape 3)
        "searchInput",       // <input type="search"> de saisie du mot-clé
        "searchResultCount", // span affichant "X résultat(s) sur Y"
        "validationModal",          // modal Bootstrap de confirmation de validation
        "factureButton",            // bouton "Facturer" (affiché post-validation uniquement)
        "backToMappingDropdownItem", // item "Retour" dans le dropdown (affiché post-validation)
        "linkedNotesBadge",          // conteneur du badge note liée
        "linkedNotesBadgeText",      // span texte du badge note liée
        "noteActionsDivider",        // séparateur dropdown (notes liées)
        "editNoteItem",              // dropdown item "Éditer la note"
        "viewNoteItem",              // dropdown item "Voir la note"
    ];

    static values = {
        bordereauId: Number, // NOUVEAU : ID du bordereau pour l'API
        sheetsData: Object,
        // NOUVEAU : On reçoit les chargements depuis le backend via le template Twig.
        chargements: Array, // [{id, nom}, ...]
        // NOUVEAU : On reçoit les types de revenu de la même manière.
        typeRevenus: Array, // [{id, nom}, ...]
        // NOUVEAU : On reçoit les options de mappage système depuis le backend.
        mappingOptions: Object,
        analysisResults: Array, // NOUVEAU : Pour la restauration de l'état
        analysisResultsHtml: Array, // NOUVEAU : HTML des résultats pour la restauration
        selectedSheetName: String, // NOUVEAU : Pour la restauration de l'état,
        mappedColumns: Object,     // NOUVEAU : Pour la restauration de l'état
        analysisStats: Object,
        currentAnalysisStep: Number, // NOUVEAU : Pour la restauration de l'état
        isBulkProcessing: Boolean, // NOUVEAU : Pour gérer l'état de traitement en lot
        idEntreprise: Number,
        idInvite: Number,
        noteFormCanvas: Object, // Canvas JSON pour le formulaire Note (facturation)
        linkedNotes: Array,     // Notes déjà liées au bordereau [{id, reference, nom}]
    };

    connect() {
        console.groupCollapsed("[BordereauAnalysis] 1. connect() - Connexion du contrôleur");
        console.log("Valeurs initiales reçues du DOM:", {
            currentAnalysisStep: this.currentAnalysisStepValue,
            selectedSheetName: this.selectedSheetNameValue,
            mappedColumns: this.mappedColumnsValue,
            analysisResults: this.analysisResultsValue, // Données brutes
            analysisResultsHtml: this.analysisResultsHtmlValue, // HTML
            analysisStats: this.analysisStatsValue
        });
        console.groupEnd();

        // NOUVEAU : Initialisation du cache pour les icônes des résultats d'analyse.
        this.iconCache = new Map();
        // NOUVEAU : Suivi des requêtes en cours pour éviter les doublons (Request Collapsing)
        this.inFlightIcons = new Map();
        this.boundHandleIconRequest = this.handleIconRequest.bind(this);
        // CORRECTION : On écoute sur `document` pour intercepter les événements des enfants
        // qui sont injectés dynamiquement et ne sont pas des descendants directs.
        document.addEventListener('analysis:icon.request', this.boundHandleIconRequest);

        // NOUVEAU : Écoute la résolution des items pour activer le bouton Valider
        this.boundHandleItemResolved = this._handleItemResolved.bind(this);
        document.addEventListener('cerveau:event', this.boundHandleItemResolved);

        // NOUVEAU : Écoute les actions individuelles venant des items
        this.boundHandleItemActionStart = this._handleItemActionStart.bind(this);
        this.boundHandleItemActionCompleted = this._handleItemActionCompleted.bind(this);
        
        // CORRECTION : Puisque BaseController.dispatch n'ajoute pas de préfixe automatique, 
        // nous devons écouter les noms d'événements tels qu'ils sont émis : "action:start" et "action:completed".
        this.element.addEventListener('action:start', this.boundHandleItemActionStart);
        this.element.addEventListener('action:completed', this.boundHandleItemActionCompleted);

        this.requiredMappings = new Set([
            'num_avenant',
            'reference_police',
            'date_effet_avenant',
            'date_expiration_avenant',
            'prime_ttc', // NOUVEAU : Ajout de la prime TTC
            'date_operation', // Nouveau champ obligatoire
            'risque', // Nouveau champ obligatoire
            'nom_client',
            'commission_ht_payable_now',
            'taxe_commission_payable_now',
            'taux_commission' // Nouveau champ obligatoire
            // NOUVEAU : Ajout de champs obligatoires pour l'étape 3
        ]);
        this.validationState = new Map();

        console.log("[BordereauAnalysis] 2. connect() - Initialisation des options de mappage.");
        this.addChargementOptions();
        this.addTypeRevenuOptions();
        this.addSystemOptions();

        this.isRestoring = false;
        this.currentStep = 1;
        this._initialToastShown = false; // Nouveau drapeau pour le toast initial
        this.isBulkProcessingValue = false; // Initialiser le drapeau de traitement en lot
        this.isSaving = false; // NOUVEAU : Le "feu de signalisation" pour la sauvegarde.

        // État du moteur de recherche (étape 3 uniquement)
        this._currentSearchTerm = '';   // dernier terme appliqué
        this._totalItemCount    = 0;    // nb total d'items rendus (référence pour le compteur)

        this._pendingSaveTimeout = null; // Identifiant pour le debounce des sauvegardes
        // Timeout de debounce dédié à la sauvegarde automatique du mappage
        this._pendingMappingSaveTimeout = null;
        this._toastInstance = null; // Initialisation propre au début

        console.log("[BordereauAnalysis] 3. connect() - Mise en place des écouteurs d'événements.");

        // Étape 1: Restauration ou initialisation de l'étape par défaut.
        if (this.currentAnalysisStepValue > 0) {
            console.log(`%c[BordereauAnalysis] 4. connect() - Données de restauration détectées (étape ${this.currentAnalysisStepValue}). Lancement du processus...`, "color: blue; font-weight: bold;");
            this._restoreAnalysisState();
        } else if (this.sheetSelectionTargets.length === 1 && this.sheetsDataValue && Object.keys(this.sheetsDataValue).length === 1) {
            console.log("[BordereauAnalysis] 4. connect() - Une seule feuille détectée. Passage automatique à l'étape 2.");
            this.showStep(2);
        } else {
            console.log("[BordereauAnalysis] 4. connect() - Exécution du chargement initial de l'UI (état par défaut).");
            this.afterConnect();
        }
        // Le drapeau est remis à false une fois la logique de connect() terminée
        console.log("[BordereauAnalysis] 6. connect() - Fin de la connexion.");
    }

    /**
     * Met à jour les badges bibliographiques de la barre d'outils
     * en fonction de l'étape courante de l'analyse.
     * Appelé à chaque transition de showStep() et après validateBordereau().
     * @param {number} step - Le numéro de l'étape courante (1, 2, 3, ou 99 pour validé - constante STATUT_ANALYSE_TERMINEE)
     */
    _updateToolbarMeta(step) {
        if (!this.hasToolbarBadgeStepTarget || !this.hasToolbarBadgeStatusTarget) return;

        // --- Calcul du texte et des couleurs selon l'étape ---
        const stepConfig = {
            1: { stepText: 'Étape 1 · Sélection' },
            2: { stepText: 'Étape 2 · Mappage' },
            3: { stepText: 'Étape 3 · Résultats' },
        };

        // Valeur PHP Bordereau::STATUT_ANALYSE_TERMINEE = 99
        // On détecte ce cas si step est supérieur à 3
        const isValidated = step > 3;

        const statusConfig = {
            1: { bg: 'rgba(108,117,125,0.4)', color: '#ffffff',  text: 'En attente' },
            2: { bg: 'rgba(255,193,7,0.3)',   color: '#ffc107',  text: 'En cours'   },
            3: { bg: 'rgba(25,135,84,0.3)',   color: '#20c997',  text: 'Analysé'    },
        };
        const validatedStatus = { bg: 'rgba(0,71,171,0.4)', color: '#90b8ff', text: 'Validé' };

        // Résolution des valeurs à appliquer
        const resolvedStep   = isValidated ? 3 : (step || 1);
        const stepCfg        = stepConfig[resolvedStep]  || stepConfig[1];
        const statusCfg      = isValidated ? validatedStatus : (statusConfig[resolvedStep] || statusConfig[1]);

        // --- Mise à jour du DOM ---
        this.toolbarBadgeStepTarget.textContent = isValidated ? 'Analyse terminée' : stepCfg.stepText;

        this.toolbarBadgeStatusTarget.textContent             = statusCfg.text;
        this.toolbarBadgeStatusTarget.style.backgroundColor   = statusCfg.bg;
        this.toolbarBadgeStatusTarget.style.color             = statusCfg.color;

        console.log(`[BordereauAnalysis] _updateToolbarMeta() — Étape ${step} → badge "${this.toolbarBadgeStepTarget.textContent}" / statut "${statusCfg.text}"`);
    }

    disconnect() {
        console.log("[BordereauAnalysis] disconnect() - Nettoyage des écouteurs.");
        document.removeEventListener('analysis:icon.request', this.boundHandleIconRequest);
        document.removeEventListener('cerveau:event', this.boundHandleItemResolved);
        this.element.removeEventListener('action:start', this.boundHandleItemActionStart);
        this.element.removeEventListener('action:completed', this.boundHandleItemActionCompleted);

        // Annuler toute sauvegarde de mappage en attente
        if (this._pendingMappingSaveTimeout) {
            clearTimeout(this._pendingMappingSaveTimeout);
            this._pendingMappingSaveTimeout = null;
        }
    }

    /**
     * Gère le début d'une action manuelle sur un item.
     */
    _handleItemActionStart() {
        if (this.isBulkProcessingValue) return;
        console.log('[BordereauAnalysis] _handleItemActionStart() - Event received, toggling progress bar ON');
        this.toggleProgressBar(true);
    }

    /**
     * Gère la fin d'une action manuelle sur un item.
     */
    _handleItemActionCompleted(event) {
        console.log('[BordereauAnalysis] _handleItemActionCompleted() - Event received, toggling progress bar OFF and showing toast');
        if (this.isBulkProcessingValue) return; // Règle : pas de toast individuel en lot
        this.toggleProgressBar(false);
        const { success, message } = event.detail;
        this._showToast(success ? 'success' : 'error', message, true);
    }

    /**
     * Gère les mises à jour initiales de l'UI.
     */
    afterConnect() {
        this.updateMappingStatusFeedback();
        this.updateSubmitButtonState();
        this.updateSelectOptionsVisuals();
    }

    /**
     * Gère les demandes d'icônes venant des items de résultat.
     * Si l'icône est en cache, elle est renvoyée immédiatement.
     * Sinon, une requête fetch est lancée pour la récupérer depuis le serveur.
     * @param {CustomEvent} event
     */
    async handleIconRequest(event) {
        console.log(`%c[Parent] 1. Reçu 'analysis:icon.request'`, 'color: orange;', event.detail);
        const { iconName, requesterId, iconSize } = event.detail;

        // SÉCURITÉ : On ne traite que les requêtes qui proviennent de nos enfants.
        // Le requesterId doit commencer par l'ID du bordereau pour être valide.
        if (!requesterId || !requesterId.startsWith(this.bordereauIdValue)) {
            console.warn(`%c[Parent] 1a. Rejeté : requesterId (${requesterId}) ne correspond pas à l'ID du bordereau (${this.bordereauIdValue}).`, 'color: red;');
            return; // On ne traite pas la requête si elle ne vient pas d'un enfant légitime.
        }
        if (!iconName || !requesterId) return;

        // 1. Vérifier le cache (icônes déjà téléchargées)
        if (this.iconCache.has(iconName)) {
            console.log(`%c[Parent] 1b. Icône '${iconName}' trouvée en cache. Renvoi immédiat.`, 'color: green;');
            this._dispatchIconLoaded(this.iconCache.get(iconName), requesterId, iconName);
            return;
        }

        // 2. Vérifier si une requête est déjà en cours pour cette icône
        if (this.inFlightIcons.has(iconName)) {
            console.log(`%c[Parent] 1c. Icône '${iconName}' déjà en cours de téléchargement. Mise en attente...`, 'color: blue;');
            try {
                const html = await this.inFlightIcons.get(iconName);
                this._dispatchIconLoaded(html, requesterId, iconName);
            } catch (error) {
                this._dispatchIconLoaded('<!-- error -->', requesterId, iconName);
            }
            return;
        }

        // 3. Lancer une nouvelle requête unique
        const fetchPromise = (async () => {
            const url = `/api/icon/api/get-icon?name=${encodeURIComponent(iconName)}&size=${iconSize}`;
            try {
                const response = await fetch(url);
                if (!response.ok) throw new Error(`Status ${response.status}`);
                const html = await response.text();

                if (html && !html.trim().startsWith('<!--')) {
                    this.iconCache.set(iconName, html);
                }
                return html;
            } catch (error) {
                console.error(`[BordereauAnalysis] Failed to fetch icon '${iconName}':`, error);
                return '<!-- error -->';
            } finally {
                this.inFlightIcons.delete(iconName); // Nettoyage une fois terminé
            }
        })();

        this.inFlightIcons.set(iconName, fetchPromise);
        const finalHtml = await fetchPromise;
        this._dispatchIconLoaded(finalHtml, requesterId, iconName);
    }

    /**
     * Helper pour envoyer l'événement de chargement d'icône.
     */
    _dispatchIconLoaded(html, requesterId, iconName) {
        document.dispatchEvent(new CustomEvent('analysis:icon.loaded', {
            bubbles: true,
            detail: { html, requesterId, iconName }
        }));
    }

    /**
     * Finalise le processus de restauration en mettant à jour l'UI.
     */
    finalizeRestoration() {
        this.isRestoring = false; // Réinitialise le drapeau
        // updateSubmitButtonState appellera updateMappingStatusFeedback
        // de manière sécurisée car isRestoring est maintenant à false.
        // On évite ainsi un double appel synchrone qui fait planter Bootstrap Toast.
        this.updateSubmitButtonState();
        this._showWelcomeToast(); // Afficher le toast de bienvenue après la restauration
    }

    /**
     * Restaure l'état de l'analyse du bordereau depuis les données du backend.
     */
    _restoreAnalysisState() {
        console.groupCollapsed("%c[BordereauAnalysis] _restoreAnalysisState() - DÉBUT DE LA RESTAURATION", "color: blue;");
        this.isRestoring = true; // Set flag to prevent premature saving
        console.log("Drapeau 'isRestoring' positionné à 'true'.");

        // 1. Restaurer la sélection de la feuille (UI)
        if (this.selectedSheetNameValue) {
            const selectedSheetInput = this.sheetSelectionTargets.find(radio => radio.value === this.selectedSheetNameValue);
            if (selectedSheetInput) {
                selectedSheetInput.checked = true;
                console.log(`1. Feuille restaurée: La feuille '${this.selectedSheetNameValue}' a été cochée.`);
            }
        } else {
            console.log("1. Feuille non restaurée: 'selectedSheetNameValue' est vide.");
        }

        // 2. Restaurer les résultats de l'analyse si l'étape est 3 (Data)
        if (this.currentAnalysisStepValue === 3 && this.analysisResultsHtmlValue) {
            console.log("2. Résultats d'analyse HTML en mémoire, rendu délégué à showStep(3).");
        } else {
            console.log("2. Résultats d'analyse non restaurés (pas à l'étape 3 ou données vides).");
        }

        // 3. Naviguer vers l'étape sauvegardée (UI - rend le conteneur de mappage visible si étape 2)
        // C'est crucial de faire cela AVANT de tenter de restaurer les selects du mappage,
        // car les selects doivent être visibles pour que leur valeur soit correctement définie et validée.
        // Cas particulier : étape 1 + feuille unique → même comportement qu'au chargement initial (connect()).
        const _isSingleSheet = this.sheetSelectionTargets.length === 1;
        let _effectiveStep  = this.currentAnalysisStepValue;
        let _effectiveSheet = this.selectedSheetNameValue;

        if (_effectiveStep === 1 && _isSingleSheet) {
            _effectiveStep  = 2;
            _effectiveSheet = this.sheetSelectionTargets[0].value;
            console.log("3. Auto-avancement : feuille unique détectée → passage forcé à l'étape 2.");
        }

        this.showStep(_effectiveStep, _effectiveSheet);
        console.log(`3. Navigation UI: Appel de showStep(${_effectiveStep}) pour afficher la bonne étape.`);

        // 4. Restaurer le mappage des colonnes (UI - doit se faire après que le conteneur soit visible)
        if (this.mappedColumnsValue && Object.keys(this.mappedColumnsValue).length > 0) {
            console.log("4. Mappage des colonnes: Tentative de restauration avec les données:", this.mappedColumnsValue);
            // Attendre que le DOM soit mis à jour après showStep
            requestAnimationFrame(() => {
                console.groupCollapsed("-> Dans requestAnimationFrame (après rendu du DOM)");
                // On doit cibler uniquement les selects du conteneur de mappage ACTIF.
                const activeMappingContainer = this.element.querySelector('.column-mapping-form:not([style*="display: none"])');
                if (activeMappingContainer) {
                    console.log("Conteneur de mappage actif trouvé:", activeMappingContainer);
                    const selectsInActiveContainer = activeMappingContainer.querySelectorAll('select[data-column-letter]');
                    selectsInActiveContainer.forEach(select => {
                        const columnLetter = select.dataset.columnLetter;
                        const mappedSystemField = Object.keys(this.mappedColumnsValue).find(key => {
                            const stored = this.mappedColumnsValue[key];
                            // Compatibilité ascendante : supporte l'ancienne structure string ET la nouvelle tableau
                            return Array.isArray(stored)
                                ? stored.includes(columnLetter)
                                : stored === columnLetter;
                        });
                        if (mappedSystemField) {
                            select.value = mappedSystemField;
                            console.log(`   - Colonne '${columnLetter}' -> Mappée sur '${mappedSystemField}'.`);
                            this.performValidation(select); // Valider la colonne restaurée pour mettre à jour l'état de validation interne
                        }
                    });
                } else {
                    console.warn("Aucun conteneur de mappage actif trouvé pour restaurer les selects.");
                }

                // Deuxième frame : finaliser une fois les valeurs effectives dans le DOM
                requestAnimationFrame(() => {
                    this.updateSelectOptionsVisuals();
                    this.finalizeRestoration();
                    console.groupEnd();
                });
            });
        } else {
            requestAnimationFrame(() => this.finalizeRestoration()); // Toujours utiliser requestAnimationFrame pour la cohérence
        }
        console.groupEnd();
    }

    /**
     * Ajoute dynamiquement les chargements comme options dans tous les selects de mappage.
     */
    addChargementOptions() {
        if (!this.hasChargementsValue || this.chargementsValue.length === 0) {
            return;
        }

        // On crée un groupe d'options pour les chargements.
        const optgroup = document.createElement('optgroup');
        optgroup.label = 'Chargements (Optionnel)';

        this.chargementsValue.forEach(chargement => {
            const option = document.createElement('option');
            // NOUVEAU : La valeur inclut maintenant l'ID et le nom "slugifié" pour correspondre au backend.
            const slug = chargement.nom.replace(/[^a-zA-Z0-9\s]/g, '').replace(/\s+/g, '_');
            option.value = `chargement_${chargement.id}_${slug}`;
            option.textContent = chargement.nom;
            optgroup.appendChild(option);
        });

        // On ajoute ce groupe d'options à chaque <select> de mappage.
        this.mappingSelectTargets.forEach(select => {
            select.appendChild(optgroup.cloneNode(true));
        });
    }

    /**
     * Ajoute dynamiquement les types de revenu comme options dans tous les selects de mappage.
     */
    addTypeRevenuOptions() {
        if (!this.hasTypeRevenusValue || this.typeRevenusValue.length === 0) {
            return;
        }

        // On crée un groupe d'options pour les revenus.
        const optgroup = document.createElement('optgroup');
        optgroup.label = 'Revenus (Optionnel)';

        this.typeRevenusValue.forEach(revenu => {
            const option = document.createElement('option');
            // NOUVEAU : La valeur inclut maintenant l'ID et le nom "slugifié".
            const slug = revenu.nom.replace(/[^a-zA-Z0-9\s]/g, '').replace(/\s+/g, '_');
            option.value = `revenu_${revenu.id}_${slug}`;
            option.textContent = revenu.nom;
            optgroup.appendChild(option);
        });

        // On ajoute ce groupe d'options à chaque <select> de mappage.
        this.mappingSelectTargets.forEach(select => {
            select.appendChild(optgroup.cloneNode(true));
        });
    }

    /**
     * Ajoute dynamiquement les options de mappage système comme options dans tous les selects.
     */
    addSystemOptions() {
        if (!this.hasMappingOptionsValue || Object.keys(this.mappingOptionsValue).length === 0) {
            // Si mappingOptionsValue est vide, cela signifie que le backend n'a pas fourni les options.
            // Cela peut arriver si le contrôleur PHP n'est pas à jour ou si l'entreprise n'a pas de données.
            // Pour éviter une erreur, on peut logguer un avertissement ou simplement retourner.
            console.warn("Aucune option de mappage système n'a été fournie par le backend.");
            return;
        }

        // On crée un groupe d'options pour les champs système obligatoires.
        const optgroup = document.createElement('optgroup');
        optgroup.label = 'Options systèmes (Obligatoire)';

        // On itère sur les options reçues du backend et on les ajoute au groupe.
        for (const key in this.mappingOptionsValue) {
            const option = document.createElement('option');
            option.value = key;
            option.textContent = this.mappingOptionsValue[key];
            optgroup.appendChild(option);
        }

        this.mappingSelectTargets.forEach(select => {
            // Insérer le groupe d'options système juste après l'option "Ignorer cette colonne"
            select.insertBefore(optgroup.cloneNode(true), select.children[1]);
        });
    }
    /**
     * Action déclenchée lorsqu'un select de mappage est modifié.
     * Valide la colonne puis planifie une sauvegarde silencieuse
     * du mappage via un debounce de 800ms.
     * @param {Event} event
     */
    validateColumn(event) {
        if (this.isBulkProcessingValue) { // NOUVEAU : Ne pas valider pendant un traitement en lot
            return;
        }

        const selectElement = event.currentTarget;
        this.performValidation(selectElement);

        // Pendant la restauration ou une sauvegarde full en cours :
        // on ne planifie aucune sauvegarde automatique.
        if (this.isRestoring || this.isSaving) {
            return;
        }

        // Vérifier qu'au moins un champ obligatoire est mappé
        // avant de juger utile de déclencher une requête réseau.
        if (!this._hasMappingDataWorthSaving()) {
            return;
        }

        // Annuler le timeout précédent (debounce)
        if (this._pendingMappingSaveTimeout) {
            clearTimeout(this._pendingMappingSaveTimeout);
        }

        // Planifier la sauvegarde 800ms après le dernier changement
        this._pendingMappingSaveTimeout = setTimeout(async () => {
            this._pendingMappingSaveTimeout = null;

            // Double vérification au moment de l'exécution
            if (this.isRestoring || this.isSaving) {
                return;
            }

            console.log("[BordereauAnalysis] validateColumn() debounce - Sauvegarde automatique du mappage.");
            await this._saveMappingOnly();

        }, 800);
    }

    /**
     * Vérifie si le mappage actuel contient au moins un champ obligatoire
     * pour justifier une requête réseau de sauvegarde.
     * @returns {boolean}
     */
    _hasMappingDataWorthSaving() {
        const activeMappingContainer = this.element.querySelector(
            '.column-mapping-form:not([style*="display: none"])'
        );
        if (!activeMappingContainer) return false;

        const selects = activeMappingContainer.querySelectorAll(
            'select[data-column-letter]'
        );

        for (const select of selects) {
            if (select.value && this.requiredMappings.has(select.value)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Sauvegarde silencieuse et légère du mappage des colonnes uniquement.
     * Utilisée exclusivement par le debounce de validateColumn().
     * - Ne modifie PAS isSaving (pas de blocage du bouton)
     * - N'affiche AUCUN feedback visible à l'utilisateur
     * - Ne touche PAS à la barre de progression
     * - En cas d'échec : log silencieux uniquement, pas d'alerte
     */
    async _saveMappingOnly() {
        // NOUVEAU : Ne pas sauvegarder le mappage si un traitement en lot est en cours
        if (this.isBulkProcessingValue) {
            return;
        }

        // Lire la feuille sélectionnée
        const selectedSheetInput = this.sheetSelectionTargets.find(
            radio => radio.checked
        );
        const selectedSheetName = selectedSheetInput
            ? selectedSheetInput.value
            : null;

        // Lire le mappage depuis le formulaire actif
        const mappedColumns = {};
        const activeMappingContainer = this.element.querySelector(
            '.column-mapping-form:not([style*="display: none"])'
        );
        if (activeMappingContainer) {
            const selects = activeMappingContainer.querySelectorAll(
                'select[data-column-letter]'
            );
            selects.forEach(select => {
                if (select.value) {
                    const col = select.dataset.columnLetter;
                    if (!mappedColumns[select.value]) {
                        mappedColumns[select.value] = [];
                    }
                    if (!mappedColumns[select.value].includes(col)) {
                        mappedColumns[select.value].push(col);
                    }
                }
            });
        }

        // Sécurité : ne jamais envoyer un mappage vide
        if (Object.keys(mappedColumns).length === 0) {
            console.log("[BordereauAnalysis] _saveMappingOnly() - Mappage vide, sauvegarde annulée.");
            return;
        }

        const payload = {
            currentAnalysisStep: this.currentStep,
            selectedSheetName: selectedSheetName,
            mappedColumns: mappedColumns,
            // analysisResults intentionnellement absent :
            // non pertinent pour une sauvegarde de mappage en cours d'étape 2
        };

        console.log("[BordereauAnalysis] _saveMappingOnly() - Payload:", payload);

        try {
            const response = await fetch(
                `/admin/bordereau/api/save-analysis-state/${this.bordereauIdValue}`,
                {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                }
            );

            if (!response.ok) {
                // Échec silencieux : le travail est toujours dans le DOM,
                // la prochaine interaction retentera automatiquement.
                const err = await this._parseErrorResponse(response);
                console.warn("[BordereauAnalysis] _saveMappingOnly() - Échec silencieux:", err);
                return;
            }

            const result = await response.json();
            console.log(
                "[BordereauAnalysis] _saveMappingOnly() - Mappage sauvegardé silencieusement.",
                result.message
            );

        } catch (error) {
            // Erreur réseau : échec silencieux
            console.warn(
                "[BordereauAnalysis] _saveMappingOnly() - Erreur réseau silencieuse:",
                error.message
            );
        }
    }

    /**
     * Affiche un toast de bienvenue spécifique à l'étape actuelle.
     * S'assure qu'il n'est affiché qu'une seule fois au chargement initial.
     */
    _showWelcomeToast() {
        if (this._initialToastShown) return; // S'assurer qu'il n'est affiché qu'une seule fois

        let title = '';
        let message = '';
        switch (this.currentStep) {
            case 1:
                title = 'Bienvenue !';
                message = 'Veuillez sélectionner la feuille de calcul contenant les données à analyser.';
                break;
            case 2:
                title = 'Étape 2 : Mappage des colonnes';
                message = 'Associez les colonnes de votre fichier aux champs système. Tous les champs obligatoires doivent être mappés et valides.';
                break;
            case 3:
                title = 'Étape 3 : Résultats de l\'analyse';
                message = 'Vérifiez les avenants détectés et traitez les anomalies ou les nouveaux éléments.';
                break;
            default:
                return; // Aucun toast pour les états non définis (ex : step=99 bordereau validé)
        }
        this._showToast('info', `<strong>${title}</strong><br>${message}`, true, true);
        this._initialToastShown = true;
    }
    /**
     * Affiche l'étape 2 (mappage) et sélectionne le bon tableau de mappage
     * en fonction de la feuille choisie à l'étape 1.
     */
    async showMappingStep(event) {
        if (event) event.preventDefault(); // Empêche le comportement par défaut du bouton
        const selectedSheetInput = this.sheetSelectionTargets.find(radio => radio.checked);
        if (!selectedSheetInput) {
            // Cas de sécurité, ne devrait pas arriver avec un radio button.
            return;
        }
        const selectedSheetName = selectedSheetInput.value;
        await this.showStep(2, selectedSheetName); // showStep appellera _saveAnalysisStateToBordereau()
    }

    /**
     * Gère la transition entre les étapes de l'analyse.
     * Applique la visibilité stricte de chaque bouton selon l'étape.
     * @param {number|Event} stepNumber
     * @param {string} [sheetName=null]
     */
    async showStep(stepNumber, sheetName = null) {
        if (this.isBulkProcessingValue) { // NOUVEAU : Empêcher les transitions d'étape pendant le traitement en lot
            return;
        }

        const previousStep = this.currentStep;
        this.currentStep = (
            typeof stepNumber === 'object' && stepNumber.currentTarget
        )
            ? parseInt(stepNumber.currentTarget.dataset.stepNumber)
            : stepNumber;

        // Mise à jour réactive des badges bibliographiques de la toolbar
        this._updateToolbarMeta(this.currentStep);

        console.log(
            `[BordereauAnalysis] showStep() - Transition étape ${previousStep} → ${this.currentStep}.`,
            `Feuille: ${sheetName || 'N/A'}. Restauration: ${this.isRestoring}`
        );

        // --- 1. Masquer tous les contenus d'étape ---
        this.step1Target.classList.add('d-none');
        this.step2Target.classList.add('d-none');
        this.step3Target.classList.add('d-none');

        // --- 2. Masquer TOUS les boutons de la barre d'outils ---
        // Principe : on repart de zéro à chaque transition.
        // Seuls les boutons pertinents à l'étape seront réaffichés.
        if (this.hasSubmitButtonTarget) {
            this.submitButtonTarget.classList.add('d-none');
        }
        this.backToMappingButtonTargets.forEach(btn => btn.classList.add('d-none'));
        if (this.hasValidateButtonTarget) {
            this.validateButtonTarget.classList.add('d-none');
        }
        if (this.hasExportPdfItemTarget) this.exportPdfItemTarget.classList.add('d-none');
        if (this.hasSearchBlockTarget)   this.searchBlockTarget.classList.add('d-none');
        if (this.hasBulkCreateItemTarget) this.bulkCreateItemTarget.classList.add('d-none');
        if (this.hasBulkUpdateItemTarget) this.bulkUpdateItemTarget.classList.add('d-none');
        if (this.hasBulkDividerTarget) this.bulkDividerTarget.classList.add('d-none');
        if (this.hasReanalyzeItemTarget) this.reanalyzeItemTarget.classList.add('d-none');
        if (this.hasFactureButtonTarget) this.factureButtonTarget.classList.add('d-none');
        this.backToMappingDropdownItemTargets.forEach(el => el.classList.add('d-none'));
        if (this.hasNoteActionsDividerTarget) this.noteActionsDividerTarget.classList.add('d-none');
        if (this.hasEditNoteItemTarget) this.editNoteItemTarget.classList.add('d-none');
        if (this.hasViewNoteItemTarget) this.viewNoteItemTarget.classList.add('d-none');

        // --- 3. Fermer le toast existant lors des transitions d'étape ---
        // sauf à l'étape 2 où le feedback de mappage doit rester visible.
        if (this._toastInstance && this.currentStep !== 2) {
            this._toastInstance.hide();
        }

        // --- 4. Mise à jour de l'icône du titre ---
        if (this.hasToolbarTitleIconPrepareTarget) {
            this.toolbarTitleIconPrepareTarget.classList.toggle('d-none', this.currentStep === 3);
        }
        if (this.hasToolbarTitleIconSuccessTarget) {
            this.toolbarTitleIconSuccessTarget.classList.toggle('d-none', this.currentStep !== 3);
        }

        // --- 4. Afficher le contenu et les boutons de l'étape active ---
        if (this.currentStep === 1) {

            // ÉTAPE 1 : aucun bouton de barre d'outils visible
            this.step1Target.classList.remove('d-none');

        } else if (this.currentStep === 2) {

            // ÉTAPE 2 : uniquement "Lancer l'analyse"
            this.step2Target.classList.remove('d-none');

            if (this.hasSubmitButtonTarget) {
                this.submitButtonTarget.classList.remove('d-none');
            }
            this.backToMappingButtonTargets.forEach(btn => {
                if (parseInt(btn.dataset.stepNumber) === 1) btn.classList.remove('d-none');
            });

            this._showMappingUI(
                sheetName ||
                this.sheetSelectionTargets.find(radio => radio.checked)?.value
            );

            this.updateSubmitButtonState(); // État correct dès l'affichage de l'étape 2

            // The toast will be shown by updateMappingStatusFeedback()
        } else if (this.currentStep === 3) {

            // ÉTAPE 3
            this.step3Target.classList.remove('d-none');

            this.backToMappingButtonTargets.forEach(btn => {
                if (parseInt(btn.dataset.stepNumber) === 2) {
                    btn.classList.remove('d-none');
                    btn.disabled = this.isBulkProcessingValue;
                }
            });

            if (this.hasExportPdfItemTarget) {
                this.exportPdfItemTarget.classList.remove('d-none');
            }
            
            // Afficher le bloc de recherche et réinitialiser le filtre
            if (this.hasSearchBlockTarget) {
                this.searchBlockTarget.classList.remove('d-none');
            }
            this._resetSearch(); // réinitialise le champ et remet tous les items visibles
            
            if (this.hasReanalyzeItemTarget) {
                this.reanalyzeItemTarget.classList.remove('d-none');
            }

            // Rendre le récapitulatif et les résultats
            this.renderAnalysisSummary(this.analysisStatsValue);
            this.renderAnalysisResults(this.analysisResultsHtmlValue);

            // Évaluer l'état réel du bouton Valider
            this._updateValidateButtonState();
            this._updateBulkButtonsState();

        } else if (this.currentStep > 3) {

            // BORDEREAU VALIDÉ (step = 99) — lecture seule
            this.step3Target.classList.remove('d-none');
            this.renderAnalysisSummary(this.analysisStatsValue);
            this.renderAnalysisResults(this.analysisResultsHtmlValue);
            if (this.hasValidateButtonTarget) {
                this.validateButtonTarget.classList.remove('d-none');
                this.validateButtonTarget.disabled = true;
                const span = this.validateButtonTarget.querySelector('span');
                if (span) span.textContent = '✓ Bordereau validé';
            }
            if (this.hasExportPdfItemTarget)  this.exportPdfItemTarget.classList.remove('d-none');
            if (this.hasSearchBlockTarget)    this.searchBlockTarget.classList.remove('d-none');
            if (this.hasReanalyzeItemTarget)  this.reanalyzeItemTarget.classList.remove('d-none');
            this.backToMappingDropdownItemTargets.forEach(el => el.classList.remove('d-none'));
            this._resetSearch();

            // Afficher le badge si des notes sont déjà liées, sinon le bouton Facturer
            if (this.linkedNotesValue?.length > 0) {
                this._renderLinkedNotesBadge(this.linkedNotesValue);
            } else if (this.hasFactureButtonTarget) {
                this.factureButtonTarget.classList.remove('d-none');
            }
        }

        // --- 5. Sauvegarde de l'étape (sauf pendant la restauration) ---
        if (!this.isRestoring) {
            // OPTIMISATION : On ne sauvegarde que le numéro de l'étape, pas tout le mappage.
            await this._saveAnalysisStateToBordereau('step_only');
        }
        this.updateSelectOptionsVisuals();
    }

    /**
     * Logique d'affichage de l'étape 2.
     * @param {string} sheetName - Le nom de la feuille à afficher. Si null, la première sera affichée.
     */
    _showMappingUI(sheetName = null) {
        console.log(`[BordereauAnalysis] _showMappingUI() - Affichage de l'UI de mappage pour la feuille: ${sheetName}`);
        const containers = this.mappingContainerTargets;
        containers.forEach((container, index) => {
            const isTargetSheet = sheetName ? container.dataset.sheetName === sheetName : index === 0;
            container.style.display = isTargetSheet ? 'block' : 'none';
        });

        if (this.selectedSheetNameValue && sheetName === this.selectedSheetNameValue) {
            const selectedSheetInput = this.sheetSelectionTargets.find(radio => radio.value === this.selectedSheetNameValue);
            if (selectedSheetInput) {
                selectedSheetInput.checked = true;
            }
        }
    }

    /**
     * Effectue la validation pour une colonne donnée.
     * @param {HTMLSelectElement} selectElement
     */
    performValidation(selectElement) {
        const mappingType = selectElement.value;
        const columnLetter = selectElement.dataset.columnLetter;
        const sheetName = selectElement.closest('[data-sheet-name]').dataset.sheetName;
        const resultCell = this.element.querySelector(`[data-result-for="${columnLetter}"][data-sheet-name="${sheetName}"]`);

        // Réinitialise l'état de validation pour cette colonne
        this.validationState.delete(columnLetter);

        // Met à jour l'état visuel du nom de la colonne (gras/normal)
        const columnNameTextElement = selectElement.closest('tr').querySelector('[data-bordereau-analysis-target="columnNameText"]');
        columnNameTextElement.classList.remove('fw-bold'); // Supprime par défaut

        if (!mappingType) {
            resultCell.innerHTML = ''; // Vide la cellule de résultat si "Ignorer" est sélectionné
            this.updateSubmitButtonState();
            return;
        }

        const sheetData = this.sheetsDataValue[sheetName];
        if (!sheetData) {
            return;
        }

        const invalidRows = [];
        sheetData.forEach((row, index) => {
            // NOUVEAU : Vérifie si la ligne est entièrement vide. Si oui, on l'ignore.
            // Cela empêche la validation des lignes vides après la fin du tableau.
            if (Object.values(row).every(cell => cell === null || cell === undefined)) {
                return; // Passe à la ligne suivante
            }

            const value = row[columnLetter];
            let isValid = false;

            if (mappingType === 'reference_police' || mappingType === 'nom_client') {
                isValid = typeof value === 'string' && value.trim() !== '';
            } else if (mappingType.startsWith('date_')) { // Gère toutes les dates (effet, expiration, opération, etc.)
                if (value === null || value === undefined) {
                    isValid = false;
                } else if (typeof value === 'number') {
                    // Gère les dates Excel stockées comme des nombres (jours depuis 1900)
                    isValid = value > 0;
                } else {
                    // Gère les dates stockées comme des chaînes (ex: "25/12/2024")
                    const date = new Date(value);
                    isValid = !isNaN(date.getTime());
                }
            } else if (mappingType === 'risque') { // Nouveau champ Risque
                isValid = typeof value === 'string' && value.trim() !== '';
            } else if (mappingType === 'num_avenant') { // Numéro d'avenant : cellule vide → "0" côté backend, donc toujours valide
                isValid = true;
            } else { // commission_ht_assureur, taxe_commission_assureur, taux_commission, chargements et revenus
                // La validation pour les champs numériques reste la même.
                if (value === null || value === undefined || String(value).trim() === '') {
                    isValid = false;
                } else {
                    let valueStr = String(value).trim();
                    // NOUVEAU : Si la valeur est juste un tiret, on la traite comme zéro.
                    if (valueStr === '-') { // Common Excel placeholder for zero/empty
                        valueStr = '0';
                    }

                    // Remove all spaces (including non-breaking spaces) and parentheses
                    let cleanedValue = valueStr.replace(/[\s\u00A0()]/g, '');

                    // Heuristic for decimal/thousand separators:
                    // Count dots and commas.
                    const dotCount = (cleanedValue.match(/\./g) || []).length;
                    const commaCount = (cleanedValue.match(/,/g) || []).length;

                    if (commaCount > 0 && dotCount > 0) {
                        // Ambiguous case: both dot and comma are present.
                        // Assume the LAST one is the decimal separator.
                        const lastDotIndex = cleanedValue.lastIndexOf('.');
                        const lastCommaIndex = cleanedValue.lastIndexOf(',');

                        if (lastCommaIndex > lastDotIndex) {
                            // Comma is the decimal separator (e.g., "1.234,56")
                            cleanedValue = cleanedValue.replace(/\./g, ''); // Remove all dots (thousand separators)
                            cleanedValue = cleanedValue.replace(',', '.'); // Replace decimal comma with dot
                        } else {
                            // Dot is the decimal separator (e.g., "1,234.56")
                            cleanedValue = cleanedValue.replace(/,/g, ''); // Remove all commas (thousand separators)
                            // Dot is already the decimal separator for parseFloat
                        }
                    } else if (commaCount > 0) {
                        // Only commas present, assume comma is decimal (e.g., "1234,56")
                        cleanedValue = cleanedValue.replace(',', '.');
                    }
                    // If only dots present, assume dot is decimal (e.g., "1234.56") - no change needed for parseFloat.
                    // If no dots or commas, it's an integer.

                    const parsedValue = parseFloat(cleanedValue);
                    isValid = !isNaN(parsedValue) && isFinite(parsedValue);
                }
            }

            if (!isValid) {
                invalidRows.push(index + 2); // +2 car l'index est 0-based et on saute la ligne d'en-tête
            }
        });

        if (invalidRows.length === 0) {
            resultCell.innerHTML = this.getFeedbackHtml('success', 'Données valides !', true, false); //
            this.validationState.set(columnLetter, true);
        } else {
            const message = `Lignes invalides: ${invalidRows.slice(0, 5).join(', ')}${invalidRows.length > 5 ? '...' : ''}`;
            resultCell.innerHTML = this.getFeedbackHtml('error', message, true, false); //
            this.validationState.set(columnLetter, false);
        }

        // Si la colonne est mappée et valide, on met le nom en gras
        if (mappingType && this.validationState.get(columnLetter)) {
            columnNameTextElement.classList.add('fw-bold');
        }

        this.updateSubmitButtonState();
    }

    /**
     * Met à jour l'état (activé/désactivé) du bouton de soumission.
     */
    updateSubmitButtonState() {
        if (this.isSaving) {
            if (this.hasSubmitButtonTarget) this.submitButtonTarget.disabled = true;
            return; // On arrête ici, la sauvegarde a la priorité.
        }

        const activeForm = this.element.querySelector('.column-mapping-form:not([style*="display: none"])');
        if (!activeForm) {
            if (this.hasSubmitButtonTarget) this.submitButtonTarget.disabled = true;
            return;
        }
        const selects = activeForm.querySelectorAll('select[data-column-letter]');
        const mappedTypes = new Set();
        let allValid = true;

        selects.forEach(select => {
            const mappingType = select.value;
            if (mappingType) {
                mappedTypes.add(mappingType);
                const columnLetter = select.dataset.columnLetter;
                if (this.validationState.get(columnLetter) === false) {
                    allValid = false;
                }
            }
        });

        const hasAllRequired = [...this.requiredMappings].every(type => mappedTypes.has(type));
        if (this.hasSubmitButtonTarget) this.submitButtonTarget.disabled = !(hasAllRequired && allValid);

        // Ne pas déclencher de feedback visuel (Toast) si le toast initial n'a pas encore été affiché.
        if (this._initialToastShown) {
            this.updateMappingStatusFeedback();
        }
    }

    /**
     * Génère le HTML pour un message de feedback.
     * @param {'success'|'error'} type
     * @param {string} message
     * @returns {string}
     * @param {boolean} [includeIcon=true] - NOUVEAU : Permet de contrôler l'affichage de l'icône.
     * @returns {string}
     */
    getFeedbackHtml(type, message, includeIcon = true, isToolbarFeedback = false) {
        let iconHtml = '';
        if (includeIcon) {
            iconHtml = type === 'success'
                ? '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-check-circle-fill me-1" viewBox="0 0 16 16"><path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0m-3.97-3.03a.75.75 0 0 0-1.08.022L7.477 9.417 5.384 7.323a.75.75 0 0 0-1.06 1.06L6.97 11.03a.75.75 0 0 0 1.079-.02l3.992-4.99a.75.75 0 0 0-.01-1.05z"/></svg>'
                : (type === 'warning'
                    ? '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-exclamation-triangle-fill me-1" viewBox="0 0 16 16"><path d="M8.982 1.566a1.13 1.13 0 0 0-1.96 0L.165 13.233c-.457.778.091 1.767.98 1.767h13.713c.889 0 1.438-.99.98-1.767zM8 5c.535 0 .954.462.9.995l-.35 3.507a.552.552 0 0 1-1.1 0L7.1 5.995A.905.905 0 0 1 8 5m.002 6a1 1 0 1 1 0 2 1 1 0 0 1 0-2"/></svg>'
                    : '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-x-circle-fill me-1" viewBox="0 0 16 16"><path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0M5.354 4.646a.5.5 0 1 0-.708.708L7.293 8l-2.647 2.646a.5.5 0 0 0 .708.708L8 8.707l2.646 2.647a.5.5 0 0 0 .708-.708L8.707 8l2.647-2.646a.5.5 0 0 0-.708-.708L8 7.293z"/></svg>');
        }

        let textColorClass;
        if (isToolbarFeedback) {
            const toolbarColorMap = {
                'success': 'toolbar-feedback-info',
                'warning': 'toolbar-feedback-warning',
                'error': 'toolbar-feedback-error',
                'info': 'toolbar-feedback-info',
            };
            textColorClass = toolbarColorMap[type] ?? 'toolbar-feedback-info';
        } else {
            // Couleurs standard pour le contenu principal (fond clair)
            const regularColorMap = {
                'success': 'text-success',
                'warning': 'text-warning',
                'error': 'text-danger',
                'info': 'text-info',
            };
            textColorClass = regularColorMap[type] ?? 'text-secondary';
        }

        return `<span class="d-inline-flex align-items-center small ${textColorClass}">${iconHtml} ${message}</span>`;
    }

    /**
     * Met à jour le paragraphe de feedback sur l'état du mappage.
     */
    updateMappingStatusFeedback() {
        // MISSION : Ne calculer le feedback que pour la feuille ACTUELLEMENT visible
        const activeForm = this.element.querySelector('.column-mapping-form:not([style*="display: none"])');
        if (!activeForm) return;

        const mappedRequiredTypes = new Set(); // Stocke les noms des champs système obligatoires mappés
        const mappedOptionalTypes = new Set(); // Stocke les noms des champs système optionnels mappés
        const totalOptionalCount = this.chargementsValue.length + this.typeRevenusValue.length;

        const selects = activeForm.querySelectorAll('select[data-column-letter]');
        selects.forEach(select => {
            const mappingType = select.value;
            if (mappingType) {
                if (this.requiredMappings.has(mappingType)) {
                    mappedRequiredTypes.add(mappingType);
                } else if (mappingType.startsWith('chargement_') || mappingType.startsWith('revenu_')) {
                    mappedOptionalTypes.add(mappingType);
                }
            }
        });

        const requiredMappedCount = mappedRequiredTypes.size;
        const requiredRemainingCount = this.requiredMappings.size - requiredMappedCount;
        const optionalMappedCount = mappedOptionalTypes.size;

        let message = ``;

        if (requiredRemainingCount > 0) {
            // Obtenir les noms des champs obligatoires non mappés
            const unmappedRequiredFields = [...this.requiredMappings].filter(
                requiredField => !mappedRequiredTypes.has(requiredField)
            );
            // Mapper les noms de champs système à leurs noms d'affichage
            const unmappedDisplayNames = unmappedRequiredFields.map(field => this.mappingOptionsValue[field] || field);

            message += `Il reste <strong>${requiredRemainingCount}</strong> champ(s) obligatoire(s) à mapper : <em>${unmappedDisplayNames.join(', ')}</em>.`;
        } else {
            message += `Tous les champs obligatoires (<strong>${this.requiredMappings.size}/${this.requiredMappings.size}</strong>) sont mappés.`;
            if (totalOptionalCount > 0) {
                message += ` Vous avez mappé <strong>${optionalMappedCount}</strong> champ(s) optionnel(s) sur <strong>${totalOptionalCount}</strong> disponible(s).`;
            }
        }

        this._showToast('info', message, true, true);
    }

    /**
     * Soumet les données mappées au backend pour l'analyse.
     * Point d'entrée de l'analyse par lots.
     * Lance l'initialisation puis enchaîne les lots séquentiellement.
     */
    async submitAnalysis(event) {
        if (this.isBulkProcessingValue) { // NOUVEAU : Empêcher l'analyse pendant un traitement en lot
            console.warn("[BordereauAnalysis] submitAnalysis() - Impossible de lancer l'analyse pendant un traitement en lot.");
            return;
        }

    // CORRECTION 2A : Annuler toutes les sauvegardes en attente
    // pour éviter qu'elle interfère avec la sauvegarde full qui suit.
    if (this._pendingSaveTimeout) {
        clearTimeout(this._pendingSaveTimeout);
        this._pendingSaveTimeout = null;
    }
    if (this._pendingMappingSaveTimeout) {
        clearTimeout(this._pendingMappingSaveTimeout);
        this._pendingMappingSaveTimeout = null;
    }

    // CORRECTION 2A : Forcer isSaving à false si un état incohérent persiste
    // (cas où une sauvegarde step_only a échoué silencieusement)
    this.isSaving = false;

    // CORRECTION 2B : Feedback visuel IMMÉDIAT avant tout traitement asynchrone
    // L'utilisateur voit instantanément que son clic a été pris en compte.
    this.toggleProgressBar(true);
    if (this.hasSubmitButtonTarget) {
        this.submitButtonTarget.disabled = true;
        this.submitButtonTarget.textContent = "Analyse en cours...";
    }

        console.log("[BordereauAnalysis] submitAnalysis() - Lancement de l'analyse par lots.");

        try {
            // CORRECTION : Avant de lancer l'analyse, on force une sauvegarde complète 
            // Si une sauvegarde de mappage est en attente (debounce #1 non écoulé),
            // on l'exécute immédiatement et on attend sa complétion avant de continuer.
            // Dans le cas nominal (debounce déjà écoulé), aucun appel réseau superflu.
            if (this._pendingMappingSaveTimeout) {
                clearTimeout(this._pendingMappingSaveTimeout);
                this._pendingMappingSaveTimeout = null;
                try {
                    await this._saveMappingOnly();
                } catch (error) {
                    this.toggleProgressBar(false);
                    this.submitButtonTarget.disabled = false;
                    this.submitButtonTarget.textContent = "Lancer l'analyse";
                    return;
                }
            }
        } catch (error) {
            // This catch block is for _saveMappingOnly() errors.
            // The UI is already reset by the inner catch block.
        }
        this._showToast('warning', 'Initialisation de l\'analyse...');
        // Réinitialiser les résultats accumulés
        this._accumulatedResultsHtml = [];
        this._accumulatedResultsStore = [];
        let finalStats = {};

        const baseUrl = `/admin/bordereau/api/submit-analysis/${this.bordereauIdValue}`;

        try {
            // --- ÉTAPE 1 : Init — compter les lignes ---
            const initResponse = await fetch(`${baseUrl}?mode=init`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({})
            });

            if (!initResponse.ok) {
                const err = await this._parseErrorResponse(initResponse);
                throw new Error(err);
            }

            const initData = await initResponse.json();
            const totalRows = initData.totalRows || 0;
            const chunkSize = initData.chunkSize || 10;

            if (totalRows === 0) {
                this._handleAnalysisCompleted({
                    analysisResults: [],
                    analysisResultsHtml: [],
                });
                return;
            }

            console.log(`[BordereauAnalysis] submitAnalysis() - ${totalRows} lignes à traiter par lots de ${chunkSize}.`);
            this._showToast('warning', `0 / ${totalRows} lignes analysées...`);

            // --- ÉTAPE 2 : Traiter les lots séquentiellement ---
            let offset = 0;
            while (offset < totalRows) {
                const processResponse = await fetch(`${baseUrl}?mode=process`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ offset })
                });

                if (!processResponse.ok) {
                    const err = await this._parseErrorResponse(processResponse);
                    throw new Error(err);
                }

                const processData = await processResponse.json();

                // Accumuler les résultats de ce lot
                this._accumulatedResultsHtml = this._accumulatedResultsHtml.concat(
                    processData.chunkResultsHtml || []
                );
                this._accumulatedResultsStore = this._accumulatedResultsStore.concat(
                    processData.chunkResultsStore || []
                );

                // Mettre à jour la barre de progression (mode déterminé)
                const processed = processData.processedCount || (offset + chunkSize);
                const percentage = Math.min(Math.round((processed / totalRows) * 100), 100);
                this._updateProgressBarPercentage(percentage);

                this._showToast('warning', `${Math.min(processed, totalRows)} / ${totalRows} lignes analysées...`);

                console.log(`[BordereauAnalysis] submitAnalysis() - Lot traité : ${processed}/${totalRows} (${percentage}%)`);

                if (processData.isLastChunk) {
                    // Final stats are already calculated and returned by the backend
                    finalStats = processData.stats || {};
                    break;
                }
                offset += chunkSize;
            }

            // --- ÉTAPE 3 : Tout est traité ---
            this._handleAnalysisCompleted({
                analysisResults: this._accumulatedResultsStore,
                analysisResultsHtml: this._accumulatedResultsHtml,
                // Pass the final stats from the last chunk
                stats: finalStats
            });



        } catch (error) {
            console.error("[BordereauAnalysis] submitAnalysis() - Erreur:", error);
            this._handleAnalysisFailed({ errorMessage: error.message });
        } finally {
            this.toggleProgressBar(false);
        }
    }

    /**
     * Rend les résultats de l'analyse des avenants dans la liste de l'étape 3.
     * @param {string[]} [resultsHtml=null] - Le HTML à rendre. Si null, utilise la valeur du contrôleur.
     */
    renderAnalysisResults(resultsHtml = null) {
        if (!this.hasAnalysisResultsListTarget) return;

        // Utilise le HTML fourni ou celui stocké dans le contrôleur
        const finalResultsHtml = resultsHtml || this.analysisResultsHtmlValue;

        if (!finalResultsHtml || finalResultsHtml.length === 0) {
            this.analysisResultsListTarget.innerHTML = '<li class="list-group-item text-center text-muted">Aucun résultat d\'analyse à afficher.</li>';
            return;
        }

        // On joint simplement les morceaux de HTML et on les injecte.
        this.analysisResultsListTarget.innerHTML = finalResultsHtml.join('');

        // Ré-appliquer le filtre actif si un terme de recherche est en cours
        if (this._currentSearchTerm) {
            this._applySearch(this._currentSearchTerm);
        }
    }
    /**
     * Gère le clic sur un bouton d'action de l'étape 3.
     * @param {Event} event
     */
    handleAnalysisAction(event) {
        event.preventDefault();
        const button = event.currentTarget;
        const eventName = button.dataset.eventName;
        const payload = JSON.parse(button.dataset.payload || '{}');

        console.log(`Action d'analyse déclenchée: ${eventName}`, payload);
        // Ici, vous enverriez un événement au Cerveau pour gérer l'action réelle.
        // this.notifyCerveau(eventName, payload);
    }

    /**
     * Gère la réception des résultats d'analyse du Cerveau.
     * @param {object} payload - Le payload contenant les résultats d'analyse.
     */
    _handleAnalysisCompleted(payload) {
        console.log("[BordereauAnalysis] _handleAnalysisCompleted() - Analyse terminée.", payload);

        // 1. Mise à jour des résultats dans les valeurs Stimulus
        this.analysisResultsValue = payload.analysisResults || [];
        this.analysisResultsHtmlValue = payload.analysisResultsHtml || [];

        // 2. Réinitialisation visuelle du bouton de soumission
        if (this.hasSubmitButtonTarget) {
            this.submitButtonTarget.disabled = false;
            this.submitButtonTarget.textContent = "Lancer l'analyse";
        }

        // 3. Passage à l'étape 3 (cela déclenche le rendu du HTML via showStep)
        this.showStep(3);

        // 4. MISSION : Correction de l'incohérence "0,00".
        // On force le recalcul et le rendu des statistiques directement à partir du DOM
        // qui vient d'être peuplé. C'est la méthode la plus fiable pour garantir
        // l'exactitude des totaux financiers sans nécessiter de rafraîchissement.
        this._recalculateAndRenderStatsFromDom();

        // Feedback de succès
        this._showToast('success', 'Analyse terminée avec succès.', true);

        // Sauvegarder uniquement l'étape (les résultats sont déjà en base)
        this._saveAnalysisStateToBordereau('step_only');
    }

    /**
     * Réagit à la résolution d'un item d'analyse.
     * Réévalue si tous les items actionnables sont résolus
     * pour activer ou non le bouton "Valider".
     * @param {CustomEvent} event
     */
    _handleItemResolved(event) {
        const { type, payload } = event.detail ?? {};

        if (type === 'ui:dialog.content-request') {
            // Le cerveau n'est pas présent sur cette page : on gère le fetch nous-mêmes.
            this._handleDialogContentRequest(payload);
            return;
        }

        if (type === 'ui:dialog.close-request') {
            document.dispatchEvent(new CustomEvent('app:dialog.do-close', {
                bubbles: true,
                detail: { dialogId: payload.dialogId }
            }));
            return;
        }

        if (type === 'ui:icon.request') {
            this._handleDialogIconRequest(payload);
            return;
        }

        if (type === 'ui:note.preview-request') {
            this._handleNotePreviewRequest(payload);
            return;
        }

        if (type === 'app:entity.saved') {
            if (payload?.originatorId === `bordereau-note-${this.bordereauIdValue}`) {
                this._renderLinkedNotesBadge([payload.entity]);
                if (this.hasFactureButtonTarget) this.factureButtonTarget.classList.add('d-none');
            }
            return;
        }

        if (type !== 'bordereau:item.resolved') return;

        console.log('[BordereauAnalysis] _handleItemResolved() - Item résolu détecté. Réévaluation du bouton Valider.');
        this._updateValidateButtonState();
        this._updateBulkButtonsState();
        this._recalculateAndRenderStatsFromDom();
    }

    /**
     * Évalue si le bouton "Valider le bordereau" doit être actif.
     * Le bouton s'active quand tous les items de type new et discrepancy
     * sont marqués comme résolus (data-resolved="true").
     */
    _updateValidateButtonState() {
        if (!this.hasValidateButtonTarget) return;

        // Récupérer tous les items de la liste
        const allItems = this.analysisResultsListTarget.querySelectorAll(
            '[data-controller="analysis-result-item"]'
        );

        if (allItems.length === 0) return;

        let totalActionable = 0; // new + discrepancy
        let totalResolved = 0; // parmi les actionnables, ceux marqués résolus

        allItems.forEach(item => {
            // Les items warning et match ne comptent pas
            const isWarning = item.classList.contains('border-danger')
                && item.dataset.resolved !== 'true'
                && !item.classList.contains('border-success');

            // On détecte le type par la classe de bordure initiale
            // (border-info = new, border-warning = discrepancy)
            const isActionable =
                item.classList.contains('border-info') ||
                item.classList.contains('border-warning') ||
                item.dataset.resolved === 'true'; // Un item résolu a changé en border-success

            if (isActionable) {
                totalActionable++;
                if (item.dataset.resolved === 'true') {
                    totalResolved++;
                }
            }
        });

        const allResolved = allItems.length > 0 && totalResolved === totalActionable;

        console.log(`[BordereauAnalysis] _updateValidateButtonState() - Actionnables: ${totalActionable}, Résolus: ${totalResolved}, Tous résolus: ${allResolved}`);

        // On affiche le bouton seulement quand il est actionnable — jamais disabled
        this.validateButtonTarget.classList.toggle('d-none', !allResolved);
    }

    /**
     * Évalue si les boutons de traitement en lot doivent être actifs.
     * Les items du dropdown sont masqués quand il n'y a rien à traiter.
     */
    _updateBulkButtonsState() {
        if (!this.hasAnalysisResultsListTarget) return;

        const allItems = this.analysisResultsListTarget.querySelectorAll(
            '[data-controller="analysis-result-item"]'
        );

        let pendingNew = 0;
        let pendingDiscrepancy = 0;

        allItems.forEach(item => {
            if (item.dataset.resolved === 'true') return; // Déjà traité

            if (item.classList.contains('border-info')) {
                pendingNew++;
            } else if (item.classList.contains('border-warning')) {
                pendingDiscrepancy++;
            }
        });

        if (this.hasBulkCreateItemTarget) {
            this.bulkCreateItemTarget.classList.toggle('d-none', pendingNew === 0);
        }
        if (this.hasBulkUpdateItemTarget) {
            this.bulkUpdateItemTarget.classList.toggle('d-none', pendingDiscrepancy === 0);
        }
        // Masquer le séparateur si les deux items en lot sont cachés
        if (this.hasBulkDividerTarget) {
            this.bulkDividerTarget.classList.toggle('d-none', pendingNew === 0 && pendingDiscrepancy === 0);
        }
    }

    /**
     * Déclenche la validation finale du bordereau.
     * Appelé au clic sur le bouton "Valider le bordereau".
     */
    validateBordereau() {
        if (this.isBulkProcessingValue) {
            console.warn("[BordereauAnalysis] validateBordereau() - Impossible de valider pendant un traitement en lot.");
            return;
        }
        const modal = new Modal(this.validationModalTarget);
        modal.show();
    }

    async confirmValidation() {
        // Fermer le modal avant de lancer la requête
        Modal.getInstance(this.validationModalTarget)?.hide();

        this.validateButtonTarget.disabled = true;
        this.toggleProgressBar(true);
        this._showToast('warning', 'Validation en cours...');

        try {
            const response = await fetch(
                `/admin/bordereau/api/validate/${this.bordereauIdValue}`,
                {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' }
                }
            );

            const result = await response.json();

            if (!response.ok || !result.success) {
                throw new Error(result.message || 'Erreur lors de la validation.');
            }

            // Succès : feedback visuel
            this._showToast('success', '✓ Bordereau validé avec succès !', true);
            const validateSpan = this.validateButtonTarget.querySelector('span');
            if (validateSpan) validateSpan.textContent = '✓ Bordereau validé';
            this.validateButtonTarget.disabled = true;

            // Afficher "Facturer", déplacer "Retour" dans le dropdown
            if (this.hasFactureButtonTarget) this.factureButtonTarget.classList.remove('d-none');
            this.backToMappingButtonTargets.forEach(btn => {
                if (parseInt(btn.dataset.stepNumber) === 2) btn.classList.add('d-none');
            });
            this.backToMappingDropdownItemTargets.forEach(el => el.classList.remove('d-none'));

            // Bordereau::STATUT_ANALYSE_TERMINEE = 99
            this._updateToolbarMeta(99);

        } catch (error) {
            console.error('[BordereauAnalysis] confirmValidation() - Erreur:', error);
            this._showToast('error', `Échec de la validation : ${error.message}`);
            this.validateButtonTarget.disabled = false;
        } finally {
            this.toggleProgressBar(false);
        }
    }

    /**
     * Affiche le bloc de synthèse des résultats d'analyse.
     * @param {object} stats - Les statistiques calculées par le serveur.
     */
    renderAnalysisSummary(stats) {
        if (!this.hasAnalysisSummaryTarget) return;

        if (!stats || stats.total === 0) {
            this.analysisSummaryTarget.innerHTML = '';
            return;
        }

        const matchPct = stats.total > 0 ? Math.round((stats.match / stats.total) * 100) : 0;
        const formatNumber = (n) => new Intl.NumberFormat('fr-FR', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }).format(n || 0);

        // Calcul de la couleur de la barre selon le taux de conformité (règle métier JSB)
        const barColor = matchPct >= 80 ? '#198754' : (matchPct >= 50 ? '#e69500' : '#dc3545');

        this.analysisSummaryTarget.innerHTML = `
            <div class="analysis-summary-card card border-0 shadow-sm mb-4">

                <!-- EN-TÊTE DE LA CARTE -->
                <div class="card-header d-flex align-items-center justify-content-between py-2 px-3"
                     style="background: linear-gradient(135deg, #0047AB 0%, #003380 100%); border-bottom: none; border-radius: calc(var(--bs-border-radius) - 1px) calc(var(--bs-border-radius) - 1px) 0 0;">
                    <div class="d-flex align-items-center gap-2">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="white" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                        <span class="fw-bold text-white small text-uppercase" style="letter-spacing: 0.5px;">
                            Récapitulatif de l'analyse
                        </span>
                    </div>
                    <span class="badge rounded-pill text-white fw-bold"
                          style="background-color: rgba(255,255,255,0.2); font-size: 0.75rem;">
                        ${stats.total} ligne${stats.total > 1 ? 's' : ''}
                    </span>
                </div>

                <div class="card-body p-3">

                    <!-- COMPTEURS PAR TYPE -->
                    <div class="row g-2 mb-3">
                        <!-- Conformes -->
                        <div class="col-3">
                            <div class="analysis-summary-counter text-center p-2 rounded-3"
                                 style="background-color: #d1e7dd; border: 1px solid rgba(25, 135, 84, 0.25);">
                                <div class="d-flex align-items-center justify-content-center gap-1 mb-1">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="#198754" viewBox="0 0 16 16" aria-hidden="true"><path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0zm-3.97-3.03a.75.75 0 0 0-1.08.022L7.477 9.417 5.384 7.323a.75.75 0 0 0-1.06 1.06L6.97 11.03a.75.75 0 0 0 1.079-.02l3.992-4.99a.75.75 0 0 0-.01-1.05z"/></svg>
                                    <span class="fw-bold" style="font-size: 1.5rem; color: #198754; line-height: 1;">${stats.match}</span>
                                </div>
                                <div class="small fw-semibold" style="color: #198754; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.4px;">Conformes</div>
                            </div>
                        </div>
                        <!-- Anomalies -->
                        <div class="col-3">
                            <div class="analysis-summary-counter text-center p-2 rounded-3"
                                 style="background-color: #fff3cd; border: 1px solid rgba(230, 149, 0, 0.4);">
                                <div class="d-flex align-items-center justify-content-center gap-1 mb-1">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="#e69500" viewBox="0 0 16 16" aria-hidden="true"><path d="M8.982 1.566a1.13 1.13 0 0 0-1.96 0L.165 13.233c-.457.778.091 1.767.98 1.767h13.713c.889 0 1.438-.99.98-1.767zM8 5c.535 0 .954.462.9.995l-.35 3.507a.552.552 0 0 1-1.1 0L7.1 5.995A.905.905 0 0 1 8 5m.002 6a1 1 0 1 1 0 2 1 1 0 0 1 0-2"/></svg>
                                    <span class="fw-bold" style="font-size: 1.5rem; color: #e69500; line-height: 1;">${stats.discrepancy}</span>
                                </div>
                                <div class="small fw-semibold" style="color: #e69500; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.4px;">Anomalies</div>
                            </div>
                        </div>
                        <!-- Nouveaux -->
                        <div class="col-3">
                            <div class="analysis-summary-counter text-center p-2 rounded-3"
                                 style="background-color: #e8f0fb; border: 1px solid rgba(0, 71, 171, 0.25);">
                                <div class="d-flex align-items-center justify-content-center gap-1 mb-1">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="#0047AB" viewBox="0 0 16 16" aria-hidden="true"><path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14zm0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16z"/><path d="M8 4a.5.5 0 0 1 .5.5v3h3a.5.5 0 0 1 0 1h-3v3a.5.5 0 0 1-1 0v-3h-3a.5.5 0 0 1 0-1h3v-3A.5.5 0 0 1 8 4z"/></svg>
                                    <span class="fw-bold" style="font-size: 1.5rem; color: #0047AB; line-height: 1;">${stats.new}</span>
                                </div>
                                <div class="small fw-semibold" style="color: #0047AB; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.4px;">Nouveaux</div>
                            </div>
                        </div>
                        <!-- Total -->
                        <div class="col-3">
                            <div class="analysis-summary-counter text-center p-2 rounded-3"
                                 style="background-color: #f8f9fa; border: 1px solid #dee2e6;">
                                <div class="d-flex align-items-center justify-content-center gap-1 mb-1">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="#6c757d" viewBox="0 0 16 16" aria-hidden="true"><path d="M5 10.5a.5.5 0 0 1 .5-.5h2a.5.5 0 0 1 0 1h-2a.5.5 0 0 1-.5-.5zm0-2a.5.5 0 0 1 .5-.5h5a.5.5 0 0 1 0 1h-5a.5.5 0 0 1-.5-.5zm0-2a.5.5 0 0 1 .5-.5h5a.5.5 0 0 1 0 1h-5a.5.5 0 0 1-.5-.5zm0-2a.5.5 0 0 1 .5-.5h5a.5.5 0 0 1 0 1h-5a.5.5 0 0 1-.5-.5z"/><path d="M3 0h10a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V2a2 2 0 0 1 2-2zm0 1a1 1 0 0 0-1 1v12a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1V2a1 1 0 0 0-1-1H3z"/></svg>
                                    <span class="fw-bold" style="font-size: 1.5rem; color: #6c757d; line-height: 1;">${stats.total}</span>
                                </div>
                                <div class="small fw-semibold" style="color: #6c757d; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.4px;">Total</div>
                            </div>
                        </div>
                    </div>

                    <!-- BARRE DE CONFORMITÉ avec couleur dynamique -->
                    <div class="mb-3 p-2 rounded-2" style="background-color: #f8f9fa;">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="small text-muted fw-semibold">Taux de conformité</span>
                            <span class="fw-bold small" style="color: ${barColor};">${matchPct} %</span>
                        </div>
                        <div class="analysis-conformity-bar">
                            <div style="height: 100%; width: ${matchPct}%; background-color: ${barColor}; border-radius: 4px; transition: width 0.4s ease;"></div>
                        </div>
                    </div>

                    <!-- TOTAUX FINANCIERS -->
                    <div class="row g-0 border rounded-2 overflow-hidden">
                        <div class="col-md-4 p-2 text-center" style="border-right: 1px solid #dee2e6;">
                            <div class="small text-muted mb-1" style="font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.4px;">Com. HT Payable Now</div>
                            <div class="fw-bold text-success" style="font-size: 1rem;">
                                ${formatNumber(stats.total_com_payable_now)}
                            </div>
                        </div>
                        <div class="col-md-4 p-2 text-center" style="border-right: 1px solid #dee2e6;">
                            <div class="small text-muted mb-1" style="font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.4px;">Taxe Com. Payable Now</div>
                            <div class="fw-bold text-success" style="font-size: 1rem;">
                                ${formatNumber(stats.total_taxe)}
                            </div>
                        </div>
                        <div class="col-md-4 p-2 text-center">
                            <div class="small text-muted mb-1" style="font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.4px;">Com. TTC</div>
                            <div class="fw-bold" style="color: #0047AB; font-size: 1rem;">
                                ${formatNumber((stats.total_com_payable_now || 0) + (stats.total_taxe || 0))}
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        `;
    }

    /**
     * Helper method to mark a single item as resolved visually.
     * @param {HTMLElement} itemElement - The DOM element of the analysis result item.
     * @param {string} message - The message to display in the details.
     */
    _markItemAsResolved(itemElement, message) {
        itemElement.classList.remove('border-info', 'border-warning', 'border-danger');
        itemElement.classList.add('border-success', 'analysis-item-resolved');
        itemElement.dataset.resolved = 'true';

        const detailsText = itemElement.querySelector('.analysis-item-header p');
        if (detailsText) {
            detailsText.innerHTML = `<span class="text-success fw-bold">✓ Traité en lot — ${message}</span>`;
        }

        const actionsContainer = itemElement.querySelector(
            '[data-analysis-result-item-target="actionsContainer"]'
        );
        if (actionsContainer) {
            actionsContainer.style.display = 'none';
        }

        const titleWrapper = itemElement.querySelector('.analysis-item-title-wrapper h5');
        if (titleWrapper && !titleWrapper.querySelector('.badge.bg-success')) {
            const badge = document.createElement('span');
            badge.className = 'badge bg-success ms-2 small';
            badge.textContent = 'Résolu';
            titleWrapper.appendChild(badge);
        }
    }

    /**
     * Helper to update the text and disabled state of an action button.
     * @param {HTMLElement} itemElement - The DOM element of the analysis result item.
     * @param {boolean} processing - True if processing, false to restore.
     * @param {string} type - 'new' or 'discrepancy'.
     */
    _updateActionButtonState(itemElement, processing, type) {
        const actionButton = itemElement.querySelector('[data-analysis-result-item-target="actionButton"]');
        if (!actionButton) return;

        const buttonLabelSpan = actionButton.querySelector('.button-label');
        if (!buttonLabelSpan) return;

        if (processing) {
            actionButton.disabled = true;
            if (type === 'new') {
                buttonLabelSpan.textContent = 'En cours de création...';
            } else if (type === 'discrepancy') {
                buttonLabelSpan.textContent = 'En cours de modification...';
            }
        } else {
            actionButton.disabled = false;
            buttonLabelSpan.textContent = actionButton.dataset.originalLabel;
        }
    }

    /**
     * Traite en lot tous les items de type "new" non encore résolus.
     * Désactive le bouton pendant le traitement pour éviter les doubles clics.
     */
    async bulkCreateAll() {
        if (!confirm(
            'Confirmez-vous la création en lot de tous les nouveaux avenants ?\n\n' +
            'Cette action traitera tous les items "Nouvel avenant détecté".'
        )) return;

        this.isBulkProcessingValue = true;
        this.toggleProgressBar(true);
        this._updateBulkButtonsState();
        this._updateValidateButtonState();

        if (this.hasBulkCreateItemTarget) {
            this.bulkCreateItemTarget.querySelector('button').disabled = true;
        }

        const allItems = this.analysisResultsListTarget.querySelectorAll(
            '[data-controller="analysis-result-item"]'
        );

        const itemsToProcess = Array.from(allItems).filter(item =>
            item.classList.contains('border-info') && item.dataset.resolved !== 'true'
        );

        const totalItemsToProcess = itemsToProcess.length;
        if (totalItemsToProcess === 0) {
            this._showToast('info', 'Aucun nouvel avenant à créer.');
            this.toggleProgressBar(false);
            this.isBulkProcessingValue = false;
            return;
        }

        // Set buttons to processing state
        itemsToProcess.forEach(itemElement => this._updateActionButtonState(itemElement, true, 'new'));

        try {
            let processed = 0;
            let errors = 0;

            // Note : Pas de toast répétitif ici, la barre de progression suffit pour le lot.
            
            for (const itemElement of itemsToProcess) {
                const actionButton = itemElement.querySelector('[data-action="click->analysis-result-item#handleAction"]');
                if (!actionButton) continue;

                const payload = JSON.parse(actionButton.dataset.payload || '{}');
                const bordereauId = actionButton.closest('[data-analysis-result-item-bordereau-id-value]')
                    ?.dataset?.analysisResultItemBordereauIdValue
                    || this.bordereauIdValue;

                try {
                    const response = await fetch(`/admin/bordereau/api/simulate-action/${bordereauId}`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            action_type: 'new',
                            avenant_id: null,
                            excel_data: payload.excel_data ?? {},
                            row_index: payload.row_index ?? null,
                            reference_police: payload.reference_police ?? null,
                            idEntreprise: this.idEntrepriseValue,
                            idInvite: this.idInviteValue,
                        })
                    });

                    const result = await response.json();
                    if (!response.ok || !result.success) throw new Error(result.message || 'Erreur lors du traitement.');

                    this._markItemAsResolved(itemElement, result.message);
                    processed++;
                    this._recalculateAndRenderStatsFromDom();
                } catch (error) {
                    console.error(`[BordereauAnalysis] bulkCreateAll() — Erreur sur item:`, error);
                    errors++;
                } finally {
                    const percentage = Math.round((processed / totalItemsToProcess) * 100);
                    this._updateProgressBarPercentage(percentage);
                }
            }

            const msg = errors > 0
                ? `Traitement terminé : ${processed} avenant(s) créé(s), ${errors} erreur(s).`
                : `${processed} avenant(s) créé(s) avec succès.`;
            this._showToast(errors > 0 ? 'warning' : 'success', msg, true);

            this.notifyCerveau('bordereau:bulk.completed', { bordereauId: this.bordereauIdValue, type: 'new', processed, errors });

        } catch (error) {
            console.error("[BordereauAnalysis] bulkCreateAll() - Erreur globale:", error);
        } finally {
            this.toggleProgressBar(false);
            this.isBulkProcessingValue = false;
            if (this.hasBulkCreateItemTarget) this.bulkCreateItemTarget.querySelector('button').disabled = false;
            this._updateBulkButtonsState();
            this._updateValidateButtonState();
            // Restore original button text for all items that were processed (even if failed)
            itemsToProcess.forEach(itemElement => this._updateActionButtonState(itemElement, false, 'new'));
        }
    }

    /**
     * Traite en lot tous les items de type "discrepancy" non encore résolus.
     */
    async bulkUpdateAll() {
        if (!confirm(
            'Confirmez-vous la mise à jour en lot de tous les avenants en anomalie ?\n\n' +
            'Cette action traitera tous les items "Anomalie(s) détectée(s)".'
        )) return;

        this.isBulkProcessingValue = true;
        this.toggleProgressBar(true);
        this._updateBulkButtonsState();
        this._updateValidateButtonState();

        if (this.hasBulkUpdateItemTarget) {
            this.bulkUpdateItemTarget.querySelector('button').disabled = true;
        }

        const allItems = this.analysisResultsListTarget.querySelectorAll(
            '[data-controller="analysis-result-item"]'
        );

        const itemsToProcess = Array.from(allItems).filter(item =>
            item.classList.contains('border-warning') && item.dataset.resolved !== 'true'
        );

        const totalItemsToProcess = itemsToProcess.length;
        if (totalItemsToProcess === 0) {
            this._showToast('info', 'Aucune anomalie à mettre à jour.');
            this.toggleProgressBar(false);
            this.isBulkProcessingValue = false;
            return;
        }

        // Set buttons to processing state
        itemsToProcess.forEach(itemElement => this._updateActionButtonState(itemElement, true, 'discrepancy'));

        try {
            let processed = 0;
            let errors = 0;

            // Pas de toast répétitif pendant le lot.
            
            for (const itemElement of itemsToProcess) {
                const actionButton = itemElement.querySelector('[data-action="click->analysis-result-item#handleAction"]');
                if (!actionButton) continue;

                const payload = JSON.parse(actionButton.dataset.payload || '{}');
                const bordereauId = actionButton.closest('[data-analysis-result-item-bordereau-id-value]')
                    ?.dataset?.analysisResultItemBordereauIdValue
                    || this.bordereauIdValue;

                try {
                    const response = await fetch(`/admin/bordereau/api/simulate-action/${bordereauId}`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            action_type: 'discrepancy',
                            avenant_id: payload.avenant_id ?? null,
                            excel_data: payload.excel_data ?? {},
                            row_index: payload.row_index ?? null,
                            reference_police: payload.reference_police ?? null,
                            idEntreprise: this.idEntrepriseValue,
                            idInvite: this.idInviteValue,
                        })
                    });

                    const result = await response.json();
                    if (!response.ok || !result.success) throw new Error(result.message || 'Erreur lors du traitement.');

                    this._markItemAsResolved(itemElement, result.message);
                    processed++;
                    this._recalculateAndRenderStatsFromDom();
                } catch (error) {
                    console.error(`[BordereauAnalysis] bulkUpdateAll() — Erreur sur item:`, error);
                    errors++;
                } finally {
                    const percentage = Math.round((processed / totalItemsToProcess) * 100);
                    this._updateProgressBarPercentage(percentage);
                }
            }

            const msg = errors > 0
                ? `Traitement terminé : ${processed} avenant(s) mis à jour, ${errors} erreur(s).`
                : `${processed} avenant(s) mis à jour avec succès.`;
            this._showToast(errors > 0 ? 'warning' : 'success', msg, true);

            this.notifyCerveau('bordereau:bulk.completed', { bordereauId: this.bordereauIdValue, type: 'discrepancy', processed, errors });

        } catch (error) {
            console.error("[BordereauAnalysis] bulkUpdateAll() - Erreur globale:", error);
        } finally {
            this.toggleProgressBar(false);
            this.isBulkProcessingValue = false;
            if (this.hasBulkUpdateItemTarget) this.bulkUpdateItemTarget.querySelector('button').disabled = false;
            this._updateBulkButtonsState();
            this._updateValidateButtonState();
            // Restore original button text for all items that were processed (even if failed)
            itemsToProcess.forEach(itemElement => this._updateActionButtonState(itemElement, false, 'discrepancy'));
        }
    }

    /**
     * Gère la réception d'un échec d'analyse du Cerveau.
     * @param {object} payload - Le payload contenant le message d'erreur.
     */
    _handleAnalysisFailed(payload) {
        const { errorMessage } = payload;
        console.error("[BordereauAnalysis] _handleAnalysisFailed() - Échec de l'analyse:", errorMessage);
        this.submitButtonTarget.disabled = false;
        this._showToast('error', `Échec de l'analyse: ${errorMessage}`);
    }

    /**
     * Affiche ou cache la barre de progression.
     */
    toggleProgressBar(isLoading) {
        // Use the local progress bar targets defined in this controller
        if (!this.hasProgressBarContainerTarget) return;

        // Annuler toute fermeture programmée pour éviter les conflits (race condition).
        // Si une action précédente (ex: sauvegarde) a fini et planifié la fermeture,
        // on l'annule car on vient de demander un nouvel affichage.
        if (this._progressBarTimeout) {
            clearTimeout(this._progressBarTimeout);
            this._progressBarTimeout = null;
        }

        if (isLoading) {
            console.log('[BordereauAnalysis] toggleProgressBar(true) - Displaying progress bar');
            this.progressBarContainerTarget.style.display = 'block';
            this.progressBarTarget.style.transition = 'none';
            this.progressBarTarget.style.width = '10%'; // Donne un feedback immédiat
            this.progressBarTarget.style.background = '#0047AB';
            
            // Force le rendu navigateur
            void this.progressBarTarget.offsetWidth;
            
            // Active l'animation de pulse/shimmer pour montrer que c'est "vivant"
            this.progressBarTarget.style.animation = 'toolbar-progress-animation 1.5s infinite linear';
            this.progressBarTarget.style.backgroundSize = '200% 100%';
            this.progressBarTarget.style.transition = 'width 0.3s ease';
            
        } else {
            // Si la barre n'est pas affichée, inutile de lancer la séquence de fermeture
            if (this.progressBarContainerTarget.style.display === 'none') return;

            // Ensure it reaches 100% before hiding, and animate it
            this.progressBarTarget.style.transition = 'width 0.3s ease';
            this.progressBarTarget.style.width = '100%';

            console.log('[BordereauAnalysis] toggleProgressBar(false) - Hiding progress bar');
            // On planifie la fermeture effective après la transition visuelle (0.3s)
            this._progressBarTimeout = setTimeout(() => {
                if (this.hasProgressBarContainerTarget) {
                    this.progressBarContainerTarget.style.display = 'none';
                    this.progressBarTarget.style.animation = '';
                    this.progressBarTarget.style.background = '';
                    this.progressBarTarget.style.transition = '';
                }
                this._progressBarTimeout = null;
            }, 500);
        }
    }

    /**
     * Met à jour le pourcentage de la barre de progression.
     * @param {number} percentage - Valeur entre 0 et 100.
     */
    _updateProgressBarPercentage(percentage) {
        if (!this.hasProgressBarTarget) return;
        this.progressBarTarget.style.width = `${percentage}%`;
        console.log(`[BordereauAnalysis] _updateProgressBarPercentage() - ${percentage}%`);
    }

    /**
     * NOUVEAU : Recalcule les statistiques d'analyse en parcourant les éléments du DOM
     * et met à jour le récapitulatif.
     * Cette méthode est utilisée pour les mises à jour en temps réel après des actions individuelles
     * ou des traitements en lot.
     */
    _recalculateAndRenderStatsFromDom() {
        if (!this.hasAnalysisResultsListTarget) return;

        const allItems = this.analysisResultsListTarget.querySelectorAll(
            '[data-controller="analysis-result-item"]'
        );

        let total = 0;
        let match = 0;
        let discrepancy = 0;
        let newItems = 0;
        let total_prime_ttc = 0.0;
        let total_commission_ht = 0.0;
        let total_com_payable_now = 0.0;
        let total_taxe = 0.0;

        allItems.forEach(itemElement => {
            total++;
            const isResolved = itemElement.dataset.resolved === 'true';
            const itemType = isResolved
                ? 'match' // Resolved items are considered 'match' for stats purposes
                : (itemElement.classList.contains('border-info') ? 'new' :
                    (itemElement.classList.contains('border-warning') ? 'discrepancy' : 'match')); // Default to match if no specific border

            switch (itemType) {
                case 'match':
                    match++;
                    break;
                case 'discrepancy':
                    discrepancy++;
                    break;
                case 'new':
                    newItems++;
                    break;
            }

            // Extract financial data from the item's data-value
            const bordereauLineInfo = JSON.parse(itemElement.dataset.analysisResultItemBordereauLineInfoValue || '{}');
            total_prime_ttc += parseFloat(bordereauLineInfo.prime_ttc || 0);

            // Recalculate HT (sum of revenues starting with 'revenu_')
            let itemRevenuHT = 0;
            for (const key in bordereauLineInfo) {
                if (key.startsWith('revenu_')) {
                    itemRevenuHT += parseFloat(bordereauLineInfo[key] || 0);
                }
            }
            total_commission_ht += itemRevenuHT;

            total_com_payable_now += parseFloat(bordereauLineInfo.commission_ht_payable_now || 0);
            total_taxe += parseFloat(bordereauLineInfo.taxe_commission_payable_now || 0);
        });

        const total_commission_ttc = total_commission_ht + total_taxe;

        const newStats = { total, match, discrepancy, new: newItems, total_prime_ttc, total_commission_ht, total_taxe, total_commission_ttc, total_com_payable_now };

        this.analysisStatsValue = newStats; // Update the Stimulus value
        this.renderAnalysisSummary(newStats); // Render the updated summary
    }

    /**
     * Parse une réponse HTTP en erreur et retourne un message lisible.
     * @param {Response} response
     * @returns {Promise<string>}
     */
    async _parseErrorResponse(response) {
        const contentType = response.headers.get('content-type') || '';
        if (contentType.includes('application/json')) {
            try {
                const err = await response.json();
                return err.error || err.message || `Erreur serveur (${response.status})`;
            } catch {
                return `Erreur serveur (${response.status}) — réponse JSON malformée.`;
            }
        }
        return `Erreur interne du serveur (${response.status}). Veuillez réessayer.`;
    }

    /**
     * Sauvegarde l'état actuel de l'analyse du bordereau en base de données.
     */
    async _saveAnalysisStateToBordereau(saveLevel = 'full') {
        // CORRECTION : Si on lance une sauvegarde complète (prioritaire), on annule 
        // toute sauvegarde debouncée en attente pour éviter des requêtes concurrentes inutiles.
        // NOUVEAU : Ne pas sauvegarder l'état si un traitement en lot est en cours
        if (this.isBulkProcessingValue) {
            console.warn("[BordereauAnalysis] _saveAnalysisStateToBordereau() - Sauvegarde annulée : traitement en lot en cours.");
            return;
        }
        if (saveLevel === 'full' && this._pendingSaveTimeout) {
            clearTimeout(this._pendingSaveTimeout);
            this._pendingSaveTimeout = null;
        }

        let payload = {
            currentAnalysisStep: this.currentStep,
        };

        // OPTIMISATION : On n'ajoute les données complètes que si une sauvegarde complète est demandée.
        if (saveLevel === 'full') {
            const selectedSheetInput = this.sheetSelectionTargets.find(radio => radio.checked);
            const selectedSheetName = selectedSheetInput ? selectedSheetInput.value : null;

            const mappedColumns = {};
            // CORRECTION 5 : On ne lit le mappage que si on est à l'étape 2
            if (this.currentStep === 2) {
                const activeMappingContainer = this.element.querySelector('.column-mapping-form:not([style*="display: none"])');
                if (activeMappingContainer) {
                    const selects = activeMappingContainer.querySelectorAll('select[data-column-letter]');
                    selects.forEach(select => {
                        if (select.value) {
                            const col = select.dataset.columnLetter;
                            if (!mappedColumns[select.value]) {
                                mappedColumns[select.value] = [];
                            }
                            if (!mappedColumns[select.value].includes(col)) {
                                mappedColumns[select.value].push(col);
                            }
                        }
                    });
                }
            }

            payload = {
                ...payload,
                selectedSheetName: selectedSheetName,
                // CORRECTION 5 : Ne pas envoyer mappedColumns si on n'est pas à l'étape 2
                ...(this.currentStep === 2 ? { mappedColumns } : {}),
                analysisResults: this.currentStep === 3 ? this.analysisResultsValue : null,
            };
        }

        console.log("[BordereauAnalysis] _saveAnalysisStateToBordereau() - Sauvegarde de l'état. Payload:", payload);

        // CORRECTION 4 : Annuler toute sauvegarde step_only en attente
        if (saveLevel === 'step_only') {
            if (this._pendingSaveTimeout) {
                clearTimeout(this._pendingSaveTimeout);
            }
            return new Promise(resolve => {
                this._pendingSaveTimeout = setTimeout(async () => {
                    this._pendingSaveTimeout = null;
                    resolve(await this._handleSaveBordereauAnalysisStateLocal({
                        url: `/admin/bordereau/api/save-analysis-state/${this.bordereauIdValue}`,
                        data: payload
                    }, 'step_only'));
                }, 300); // Debounce de 300ms
            });
        }

        // CORRECTION 3 : On ne passe le feu au rouge que pour les sauvegardes complètes
        if (saveLevel === 'full') {
            this.isSaving = true;
            this.updateSubmitButtonState();
        }

        // On retourne maintenant la promesse pour pouvoir l'attendre (await)
        return this._handleSaveBordereauAnalysisStateLocal({
            url: `/admin/bordereau/api/save-analysis-state/${this.bordereauIdValue}`,
            data: payload
        }, 'full');
    }

    /**
     * NOUVEAU: Gère la réception de la complétion de la sauvegarde de l'état du bordereau.
     * @param {object} payload - Le payload contenant le message de succès.
     */
    _handleSaveStateCompleted(payload) {
        console.log("[BordereauAnalysis] _handleSaveStateCompleted() - Sauvegarde de l'état terminée.", payload.message);
        // CORRECTION : On ne cache plus la barre de progression ici car cette méthode 
        // est souvent appelée au sein d'un flux plus large (ex: submitAnalysis) 
        // qui gère lui-même le cycle de vie de la barre de manière globale.
    }

    /**
     * NOUVEAU: Gère la réception d'un échec de la sauvegarde de l'état du bordereau.
     * @param {object} payload - Le payload contenant le message d'erreur.
     */
    _handleSaveStateFailed(payload) {
        console.error("[BordereauAnalysis] _handleSaveStateFailed() - Échec de la sauvegarde de l'état:", payload.errorMessage);
        // CORRECTION : Idem ici. On laisse le soin à l'appelant (celui qui a démarré la barre)
        // de la cacher en cas d'erreur s'il le juge nécessaire.
    }

    /**
     * Met à jour l'apparence des options dans les selects pour indiquer celles déjà mappées.
     */
    updateSelectOptionsVisuals() {
        const selectedValues = new Set();
        this.mappingSelectTargets.forEach(select => {
            if (select.value) {
                selectedValues.add(select.value);
            }
        });

        this.mappingSelectTargets.forEach(select => {
            Array.from(select.options).forEach(option => {
                option.classList.remove('mapped-option');
                // On ne bloque que les champs optionnels (chargements et revenus)
                // Les champs système obligatoires peuvent être mappés sur plusieurs colonnes
                const isOptional = option.value.startsWith('chargement_') || option.value.startsWith('revenu_');
                if (isOptional && selectedValues.has(option.value) && option.value !== select.value) {
                    option.classList.add('mapped-option');
                }
            });
        });
    }


    /**
     * NOUVEAU : Gère la sauvegarde de l'état de l'analyse du bordereau au backend.
     * Copie de _handleSaveBordereauAnalysisState du Cerveau, adaptée pour l'autonomie.
     * @param {object} payload
     * @param {string} payload.url - L'URL de l'API pour sauvegarder l'état.
     * @param {object} payload.data - Les données à envoyer (selectedSheetName, mappedColumns, currentAnalysisStep).
     */
    async _handleSaveBordereauAnalysisStateLocal(payload, saveLevel = 'full') {
        if (!payload.url || !payload.data) {
            console.error("[BordereauAnalysis] _handleSaveBordereauAnalysisStateLocal() - Demande de sauvegarde de l'état de l'analyse de bordereau reçue sans URL ou données.", payload);
            // Directly call local error handler
            this._handleSaveStateFailed({ errorMessage: "Impossible de sauvegarder l'état de l'analyse : URL ou données manquantes." });
            return;
        }

        console.log("[BordereauAnalysis] _handleSaveBordereauAnalysisStateLocal() - Sauvegarde de l'état à l'API:", payload.url, payload.data);
        // On n'active plus la barre de progression ici pour éviter qu'elle clignote.
        // Elle est déjà activée par la méthode appelante (submitAnalysis).

        try {
            const response = await fetch(payload.url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload.data)
            });
            const result = await response.json(); // Toujours essayer de parser le JSON

            if (!response.ok) {
                throw new Error(result.message || "Erreur lors de la sauvegarde de l'état.");
            }
            console.log("[BordereauAnalysis] _handleSaveBordereauAnalysisStateLocal() - État sauvegardé avec succès:", result.message);
            // Directly call local completion handler
            this._handleSaveStateCompleted({ message: result.message });
        } catch (error) {
            console.error("[BordereauAnalysis] _handleSaveBordereauAnalysisStateLocal() - Erreur lors de la sauvegarde de l'état:", error);
            // Directly call local error handler
            this._handleSaveStateFailed({ errorMessage: error.message || "Une erreur inconnue est survenue lors de la sauvegarde de l'état." });
            throw error; // RE-THROW : Important pour que submitAnalysis puisse catcher l'erreur et restaurer l'UI correctement.
        } finally {
            console.log("[BordereauAnalysis] _handleSaveBordereauAnalysisStateLocal() - Fin de l'opération. Désactivation de la barre de progression.");

            // CORRECTION 3 : On remet le feu au vert uniquement si on l'avait passé au rouge
            if (this.isSaving) {
                this.isSaving = false;
                this.updateSubmitButtonState();
            }
        }
    }

    /**
     * Affiche un message de feedback dans le composant Toast Bootstrap 5.
     * Remplace l'affichage inline dans la toolbar.
     *
     * @param {'success'|'warning'|'error'|'info'} type
     * @param {string} message - Texte du message (HTML autorisé)
     * @param {boolean} [autoHide=false] - Si true, le toast se ferme automatiquement après 4s
     */
    _showToast(type, message, autoHide = true, forceShow = false) {
        console.log(`[BordereauAnalysis] _showToast() - Type: ${type}, Message: ${message}, ForceShow: ${forceShow}`);
        if (!this.element.isConnected || !this.hasToastContainerTarget) {
            return;
        }

        // Éviter les mises à jour visuelles si le message est strictement identique au précédent
        if (!forceShow && this._lastToastMessage === message && this._lastToastType === type) return;

        // Mapping type → classes Bootstrap + couleur de fond du toast
        const toastConfig = {
            success: { bg: 'text-bg-success', icon: '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" viewBox="0 0 16 16"><path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0m-3.97-3.03a.75.75 0 0 0-1.08.022L7.477 9.417 5.384 7.323a.75.75 0 0 0-1.06 1.06L6.97 11.03a.75.75 0 0 0 1.079-.02l3.992-4.99a.75.75 0 0 0-.01-1.05z"/></svg>' },
            warning: { bg: 'text-bg-warning', icon: '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" viewBox="0 0 16 16"><path d="M8.982 1.566a1.13 1.13 0 0 0-1.96 0L.165 13.233c-.457.778.091 1.767.98 1.767h13.713c.889 0 1.438-.99.98-1.767zM8 5c.535 0 .954.462.9.995l-.35 3.507a.552.552 0 0 1-1.1 0L7.1 5.995A.905.905 0 0 1 8 5m.002 6a1 1 0 1 1 0 2 1 1 0 0 1 0-2"/></svg>' },
            error:   { bg: 'text-bg-danger',  icon: '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" viewBox="0 0 16 16"><path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0M5.354 4.646a.5.5 0 1 0-.708.708L7.293 8l-2.647 2.646a.5.5 0 0 0 .708.708L8 8.707l2.646 2.647a.5.5 0 0 0 .708-.708L8.707 8l2.647-2.646a.5.5 0 0 0-.708-.708L8 7.293z"/></svg>' },
            info:    { bg: 'text-bg-dark',    icon: '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" viewBox="0 0 16 16"><path d="M8 16A8 8 0 1 0 8 0a8 8 0 0 0 0 16m.93-9.412-1 4.705c-.07.34.029.533.304.533.194 0 .487-.07.686-.246l-.088.416c-.287.346-.92.598-1.465.598-.703 0-1.002-.422-.808-1.319l.738-3.468c.064-.293.006-.399-.287-.47l-.451-.081.082-.381 2.29-.287zM8 5.5a1 1 0 1 1 0-2 1 1 0 0 1 0 2"/></svg>' },
        };

        const config = toastConfig[type] ?? toastConfig.info;
        
        // Déterminer la classe du bouton de fermeture selon la couleur de fond
        const closeButtonClass = (config.bg === 'text-bg-dark' || config.bg === 'text-bg-success' || config.bg === 'text-bg-danger') ? 'btn-close-white' : '';
        
        // Générer un ID unique pour éviter les conflits d'instances Bootstrap
        const toastId = `jsb-toast-${Date.now()}`;

        const toastHTML = `
            <div id="${toastId}" class="toast align-items-center ${config.bg} bg-opacity-75 border border-secondary p-2 m-1" role="alert" aria-live="assertive" aria-atomic="true">
                <div class="d-flex">
                    <div class="toast-body">
                        <span class="flex-shrink-0">${config.icon}</span>
                        <span>${message}</span>
                    </div>
                    <button type="button" class="btn-close ${closeButtonClass} me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                </div>
            </div>
        `;

        // Append the toast to the toast container
        this.toastContainerTarget.insertAdjacentHTML('beforeend', toastHTML);
        const newToastEl = document.getElementById(toastId);

        try {
            const toast = new Toast(newToastEl, {
                autohide: autoHide, //
                delay: 10000 // Le toast disparaît après 10 secondes
            });

            // Nettoyer le DOM après la fermeture pour ne pas saturer la page
            newToastEl.addEventListener('hidden.bs.toast', () => {
                newToastEl.remove();
            });

            toast.show();
            
            this._lastToastMessage = message;
            this._lastToastType = type;
        } catch (e) {
            console.warn("[BordereauAnalysis] _showToast() - Erreur Bootstrap:", e);
        }
    }

    /**
     * Déclencheur de la recherche.
     * Appelé au clic sur le bouton de recherche OU à l'appui sur Enter dans le champ.
     * @param {Event} event
     */
    triggerSearch(event) {
        // Autoriser le déclenchement via Enter ou clic bouton
        if (event.type === 'keydown' && event.key !== 'Enter') return;
        event.preventDefault();
        if (!this.hasSearchInputTarget) return;
        this._currentSearchTerm = this.searchInputTarget.value.trim().toLowerCase();
        this._applySearch(this._currentSearchTerm);
    }

    /**
     * Applique le filtre sur les items déjà présents dans le DOM.
     * Masque / affiche les <li> selon que les champs cibles contiennent le terme.
     * Ne touche pas aux données Stimulus ni à la logique métier des boutons d'action.
     * @param {string} term - Terme de recherche normalisé (toLowerCase, trim)
     */
    _applySearch(term) {
        if (!this.hasAnalysisResultsListTarget) return;

        const allItems = this.analysisResultsListTarget.querySelectorAll(
            '[data-controller="analysis-result-item"]'
        );

        this._totalItemCount = allItems.length;
        let visibleCount = 0;

        allItems.forEach(itemEl => {
            // Lire les champs indexés depuis le data-value déjà en place
            const lineInfo = JSON.parse(
                itemEl.dataset.analysisResultItemBordereauLineInfoValue || '{}'
            );

            const referencePolice = (lineInfo.reference_police || '').toLowerCase();
            const nomClient       = (lineInfo.nom_client       || '').toLowerCase();
            const risque          = (lineInfo.risque            || '').toLowerCase();

            const matches = !term
                || referencePolice.includes(term)
                || nomClient.includes(term)
                || risque.includes(term);

            // Afficher / masquer sans toucher à la structure ni aux gestionnaires d'événements
            itemEl.classList.toggle('d-none', !matches);
            if (matches) visibleCount++;
        });

        this._updateSearchResultCount(visibleCount, this._totalItemCount, term);
    }

    /**
     * Met à jour l'indicateur de résultats de recherche.
     * @param {number} visible  - Nb d'items actuellement affichés
     * @param {number} total    - Nb total d'items dans la liste
     * @param {string} term     - Terme recherché (vide = pas de filtre actif)
     */
    _updateSearchResultCount(visible, total, term) {
        if (!this.hasSearchResultCountTarget) return;

        if (!term) {
            // Pas de filtre actif : on cache le compteur
            this.searchResultCountTarget.textContent = '';
            this.searchResultCountTarget.classList.add('d-none');
            return;
        }

        this.searchResultCountTarget.classList.remove('d-none');

        if (visible === 0) {
            this.searchResultCountTarget.textContent = 'Aucun résultat';
            this.searchResultCountTarget.style.color = '#dc3545';
        } else {
            this.searchResultCountTarget.textContent =
                `${visible} / ${total} résultat${visible > 1 ? 's' : ''}`;
            this.searchResultCountTarget.style.color = '#ced4da'; // toolbar-feedback-info
        }
    }

    /**
     * Réinitialise le champ de recherche et rend tous les items visibles.
     * Appelé à chaque entrée à l'étape 3 (showStep) et au clic sur la croix de reset.
     */
    _resetSearch() {
        this._currentSearchTerm = '';
        if (this.hasSearchInputTarget) {
            this.searchInputTarget.value = '';
        }
        if (this.hasSearchResultCountTarget) {
            this.searchResultCountTarget.textContent = '';
            this.searchResultCountTarget.classList.add('d-none');
        }
        // Rendre tous les items visibles (lever d-none éventuellement posé par un filtre précédent)
        if (this.hasAnalysisResultsListTarget) {
            this.analysisResultsListTarget
                .querySelectorAll('[data-controller="analysis-result-item"]')
                .forEach(item => item.classList.remove('d-none'));
        }
    }

    /**
     * Remplace le cerveau pour charger le contenu HTML d'une boîte de dialogue.
     * Intercepte ui:dialog.content-request et répond avec ui:dialog.content-ready.
     * @param {object} payload - Identique au payload traité par cerveau.handleDialogContentRequest.
     */
    async _handleDialogContentRequest(payload) {
        const { dialogId, endpoint, entity, entityFormCanvas } = payload;

        try {
            let urlString = endpoint;
            if (entity && entity.id) {
                urlString += `/${entity.id}`;
            }

            const url = new URL(urlString, window.location.origin);
            if (this.idEntrepriseValue) url.searchParams.set('idEntreprise', this.idEntrepriseValue);
            if (this.idInviteValue)    url.searchParams.set('idInvite',    this.idInviteValue);

            const response = await fetch(url.pathname + url.search);
            if (!response.ok) throw new Error(`Erreur serveur ${response.status}`);

            const html = await response.text();

            const tempDiv = document.createElement('div');
            tempDiv.innerHTML = html;
            const contentRoot = tempDiv.querySelector('[data-icon-name]');
            const icon = contentRoot ? contentRoot.dataset.iconName : null;

            const isCreationMode = !(entity && entity.id);
            const title = isCreationMode
                ? (entityFormCanvas?.parametres?.titre_creation || 'Création')
                : (entityFormCanvas?.parametres?.titre_modification || 'Modification #%id%').replace('%id%', entity.id);

            document.dispatchEvent(new CustomEvent('ui:dialog.content-ready', {
                bubbles: true,
                detail: { dialogId, html, title, icon }
            }));
        } catch (error) {
            document.dispatchEvent(new CustomEvent('ui:dialog.content-ready', {
                bubbles: true,
                detail: { dialogId, error: { message: error.message } }
            }));
        }
    }

    /**
     * Ouvre le dialog de création de Note pré-rempli depuis ce bordereau.
     * Dispatche app:boite-dialogue:init-request directement (page autonome, pas de cerveau).
     */
    createFacture() {
        const bordereauId = this.bordereauIdValue;
        document.dispatchEvent(new CustomEvent('app:boite-dialogue:init-request', {
            bubbles: true,
            detail: {
                entity: {},
                entityFormCanvas: this.noteFormCanvasValue,
                isCreationMode: true,
                context: {
                    originatorId: `bordereau-note-${bordereauId}`,
                    idEntreprise: this.idEntrepriseValue,
                    idInvite: this.idInviteValue
                },
                parentContext: { id: String(bordereauId), fieldName: 'bordereau' }
            }
        }));
    }

    /**
     * Affiche le badge de note liée et masque le bouton Facturer.
     * @param {Array} notes - Tableau de {id, reference, nom}
     */
    _renderLinkedNotesBadge(notes) {
        this.savedNoteEntities = notes;
        if (!this.hasLinkedNotesBadgeTarget) return;
        const first = notes[0];
        this.linkedNotesBadgeTextTarget.textContent = notes.length > 1
            ? `${notes.length} notes liées`
            : (first.reference || first.id);
        this.linkedNotesBadgeTarget.classList.remove('d-none');
        if (this.hasFactureButtonTarget) this.factureButtonTarget.classList.add('d-none');
        if (this.hasNoteActionsDividerTarget) this.noteActionsDividerTarget.classList.remove('d-none');
        if (this.hasEditNoteItemTarget) this.editNoteItemTarget.classList.remove('d-none');
        if (this.hasViewNoteItemTarget) this.viewNoteItemTarget.classList.remove('d-none');
    }

    /**
     * Rouvre le dialog de la note liée en mode édition.
     */
    openLinkedNoteDialog() {
        const note = this.savedNoteEntities?.[0];
        if (!note) return;
        const editCanvas = {
            parametres: {
                ...this.noteFormCanvasValue.parametres,
                isCreationMode: false,
                titre_modification: `Note ${note.reference || note.id}`
            }
        };
        document.dispatchEvent(new CustomEvent('app:boite-dialogue:init-request', {
            bubbles: true,
            detail: {
                entity: note,
                entityFormCanvas: editCanvas,
                isCreationMode: false,
                context: {
                    idEntreprise: this.idEntrepriseValue,
                    idInvite: this.idInviteValue
                }
            }
        }));
    }

    async viewLinkedNote() {
        const note = this.savedNoteEntities?.[0];
        if (!note) return;
        await this._handleNotePreviewRequest({ url: `/admin/note/api/get-preview-url/${note.id}` });
    }

    async _handleNotePreviewRequest({ url }) {
        if (!url) return;
        try {
            // Extraire le noteId depuis l'URL (/admin/note/api/get-preview-url/{noteId})
            const noteId = url.split('/').at(-1);
            const response = await fetch(`/admin/note/workspace-apercu/${noteId}`);
            if (!response.ok) return;
            const { html, title } = await response.json();
            document.dispatchEvent(new CustomEvent('app:workspace.inject-html', {
                bubbles: true,
                detail: { html, title, iconAlias: 'note', tabKey: `note-preview-${noteId}` },
            }));
        } catch (e) {
            console.warn('[BordereauAnalysis] _handleNotePreviewRequest() failed:', e);
        }
    }

    async _handleDialogIconRequest({ iconName, iconSize, requesterId }) {
        if (this.iconCache.has(iconName)) {
            document.dispatchEvent(new CustomEvent('app:icon.loaded', {
                bubbles: true,
                detail: { html: this.iconCache.get(iconName), requesterId, iconName }
            }));
            return;
        }
        try {
            const url = `/api/icon/api/get-icon?name=${encodeURIComponent(iconName)}&size=${iconSize || 24}`;
            const response = await fetch(url);
            if (!response.ok) return;
            const html = await response.text();
            if (html && !html.trim().startsWith('<!--')) {
                this.iconCache.set(iconName, html);
            }
            document.dispatchEvent(new CustomEvent('app:icon.loaded', {
                bubbles: true,
                detail: { html, requesterId, iconName }
            }));
        } catch (e) {
            console.warn('[BordereauAnalysis] _handleDialogIconRequest() failed:', e);
        }
    }
}