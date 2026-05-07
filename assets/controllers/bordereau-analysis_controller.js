import { Controller } from '@hotwired/stimulus';
/**
 * @class BordereauAnalysisController
 * @description Gère l'interface de l'analyse de bordereau en deux étapes.
 * Etape 1: Sélection de la feuille Excel.
 * Etape 2: Mappage des colonnes de la feuille sélectionnée.
 */
export default class extends Controller {
    static targets = [ // NOUVEAU : Ajout de la cible pour le bouton de retour
        "sheetSelection", "step2", "mappingContainer", "mappingStatusFeedback",
        "mappingSelect", "analysisResult", "submitButton", "columnNameText", "step1", "step3", "analysisResultsList", "progressBar",
        "backToMappingButton"
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
        analysisResults: Array
    };

    connect() {
        console.log("[BordereauAnalysisController] Connecté.");
        this.requiredMappings = new Set([
            'reference_police',
            'date_effet_avenant',
            'date_expiration_avenant',
            'prime_ttc', // NOUVEAU : Ajout de la prime TTC
            'date_operation', // Nouveau champ obligatoire
            'risque',         // Nouveau champ obligatoire
            'nom_client',
            'commission_ht_assureur',
            'taxe_commission_assureur',
            'taux_commission' // Nouveau champ obligatoire
            // NOUVEAU : Ajout de champs obligatoires pour l'étape 3
        ]);
        this.validationState = new Map(); // Stocke l'état de validation pour chaque colonne mappée

        // NOUVEAU : On ajoute les chargements reçus à la liste des champs à mapper.
        this.addChargementOptions();
        // NOUVEAU : On ajoute les types de revenu.
        this.addTypeRevenuOptions();
        // NOUVEAU : On ajoute les options de mappage système.
        this.addSystemOptions();
        // Initialise le feedback de mappage
        this.updateMappingStatusFeedback();
        
        this.currentStep = 1; // NOUVEAU : Initialise l'étape actuelle
        if (this.sheetSelectionTargets.length === 1 && this.sheetsDataValue && Object.keys(this.sheetsDataValue).length === 1) {
            this.showStep(2); // Si une seule feuille, passe directement à l'étape 2
        }
        this.updateSubmitButtonState();
        // NOUVEAU : Écoute l'événement de complétion de l'analyse du Cerveau
        this.boundHandleAnalysisCompleted = this._handleAnalysisCompleted.bind(this);
        document.addEventListener('bordereau:analysis-completed', this.boundHandleAnalysisCompleted);
        // NOUVEAU : Écoute l'événement d'échec de l'analyse du Cerveau
        this.boundHandleAnalysisFailed = this._handleAnalysisFailed.bind(this);
        document.addEventListener('bordereau:analysis-failed', this.boundHandleAnalysisFailed);
        this.updateSelectOptionsVisuals(); // Initialise la coloration des options
    }

    disconnect() {
        // Nettoie les écouteurs d'événements pour éviter les fuites de mémoire
        document.removeEventListener('bordereau:analysis-completed', this.boundHandleAnalysisCompleted);
        document.removeEventListener('bordereau:analysis-failed', this.boundHandleAnalysisFailed);
    }

    /**
     * NOUVEAU : Ajoute dynamiquement les chargements comme options dans tous les selects de mappage.
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
            // La valeur sera préfixée pour être facilement identifiable lors de la soumission.
            option.value = `chargement_${chargement.id}`;
            option.textContent = chargement.nom;
            optgroup.appendChild(option);
        });

        // On ajoute ce groupe d'options à chaque <select> de mappage.
        this.mappingSelectTargets.forEach(select => {
            select.appendChild(optgroup.cloneNode(true));
        });
    }

    /**
     * NOUVEAU : Ajoute dynamiquement les types de revenu comme options dans tous les selects de mappage.
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
            // La valeur sera préfixée pour être facilement identifiable lors de la soumission.
            option.value = `revenu_${revenu.id}`;
            option.textContent = revenu.nom;
            optgroup.appendChild(option);
        });

        // On ajoute ce groupe d'options à chaque <select> de mappage.
        this.mappingSelectTargets.forEach(select => {
            select.appendChild(optgroup.cloneNode(true));
        });
    }

    /**
     * NOUVEAU : Ajoute dynamiquement les options de mappage système comme options dans tous les selects.
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
     * @param {Event} event
     */
    // La méthode validateColumn est appelée lorsque l'utilisateur change une sélection.
    // Elle doit maintenant aussi mettre à jour le feedback et la coloration des options.
    validateColumn(event) {
        const selectElement = event.currentTarget;
        this.performValidation(selectElement);
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
        this.showStep(2, selectedSheetName);
    }

