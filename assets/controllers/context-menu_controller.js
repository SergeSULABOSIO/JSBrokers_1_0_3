import { Controller } from '@hotwired/stimulus';

/**
 * @class ContextMenuController
 * @extends Controller
 * @description Gère l'affichage, le positionnement et les actions d'un menu contextuel.
 * Le menu s'adapte en fonction de la sélection actuelle dans la liste.
 */
export default class extends Controller {
    /**
     * @property {HTMLElement[]} menuTargets - L'élément conteneur du menu.
     * @property {HTMLElement[]} btAjouterTargets - Le bouton "Ajouter".
     * @property {HTMLElement[]} btModifierTargets - Le bouton "Modifier".
     * @property {HTMLElement[]} btOuvrirTargets - Le bouton "Ouvrir".
     * @property {HTMLElement[]} btToutCocherTargets - Le bouton "Tout cocher".
     * @property {HTMLElement[]} btActualiserTargets - Le bouton "Actualiser".
     * @property {HTMLElement[]} btSupprimerTargets - Le bouton "Supprimer".
     * @property {HTMLElement[]} btParametrerTargets - Le bouton "Paramétrer".
     * @property {HTMLElement[]} btQuitterTargets - Le bouton "Quitter".
     */
    static targets = [
        'menu', 'btAjouter', 'btModifier', 'btOuvrir', 'btToutCocher',
        'btActualiser', 'btSupprimer', 'btParametrer', 'btQuitter',
        // NOUVEAU : Cibles pour les actions spécifiques
        'specificActionsContainer',
        'specificActionsSeparator'
    ];

    /**
     * Méthode du cycle de vie de Stimulus.
     * Initialise l'état et met en place les écouteurs.
     */
    connect() {
        this.nomControleur = "CONTEXT-MENU";
        console.log(`${this.nomControleur} - Connecté.`);

        // --- CORRECTION : Stockage de l'état de la sélection ---
        this.selection = [];
        this.entities = [];
        this.entityFormCanvas = null;

        // NOUVEAU : Cache pour les icônes, comme dans la barre d'outils
        this.iconCache = new Map();

        this.boundHandleContextUpdate = this.handleContextUpdate.bind(this);
        this.boundHideContextMenu = this.hideContextMenu.bind(this);
        this.boundHandleKeyboardShortcuts = this.handleKeyboardShortcuts.bind(this);

        this.menuTarget.style.display = 'none';
        document.addEventListener('click', this.boundHideContextMenu, false);
        document.addEventListener('app:context.changed', this.boundHandleContextUpdate);

        // NOUVEAU : Écouteur pour la réception des icônes
        this.boundHandleIconLoaded = this.handleIconLoaded.bind(this);
        document.addEventListener('app:icon.loaded', this.boundHandleIconLoaded);
    }

    /**
     * Méthode du cycle de vie de Stimulus.
     * Nettoie les écouteurs pour éviter les fuites de mémoire.
     */
    disconnect() {
        document.removeEventListener('click', this.boundHideContextMenu, false);
        document.removeEventListener('app:context.changed', this.boundHandleContextUpdate);
        document.removeEventListener('app:icon.loaded', this.boundHandleIconLoaded);
        document.removeEventListener('keydown', this.boundHandleKeyboardShortcuts);
    }

    /**
     * Met à jour l'état interne du contrôleur avec la sélection actuelle de l'application.
     * Affiche le menu si les coordonnées sont fournies.
     * @param {CustomEvent} event - L'événement `app:context.changed`.
     */
    handleContextUpdate(event) {
        // REFACTORING : On récupère maintenant le formCanvas depuis le niveau supérieur de l'événement,
        // car c'est un contexte partagé, et non plus spécifique à un élément sélectionné.
        const { selection: selectos, contextMenuPosition, formCanvas } = event.detail;
        this.entities = selectos;
        this.entityFormCanvas = formCanvas; // On utilise le formCanvas global

        // Si le payload contient la position du menu, on l'affiche.
        if (contextMenuPosition) {
            this._displayMenu(contextMenuPosition);
            this.organizeButtons(this.entities);
        } else {
            // S'il n'y a pas de position, on s'assure que le menu est caché (cas d'un clic gauche).
            this.hideContextMenu();
        }
    }

