import { Controller } from '@hotwired/stimulus';
import { actionsVisibles, urlAction } from './actions-groupees.js';
import { BarreActions } from './barre-actions.js';
import { MenuContextuel } from './menu-contextuel.js';
import { DefilementRail } from './rail-defilement.js';
import { outilsAuRail } from './rail-outils.js';
import { expliquerUnRefus, fermerInfobulle } from './infobulle-moteur.js';

/**
 * @class ToolbarController - REFACTORED (V3)
 * @extends Controller
 * @description Gère la barre d'outils principale. Son rôle est de :
 * 1. Écouter `ui:selection.changed` (via le Cerveau) pour mettre à jour la liste des éléments sélectionnés (`selectos`).
 * 2. Écouter `ui:tab.context-changed` (via le Cerveau) pour mettre à jour le contexte de formulaire actif (`entityFormCanvas`).
 * 3. Ajuster la visibilité des boutons en fonction du nombre d'éléments sélectionnés.
 * 4. Notifier le Cerveau des actions initiées par l'utilisateur (Ajouter, Supprimer, etc.), en préfixant tous les événements par `ui:toolbar.`.
 */
export default class extends Controller {
    /**
     * @property {HTMLElement} btquitterTarget - Le bouton pour quitter la rubrique.
     * @property {HTMLElement} btparametresTarget - Le bouton pour les paramètres.
     * @property {HTMLElement} btrechargerTarget - Le bouton pour recharger la liste.
     * @property {HTMLElement} btajouterTarget - Le bouton pour ajouter un élément.
     * @property {HTMLElement} btmodifierTarget - Le bouton pour modifier un élément.
     * @property {HTMLElement} btsupprimerTarget - Le bouton pour supprimer un ou plusieurs éléments.
     * @property {HTMLElement} bttoutcocherTarget - Le bouton pour tout cocher/décocher.
     * @property {HTMLElement} btouvrirTarget - Le bouton pour ouvrir un ou plusieurs éléments.
     */
    static targets = [
        'btquitter',
        'btparametres',
        'btrecharger',
        'btajouter',
        'btmodifier',
        'btsupprimer',
        'bttoutcocher',
        'btouvrir',
        'specificActionsSeparator', // NOUVEAU : Séparateur
        'specificActionsContainer', // NOUVEAU : Conteneur pour les boutons
        'btplus',                   // « Plus » : Sélectionner tout, Actualiser, Paramètres, Quitter
        'menuPlus',
        'defilant',                 // la zone du rail qui défile en dernier recours
        'outilsRail',               // les outils de la rubrique, au rail autant qu'il en tient
        'separateurPlus',           // le séparateur de « Plus », avant Quitter
        'piedRail',                 // le pied du rail, ancré en bas : outils et « Plus »
    ];

    /**
     * @property {ObjectValue} entityFormCanvasValue - La configuration (canvas) du formulaire d'édition/création
     * pour l'entité de la rubrique actuelle. Fourni par le serveur.
     */
    static values = {
        entityFormCanvas: Object,
    }

    /**
     * Méthode du cycle de vie de Stimulus.
     * S'exécute lorsque le contrôleur est connecté au DOM.
     */
    connect() {
        this.nomControleur = "Toolbar";
        console.log(`${this.nomControleur} - Connecté`);
        this.initialize();
    }

