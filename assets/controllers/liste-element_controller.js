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
        console.log(this.nomControleur + " - Connecté");
        this.detailsVisible = false;
        this.listePrincipale = document.getElementById("liste");
        this.contextMenuTarget = document.getElementById("liste_row_" + this.idobjetValue);
        this.menu = document.getElementById("simpleContextMenu");
        this.setEcouteurs();
    }

    setEcouteurs(){
        this.contextMenuTarget.addEventListener('contextmenu', this.handleContextMenu.bind(this));
    }
    

    disconnect(){
        console.log(this.nomControleur + " - Déconnecté - Suppression d'écouteurs.");
        this.contextMenuTarget.removeEventListener('contextmenu', this.handleContextMenu.bind(this));
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
    action_afficher_details = (event) => {
        event.preventDefault(); // Empêche la soumission classique du formulaire
        if (this.detailsVisible == true) {
            this.detailsTarget.style.display = "none";
            this.detailsVisible = false;
        } else {
            this.detailsTarget.style.display = "block";
            this.detailsVisible = true;
        }
    }
}