    /**
     * NOUVEAU : Positionne et affiche le menu.
     * @param {object} position - L'objet contenant menuX et menuY.
     * @private
     */
    _displayMenu(position) {
        const { menuX, menuY } = position;
        const menuWidth = this.menuTarget.offsetWidth;
        const menuHeight = this.menuTarget.offsetHeight;
        const windowWidth = window.innerWidth;
        const windowHeight = window.innerHeight;

        let left = (menuX + menuWidth > windowWidth) ? windowWidth - menuWidth - 5 : menuX;
        let top = (menuY + menuHeight > windowHeight) ? windowHeight - menuHeight - 5 : menuY;

        this.menuTarget.style.left = `${left}px`;
        this.menuTarget.style.top = `${top}px`;
        this.menuTarget.style.display = 'block';

        document.addEventListener('keydown', this.boundHandleKeyboardShortcuts);
    }

    /**
     * Affiche ou masque les boutons du menu en fonction de la sélection.
     * @param {Array<string>} selection - Le tableau des IDs sélectionnés.
     * @private
     */
    organizeButtons(selectos) {
        const hasSelection = selectos.length > 0;
        const isSingleSelection = selectos.length === 1;

        console.log(this.nomControleur + " - organizeButtons - Code: 8888 - Selection:", selectos, "Length:", selectos.length, "Single:", isSingleSelection, "Has:", hasSelection);
        
        // console.log(this.nomControleur + " - organizeButtons - Code: 8888 - hasBtModifierTarget:", this.hasBtModifierTarget);
        if (this.hasBtModifierTarget) {
            if (isSingleSelection) {
                this.btModifierTarget.classList.add("d-flex");
                this.btModifierTarget.style.display = 'flex';
            } else {
                this.btModifierTarget.classList.remove("d-flex");
                this.btModifierTarget.style.display = 'none';
            }
        }
        if (this.hasBtouvrirTarget) {
            if (hasSelection) {
                this.btOuvrirTarget.classList.add("d-flex");
                this.btOuvrirTarget.style.display = 'flex';
            } else {
                this.btOuvrirTarget.classList.remove("d-flex");
                this.btOuvrirTarget.style.display = 'none';
            }
        }
        if (this.hasBtsupprimerTarget) {
            if (hasSelection) {
                this.btSupprimerTarget.classList.add("d-flex");
                this.btSupprimerTarget.style.display = 'flex';
            } else {
                this.btSupprimerTarget.classList.remove("d-flex");
                this.btSupprimerTarget.style.display = 'none';
            }
        }

        // NOUVEAU : Gérer les actions spécifiques
        const specificActions = this.entityFormCanvas?.parametres?.attribute_actions || [];
        const canShowSpecificActions = isSingleSelection && specificActions.length > 0;
        if (this.hasSpecificActionsContainerTarget) {
            this.updateSpecificActionButtons(canShowSpecificActions ? specificActions : []);
        }
    }

    /**
     * Masque le menu contextuel.
     */
    hideContextMenu() {
        if (this.hasMenuTarget) {
            this.menuTarget.style.display = 'none';
            // Désactive l'écoute des raccourcis clavier quand le menu est caché
            document.removeEventListener('keydown', this.boundHandleKeyboardShortcuts);
        }
    }

    /**
     * Gère l'action spécifique "Ouvrir" du menu contextuel pour corriger l'erreur.
     * @param {MouseEvent} event - L'événement de clic.
     */
    handleKeyboardShortcuts(event) {
        if (this.menuTarget.style.display !== 'block') return;

        const key = event.key.toUpperCase();
        let targetButton = null;

        switch (key) {
            case 'A':
                targetButton = this.btAjouterTarget;
                break;
            case 'M':
                targetButton = this.btModifierTarget;
                break;
            case 'O':
                targetButton = this.btOuvrirTarget;
                break;
            case 'S':
                targetButton = this.btSupprimerTarget;
                break;
            case 'R':
                targetButton = this.btActualiserTarget;
                break;
            case 'Q':
                targetButton = this.btQuitterTarget;
                break;
        }

        if (targetButton && targetButton.style.display !== 'none') {
            event.preventDefault();
            targetButton.click();
        }
    }