    /**
     * Initialise les propriétés et les écouteurs d'événements.
     * @private
     */
    initialize() {
        // CORRECTION : Définir le nom du contrôleur en premier pour que les événements envoyés depuis initialize() soient valides.
        this.nomControleur = "Toolbar";

        // Isolation multi-onglets : détecte l'onglet workspace parent
        const workspacePanel = this.element.closest('[data-tab-id]');
        this.workspaceTabId = workspacePanel ? workspacePanel.dataset.tabId : null;

        this.selectos = [];
        this.activeFormCanvas = this.entityFormCanvasValue;
        
        // UNE SEULE fonction liée : initialize() passe deux fois (Stimulus, puis connect()).
        // Deux bind() distincts posaient deux écouteurs, dont disconnect() n'ôtait que le
        // second — le premier survivait et touchait une barre détruite (barreActions nul).
        this.boundHandleContextUpdate ??= this.handleContextUpdate.bind(this);

        // Le RENDU des actions spécifiques (boutons, familles, menus, icônes, clavier) est
        // partagé avec la barre du dialogue d'entité : barre-actions.js. La barre ne
        // garde ici que ce qui lui est propre — quelles actions, et ce qu'un clic envoie.
        //
        // UNE SEULE INSTANCE : Stimulus appelle initialize() avant connect(), qui le
        // rappelle. Deux barres, ce seraient deux écouteurs clavier — chaque flèche
        // sauterait alors deux boutons.
        this.barreActions ??= new BarreActions(this.specificActionsContainerTarget, {
            declencher: (action) => this._declencher(action),
            prefixe: 'toolbar-action',
            barre: this.element,
        });

        // « PLUS » PASSE PAR LA COQUILLE COMMUNE (menu-contextuel.js) : clavier, Échap, clic
        // dehors, un seul menu ouvert dans la page. Une entrée jouée referme le menu ; son
        // geste, lui, est celui qu'elle avait au rail (`notify`, même événement du Cerveau).
        if (this.hasMenuPlusTarget && !this.menuPlus) {
            // `hidden` (et non un `style=` en ligne) : rendu masqué par le serveur.
            this.menuPlus = new MenuContextuel(this.menuPlusTarget, { affichage: 'hidden' });
            this.menuPlusTarget.addEventListener('click', (event) => {
                if (event.target.closest('[role="menuitem"]')) this.menuPlus.fermer();
            });
        }

        // Le rail défile en DERNIER RECOURS, et le dit (flèches, fondus) — rail-defilement.js.
        if (this.hasDefilantTarget && !this.defilementRail) {
            this.defilementRail = new DefilementRail(this.element, this.defilantTarget);
        }
        // Les outils reviennent au rail AUSSITÔT qu'un espace se dégage : la fenêtre change de
        // hauteur (ici) ; la sélection change les boutons (organizeButtons).
        if (this.hasDefilantTarget && !this.observateurOutils && typeof ResizeObserver !== 'undefined') {
            this.observateurOutils = new ResizeObserver(() => this._placerLesOutils());
            this.observateurOutils.observe(this.defilantTarget);
        }

        this.initializeToolbarState();
        this.setupEventListeners();

        // Pré-charger les icônes spécifiques dès le début.
        this.barreActions.precharger(this.activeFormCanvas?.parametres?.attribute_actions);
    }

    /**
     * Met en place les écouteurs d'événements globaux.
     * La barre d'outils écoute uniquement le Cerveau pour ajuster son état.
     * @private
     */
    setupEventListeners() {
        document.addEventListener('app:context.changed', this.boundHandleContextUpdate); // NOUVEAU : Écoute le changement de contexte global
    }

    /**
     * Gère la mise à jour du contexte reçue du Cerveau (sélection, onglet actif, etc.).
     * @param {CustomEvent} event - L'événement `ui:selection.changed`.
     */
    handleContextUpdate(event) {
        if (this.workspaceTabId && event.detail.workspaceTabId !== this.workspaceTabId) return;
        const { selection, formCanvas } = event.detail;
        this.selectos = selection || [];
        this.activeFormCanvas = formCanvas || {};
        this.organizeButtons();
    }

    /**
     * Méthode du cycle de vie de Stimulus.
     * Nettoie les écouteurs pour éviter les fuites de mémoire lors de la déconnexion.
     */
    disconnect() {
        document.removeEventListener('app:context.changed', this.boundHandleContextUpdate);
        this.barreActions?.detruire();
        this.barreActions = null;
        this.menuPlus?.fermer({ rendreLeFocus: false });
        this.defilementRail?.detruire();
        this.defilementRail = null;
        this.observateurOutils?.disconnect();
        this.observateurOutils = null;
    }

