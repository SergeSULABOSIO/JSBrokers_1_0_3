import { Controller } from '@hotwired/stimulus';
import { conditionRemplie } from './condition-action.js';
import { grouperActions } from './actions-groupees.js';

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

        // « Ajouter » reste VISIBLE mais INACTIF pour les entités qui n'existent que
        // rattachées à une autre (cf. `creation_interdite`, même drapeau que la barre
        // d'outils). Le masquer laisserait croire à un droit manquant ; grisé avec son
        // infobulle, il dit où créer. Le raccourci « A » suit : il ne fait que cliquer
        // ce bouton, qu'un `disabled` neutralise.
        if (this.hasBtAjouterTarget) {
            const creationInterdite = this.entityFormCanvas?.parametres?.creation_interdite === true;
            this.btAjouterTarget.classList.toggle('is-disabled', creationInterdite);
            this.btAjouterTarget.setAttribute('aria-disabled', creationInterdite ? 'true' : 'false');
            if (creationInterdite) {
                this.btAjouterTarget.setAttribute('title', "Une condition de partage se crée depuis la fiche d'un partenaire, d'un invité ou d'une piste.");
            } else {
                this.btAjouterTarget.removeAttribute('title');
            }
            if ('disabled' in this.btAjouterTarget) this.btAjouterTarget.disabled = creationInterdite;
        }

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
        // CASSE DE LA PROPRIÉTÉ, ET LE DÉFAUT ÉTAIT MUET. Ces deux gardes s'écrivaient
        // « hasBtouvrirTarget » / « hasBtsupprimerTarget » — Stimulus expose
        // « hasBtOuvrirTarget » / « hasBtSupprimerTarget ». Les expressions valaient donc
        // `undefined`, les blocs ne s'exécutaient JAMAIS, et « Ouvrir » comme
        // « Supprimer » restaient proposés — raccourcis O et S compris — alors qu'aucune
        // ligne n'était sélectionnée. Rien ne le signalait : une propriété inconnue ne
        // lève pas en JavaScript, elle vaut `undefined`.
        if (this.hasBtOuvrirTarget) {
            if (hasSelection) {
                this.btOuvrirTarget.classList.add("d-flex");
                this.btOuvrirTarget.style.display = 'flex';
            } else {
                this.btOuvrirTarget.classList.remove("d-flex");
                this.btOuvrirTarget.style.display = 'none';
            }
        }
        if (this.hasBtSupprimerTarget) {
            if (hasSelection) {
                this.btSupprimerTarget.classList.add("d-flex");
                this.btSupprimerTarget.style.display = 'flex';
            } else {
                this.btSupprimerTarget.classList.remove("d-flex");
                this.btSupprimerTarget.style.display = 'none';
            }
        }

        // Gérer les actions spécifiques avec filtrage conditionnel par état.
        // Une action déclarée `multi: true` est visible dès 1 sélection (unique ou
        // multiple) ; les autres restent strictement réservées à la sélection UNIQUE
        // (comportement historique inchangé).
        const rawActions  = this.entityFormCanvas?.parametres?.attribute_actions || [];
        const entityData  = this.entities[0]?.entity || {};
        const specificActions = rawActions.filter(action => {
            const countOk = action.multi === true ? hasSelection : isSingleSelection;
            if (!countOk) return false;
            return conditionRemplie(entityData, action.condition);
        });
        if (this.hasSpecificActionsContainerTarget) {
            this.updateSpecificActionButtons(specificActions);
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

        // REGROUPEMENT PAR FAMILLE (même règle que la barre d'outils, cf.
        // actions-groupees.js) : une famille devient UN item qui ouvre un sous-menu.
        // Pas de plafond ici : un menu vertical peut défiler, contrairement à la barre.
        grouperActions(actions).forEach((entree) => {
            this.specificActionsContainerTarget.appendChild(
                entree.type === 'groupe'
                    ? this._creerItemGroupe(entree, selectedId)
                    : this._creerItemAction(entree.action, selectedId),
            );
        });
    }

    /**
     * Item d'une action simple : icône + libellé, notifié au cerveau par la
     * délégation Stimulus historique (data-action / data-url / data-selection).
     * @private
     */
    _creerItemAction(action, selectedId) {
        const li = document.createElement('li');
        li.setAttribute('role', 'menuitem');
        li.setAttribute('tabindex', '-1');
        li.setAttribute('data-action', 'click->context-menu#notify');
        li.setAttribute('data-context-menu-event-name-param', action.event);
        li.dataset.url = action.url && action.url.includes('%id%') ? action.url.replace('%id%', selectedId) : action.url;
        li.dataset.selection = JSON.stringify(this.entities);

        li.appendChild(this._creerIcone(action.icon));

        const labelSpan = document.createElement('span');
        labelSpan.className = 'flex-grow-1';
        labelSpan.textContent = action.label;
        li.appendChild(labelSpan);

        const shortcutSpan = document.createElement('span');
        shortcutSpan.className = 'context-menu-shortcut';
        li.appendChild(shortcutSpan);

        return li;
    }

    /**
     * Item d'une FAMILLE : un sous-menu qui s'ouvre au survol et au clic, replié à
     * la sortie. Conforme au pattern WAI-ARIA Menu (aria-haspopup + aria-expanded,
     * sous-liste role="menu"). Le sous-menu se rabat à GAUCHE quand il déborderait
     * du viewport — le menu contextuel s'ouvre là où l'utilisateur a cliqué, souvent
     * près du bord droit.
     * @private
     */
    _creerItemGroupe(groupe, selectedId) {
        const li = document.createElement('li');
        li.setAttribute('role', 'menuitem');
        li.setAttribute('tabindex', '-1');
        li.setAttribute('aria-haspopup', 'menu');
        li.setAttribute('aria-expanded', 'false');
        li.className = 'context-menu-groupe';

        li.appendChild(this._creerIcone(groupe.icon));

        const labelSpan = document.createElement('span');
        labelSpan.className = 'flex-grow-1';
        labelSpan.textContent = groupe.label;
        li.appendChild(labelSpan);

        // Chevron « ouvre un sous-menu » : ::after CSS, pour survivre au remplacement
        // d'innerHTML que fait le circuit d'icônes sur ses conteneurs.
        const chevron = document.createElement('span');
        chevron.className = 'context-menu-shortcut context-menu-groupe-chevron';
        chevron.setAttribute('aria-hidden', 'true');
        li.appendChild(chevron);

        const sousMenu = document.createElement('ul');
        sousMenu.className = 'context-submenu';
        sousMenu.setAttribute('role', 'menu');
        sousMenu.setAttribute('aria-label', groupe.label);

        groupe.actions.forEach((action) => {
            sousMenu.appendChild(this._creerItemAction(action, selectedId));
        });
        li.appendChild(sousMenu);

        const ouvrir = () => {
            li.classList.add('is-open');
            li.setAttribute('aria-expanded', 'true');
            // Rabat vers la gauche si le sous-menu sortirait de l'écran.
            const rect = sousMenu.getBoundingClientRect();
            li.classList.toggle('submenu-left', rect.right > window.innerWidth - 8);
        };
        const fermer = () => {
            li.classList.remove('is-open', 'submenu-left');
            li.setAttribute('aria-expanded', 'false');
        };

        li.addEventListener('mouseenter', ouvrir);
        li.addEventListener('mouseleave', fermer);
        // Clic sur l'item de famille : bascule (et n'exécute rien — une famille
        // n'est pas une action). Le clic sur un MEMBRE remonte au handler du menu.
        li.addEventListener('click', (event) => {
            if (event.target.closest('.context-submenu')) return;
            event.stopPropagation();
            li.classList.contains('is-open') ? fermer() : ouvrir();
        });

        return li;
    }

    /**
     * Conteneur d'icône alimenté par le circuit d'icônes du cerveau (cache d'abord).
     * @private
     */
    _creerIcone(iconName) {
        const span = document.createElement('span');
        span.className = 'context-menu-icon';
        span.setAttribute('aria-hidden', 'true');
        if (!iconName) return span;

        span.id = `context-menu-icon-${iconName.replace(':', '--')}-${crypto.randomUUID()}`;
        if (this.iconCache.has(iconName)) {
            // Différé d'une microtâche : l'élément n'est pas encore dans le document,
            // et handleIconLoaded le retrouve par getElementById.
            queueMicrotask(() => this.handleIconLoaded({
                detail: { html: this.iconCache.get(iconName), requesterId: span.id, iconName },
            }));
        } else {
            this.notifyCerveau('ui:icon.request', { iconName, iconSize: 18, requesterId: span.id });
        }

        return span;
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