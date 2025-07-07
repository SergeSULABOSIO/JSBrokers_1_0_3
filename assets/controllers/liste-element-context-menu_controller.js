import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = [
        'menu',
    ];

    static values = {
        idobjet: Number,
    };


    connect() {
        this.nomControleur = "LISTE-ELEMENT-CONTEXT-MENU";
        console.log(this.nomControleur + " - Connecté - " + this.idobjetValue);
        this.init();
    }

    init() {
        this.targetElement = document.getElementById("myContextMenuTarget_" + this.idobjetValue); // Ton élément cible (ID mis à jour)
        this.menu = this.menuTarget; // Le menu HTML
        this.listePrincipale = document.getElementById("liste");
        console.log("CONTEXT MENU TARGETS:", this.targetElement, this.menuTarget);
        this.setEcouteurs();
    }

    setEcouteurs() {
        // 1. Empêcher le menu contextuel natif du navigateur au clic droit sur l'élément cible
        this.boundHandleContextMenu = this.handleContextMenu.bind(this);
        this.targetElement.addEventListener('contextmenu', this.boundHandleContextMenu);

        // 2. Cacher le menu si on clique n'importe où ailleurs dans le document
        this.boundHideMenu = this.hideMenu.bind(this);
        document.addEventListener('click', this.boundHideMenu);
        document.addEventListener('contextmenu', this.boundHideMenu); // Aussi au clic droit ailleurs
        document.addEventListener('scroll', this.boundHideMenu); // Cacher si on scroll
        window.addEventListener('resize', this.boundHideMenu); // Cacher si la fenêtre est redimensionnée

        // Pour éviter que le clic sur une option du menu ne le cache immédiatement (stopPropagation)
        this.menu.addEventListener('click', (e) => e.stopPropagation());
    }

    disconnect() {
        console.log(this.nomControleur + " - Déconnecté - Suppression d'écouteurs.");
        if (this.targetElement && this.boundHandleContextMenu) {
            this.targetElement.removeEventListener('contextmenu', this.boundHandleContextMenu);
        }
        if (this.boundHideMenu) {
            document.removeEventListener('click', this.boundHideMenu);
            document.removeEventListener('contextmenu', this.boundHideMenu);
            document.removeEventListener('scroll', this.boundHideMenu);
            window.removeEventListener('resize', this.boundHideMenu);
        }
    }

    /**
     * Gère l'événement de clic droit sur l'élément cible.
     */
    handleContextMenu(event) {
        console.log("JE SUIS ICI....");
        event.preventDefault(); // Empêche le menu contextuel natif du navigateur d'apparaître

        this.menu.style.display = 'block'; // Affiche le menu

        // Positionne le menu près du curseur de la souris, en s'assurant qu'il reste dans la fenêtre
        let menuX = event.clientX;
        let menuY = event.clientY;

        const menuWidth = this.menu.offsetWidth;
        const menuHeight = this.menu.offsetHeight;
        const windowWidth = window.innerWidth;
        const windowHeight = window.innerHeight;

        if (menuX + menuWidth > windowWidth) {
            menuX = windowWidth - menuWidth - 5; // Décale à gauche si trop à droite
        }
        if (menuY + menuHeight > windowHeight) {
            menuY = windowHeight - menuHeight - 5; // Décale vers le haut si trop en bas
        }

        this.menu.style.left = menuX + "px";
        this.menu.style.top = menuY + "px";

        console.log("Left: " + this.menu.style.left, "Top: " + this.menu.style.top);
    }

    /**
     * Cache le menu contextuel.
     */
    hideMenu() {
        this.menu.style.display = 'none';
    }

    /**
     * Exécute l'action associée à l'option de menu cliquée.
     * Cette méthode est appelée via data-action="click->context-menu#execute"
     */
    execute(event) {
        event.stopPropagation(); // Empêche le clic de masquer immédiatement le menu
        
        this.hideMenu(); // Cache le menu après avoir cliqué sur une option

        // Récupère les paramètres passés via data-context-menu-action-param et data-context-menu-label-param
        const action = event.params.action; 
        const label = event.params.label;

        console.log(`Action exécutée : "${action}" (${label})`);

        // Logique spécifique pour chaque option
        switch (action) {
            case 'new':
                this.handleNewAction();
                break;
            case 'properties':
                this.handlePropertiesAction();
                break;
            default:
                console.warn(`Action "${action}" non reconnue.`);
        }
    }

    // --- Méthodes spécifiques aux actions du menu ---

    context_action_ajouter(event) {
        event.stopPropagation(); // Empêche le clic de masquer immédiatement le menu
        this.hideMenu(); // Cache le menu après avoir cliqué sur une option

        alert("Action déclenchée !");
        // Ici, tu mettrais le code pour créer un nouvel élément, ouvrir un modal, etc.
    }

    context_action_actualiser(event) {
        event.stopPropagation(); // Empêche le clic de masquer immédiatement le menu
        this.hideMenu(); // Cache le menu après avoir cliqué sur une option

        alert("Action déclenchée !");
        // Ici, tu afficherais un panneau de propriétés, un formulaire de modification, etc.
    }

    context_action_supprimer(event) {
        event.stopPropagation(); // Empêche le clic de masquer immédiatement le menu
        this.hideMenu(); // Cache le menu après avoir cliqué sur une option

        alert("Action déclenchée !");
        // Ici, tu afficherais un panneau de propriétés, un formulaire de modification, etc.
    }

    context_action_parametrer(event) {
        event.stopPropagation(); // Empêche le clic de masquer immédiatement le menu
        this.hideMenu(); // Cache le menu après avoir cliqué sur une option

        alert("Action déclenchée !");
        // Ici, tu afficherais un panneau de propriétés, un formulaire de modification, etc.
    }

    context_action_quitter(event) {
        event.stopPropagation(); // Empêche le clic de masquer immédiatement le menu
        this.hideMenu(); // Cache le menu après avoir cliqué sur une option

        alert("Action déclenchée !");
        // Ici, tu afficherais un panneau de propriétés, un formulaire de modification, etc.
    }
}