    /**
     * LES OUTILS DE LA RUBRIQUE, AU RAIL AUTANT QU'IL EN TIENT (décision de Serge, 2026-10-28).
     *
     * Sélectionner tout, Actualiser, Paramètres, Quitter : les premiers qui tiennent sont au rail,
     * les autres dans « Plus », qui disparaît quand tous tiennent. La règle vit dans
     * rail-outils.js ; ici, on MESURE et on applique. `base` exclut ce que la règle fait varier
     * (les outils, « Plus ») : elle ne peut pas osciller.
     * @private
     */
    _placerLesOutils() {
        if (!this.hasOutilsRailTarget || !this.hasDefilantTarget || !this.hasMenuPlusTarget || !this.hasPiedRailTarget) return;
        const zone = this.defilantTarget;
        const disponible = zone.clientHeight;
        if (!disponible) return; // onglet en arrière-plan : on mesurera à l'affichage

        const style = getComputedStyle(zone);
        const ecart = parseFloat(style.rowGap) || 0;
        const hauteur = (e) => {
            const s = getComputedStyle(e);
            return e.getBoundingClientRect().height + (parseFloat(s.marginTop) || 0) + (parseFloat(s.marginBottom) || 0);
        };
        // Tout ce qui précède le pied (actions), puis l'écart et le séparateur du pied : c'est la
        // part que la règle ne fait pas varier. Le reste du pied — outils et « Plus » — est ce
        // qu'elle décide.
        const pied = this.piedRailTarget;
        const presents = [...zone.children].filter((e) => e !== pied && getComputedStyle(e).display !== 'none');
        const separateur = pied.querySelector(':scope > .toolbar-separator');
        const base = (parseFloat(style.paddingTop) || 0) + (parseFloat(style.paddingBottom) || 0)
            + presents.reduce((total, e) => total + hauteur(e), 0) + ecart * presents.length
            + (separateur ? hauteur(separateur) : 0);
        // La hauteur d'un bouton du rail, mesurée sur un bouton affiché, puis gardée.
        const modele = [...zone.querySelectorAll('button')].find((b) => !this.menuPlusTarget.contains(b) && b.offsetHeight > 0);
        if (modele) this.hauteurBouton = modele.offsetHeight;

        const outils = [...this.outilsRailTarget.querySelectorAll('[data-outil]')];
        const auRail = outilsAuRail({ disponible, base, bouton: (this.hauteurBouton || 0) + ecart, nombre: outils.length });
        if (auRail === null || auRail === this.outilsAuRail) return;
        this.outilsAuRail = auRail;

        // Le focus est-il sur un outil qui va changer de place ? On le suivra.
        const actif = this.element.contains(document.activeElement) ? document.activeElement : null;

        // Au rail : les premiers ; dans « Plus » : les autres. Un seul des deux exemplaires
        // d'un outil est affiché (`style.display` : la feuille de style l'emporterait sur `hidden`).
        // Les deux exemplaires se reconnaissent par leur `data-outil`, jamais par leur rang.
        const rang = (e) => Number(e.dataset.outil);
        outils.forEach((bouton) => { bouton.style.display = rang(bouton) < auRail ? '' : 'none'; });
        this.outilsRailTarget.style.display = auRail > 0 ? '' : 'none';
        const entrees = [...this.menuPlusTarget.querySelectorAll('[data-outil]')];
        entrees.forEach((entree) => { entree.style.display = rang(entree) < auRail ? 'none' : ''; });
        // Le séparateur ne précède Quitter dans « Plus » que si d'autres outils y restent.
        this.separateurPlusTarget.style.display = auRail < outils.length - 1 ? '' : 'none';
        const tousAuRail = auRail === outils.length;
        if (tousAuRail) this.menuPlus?.fermer({ rendreLeFocus: false });
        this.btplusTarget.style.display = tousAuRail ? 'none' : '';

        // LE FOCUS SUIT L'OUTIL : masqué sous le clavier, il tombait sur la page. On le pose sur
        // l'autre exemplaire (au rail), ou sur « Plus » quand l'outil y est parti.
        if (actif && actif.getClientRects().length === 0) {
            const outil = actif.dataset.outil ?? this.dernierOutil;
            const pendant = outil !== undefined ? outils.find((b) => b.dataset.outil === String(outil)) : null;
            const cible = [pendant, this.btplusTarget, ...this._boutonsDuRail()].find((b) => b && b.getClientRects().length > 0);
            cible?.focus({ preventScroll: true });
        }
        // Les boutons ont bougé : une bulle ouverte désignerait un autre bouton.
        fermerInfobulle(this.element);

        this.barreActions?.rafraichirNavigation();
        this.defilementRail?.marquer();
    }