    /**
     * NOUVEAU : Gère la transition entre les étapes de l'analyse.
     * @param {number} stepNumber - Le numéro de l'étape à afficher (1, 2 ou 3).
     * @param {string} [sheetName=null] - Le nom de la feuille à afficher pour l'étape 2.
     */
    showStep(stepNumber, sheetName = null) {
        // Si l'événement est un clic, on récupère le numéro d'étape depuis le data-attribute
        const targetStep = (typeof stepNumber === 'object') ? parseInt(stepNumber.currentTarget.dataset.stepNumber) : stepNumber;
        this.currentStep = targetStep;
        this.step1Target.classList.add('d-none');
        this.step2Target.classList.add('d-none');
        this.step3Target.classList.add('d-none');

        // Cache les boutons de la barre d'outils par défaut
        this.submitButtonTarget.classList.add('d-none');
        this.backToMappingButtonTarget.classList.add('d-none');
        // NOUVEAU : Cacher le feedback quand on change d'étape
        this.mappingStatusFeedbackTarget.classList.add('d-none');

        if (targetStep === 1) {
            this.step1Target.classList.remove('d-none');
        } else if (targetStep === 2) {
            this.step2Target.classList.remove('d-none');
            this.submitButtonTarget.classList.remove('d-none'); // Affiche "Lancer l'analyse"
            this.mappingStatusFeedbackTarget.classList.remove('d-none'); // Affiche le conteneur de feedback
            this._showMappingUI(sheetName || this.sheetSelectionTargets.find(radio => radio.checked)?.value);
        } else if (targetStep === 3) {
            this.step3Target.classList.remove('d-none');
            this.backToMappingButtonTarget.classList.remove('d-none'); // Affiche "Retour au mappage"
            this.renderAnalysisResults();
        }
    }
    /**
     * Logique d'affichage de l'étape 2.
     * @param {string} sheetName - Le nom de la feuille à afficher. Si null, la première sera affichée.
     */
    _showMappingUI(sheetName = null) {
        // NOUVEAU : On affiche le bouton "Lancer l'analyse" et le feedback dans la barre d'outils
        // car on entre dans l'étape de mappage.
        this.mappingStatusFeedbackTarget.classList.remove('d-none');

        this.mappingContainerTargets.forEach(container => {
            const isTargetSheet = sheetName ? container.dataset.sheetName === sheetName : container.dataset.isFirst === 'true';
            // NOUVEAU : Utilise style.display pour la flexibilité, car d-none peut être surchargé.
            container.style.display = isTargetSheet ? 'block' : 'none';
        });
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
            resultCell.innerHTML = this.getFeedbackHtml('error', 'Données de la feuille introuvables.');
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
            resultCell.innerHTML = this.getFeedbackHtml('success', 'Données valides !');
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
        const activeForm = this.element.querySelector('.column-mapping-form:not([style*="display: none"])');
        if (!activeForm) {
            this.submitButtonTarget.disabled = true;
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
        this.submitButtonTarget.disabled = !(hasAllRequired && allValid);
        this.updateMappingStatusFeedback(); // Met à jour le feedback après chaque validation
    }

    /**
     * Génère le HTML pour un message de feedback.
     * @param {'success'|'error'} type
     * @param {string} message
     * @returns {string}
     */
    getFeedbackHtml(type, message) {
        const icon = type === 'success'
            ? '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-check-circle-fill me-1" viewBox="0 0 16 16"><path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0m-3.97-3.03a.75.75 0 0 0-1.08.022L7.477 9.417 5.384 7.323a.75.75 0 0 0-1.06 1.06L6.97 11.03a.75.75 0 0 0 1.079-.02l3.992-4.99a.75.75 0 0 0-.01-1.05z"/></svg>'
            : (type === 'warning'
                ? '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-exclamation-triangle-fill me-1" viewBox="0 0 16 16"><path d="M8.982 1.566a1.13 1.13 0 0 0-1.96 0L.165 13.233c-.457.778.091 1.767.98 1.767h13.713c.889 0 1.438-.99.98-1.767zM8 5c.535 0 .954.462.9.995l-.35 3.507a.552.552 0 0 1-1.1 0L7.1 5.995A.905.905 0 0 1 8 5m.002 6a1 1 0 1 1 0 2 1 1 0 0 1 0-2"/></svg>'
                : '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-x-circle-fill me-1" viewBox="0 0 16 16"><path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0M5.354 4.646a.5.5 0 1 0-.708.708L7.293 8l-2.647 2.646a.5.5 0 0 0 .708.708L8 8.707l2.646 2.647a.5.5 0 0 0 .708-.708L8.707 8l2.647-2.646a.5.5 0 0 0-.708-.708L8 7.293z"/></svg>');

        let textColorClass = '';
        if (type === 'success') {
            textColorClass = 'text-success';
        } else if (type === 'warning') {
            textColorClass = 'text-warning'; // Rendre le texte d'avertissement jaune pour les feedbacks hors toolbar
        } else { // error
            textColorClass = 'text-danger';
        }

        // Le CSS pour .actions-bar override la couleur pour le feedback de la toolbar.
        return `<span class="d-inline-flex align-items-center small ${textColorClass}">${icon} ${message}</span>`;
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
        this.updateSelectOptionsVisuals(); // Met à jour la coloration des options
    }

    /**
     * NOUVEAU : Soumet les données mappées au backend pour l'analyse.
     */
    submitAnalysis(event) { // Rendu synchrone pour que Stimulus le trouve toujours
        event.preventDefault();
        this._doSubmitAnalysis(); // Appelle la méthode asynchrone réelle
    }

    /**
     * NOUVEAU : Méthode asynchrone interne pour gérer la logique de soumission.
     */
    async _doSubmitAnalysis() {
        const activeForm = this.element.querySelector('.column-mapping-form:not([style*="display: none"])');
        if (!activeForm) {
            console.error("[BordereauAnalysisController] Aucun formulaire de mappage actif trouvé.");
            console.error("Aucun formulaire de mappage actif trouvé.");
            return;
        }

        const mappedColumns = {};
        const selects = activeForm.querySelectorAll('select[data-column-letter]');
        selects.forEach(select => {
            if (select.value) { // N'inclut que les colonnes mappées
                mappedColumns[select.value] = select.dataset.columnLetter;
            }
        });

        const selectedSheetName = activeForm.closest('[data-bordereau-analysis-target="mappingContainer"]').dataset.sheetName;
        const payload = {
            sheetName: selectedSheetName,
            mappedColumns: mappedColumns,
            sheetsData: this.sheetsDataValue // Envoyer toutes les données des feuilles pour que le backend puisse travailler avec
        };

        this.submitButtonTarget.disabled = true;
        this.submitButtonTarget.textContent = "Analyse en cours...";
        this.mappingStatusFeedbackTarget.innerHTML = this.getFeedbackHtml('warning', 'Analyse en cours...');
        this.toggleProgressBar(true);
        console.log("[BordereauAnalysisController] Préparation du payload pour l'analyse...", payload);
        // NOUVEAU : Notifier le Cerveau pour qu'il gère la soumission de l'analyse
        try {
            this.element.dispatchEvent(new CustomEvent('cerveau:event', {
                detail: {
                    type: 'bordereau:submit-analysis',
                    source: 'bordereau-analysis_controller',
                    payload: {
                        url: `/admin/bordereau/api/submit-analysis/${this.bordereauIdValue}`,
                        data: payload
                    },
                    timestamp: Date.now()
                },
                bubbles: true // L'événement doit remonter pour être intercepté par le Cerveau
            }));
            console.log("[BordereauAnalysisController] Événement 'bordereau:submit-analysis' envoyé au Cerveau.");
        } catch (error) {
            console.error("[BordereauAnalysisController] Erreur lors de la soumission de l'analyse:", error);
            console.error("Erreur lors de la soumission de l'analyse:", error);
            this.mappingStatusFeedbackTarget.innerHTML = this.getFeedbackHtml('error', `Erreur lors de l'analyse: ${error.message}`);
            this.submitButtonTarget.disabled = false;
            this.toggleProgressBar(false);
            this.submitButtonTarget.textContent = "Lancer l'analyse";
        }
    }

    /**
     * NOUVEAU : Rend les résultats de l'analyse des avenants.
     */
    renderAnalysisResults() {
        if (!this.hasAnalysisResultsListTarget) return;

        this.analysisResultsListTarget.innerHTML = ''; // Vide la liste précédente

        if (!this.analysisResultsValue || this.analysisResultsValue.length === 0) {
            this.analysisResultsListTarget.innerHTML = '<li class="list-group-item text-center text-muted">Aucun résultat d\'analyse à afficher.</li>';
            return;
        }

        this.analysisResultsValue.forEach(result => {
            const listItem = document.createElement('li');
            listItem.classList.add('list-group-item', 'd-flex', 'flex-column', 'gap-2');

            let statusClass = '';
            let statusIcon = '';
            if (result.type === 'new') {
                statusClass = 'list-group-item-info';
                statusIcon = '<i class="bi bi-plus-circle-fill text-info me-2"></i>';
            } else if (result.type === 'discrepancy') {
                statusClass = 'list-group-item-warning';
                statusIcon = '<i class="bi bi-exclamation-triangle-fill text-warning me-2"></i>';
            } else if (result.type === 'match') {
                statusClass = 'list-group-item-success';
                statusIcon = '<i class="bi bi-check-circle-fill text-success me-2"></i>';
            }

            if (statusClass) {
                listItem.classList.add(statusClass);
            }

            let bordereauInfoHtml = Object.entries(result.bordereau_line_info || {}).map(([key, value]) => `<strong>${key.replace(/_/g, ' ')}:</strong> ${value}`).join(' | ');

            let actionsHtml = result.actions.map(action =>
                `<a href="#" class="btn btn-sm btn-outline-primary" data-action="click->bordereau-analysis#handleAnalysisAction" data-event-name="${action.event}" data-payload='${JSON.stringify(action.payload)}'>${action.label}</a>`
            ).join(' ');

            listItem.innerHTML = `
                <div class="d-flex align-items-center">
                    ${statusIcon}
                    <h5 class="mb-0">${result.bordereau_line_info.reference_police || 'Avenant sans référence'}</h5>
                </div>
                <p class="mb-1 text-muted small">${bordereauInfoHtml}</p>
                <p class="mb-2">${result.details}</p>
                ${actionsHtml ? `<div class="d-flex gap-2">${actionsHtml}</div>` : ''}
            `;
            this.analysisResultsListTarget.appendChild(listItem);
        });
    }

    /**
     * NOUVEAU : Gère le clic sur un bouton d'action de l'étape 3.
     * Pour l'instant, ne fait que logguer l'événement.
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
     * NOUVEAU : Gère la réception des résultats d'analyse du Cerveau.
     * @param {CustomEvent} event
     */
    _handleAnalysisCompleted(event) {
        const { analysisResults } = event.detail;
        console.log("[BordereauAnalysisController] Reçu 'bordereau:analysis-completed' du Cerveau. Résultats:", analysisResults);
        this.analysisResultsValue = analysisResults;
        this.showStep(3); // Passe à l'étape 3
        this.submitButtonTarget.disabled = false;
        this.submitButtonTarget.textContent = "Lancer l'analyse";
        this.mappingStatusFeedbackTarget.classList.remove('d-none'); // S'assurer que le feedback est visible
        this.mappingStatusFeedbackTarget.innerHTML = this.getFeedbackHtml('success', 'Analyse terminée avec succès.');
        this.toggleProgressBar(false);
    }

    /**
     * NOUVEAU : Gère la réception d'un échec d'analyse du Cerveau.
     * @param {CustomEvent} event
     */
    _handleAnalysisFailed(event) {
        const { errorMessage } = event.detail;
        console.error("[BordereauAnalysisController] Reçu 'bordereau:analysis-failed' du Cerveau. Erreur:", errorMessage);
        this.submitButtonTarget.disabled = false;
        this.submitButtonTarget.textContent = "Lancer l'analyse";
        this.mappingStatusFeedbackTarget.classList.remove('d-none'); // S'assurer que le feedback est visible
        this.mappingStatusFeedbackTarget.innerHTML = this.getFeedbackHtml('error', `Échec de l'analyse: ${errorMessage}`);
        this.toggleProgressBar(false);
    }

    /**
     * NOUVEAU : Affiche ou cache la barre de progression.
     * @param {boolean} isLoading
     */
    toggleProgressBar(isLoading) {
        if (this.hasProgressBarTarget) {
            this.progressBarTarget.parentElement.style.display = isLoading ? 'block' : 'none';
        }
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
}