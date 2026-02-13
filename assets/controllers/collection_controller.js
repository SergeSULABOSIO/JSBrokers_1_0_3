import { Controller } from '@hotwired/stimulus';

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
        disabled: Boolean,
        entiteNom: String,
        idEntreprise: Number,
        idInvite: Number,
        context: Object,
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
        this.load();
    }

    disconnect() {
        document.removeEventListener('app:list.refresh-request', this.boundRefresh);
        // NOUVEAU : S'assurer que l'infobulle est retirée si le contrôleur est déconnecté
        if (this.tooltipElement) {
            this.tooltipElement.remove();
        }
    }

    /**
     * Charge ou recharge le contenu de la liste via AJAX.
     */
    async load() {
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
        if (this.disabledValue) return;

        this.contentPanelTarget.classList.toggle('is-open');
        const icon = this.element.querySelector('.toggle-icon');
        if (icon) {
            icon.textContent = this.contentPanelTarget.classList.contains('is-open') ? '-' : '+';
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
        };
 
        // Le contexte parent pour lier le nouvel élément.
        const parentContext = {
            id: this.parentEntityIdValue,
            fieldName: this.parentFieldNameValue
        };
 
        // On utilise le même événement que la barre d'outils pour une logique unifiée dans le cerveau.
        this.notifyCerveau('ui:toolbar.add-request', {
            formCanvas: formCanvas,
            context: context,
            parentContext: parentContext // On passe le contexte parent pour le lien
        });
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
        });
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
        const skeletonRow = `
            <tr>
                <td class="p-3 m-2" style="width:80px">
                    <div class="skeleton-line" style="width: 40px; height: 24px; border-radius: var(--bs-border-radius-sm);"></div>
                </td>
                <td class="p-2">
                    <div class="skeleton-line" style="width: 70%; height: 16px;"></div>
                    <div class="skeleton-line" style="width: 50%; height: 12px; margin-top: 8px;"></div>
                </td>
                <td class="text-end pe-3">
                    <div class="skeleton-line" style="width: 65px; height: 30px; border-radius: var(--bs-border-radius);"></div>
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
            // CORRECTION: On utilise 'block' pour que le texte d'aide reste en dessous du titre.
            // 'flex' mettrait le titre et l'aide sur la même ligne.
            this.titleContentTarget.style.display = isLoading ? 'none' : 'block';
        }
        
        // Gère l'affichage du squelette dans le contenu de l'accordéon.
        if (isLoading) {
            this.listContainerTarget.innerHTML = this._getSkeletonHtml();
        }
    }
}