    /** Les boutons du rail (hors menus). @private */
    _boutonsDuRail() {
        return [...this.defilantTarget.querySelectorAll('button')].filter((b) => !this.menuPlusTarget.contains(b));
    }

    /**
     * Une flèche du rail : une « page » vers le haut ou le bas.
     * @param {{params: {sens: number}}} event
     */
    defiler(event) {
        this.defilementRail?.avancer(Number(event.params.sens) < 0 ? -1 : 1);
    }

    /**
     * Une flèche n'est pas focalisable : la presser renverrait le focus sur la page. On le
     * laisse là où il était (le bouton du rail qu'on parcourait).
     * @param {MouseEvent} event
     */
    garderLeFocus(event) {
        event.preventDefault();
    }

    /**
     * Ouvre ou referme « Plus », à DROITE de son bouton : dans un rail vertical, un menu
     * ouvert dessous recouvrirait les boutons suivants.
     * @param {MouseEvent} event
     */
    basculerPlus(event) {
        if (!this.menuPlus) return;
        if (this.menuPlus.estOuvert()) {
            this.menuPlus.fermer();
            return;
        }
        const r = this.btplusTarget.getBoundingClientRect();
        this.menuPlus.ouvrir({
            ancre: { left: r.right, right: r.right, top: r.top, bottom: r.top },
            alignement: 'gauche',
            declencheur: this.btplusTarget,
            // Le focus revient au bouton à la fermeture, même quand le clic ne l'y avait pas
            // posé (certains navigateurs ne focalisent pas un bouton cliqué).
            retour: this.btplusTarget,
            // Au clavier (Entrée, Espace : `detail` vaut 0), le focus va à la première entrée.
            viaClavier: event.detail === 0,
        });
    }

    /**
     * Affiche ou masque les boutons contextuels en fonction de la sélection actuelle.
     * La logique est centralisée ici et se base sur le nombre d'éléments dans `this.selectos`.
     * @private
     */
    organizeButtons() {
        const selectionCount = this.selectos?.length || 0;
        const canvasParams = this.activeFormCanvas?.parametres || {};

        // Conditions de visibilité basées sur la sélection ET les permissions du canvas.
        const canAdd = !!canvasParams.endpoint_submit_url;
        // `creation_interdite` : certaines entités n'existent que RATTACHÉES à une autre
        // (une condition de partage sans bénéficiaire ni affaire n'est qu'une règle
        // orpheline). Leur rubrique reste consultable et éditable, mais la création se
        // fait depuis la fiche parente.
        //
        // Le bouton est GRISÉ, PAS MASQUÉ : un bouton qui disparaît laisse croire à un
        // droit manquant ou à un bug ; désactivé avec son infobulle, il dit où aller.
        const creationInterdite = canvasParams.creation_interdite === true;
        // LE MOTIF VIENT DU CANEVAS. Il était écrit ici, au nom des conditions de
        // partage — la seule rubrique qui posait alors le drapeau. La deuxième (les
        // reversements de rétrocommission) aurait donc annoncé à l'utilisateur d'aller
        // créer sa pièce « depuis la fiche d'un partenaire ». Chaque rubrique dit
        // désormais où l'on crée la sienne.
        const motifCreation = canvasParams.creation_interdite_message
            || 'Cet enregistrement se crée depuis la fiche à laquelle il se rattache.';
        const canEdit = selectionCount === 1 && !!canvasParams.endpoint_submit_url;
        const canDelete = selectionCount > 0 && !!canvasParams.endpoint_delete_url;
        const canOpen = selectionCount > 0; // L'ouverture est généralement toujours possible si sélection.

        // Règle : "Ajouter" est visible si le canvas le permet.
        this.toggleButton(this.btajouterTarget, canAdd);
        this.setButtonDisabled(this.btajouterTarget, creationInterdite, motifCreation);

        // Règle : "Modifier" est visible uniquement pour une sélection unique.
        this.toggleButton(this.btmodifierTarget, canEdit);

        // Règle : "Ouvrir" est visible dès qu'il y a au moins une sélection (unique ou multiple).
        this.toggleButton(this.btouvrirTarget, canOpen);

        // Règle : "Supprimer" est visible dès qu'il y a au moins une sélection (unique ou multiple).
        this.toggleButton(this.btsupprimerTarget, canDelete);

        // Les actions spécifiques que la sélection permet : MÊME règle que le menu
        // contextuel (actions-groupees.js#actionsVisibles) — portées `sans_selection`,
        // `multi` ou sélection unique, puis la condition de chaque action.
        this.updateSpecificActionButtons(actionsVisibles(canvasParams.attribute_actions, this.selectos));
        // Les boutons d'action ont pu changer : une bulle ouverte désignerait un autre bouton.
        fermerInfobulle(this.element);
        // La sélection change les boutons : les outils gagnent ou cèdent leur place au rail.
        this._placerLesOutils();
    }

