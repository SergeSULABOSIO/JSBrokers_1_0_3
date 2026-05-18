import BaseController from './base_controller.js';
/**
 * @class BordereauAnalysisController
 * @description Gère l'interface de l'analyse de bordereau en deux étapes.
 * Etape 1: Sélection de la feuille Excel.
 * Etape 2: Mappage des colonnes de la feuille sélectionnée.
 */
export default class extends BaseController { // NOUVEAU : Ajout du bouton de retour
    static targets = [ // NOUVEAU : Ajout de la cible pour le bouton de retour
        "sheetSelection", "step2", "mappingContainer", "mappingStatusFeedback", "mappingForm",
        "mappingSelect", "analysisResult", "submitButton", "columnNameText", "step1", "step3", "analysisResultsList", "progressBar", "progressBarContainer", "analysisSummary", "validateButton",
        "backToMappingButton", "exportPdfButton",
        "backToMappingButton", "exportPdfButton", "bulkCreateButton", "bulkUpdateButton",
        "batchActionsBlock",
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
        this.boundHandleIconRequest = this.handleIconRequest.bind(this);
        // CORRECTION : On écoute sur `document` pour intercepter les événements des enfants
        // qui sont injectés dynamiquement et ne sont pas des descendants directs.
        document.addEventListener('analysis:icon.request', this.boundHandleIconRequest);

        // NOUVEAU : Écoute la résolution des items pour activer le bouton Valider
        this.boundHandleItemResolved = this._handleItemResolved.bind(this);
        document.addEventListener('cerveau:event', this.boundHandleItemResolved);

        this.requiredMappings = new Set([
            'reference_police',
            'date_effet_avenant',
            'date_expiration_avenant',
            'prime_ttc', // NOUVEAU : Ajout de la prime TTC
            'date_operation', // Nouveau champ obligatoire
            'risque', // Nouveau champ obligatoire
            'nom_client',
            'commission_ht_assureur',
            'taxe_commission_assureur',
            'taux_commission' // Nouveau champ obligatoire
            // NOUVEAU : Ajout de champs obligatoires pour l'étape 3
        ]);
        this.validationState = new Map();

        console.log("[BordereauAnalysis] 2. connect() - Initialisation des options de mappage.");
        this.addChargementOptions();
        this.addTypeRevenuOptions();
        this.addSystemOptions();

        this.isRestoring = false;
        this.isConnecting = true; // Nouveau drapeau: true pendant la connexion initiale
        this.currentStep = 1;
        this.isSaving = false; // NOUVEAU : Le "feu de signalisation" pour la sauvegarde.
        this._pendingSaveTimeout = null; // Identifiant pour le debounce des sauvegardes
        // Timeout de debounce dédié à la sauvegarde automatique du mappage
        this._pendingMappingSaveTimeout = null;

        console.log("[BordereauAnalysis] 3. connect() - Mise en place des écouteurs d'événements.");

        // Étape 1: Chargement initial comme si rien n'était à restaurer.
        console.log("[BordereauAnalysis] 4. connect() - Exécution du chargement initial de l'UI (état par défaut).");
        this.afterConnect();

        // Étape 2: Si des données de restauration existent, on les applique.
        if (this.currentAnalysisStepValue > 0) {
            console.log(`%c[BordereauAnalysis] 5. connect() - Données de restauration détectées (étape ${this.currentAnalysisStepValue}). Lancement du processus...`, "color: blue; font-weight: bold;");
            this._restoreAnalysisState();
        } else if (this.sheetSelectionTargets.length === 1 && this.sheetsDataValue && Object.keys(this.sheetsDataValue).length === 1) {
            console.log("[BordereauAnalysis] 5. connect() - Une seule feuille détectée. Passage automatique à l'étape 2.");
            this.showStep(2);
        }
        this.isConnecting = false; // Le drapeau est remis à false une fois la logique de connect() terminée
        console.log("[BordereauAnalysis] 6. connect() - Fin de la connexion.");
    }

    disconnect() {
        console.log("[BordereauAnalysis] disconnect() - Nettoyage des écouteurs.");
        document.removeEventListener('analysis:icon.request', this.boundHandleIconRequest);
        document.removeEventListener('cerveau:event', this.boundHandleItemResolved);
        // Annuler toute sauvegarde de mappage en attente
        if (this._pendingMappingSaveTimeout) {
            clearTimeout(this._pendingMappingSaveTimeout);
            this._pendingMappingSaveTimeout = null;
        }
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

        if (this.iconCache.has(iconName)) {
            // Si l'icône est en cache, on la renvoie directement.
            console.log(`%c[Parent] 1b. Icône '${iconName}' trouvée en cache. Renvoi immédiat.`, 'color: green;');
            document.dispatchEvent(new CustomEvent('analysis:icon.loaded', {
                bubbles: true, detail: {
                    html: this.iconCache.get(iconName), // HTML de l'icône
                    iconName: iconName,                 // Nom de l'icône (pour le cache)
                    requesterId: requesterId,           // ID de l'élément demandeur
                }
            }));
        } else {
            // Sinon, on la récupère depuis le serveur.
            const url = `/api/icon/api/get-icon?name=${encodeURIComponent(iconName)}&size=${iconSize}`;
            try {
                const response = await fetch(url);
                if (!response.ok) throw new Error(`Icon fetch failed with status ${response.status}`);
                
                const html = await response.text();
                
                // On met en cache si la réponse est valide.
                if (html && !html.trim().startsWith('<!--')) {
                    this.iconCache.set(iconName, html);
                }

                // On diffuse l'événement à l'enfant qui a fait la demande.
                document.dispatchEvent(new CustomEvent('analysis:icon.loaded', { bubbles: true, detail: { html, requesterId, iconName: iconName } }));

            } catch (error) {
                console.error(`[BordereauAnalysis] Failed to fetch icon '${iconName}':`, error);
                // On envoie quand même une réponse pour ne pas bloquer l'UI.
                document.dispatchEvent(new CustomEvent('analysis:icon.loaded', { bubbles: true, detail: { html: `<!-- error -->`, requesterId, iconName: iconName } }));
            }
        }
    }

