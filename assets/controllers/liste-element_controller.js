import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = [
        'texteprincipal',
        'textesecondaire',
        'details',
    ];

    static values = {
        idobjet: Number,
    };

    connect() {
        this.nomControleur = "LISTE-ELEMENT";
        this.app_liste_element_contextmenu = "contextmenu";
        this.app_liste_element_click = "click";
        this.app_liste_element_scroll = "scroll";
        this.app_liste_element_resize = "resize";
        this.app_liste_element_developper = "app.liste-element:developper";
        console.log(this.nomControleur + " - Connecté");
        this.detailsVisible = false;
        this.listePrincipale = document.getElementById("liste");
        this.contextMenuTarget = document.getElementById("liste_row_" + this.idobjetValue);
        this.menu = document.getElementById("simpleContextMenu");
        this.setEcouteurs();
    }

    setEcouteurs(){
        this.contextMenuTarget.addEventListener(this.app_liste_element_contextmenu, this.handleContextMenu.bind(this));
        this.contextMenuTarget.addEventListener(this.app_liste_element_developper, this.action_afficher_details.bind(this));
        this.boundHideContextMenu = this.hideContextMenu.bind(this);
        document.addEventListener(this.app_liste_element_click, this.boundHideContextMenu);
        document.addEventListener(this.app_liste_element_scroll, this.boundHideContextMenu); // Cacher si on scroll
        window.addEventListener(this.app_liste_element_resize, this.boundHideContextMenu); // Cacher si la fenêtre est redimensionnée
        // Pour éviter que le clic sur une option du menu ne le cache immédiatement (stopPropagation)
        this.menu.addEventListener(this.app_liste_element_click, (e) => e.stopPropagation());
    }
    
    disconnect(){
        console.log(this.nomControleur + " - Déconnecté - Suppression d'écouteurs.");
        this.contextMenuTarget.removeEventListener(this.app_liste_element_contextmenu, this.handleContextMenu.bind(this));
        this.contextMenuTarget.removeEventListener(this.app_liste_element_developper, this.action_afficher_details.bind(this));
        if (this.boundHideContextMenu) {
            document.removeEventListener(this.app_liste_element_click, this.boundHideMenu);
            document.removeEventListener(this.app_liste_element_scroll, this.boundHideMenu); // Cacher si on scroll
            window.removeEventListener(this.app_liste_element_resize, this.boundHideMenu); // Cacher si la fenêtre est redimensionnée
        }
    }
    
    hideContextMenu(){
        this.menu.style.display = 'none';
        console.log("CHECHER MENU CONTEXTUEL");
    }

    /**
     * Gère l'événement de clic droit sur l'élément cible.
     */
    handleContextMenu(event) {
        event.preventDefault(); // Empêche le menu contextuel natif du navigateur d'apparaître

        //On doit cocher cet élément
        this.action_cocher();

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

        this.menu.style.left = `${menuX}px`;
        this.menu.style.top = `${menuY}px`;

        console.log(this.nomControleur + " ID " + this.idobjetValue + " - CLICK DROIT - PROPAGATION DE L'EVENEMENT VERS LA LISTE PRINCIPALE.", "MenuX: " + menuX, "MenuY: " + menuY, new Date());
    }

    action_cocher(){
        console.log(this.nomControleur + " - Action_cocher ", "check_" + this.idobjetValue);
        this.buildCustomEvent("app:liste-principale:cocher", 
            true, 
            true,
            {
                idCheckBox: "check_" + this.idobjetValue,
            }
        );
    }

    action_supprimer() {
        console.log(this.nomControleur + " - Action_supprimer ", this.idobjetValue);
        this.buildCustomEvent("app:liste-principale:supprimer", 
            true, 
            true,
            {
                titre: "Suppression",
            }
        );
    }

    action_modifier() {
        console.log(this.nomControleur + " - Action_modifier ", this.idobjetValue);
        this.buildCustomEvent("app:liste-principale:modifier", 
            true, 
            true,
            {
                titre: "Modification",
            }
        );
    }

    buildCustomEvent(nomEvent, canBubble, canCompose, detailTab) {
        const event = new CustomEvent(nomEvent, {
            bubbles: canBubble,
            composed: canCompose,
            detail: detailTab
        });
        this.listePrincipale.dispatchEvent(event);
    }


    /**
     * 
     * @param {MouseEvent} event 
     */
    action_afficher_details(event){
        event.preventDefault(); // Empêche la soumission classique du formulaire
        event.stopPropagation();
        console.log("ECOUTEUR DEVELOPPER");
        if (this.detailsVisible == true) {
            this.detailsTarget.style.display = "none";
            this.detailsVisible = false;
        } else {
            this.detailsTarget.style.display = "block";
            this.detailsVisible = true;
        }
    }
}