    /**
     * Méthode utilitaire pour afficher/masquer un bouton cible.
     * @param {HTMLElement | undefined} target - Le bouton cible Stimulus (peut être optionnel).
     * @param {boolean} show - `true` pour afficher, `false` pour masquer.
     * @private
     */
    toggleButton(target, show) {
        if (!target) return;
        // Visible : on rend la main à la feuille de style (rail en colonne, entrée de menu en
        // ligne) au lieu d'imposer un `display` qui défait leur mise en page.
        target.style.display = show ? '' : 'none';
    }

    /**
     * Grise un bouton sans le faire disparaître, et dit POURQUOI par une infobulle.
     *
     * Masquer une action légitime ailleurs laisse l'utilisateur chercher un droit qu'il a
     * peut-être, ou soupçonner une panne. Un bouton inactif qui explique où aller répond
     * à la question au lieu de la poser.
     *
     * @param {HTMLElement|undefined} target
     * @param {boolean} disabled
     * @param {string} raison - infobulle affichée à l'état inactif.
     * @private
     *
     * `aria-disabled` et JAMAIS `disabled` (chantier 20) : un bouton `disabled` ne prend ni le
     * survol ni le focus — sa raison ne s'affichait jamais. Comme la barre des dialogues
     * (barre-actions.js) : le bouton reste survolable et focalisable, son infobulle dit
     * « Ajouter — <raison> », et `notify` refuse le geste. Le libellé d'origine (le `title`
     * du gabarit) est mémorisé : réactivé, le bouton le retrouve au lieu de le perdre.
     */
    setButtonDisabled(target, disabled, raison = '') {
        if (!target) return;
        target.classList.toggle('is-disabled', disabled);
        target.setAttribute('aria-disabled', disabled ? 'true' : 'false');
        const bouton = target.matches('button, a') ? target : target.querySelector('button, a');
        if (bouton) {
            bouton.setAttribute('aria-disabled', disabled ? 'true' : 'false');
            if (bouton.dataset.libelle === undefined) {
                bouton.dataset.libelle = bouton.getAttribute('title') ?? bouton.dataset.infobulleTitre ?? '';
            }
            const libelle = bouton.dataset.libelle;
            bouton.setAttribute('title', disabled && raison ? (libelle ? `${libelle} — ${raison}` : raison) : libelle);
        }
    }