    /**
     * Finalise le processus de restauration en mettant à jour l'UI.
     */
    finalizeRestoration() {
        this.isRestoring = false; // Réinitialise le drapeau
        this.updateMappingStatusFeedback();
        this.updateSubmitButtonState();
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
            console.log("2. Résultats d'analyse HTML restaurés (en mémoire):", this.analysisResultsHtmlValue);
            // CORRECTION : Forcer le rendu des résultats qui sont déjà en mémoire.
            this.renderAnalysisResults(this.analysisResultsHtmlValue);
            console.log("-> Le rendu des résultats a été déclenché.");
        } else {
            console.log("2. Résultats d'analyse non restaurés (pas à l'étape 3 ou données vides).");
        }
    
        // 3. Naviguer vers l'étape sauvegardée (UI - rend le conteneur de mappage visible si étape 2)
        // C'est crucial de faire cela AVANT de tenter de restaurer les selects du mappage,
        // car les selects doivent être visibles pour que leur valeur soit correctement définie et validée.
        this.showStep(this.currentAnalysisStepValue, this.selectedSheetNameValue);
        console.log(`3. Navigation UI: Appel de showStep(${this.currentAnalysisStepValue}) pour afficher la bonne étape.`);

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
                        const mappedSystemField = Object.keys(this.mappedColumnsValue).find(key => this.mappedColumnsValue[key] === columnLetter);
                        if (mappedSystemField) {
                            select.value = mappedSystemField;
                            console.log(`   - Colonne '${columnLetter}' -> Mappée sur '${mappedSystemField}'.`);
                            this.performValidation(select); // Valider la colonne restaurée pour mettre à jour l'état de validation interne
                            this.updateSelectOptionsVisuals(); // Ensure visuals are updated after validation
                        }
                    });
                } else {
                    console.warn("Aucun conteneur de mappage actif trouvé pour restaurer les selects.");
                }
                this.finalizeRestoration();
                this.updateSelectOptionsVisuals(); // Appel final pour garantir la cohérence visuelle
                console.groupEnd();
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
                    mappedColumns[select.value] = select.dataset.columnLetter;
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
            selectedSheetName:   selectedSheetName,
            mappedColumns:       mappedColumns,
            // analysisResults intentionnellement absent :
            // non pertinent pour une sauvegarde de mappage en cours d'étape 2
        };

        console.log("[BordereauAnalysis] _saveMappingOnly() - Payload:", payload);

        try {
            const response = await fetch(
                `/admin/bordereau/api/save-analysis-state/${this.bordereauIdValue}`,
                {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body:    JSON.stringify(payload)
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
     * Affiche l'étape 2 (mappage) et sélectionne le bon tableau de mappage
     * en fonction de la feuille choisie à l'étape 1.
     */
    showMappingStep(event) {
        if (event) event.preventDefault(); // Empêche le comportement par défaut du bouton
        const selectedSheetInput = this.sheetSelectionTargets.find(radio => radio.checked);
        if (!selectedSheetInput) {
            // Cas de sécurité, ne devrait pas arriver avec un radio button.
            return;
        }
        const selectedSheetName = selectedSheetInput.value;
        this.showStep(2, selectedSheetName); // showStep appellera _saveAnalysisStateToBordereau()
    }

    /**
     * Gère la transition entre les étapes de l'analyse.
     * Applique la visibilité stricte de chaque bouton selon l'étape.
     * @param {number|Event} stepNumber
     * @param {string} [sheetName=null]
     */
    async showStep(stepNumber, sheetName = null) {
        const previousStep = this.currentStep;
        this.currentStep = (
            typeof stepNumber === 'object' && stepNumber.currentTarget
        )
            ? parseInt(stepNumber.currentTarget.dataset.stepNumber)
            : stepNumber;

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
        if (this.hasBackToMappingButtonTarget) {
            this.backToMappingButtonTarget.classList.add('d-none');
        }
        if (this.hasValidateButtonTarget) {
            this.validateButtonTarget.classList.add('d-none');
        }
        if (this.hasExportPdfButtonTarget) {
            this.exportPdfButtonTarget.classList.add('d-none');
        }
        if (this.hasBulkCreateButtonTarget) {
            this.bulkCreateButtonTarget.classList.add('d-none');
        }
        if (this.hasBulkUpdateButtonTarget) {
            this.bulkUpdateButtonTarget.classList.add('d-none');
        }

        // --- 3. Réinitialiser le feedback ---
        if (this.hasMappingStatusFeedbackTarget) {
            this.mappingStatusFeedbackTarget.innerHTML = '';
            this.mappingStatusFeedbackTarget.classList.add('d-none');
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
            if (this.hasMappingStatusFeedbackTarget) {
                this.mappingStatusFeedbackTarget.classList.remove('d-none');
            }

            this._showMappingUI(
                sheetName ||
                this.sheetSelectionTargets.find(radio => radio.checked)?.value
            );

        } else if (this.currentStep === 3) {

            // ÉTAPE 3 : "Retour au mappage" + "Exporter PDF" toujours visibles
            // "Valider" : visible mais son état actif/inactif est géré par
            //             _updateValidateButtonState()
            this.step3Target.classList.remove('d-none');

            if (this.hasBackToMappingButtonTarget) {
                this.backToMappingButtonTarget.classList.remove('d-none');
            }
            if (this.hasExportPdfButtonTarget) {
                this.exportPdfButtonTarget.classList.remove('d-none');
            }
            // Afficher les boutons en lot uniquement si des items actionnables existent.
            // L'état actif/inactif sera géré par _updateBulkButtonsState().
            if (this.hasBulkCreateButtonTarget) {
                this.bulkCreateButtonTarget.classList.remove('d-none');
            }
            if (this.hasBulkUpdateButtonTarget) {
                this.bulkUpdateButtonTarget.classList.remove('d-none');
            }

            // Le bouton Valider est rendu visible ici mais désactivé par défaut.
            // _updateValidateButtonState() décidera s'il doit être actif.
            if (this.hasValidateButtonTarget) {
                this.validateButtonTarget.classList.remove('d-none');
                this.validateButtonTarget.disabled = true; // Désactivé jusqu'à évaluation
            }

            // Rendre le récapitulatif et les résultats
            this.renderAnalysisSummary(this.analysisStatsValue);
            this.renderAnalysisResults(this.analysisResultsHtmlValue);

            // Évaluer l'état réel du bouton Valider
            this._updateValidateButtonState();
            this._updateBulkButtonsState();
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
        this.mappingContainerTargets.forEach(container => {
            const isTargetSheet = sheetName ? container.dataset.sheetName === sheetName : container.dataset.isFirst === 'true';
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
            resultCell.innerHTML = this.getFeedbackHtml('success', 'Données valides !', true);
            this.validationState.set(columnLetter, true);
        } else {
            const message = `Lignes invalides: ${invalidRows.slice(0, 5).join(', ')}${invalidRows.length > 5 ? '...' : ''}`;
            resultCell.innerHTML = this.getFeedbackHtml('error', message);
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
        this.updateMappingStatusFeedback(); // Met à jour le feedback après chaque validation
    }

    /**
     * Génère le HTML pour un message de feedback.
     * @param {'success'|'error'} type
     * @param {string} message
     * @returns {string}
     * @param {boolean} [includeIcon=true] - NOUVEAU : Permet de contrôler l'affichage de l'icône.
     * @returns {string}
     */
    getFeedbackHtml(type, message, includeIcon = true) {
        let iconHtml = '';
        if (includeIcon) {
            iconHtml = type === 'success'
                ? '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-check-circle-fill me-1" viewBox="0 0 16 16"><path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0m-3.97-3.03a.75.75 0 0 0-1.08.022L7.477 9.417 5.384 7.323a.75.75 0 0 0-1.06 1.06L6.97 11.03a.75.75 0 0 0 1.079-.02l3.992-4.99a.75.75 0 0 0-.01-1.05z"/></svg>'
                : (type === 'warning'
                    ? '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-exclamation-triangle-fill me-1" viewBox="0 0 16 16"><path d="M8.982 1.566a1.13 1.13 0 0 0-1.96 0L.165 13.233c-.457.778.091 1.767.98 1.767h13.713c.889 0 1.438-.99.98-1.767zM8 5c.535 0 .954.462.9.995l-.35 3.507a.552.552 0 0 1-1.1 0L7.1 5.995A.905.905 0 0 1 8 5m.002 6a1 1 0 1 1 0 2 1 1 0 0 1 0-2"/></svg>'
                    : '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-x-circle-fill me-1" viewBox="0 0 16 16"><path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0M5.354 4.646a.5.5 0 1 0-.708.708L7.293 8l-2.647 2.646a.5.5 0 0 0 .708.708L8 8.707l2.646 2.647a.5.5 0 0 0 .708-.708L8.707 8l2.647-2.646a.5.5 0 0 0-.708-.708L8 7.293z"/></svg>');
        }

        let textColorClass = '';
        if (type === 'success') {
            textColorClass = 'text-success';
        } else if (type === 'warning') {
            textColorClass = 'text-warning'; // Rendre le texte d'avertissement jaune pour les feedbacks hors toolbar
        } else { // error
            textColorClass = 'text-danger';
        }

        // Le CSS pour .actions-bar override la couleur pour le feedback de la toolbar.
        return `<span class="d-inline-flex align-items-center small ${textColorClass}">${iconHtml} ${message}</span>`;
    }

    /**
     * Met à jour le paragraphe de feedback sur l'état du mappage.
     */
    updateMappingStatusFeedback() {
        if (!this.hasMappingStatusFeedbackTarget) {
            return;
        }

        const mappedRequiredCount = new Set();
        const mappedOptionalCount = new Set();
        const totalOptionalCount = this.chargementsValue.length + this.typeRevenusValue.length;

        this.mappingSelectTargets.forEach(select => {
            const mappingType = select.value;
            if (mappingType) {
                if (this.requiredMappings.has(mappingType)) {
                    mappedRequiredCount.add(mappingType);
                } else if (mappingType.startsWith('chargement_') || mappingType.startsWith('revenu_')) {
                    mappedOptionalCount.add(mappingType);
                }
            }
        });

        const requiredMapped = mappedRequiredCount.size;
        const requiredRemaining = this.requiredMappings.size - requiredMapped;
        const optionalMapped = mappedOptionalCount.size;

        let message = ``;

        if (requiredRemaining > 0) {
            message += `Il reste <strong>${requiredRemaining}</strong> champ(s) obligatoire(s) à mapper.`;
        } else {
            message += `Tous les champs obligatoires (<strong>${this.requiredMappings.size}/${this.requiredMappings.size}</strong>) sont mappés.`;
            if (totalOptionalCount > 0) {
                message += ` Vous avez mappé <strong>${optionalMapped}</strong> champ(s) optionnel(s) sur <strong>${totalOptionalCount}</strong> disponible(s).`;
            }
        }

        this.mappingStatusFeedbackTarget.innerHTML = message;
        // Appel redondant supprimé. La mise à jour se fait déjà aux moments clés.
    }

    /**
     * Soumet les données mappées au backend pour l'analyse.
     * Point d'entrée de l'analyse par lots.
     * Lance l'initialisation puis enchaîne les lots séquentiellement.
     */
    async submitAnalysis(event) {
        console.log("[BordereauAnalysis] submitAnalysis() - Lancement de l'analyse par lots.");

        try {
            // CORRECTION : Avant de lancer l'analyse, on force une sauvegarde complète 
            // pour synchroniser le mappage de l'UI vers la base de données.
            // Le backend lit la configuration depuis l'entité, c'est donc indispensable.
            await this._saveAnalysisStateToBordereau('full');
        } catch (error) {
            return; // L'erreur est déjà gérée par _handleSaveStateFailed
        }

        // Désactiver le bouton et préparer l'UI
        this.submitButtonTarget.disabled = true;
        this.submitButtonTarget.textContent = "Analyse en cours...";
        this.toggleProgressBar(true);
        this.mappingStatusFeedbackTarget.innerHTML = this.getFeedbackHtml(
            'warning', 'Initialisation de l\'analyse...', false
        );

        // Réinitialiser les résultats accumulés
        this._accumulatedResultsHtml  = [];
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
                    analysisResults:     [],
                    analysisResultsHtml: [],
                });
                return;
            }

            console.log(`[BordereauAnalysis] submitAnalysis() - ${totalRows} lignes à traiter par lots de ${chunkSize}.`);
            this.mappingStatusFeedbackTarget.innerHTML = this.getFeedbackHtml(
                'warning', `0 / ${totalRows} lignes analysées...`, false
            );

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
                this._accumulatedResultsHtml  = this._accumulatedResultsHtml.concat(
                    processData.chunkResultsHtml || []
                );
                this._accumulatedResultsStore = this._accumulatedResultsStore.concat(
                    processData.chunkResultsStore || []
                );

                // Mettre à jour la barre de progression (mode déterminé)
                const processed  = processData.processedCount || (offset + chunkSize);
                const percentage = Math.min(Math.round((processed / totalRows) * 100), 100);
                this._updateProgressBarPercentage(percentage);

                // Mettre à jour le feedback texte
                this.mappingStatusFeedbackTarget.innerHTML = this.getFeedbackHtml(
                    'warning',
                    `${Math.min(processed, totalRows)} / ${totalRows} lignes analysées...`,
                    false
                );

                console.log(`[BordereauAnalysis] submitAnalysis() - Lot traité : ${processed}/${totalRows} (${percentage}%)`);

                if (processData.isLastChunk) {
                    finalStats = processData.stats || {};
                    break;
                }
                offset += chunkSize;
            }

            // --- ÉTAPE 3 : Tout est traité ---
            this._handleAnalysisCompleted({
                analysisResults:     this._accumulatedResultsStore,
                analysisResultsHtml: this._accumulatedResultsHtml,
                stats:               finalStats
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

        // Rendre le bloc d'actions en lot après injection des résultats dans le DOM
        // Utiliser requestAnimationFrame pour s'assurer que le DOM est à jour
        requestAnimationFrame(() => {
            this.renderBatchActionsBlock();
        });
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

        // Mettre à jour les données en mémoire
        this.analysisResultsValue     = payload.analysisResults     || [];
        this.analysisStatsValue       = payload.stats               || {};
        this.analysisResultsHtmlValue = payload.analysisResultsHtml || [];

        // Réinitialiser l'état du bouton "Lancer l'analyse" AVANT showStep
        // pour qu'il soit propre si l'utilisateur revient à l'étape 2
        if (this.hasSubmitButtonTarget) {
            this.submitButtonTarget.disabled = false;
            this.submitButtonTarget.textContent = "Lancer l'analyse";
        }

        // Passer à l'étape 3 — showStep gère la visibilité de tous les boutons
        this.showStep(3);

        // Feedback de succès (après showStep car showStep vide le feedback)
        if (this.hasMappingStatusFeedbackTarget) {
            this.mappingStatusFeedbackTarget.classList.remove('d-none');
            this.mappingStatusFeedbackTarget.innerHTML = this.getFeedbackHtml(
                'success', 'Analyse terminée avec succès.', false
            );
        }

        this.toggleProgressBar(false);

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
        if (event.detail?.type !== 'bordereau:item.resolved') return;

        console.log('[BordereauAnalysis] _handleItemResolved() - Item résolu détecté. Réévaluation du bouton Valider.');
        this._updateValidateButtonState();
        this._updateBulkButtonsState();

        // Réévaluer le bloc d'actions en lot
        this.renderBatchActionsBlock();
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

        let totalActionable  = 0; // new + discrepancy
        let totalResolved    = 0; // parmi les actionnables, ceux marqués résolus

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

        const allResolved = totalActionable > 0 && totalResolved === totalActionable;

        console.log(`[BordereauAnalysis] _updateValidateButtonState() - Actionnables: ${totalActionable}, Résolus: ${totalResolved}, Tous résolus: ${allResolved}`);

        // Activer ou désactiver le bouton Valider
        // (sa visibilité est gérée exclusivement par showStep())
        this.validateButtonTarget.disabled = !allResolved;
    }

    /**
     * Déclenche la validation finale du bordereau.
     * Appelé au clic sur le bouton "Valider le bordereau".
     */
    async validateBordereau() {
        if (!confirm(
            'Confirmez-vous la validation de ce bordereau ?\n\n' +
            'Cette action signifie que tous les écarts ont été traités ' +
            'et que le bordereau est conforme.'
        )) return;

        this.validateButtonTarget.disabled = true;
        this.toggleProgressBar(true);
        this.mappingStatusFeedbackTarget.classList.remove('d-none');
        this.mappingStatusFeedbackTarget.innerHTML = this.getFeedbackHtml(
            'warning', 'Validation en cours...', false
        );

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

            // Succès : feedback visuel et désactivation définitive du bouton
            this.mappingStatusFeedbackTarget.innerHTML = this.getFeedbackHtml(
                'success', '✓ Bordereau validé avec succès !', false
            );
            this.validateButtonTarget.textContent = '✓ Bordereau validé';
            this.validateButtonTarget.disabled = true;

            // TODO: Ultérieurement, rediriger vers la page du bordereau
            //       ou afficher une modale de confirmation avec récapitulatif.

        } catch (error) {
            console.error('[BordereauAnalysis] validateBordereau() - Erreur:', error);
            this.mappingStatusFeedbackTarget.innerHTML = this.getFeedbackHtml(
                'error', `Échec de la validation : ${error.message}`, false
            );
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

        const matchPct       = stats.total > 0 ? Math.round((stats.match / stats.total) * 100) : 0;
        const formatNumber   = (n) => new Intl.NumberFormat('fr-FR', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }).format(n || 0);

        // Détermine la couleur de la barre de progression selon le % de conformité
        let progressClass = 'bg-danger';
        if (matchPct >= 80) progressClass = 'bg-success';
        else if (matchPct >= 50) progressClass = 'bg-warning';

        this.analysisSummaryTarget.innerHTML = `
            <div class="card mb-4 border-0 shadow-sm">
                <div class="card-body p-3">
                    <h6 class="fw-bold mb-3 text-muted text-uppercase small">
                        Récapitulatif de l'analyse
                    </h6>

                    <!-- Compteurs par type -->
                    <div class="row g-2 mb-3">
                        <div class="col-3">
                            <div class="text-center p-2 rounded" style="background:#f0fff4; border:1px solid #198754">
                                <div class="fw-bold fs-4 text-success">${stats.match}</div>
                                <div class="small text-muted">Conformes</div>
                            </div>
                        </div>
                        <div class="col-3">
                            <div class="text-center p-2 rounded" style="background:#fff8e1; border:1px solid #ffc107">
                                <div class="fw-bold fs-4 text-warning">${stats.discrepancy}</div>
                                <div class="small text-muted">Anomalies</div>
                            </div>
                        </div>
                        <div class="col-3">
                            <div class="text-center p-2 rounded" style="background:#e8f4fd; border:1px solid #0d6efd">
                                <div class="fw-bold fs-4 text-primary">${stats.new}</div>
                                <div class="small text-muted">Nouveaux</div>
                            </div>
                        </div>
                        <div class="col-3">
                            <div class="text-center p-2 rounded" style="background:#f8f9fa; border:1px solid #6c757d">
                                <div class="fw-bold fs-4 text-secondary">${stats.total}</div>
                                <div class="small text-muted">Total</div>
                            </div>
                        </div>
                    </div>

                    <!-- Barre de conformité -->
                    <div class="mb-3">
                        <div class="d-flex justify-content-between small text-muted mb-1">
                            <span>Taux de conformité</span>
                            <span class="fw-bold">${matchPct}%</span>
                        </div>
                        <div class="progress" style="height:8px; border-radius:4px;">
                            <div class="progress-bar ${progressClass}"
                                 role="progressbar"
                                 style="width:${matchPct}%"
                                 aria-valuenow="${matchPct}"
                                 aria-valuemin="0"
                                 aria-valuemax="100">
                            </div>
                        </div>
                    </div>

                    <!-- Totaux financiers -->
                    <div class="row g-2 pt-2 border-top">
                        <div class="col-md-4">
                            <div class="small text-muted">Primes TTC (bordereau)</div>
                            <div class="fw-bold" style="color:#0047AB">
                                ${formatNumber(stats.total_prime_ttc)}
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="small text-muted">Commissions HT</div>
                            <div class="fw-bold text-success">
                                ${formatNumber(stats.total_commission_ht)}
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="small text-muted">Commissions TTC</div>
                            <div class="fw-bold text-success">
                                ${formatNumber(stats.total_commission_ttc)}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        `;
    }

        /**
     * Rend le bloc des actions en lot sous le récapitulatif.
     * Analyse les items actuellement affichés dans la liste pour déterminer
     * quels boutons en lot sont pertinents.
     * Appelé après chaque rendu des résultats et après chaque résolution d'item.
     */
    renderBatchActionsBlock() {
        if (!this.hasBatchActionsBlockTarget) return;

        const allItems = this.analysisResultsListTarget
            ? this.analysisResultsListTarget.querySelectorAll(
                '[data-controller="analysis-result-item"]'
              )
            : [];

        // Compter les items actionnables non résolus par type
        let pendingNew         = 0;
        let pendingDiscrepancy = 0;

        allItems.forEach(item => {
            if (item.dataset.resolved === 'true') return;
            if (item.classList.contains('border-info'))    pendingNew++;
            if (item.classList.contains('border-warning')) pendingDiscrepancy++;
        });

        const hasNew         = pendingNew > 0;
        const hasDiscrepancy = pendingDiscrepancy > 0;
        const hasBoth        = hasNew && hasDiscrepancy;
        const hasAny         = hasNew || hasDiscrepancy;

        // Si rien à traiter, masquer le bloc entier
        if (!hasAny) {
            this.batchActionsBlockTarget.innerHTML = '';
            this.batchActionsBlockTarget.classList.add('d-none');
            return;
        }

        // Construire les boutons contextuels
        const btnCreate = hasNew ? `
            <button type="button"
                    class="btn btn-sm btn-outline-primary d-inline-flex align-items-center gap-2"
                    data-action="click->bordereau-analysis#batchCreate">
                <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15"
                     viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="12" y1="8" x2="12" y2="16"/>
                    <line x1="8" y1="12" x2="16" y2="12"/>
                </svg>
                Créer tous les avenants
                <span class="badge bg-primary rounded-pill">${pendingNew}</span>
            </button>
        ` : '';

        const btnUpdate = hasDiscrepancy ? `
            <button type="button"
                    class="btn btn-sm btn-outline-warning d-inline-flex align-items-center gap-2"
                    data-action="click->bordereau-analysis#batchUpdate">
                <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15"
                     viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                    <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                </svg>
                Mettre à jour tous les avenants
                <span class="badge bg-warning text-dark rounded-pill">${pendingDiscrepancy}</span>
            </button>
        ` : '';

        const btnAll = hasBoth ? `
            <button type="button"
                    class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-2"
                    data-action="click->bordereau-analysis#batchAll">
                <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15"
                     viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="20 6 9 17 4 12"/>
                </svg>
                Tout traiter
                <span class="badge bg-secondary rounded-pill">${pendingNew + pendingDiscrepancy}</span>
            </button>
        ` : '';

        this.batchActionsBlockTarget.innerHTML = `
            <div class="d-flex align-items-center gap-2 flex-wrap p-3
                        rounded border border-dashed mb-3"
                 style="border-color:#dee2e6; background:#fafbfc;">
                <span class="small text-muted fw-bold me-1">Actions en lot :</span>
                ${btnCreate}
                ${btnUpdate}
                ${btnAll}
            </div>
        `;
        this.batchActionsBlockTarget.classList.remove('d-none');
    }

    /**
     * Exécute une action en lot sur un ensemble d'items.
     * @param {'new'|'discrepancy'|'all'} batchType
     */
    async _executeBatchAction(batchType) {
        const allItems = this.analysisResultsListTarget
            ? this.analysisResultsListTarget.querySelectorAll(
                '[data-controller="analysis-result-item"]'
              )
            : [];

        const itemsToProcess = [];

        allItems.forEach(item => {
            if (item.dataset.resolved === 'true') return;

            const isNew         = item.classList.contains('border-info');
            const isDiscrepancy = item.classList.contains('border-warning');

            const shouldProcess =
                batchType === 'all'
                || (batchType === 'new'         && isNew)
                || (batchType === 'discrepancy' && isDiscrepancy);

            if (!shouldProcess) return;

            const firstActionButton = item.querySelector('[data-analysis-result-item-target="actionButton"]');
            if (!firstActionButton) return;

            let payload = {};
            try {
                payload = JSON.parse(firstActionButton.dataset.payload || '{}');
            } catch {
                return;
            }

            itemsToProcess.push({
                action_type:       isNew ? 'new' : 'discrepancy',
                avenant_id:        payload.avenant_id        ?? null,
                excel_data:        payload.excel_data         ?? {},
                row_index:         payload.row_index          ?? null,
                reference_police:  payload.reference_police   ?? null,
                _domElement:       item,
            });
        });

        if (itemsToProcess.length === 0) {
            console.log('[BordereauAnalysis] _executeBatchAction() - Aucun item à traiter.');
            return;
        }

        this.mappingStatusFeedbackTarget.classList.remove('d-none');
        this.mappingStatusFeedbackTarget.innerHTML = this.getFeedbackHtml(
            'warning',
            `Traitement en lot de ${itemsToProcess.length} élément(s)...`,
            false
        );
        this.toggleProgressBar(true);

        try {
            const serializableItems = itemsToProcess.map(({ _domElement, ...rest }) => rest);
            const response = await fetch(`/admin/bordereau/api/simulate-batch-action/${this.bordereauIdValue}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ items: serializableItems })
            });

            if (!response.ok) {
                const err = await this._parseErrorResponse(response);
                throw new Error(err);
            }

            const result = await response.json();

            console.log('[BordereauAnalysis] _executeBatchAction() - Résultat:', result);

            result.results.forEach(itemResult => {
                if (!itemResult.success) return;
                const matchingItem = itemsToProcess.find(i => i.row_index === itemResult.row_index);
                if (!matchingItem?._domElement) return;
                const domEl = matchingItem._domElement;

                domEl.classList.remove('border-info', 'border-warning', 'border-danger');
                domEl.classList.add('border-success', 'analysis-item-resolved');
                domEl.dataset.resolved = 'true';

                const detailsText = domEl.querySelector('.analysis-item-header p');
                if (detailsText) {
                    detailsText.innerHTML = `
                        <span class="text-success fw-bold">
                            ✓ Traité en lot — ${itemResult.message}
                        </span>
                    `;
                }

                const actionsContainer = domEl.querySelector('[data-analysis-result-item-target="actionsContainer"]');
                if (actionsContainer) actionsContainer.style.display = 'none';

                const titleWrapper = domEl.querySelector('.analysis-item-title-wrapper h5');
                if (titleWrapper && !titleWrapper.querySelector('.badge')) {
                    const badge = document.createElement('span');
                    badge.className = 'badge bg-success ms-2 small';
                    badge.textContent = 'Résolu';
                    titleWrapper.appendChild(badge);
                }
            });

            const msg = result.failCount > 0
                ? `${result.successCount} traité(s), ${result.failCount} en échec.`
                : `${result.successCount} élément(s) traité(s) avec succès.`;

            this.mappingStatusFeedbackTarget.innerHTML = this.getFeedbackHtml(
                result.failCount > 0 ? 'error' : 'success', msg, false
            );

            this.renderBatchActionsBlock();
            this._updateValidateButtonState();
        } catch (error) {
            console.error('[BordereauAnalysis] _executeBatchAction() - Erreur:', error);
            this.mappingStatusFeedbackTarget.innerHTML = this.getFeedbackHtml(
                'error', `Échec du traitement en lot : ${error.message}`, false
            );
        } finally {
            this.toggleProgressBar(false);
        }
    }

    /**
     * Crée tous les avenants de type "new" non résolus en une seule requête.
     */
    async batchCreate() {
        console.log('[BordereauAnalysis] batchCreate() - Création en lot des nouveaux avenants.');
        await this._executeBatchAction('new');
    }

    /**
     * Met à jour tous les avenants de type "discrepancy" non résolus en une seule requête.
     */
    async batchUpdate() {
        console.log('[BordereauAnalysis] batchUpdate() - Mise à jour en lot des avenants en anomalie.');
        await this._executeBatchAction('discrepancy');
    }

    /**
     * Traite en lot tous les items actionnables (new + discrepancy) non résolus.
     */
    async batchAll() {
        console.log('[BordereauAnalysis] batchAll() - Traitement en lot de tous les items actionnables.');
        await this._executeBatchAction('all');
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
     * Traite en lot tous les items de type "new" non encore résolus.
     * Désactive le bouton pendant le traitement pour éviter les doubles clics.
     */
    async bulkCreateAll() {
        if (!confirm(
            'Confirmez-vous la création en lot de tous les nouveaux avenants ?\n\n' +
            'Cette action traitera tous les items "Nouvel avenant détecté".'
        )) return;

        if (this.hasBulkCreateButtonTarget) {
            this.bulkCreateButtonTarget.disabled = true;
            this.bulkCreateButtonTarget.querySelector('span').textContent = 'Création en cours...';
        }

        const allItems = this.analysisResultsListTarget.querySelectorAll(
            '[data-controller="analysis-result-item"]'
        );

        let processed = 0;
        let errors    = 0;

        for (const itemElement of allItems) {
            // On ne traite que les items "new" non encore résolus
            if (
                !itemElement.classList.contains('border-info') ||
                itemElement.dataset.resolved === 'true'
            ) {
                continue;
            }

            // Récupérer le bouton "Créer l'avenant" de cet item
            const actionButton = itemElement.querySelector(
                '[data-action="click->analysis-result-item#handleAction"]'
            );
            if (!actionButton) continue;

            const payload = JSON.parse(actionButton.dataset.payload || '{}');
            const bordereauId = actionButton.closest('[data-analysis-result-item-bordereau-id-value]')
                ?.dataset?.analysisResultItemBordereauIdValue
                || this.bordereauIdValue;

            try {
                const response = await fetch(
                    `/admin/bordereau/api/simulate-action/${bordereauId}`,
                    {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            action_type:       'new',
                            avenant_id:        null,
                            excel_data:        payload.excel_data ?? {},
                            row_index:         payload.row_index ?? null,
                            reference_police:  payload.reference_police ?? null,
                        })
                    }
                );

                const result = await response.json();

                if (!response.ok || !result.success) {
                    throw new Error(result.message || 'Erreur lors du traitement.');
                }

                this._markItemAsResolved(itemElement, result.message);
                processed++;

            } catch (error) {
                console.error(
                    `[BordereauAnalysis] bulkCreateAll() — Erreur sur item:`,
                    error
                );
                errors++;
            }
        }

        console.log(
            `[BordereauAnalysis] bulkCreateAll() — Terminé. ` +
            `Traités: ${processed}, Erreurs: ${errors}`
        );

        // Rétablir le libellé du bouton
        if (this.hasBulkCreateButtonTarget) {
            this.bulkCreateButtonTarget.querySelector('span').textContent =
                'Créer tous les avenants';
        }

        // Réévaluer l'état de tous les boutons
        this._updateBulkButtonsState();
        this._updateValidateButtonState();

        // Feedback dans la barre d'outils
        if (this.hasMappingStatusFeedbackTarget) {
            this.mappingStatusFeedbackTarget.classList.remove('d-none');
            const msg = errors > 0
                ? `${processed} avenant(s) créé(s), ${errors} erreur(s).`
                : `${processed} avenant(s) créé(s) avec succès.`;
            this.mappingStatusFeedbackTarget.innerHTML = this.getFeedbackHtml(
                errors > 0 ? 'warning' : 'success', msg, false
            );
        }

        // Notifier le Cerveau pour réévaluation globale
        this.notifyCerveau('bordereau:bulk.completed', {
            bordereauId: this.bordereauIdValue,
            type: 'new',
            processed,
            errors
        });
    }

    /**
     * Traite en lot tous les items de type "discrepancy" non encore résolus.
     */
    async bulkUpdateAll() {
        if (!confirm(
            'Confirmez-vous la mise à jour en lot de tous les avenants en anomalie ?\n\n' +
            'Cette action traitera tous les items "Anomalie(s) détectée(s)".'
        )) return;

        if (this.hasBulkUpdateButtonTarget) {
            this.bulkUpdateButtonTarget.disabled = true;
            this.bulkUpdateButtonTarget.querySelector('span').textContent = 'Mise à jour en cours...';
        }

        const allItems = this.analysisResultsListTarget.querySelectorAll(
            '[data-controller="analysis-result-item"]'
        );

        let processed = 0;
        let errors    = 0;

        for (const itemElement of allItems) {
            // On ne traite que les items "discrepancy" non encore résolus
            if (
                !itemElement.classList.contains('border-warning') ||
                itemElement.dataset.resolved === 'true'
            ) {
                continue;
            }

            const actionButton = itemElement.querySelector(
                '[data-action="click->analysis-result-item#handleAction"]'
            );
            if (!actionButton) continue;

            const payload = JSON.parse(actionButton.dataset.payload || '{}');
            const bordereauId = actionButton.closest('[data-analysis-result-item-bordereau-id-value]')
                ?.dataset?.analysisResultItemBordereauIdValue
                || this.bordereauIdValue;

            try {
                const response = await fetch(
                    `/admin/bordereau/api/simulate-action/${bordereauId}`,
                    {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            action_type:       'discrepancy',
                            avenant_id:        payload.avenant_id ?? null,
                            excel_data:        payload.excel_data ?? {},
                            row_index:         payload.row_index ?? null,
                            reference_police:  payload.reference_police ?? null,
                        })
                    }
                );

                const result = await response.json();

                if (!response.ok || !result.success) {
                    throw new Error(result.message || 'Erreur lors du traitement.');
                }

                this._markItemAsResolved(itemElement, result.message);
                processed++;

            } catch (error) {
                console.error(
                    `[BordereauAnalysis] bulkUpdateAll() — Erreur sur item:`,
                    error
                );
                errors++;
            }
        }

        console.log(
            `[BordereauAnalysis] bulkUpdateAll() — Terminé. ` +
            `Traités: ${processed}, Erreurs: ${errors}`
        );

        if (this.hasBulkUpdateButtonTarget) {
            this.bulkUpdateButtonTarget.querySelector('span').textContent =
                'Mettre à jour tous les avenants';
        }

        this._updateBulkButtonsState();
        this._updateValidateButtonState();

        if (this.hasMappingStatusFeedbackTarget) {
            this.mappingStatusFeedbackTarget.classList.remove('d-none');
            const msg = errors > 0
                ? `${processed} avenant(s) mis à jour, ${errors} erreur(s).`
                : `${processed} avenant(s) mis à jour avec succès.`;
            this.mappingStatusFeedbackTarget.innerHTML = this.getFeedbackHtml(
                errors > 0 ? 'warning' : 'success', msg, false
            );
        }

        this.notifyCerveau('bordereau:bulk.completed', {
            bordereauId: this.bordereauIdValue,
            type: 'discrepancy',
            processed,
            errors
        });
    }

    /**
     * Gère la réception d'un échec d'analyse du Cerveau.
     * @param {object} payload - Le payload contenant le message d'erreur.
     */
    _handleAnalysisFailed(payload) {
        const { errorMessage } = payload;
        console.error("[BordereauAnalysis] _handleAnalysisFailed() - Échec de l'analyse:", errorMessage);
        this.submitButtonTarget.disabled = false; // Réactiver le bouton
        this.mappingStatusFeedbackTarget.classList.remove('d-none'); // Ensure feedback is visible
        this.mappingStatusFeedbackTarget.innerHTML = this.getFeedbackHtml('error', `Échec de l'analyse: ${errorMessage}`, false); // No icon for toolbar feedback
        this.toggleProgressBar(false);
    }

    /**
     * Affiche ou cache la barre de progression.
     */
    toggleProgressBar(isLoading) {
        // Use the local progress bar targets defined in this controller
        if (!this.hasProgressBarContainerTarget) return;
        this.progressBarContainerTarget.style.display = isLoading ? 'block' : 'none';
        if (isLoading) {
            this.progressBarTarget.style.animation = 'none';
            this.progressBarTarget.style.backgroundSize = '100% 100%';
            this.progressBarTarget.style.width = '0%';
            this.progressBarTarget.style.background = '#0047AB';
            this.progressBarTarget.style.transition = 'width 0.3s ease';
        } else {
            this.progressBarTarget.style.animation = '';
            this.progressBarTarget.style.width = '100%';
            this.progressBarTarget.style.background = '';
            this.progressBarTarget.style.transition = '';
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
                            mappedColumns[select.value] = select.dataset.columnLetter;
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
        this.toggleProgressBar(false);
    }

    /**
     * NOUVEAU: Gère la réception d'un échec de la sauvegarde de l'état du bordereau.
     * @param {object} payload - Le payload contenant le message d'erreur.
     */
    _handleSaveStateFailed(payload) {
        console.error("[BordereauAnalysis] _handleSaveStateFailed() - Échec de la sauvegarde de l'état:", payload.errorMessage);
        this.toggleProgressBar(false);
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
                // Si l'option est sélectionnée ailleurs ET n'est pas l'option actuellement sélectionnée dans ce select
                if (selectedValues.has(option.value) && option.value !== select.value) {
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
            return false; // Retourne false en cas d'échec
        } finally {
            console.log("[BordereauAnalysis] _handleSaveBordereauAnalysisStateLocal() - Fin de l'opération. Désactivation de la barre de progression.");
            
            // CORRECTION 3 : On remet le feu au vert uniquement si on l'avait passé au rouge
            if (this.isSaving) {
                this.isSaving = false;
                this.updateSubmitButtonState();
            }
        }
    }
}