    /**
     * NOUVEAU : Crée et affiche les boutons pour les actions spécifiques dans le menu.
     * @param {Array} actions - Le tableau de configuration des actions.
     * @private
     */
    updateSpecificActionButtons(actions) {
        this.specificActionsContainerTarget.innerHTML = '';
        this.specificActionsSeparatorTarget.style.display = actions.length > 0 ? 'block' : 'none';

        if (actions.length === 0) return;

        const selectedId = this.entities[0].id;

        actions.forEach(action => {
            const finalUrl = action.url.includes('%id%') ? action.url.replace('%id%', selectedId) : action.url;

            const li = document.createElement('li');
            // CORRECTION : Utilisation des mêmes classes que les autres options pour un alignement parfait.
            li.className = 'd-flex align-items-center gap-3';
            li.setAttribute('data-action', 'click->context-menu#notify');
            li.setAttribute('data-context-menu-event-name-param', action.event);
            // On stocke les données nécessaires directement sur l'élément <li>
            li.dataset.url = finalUrl;
            li.dataset.selection = JSON.stringify(this.entities);

            const iconSpan = document.createElement('span');
            // CORRECTION : Utilisation de la bonne classe pour l'icône.
            iconSpan.className = 'context-menu-icon';
            iconSpan.id = `context-menu-icon-${action.icon.replace(':', '--')}-${crypto.randomUUID()}`;
            li.appendChild(iconSpan);

            const labelSpan = document.createElement('span');
            labelSpan.className = 'flex-grow-1';
            labelSpan.textContent = action.label;
            li.appendChild(labelSpan);

            // CORRECTION : Ajout du conteneur pour le raccourci, même s'il est vide, pour maintenir l'alignement.
            const shortcutSpan = document.createElement('span');
            shortcutSpan.className = 'context-menu-shortcut';
            li.appendChild(shortcutSpan);

            this.specificActionsContainerTarget.appendChild(li);

            // On charge l'icône
            if (this.iconCache.has(action.icon)) {
                this.handleIconLoaded({ detail: { html: this.iconCache.get(action.icon), requesterId: iconSpan.id, iconName: action.icon } });
            } else {
                this.notifyCerveau('ui:icon.request', {
                    iconName: action.icon,
                    iconSize: 18,
                    requesterId: iconSpan.id
                });
            }
        });
    }

    /**
     * NOUVEAU : Gère la réception du HTML de l'icône et l'injecte.
     * @param {CustomEvent} event
     */
    handleIconLoaded(event) {
        const { html, requesterId, iconName } = event.detail;

        if (iconName && html && !html.trim().startsWith('<!--')) {
            this.iconCache.set(iconName, html);
        }

        // On ne traite que les requêtes venant de ce contrôleur
        if (requesterId && requesterId.startsWith('context-menu-icon-')) {
            const iconContainer = document.getElementById(requesterId);
            if (iconContainer && html && !html.trim().startsWith('<!--')) {
                // Injection robuste de l'icône
                iconContainer.innerHTML = '';
                const template = document.createElement('template');
                template.innerHTML = html.trim();
                if (template.content.firstChild) {
                    // On s'assure que l'icône SVG a la bonne couleur pour le menu sombre
                    const svg = template.content.firstChild;
                    if (svg.tagName.toLowerCase() === 'svg') {
                        svg.style.color = 'inherit'; // Hérite la couleur du texte du menu
                    }
                    iconContainer.appendChild(svg);
                }
            }
        }
    }

    /**
     * Méthode générique pour notifier le Cerveau d'une action.
     * @param {MouseEvent} event - L'événement de clic du bouton.
     */
    notify(event) {
        event.stopPropagation();
        this.hideContextMenu();

        const button = event.currentTarget;
        // CORRECTION : On récupère les données depuis l'élément <li>
        const eventName = button.dataset.contextMenuEventNameParam || button.dataset.eventName;

        if (!eventName) {
            console.error("L'élément de menu n'a pas de 'data-context-menu-event-name-param' ou 'data-event-name' défini.", button);
            return;
        }

        // Le payload est maintenant générique, comme pour la barre d'outils.
        // C'est au cerveau de l'interpréter.
        const payload = {
            // NOUVEAU : On ajoute l'URL pour les actions spécifiques
            url: button.dataset.url,
            // On garde la logique existante pour les autres boutons
            selection: this.entities, // Envoie la sélection complète (objets selecto)
            formCanvas: this.entityFormCanvas, // Envoie le contexte du formulaire actif (celui de l'onglet)
        };

        this.notifyCerveau(eventName, payload);
    }

    /**
     * Méthode centralisée pour envoyer un événement au Cerveau.
     * @param {string} type - Le type d'événement (ex: 'ui:toolbar.add-request').
     * @param {object} [payload={}] - Les données à envoyer.
     * @private
     */
    notifyCerveau(type, payload = {}) {
        console.log(`${this.nomControleur} - Notification du Cerveau: ${type}`, payload);
        const event = new CustomEvent('cerveau:event', {
            bubbles: true,
            detail: { type, source: this.nomControleur, payload, timestamp: Date.now() }
        });
        this.element.dispatchEvent(event);
    }
}