    /**
     * Initialise l'état des boutons. Certains sont toujours visibles,
     * d'autres sont cachés par défaut.
     * @private
     */
    initializeToolbarState() {
        // Les entrées de « Plus » : toutes affichées ici ; la place des outils (rail ou « Plus »)
        // se décide ensuite, d'après la hauteur (_placerLesOutils).
        this.toggleButton(this.btquitterTarget, true);
        this.toggleButton(this.btparametresTarget, true);
        this.toggleButton(this.btrechargerTarget, true);
        this.toggleButton(this.bttoutcocherTarget, true);

        // Les boutons contextuels sont cachés par défaut.
        this.toggleButton(this.btajouterTarget, false); // Dépend maintenant du canvas
        this.toggleButton(this.btmodifierTarget, false);
        this.toggleButton(this.btouvrirTarget, false);
        this.toggleButton(this.btsupprimerTarget, false);

        // Les actions spécifiques sont aussi cachées par défaut.
        this.toggleButton(this.specificActionsSeparatorTarget, false);
        this.specificActionsContainerTarget.innerHTML = '';

        // La place des outils de la rubrique se décide à nouveau (rail-outils.js).
        this.outilsAuRail = null;

        // Un seul arrêt de tabulation dans la barre, posé dès l'affichage.
        this.barreActions.rafraichirNavigation();
    }

    /**
     * Méthode générique pour notifier le Cerveau d'une action de la barre d'outils.
     * L'événement à envoyer est défini dans l'attribut `data-toolbar-event-name-param` du bouton.
     * @param {MouseEvent} event - L'événement de clic.
     * @fires CustomEvent#cerveau:event
     */
    notify(event) {
        const button = event.currentTarget;
        // Un bouton inactif (aria-disabled) ne joue pas — il DIT pourquoi, au survol comme au geste
        // (au doigt, il n'y a pas de survol : C4).
        if (button.getAttribute('aria-disabled') === 'true' || button.closest('[aria-disabled="true"]')) {
            event.preventDefault();
            expliquerUnRefus(button);
            return;
        }
        // Le dernier outil joué : si la décision du rail le déplace, le focus le suivra.
        if (button.dataset.outil !== undefined) this.dernierOutil = button.dataset.outil;
        const eventName = button.dataset.toolbarEventNameParam;

        if (!eventName) {
            console.error("Le bouton n'a pas de 'data-toolbar-event-name-param' défini.", button);
            return;
        }

        // Le payload est maintenant générique. Il contient tout le contexte dont le cerveau pourrait avoir besoin.
        // C'est au cerveau de décider quelles informations utiliser.
        const payload = {
            selection: this.selectos, // Envoie la sélection complète (objets selecto)
            formCanvas: this.activeFormCanvas, // Envoie le contexte du formulaire actif
            // On pourrait ajouter ici d'autres éléments de contexte si nécessaire
        };

        this.notifyCerveau(eventName, payload);
    }

    /**
     * Méthode centralisée pour envoyer un événement au Cerveau.
     * @param {string} type Le type d'événement pour le Cerveau (ex: 'ui:toolbar.add-request').
     * @param {object} [payload={}] - Données additionnelles à envoyer.
     * @private
     */
    notifyCerveau(type, payload = {}) {
        const event = new CustomEvent('cerveau:event', {
            bubbles: true,
            detail: { type, source: this.nomControleur, payload, timestamp: Date.now() }
        });
        this.element.dispatchEvent(event);
    }

    /**
     * Affiche les actions spécifiques — rendu délégué au module partagé.
     * @param {Array} actions - Le tableau de configuration des actions venant du FormCanvas.
     * @private
     */
    updateSpecificActionButtons(actions) {
        // On affiche ou masque le séparateur en fonction de la présence d'actions
        this.toggleButton(this.specificActionsSeparatorTarget, actions.length > 0);

        // REGROUPEMENT PAR FAMILLE, SANS PLAFOND (décision de Serge, 2026-10-28). Le plafond
        // de quatre entrées repliait le surplus dans « Autres actions », un second « ⋮ » qu'on
        // confondait avec « Plus ». Il tenait à l'ancienne barre horizontale, qui partageait sa
        // ligne avec la recherche ; le rail vertical a la place, et défile en dernier recours.
        this.barreActions.afficher(actions);
    }

    /**
     * Un clic sur une action spécifique : l'action part au cerveau avec la sélection.
     * @private
     */
    _declencher(action) {
        // Une action transverse n'a pas de ligne : l'identifiant peut manquer, et le
        // lire sans précaution faisait échouer TOUT le rendu de la barre.
        const selectedId = this.selectos[0]?.id ?? null;
        this.notifyCerveau(action.event, { url: urlAction(action, selectedId), selection: this.selectos });
    }
}
