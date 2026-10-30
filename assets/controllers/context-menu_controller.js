import { Controller } from '@hotwired/stimulus';
import { actionsVisibles, grouperActions, urlAction } from './actions-groupees.js';
import { CircuitIcones } from './circuit-icones.js';
import { MenuContextuel } from './menu-contextuel.js';
import { ajoutOffert } from './ajout-regles.js';

/**
 * @class ContextMenuController
 * @extends Controller
 * @description Gère l'affichage, le positionnement et les actions d'un menu contextuel.
 * Le menu s'adapte en fonction de la sélection actuelle dans la liste. Ouverture, flèches,
 * Échap, fermetures : la coquille commune (menu-contextuel.js, chantier 3) ; les raccourcis
 * à une lettre (A, M, O, S, R, Q) restent ici, propres à cet écran.
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

        // Le circuit d'icônes est PARTAGÉ avec les barres d'actions (circuit-icones.js) :
        // même cache, mêmes identifiants uniques. Dans un menu, l'icône prend la couleur
        // du texte de son entrée.
        this.icones = new CircuitIcones(this.element, {
            prefixe: 'context-menu-icon',
            habiller: (svg) => { svg.style.color = 'inherit'; },
        });

        this.boundHandleContextUpdate = this.handleContextUpdate.bind(this);
        this.boundHandleKeyboardShortcuts = this.handleKeyboardShortcuts.bind(this);

        this.menuTarget.style.display = 'none';
        this.coquille = new MenuContextuel(this.menuTarget, {
            surFermeture: () => document.removeEventListener('keydown', this.boundHandleKeyboardShortcuts),
        });
        document.addEventListener('app:context.changed', this.boundHandleContextUpdate);

    }

    /**
     * Méthode du cycle de vie de Stimulus.
     * Nettoie les écouteurs pour éviter les fuites de mémoire.
     */
    disconnect() {
        document.removeEventListener('app:context.changed', this.boundHandleContextUpdate);
        this.hideContextMenu();
        this.icones.detruire();
    }

    /**
     * Met à jour l'état interne du contrôleur avec la sélection actuelle de l'application.
     * Affiche le menu si les coordonnées sont fournies.
     * @param {CustomEvent} event - L'événement `app:context.changed`.
     */
    handleContextUpdate(event) {
        // Le contexte d'un onglet EN ARRIÈRE-PLAN (réponse lente arrivée après un changement
        // d'onglet) ne concerne pas l'écran : il n'efface ni la sélection retenue ni le menu ouvert.
        if (event.detail?.arrierePlan) return;
        // UN MENU PAR RUBRIQUE OUVERTE, et chacun reçoit les contextes de toutes : seul celui de
        // la rubrique concernée répond. Sans cela, le menu d'un onglet CACHÉ s'ouvrait aussi —
        // et, la coquille n'en gardant qu'un ouvert, il pouvait fermer le bon (Serge, 2026-10-25).
        // Une autre rubrique passe au premier plan : ce menu-ci se ferme s'il était ouvert (ses
        // raccourcis A, M, S… joueraient sinon sur sa sélection depuis l'autre onglet) — fermer
        // son propre menu ne ferme jamais celui d'un autre.
        const onglet = this.element.closest('[data-tab-id]')?.dataset.tabId;
        if (onglet && event.detail?.workspaceTabId && event.detail.workspaceTabId !== onglet) {
            this.hideContextMenu();
            return;
        }
        // REFACTORING : On récupère maintenant le formCanvas depuis le niveau supérieur de l'événement,
        // car c'est un contexte partagé, et non plus spécifique à un élément sélectionné.
        const { selection: selectos, contextMenuPosition, formCanvas, tabId, champParent } = event.detail;
        this.entities = selectos;
        this.entityFormCanvas = formCanvas; // On utilise le formCanvas global
        // Comme la barre d'outils : un contexte sans onglet garde l'onglet connu.
        if (tabId) {
            this.ongletInterne = tabId;
            this.champParent = champParent ?? null;
        }

        // Si le payload contient la position du menu, on l'affiche — entrées triées AVANT la
        // mesure : la taille du menu est celle de la sélection courante.
        if (contextMenuPosition) {
            this.organizeButtons(this.entities);
            this._displayMenu(contextMenuPosition);
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
        // Le geste de la ligne (clavier : sous la ligne, première entrée) ; à défaut, le point.
        this.coquille.ouvrir(position.geste || { point: { x: position.menuX, y: position.menuY } });
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

        // « Ajouter » : la même décision que la barre d'outils (ajout-regles.js) — absent d'une
        // rubrique fermée à la création, et de la liste PRINCIPALE d'une entité qui ne naît que
        // rattachée à son parent (Serge, 2026-10-30 : absent, pas grisé). Sa lettre « A » ne joue
        // pas non plus : handleKeyboardShortcuts ignore une entrée masquée.
        if (this.hasBtAjouterTarget) {
            this.btAjouterTarget.style.display = ajoutOffert(this.entityFormCanvas?.parametres, this.ongletInterne || 'principal', this.champParent) ? '' : 'none';
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

        // Les actions spécifiques que la sélection permet : MÊME règle que la barre
        // d'outils (actions-groupees.js#actionsVisibles).
        const specificActions = actionsVisibles(this.entityFormCanvas?.parametres?.attribute_actions, this.entities);
        if (this.hasSpecificActionsContainerTarget) {
            this.updateSpecificActionButtons(specificActions);
        }
    }

    /**
     * Masque le menu contextuel.
     */
    hideContextMenu() {
        // La coquille retire aussi l'écoute des raccourcis (surFermeture).
        if (this.coquille) this.coquille.fermer();
    }

    /**
     * Gère l'action spécifique "Ouvrir" du menu contextuel pour corriger l'erreur.
     * @param {MouseEvent} event - L'événement de clic.
     */
    handleKeyboardShortcuts(event) {
        if (!this.coquille.estOuvert()) return;
        // Une lettre tapée avec Ctrl/Alt/Cmd n'est pas un raccourci du menu.
        if (event.ctrlKey || event.altKey || event.metaKey) return;

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

        // Une entrée inactive (« Ajouter » quand la création est interdite) ne se joue pas plus
        // par sa lettre que par Entrée : le <li> n'a pas de `disabled` qui l'en empêcherait.
        if (targetButton && targetButton.getAttribute('aria-disabled') === 'true') return;
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

        // Une action transverse n'a pas de ligne : sans cette précaution, ouvrir le menu
        // sur une liste sans sélection faisait échouer tout son rendu.
        const selectedId = this.entities[0]?.id ?? null;

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
        li.dataset.url = urlAction(action, selectedId) ?? '';
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
     * Conteneur d'icône d'une entrée, alimenté par le circuit partagé (cache d'abord).
     * @private
     */
    _creerIcone(iconName) {
        const span = document.createElement('span');
        span.className = 'context-menu-icon';
        span.setAttribute('aria-hidden', 'true');
        this.icones.poser(span, iconName, 18);

        return span;
    }

    /**
     * Méthode générique pour notifier le Cerveau d'une action.
     * @param {MouseEvent} event - L'événement de clic du bouton.
     */
    notify(event) {
        event.stopPropagation();
        const button = event.currentTarget;
        // Une entrée inactive (« Ajouter » quand la création est interdite) : survolable pour
        // dire pourquoi, elle ne joue pas — le menu reste ouvert (chantier 20).
        if (button.getAttribute('aria-disabled') === 'true') {
            event.preventDefault();
            return;
        }
        this.hideContextMenu();

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