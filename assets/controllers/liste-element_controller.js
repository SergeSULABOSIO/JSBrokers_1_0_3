import { Controller } from '@hotwired/stimulus';
import { buildCustomEventForElement, EVEN_ACTION_CLICK, EVEN_ACTION_COCHER, EVEN_ACTION_DEVELOPPER, EVEN_ACTION_MENU_CONTEXTUEL, EVEN_ACTION_MODIFIER, EVEN_ACTION_RESIZE, EVEN_ACTION_SCROLL, EVEN_ACTION_SUPPRIMER, EVEN_LISTE_ELEMENT_CHECK_REQUEST, EVEN_LISTE_ELEMENT_CHECKED, EVEN_LISTE_ELEMENT_DELETE_REQUEST, EVEN_LISTE_ELEMENT_DELETED, EVEN_LISTE_ELEMENT_EXPAND_REQUEST, EVEN_LISTE_ELEMENT_EXPANDED, EVEN_LISTE_ELEMENT_MODIFIED, EVEN_LISTE_ELEMENT_MODIFY_REQUEST } from './base_controller.js';

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
        console.log(this.nomControleur + " - Définition des écouteurs.");
        document.addEventListener(EVEN_LISTE_ELEMENT_CHECK_REQUEST, this.handleCheckRequest.bind(this));
        document.addEventListener(EVEN_LISTE_ELEMENT_CHECKED, this.handleChecked.bind(this));
        document.addEventListener(EVEN_LISTE_ELEMENT_DELETE_REQUEST, this.handleDeleteRequest.bind(this));
        document.addEventListener(EVEN_LISTE_ELEMENT_DELETED, this.handleDeleted.bind(this));
        document.addEventListener(EVEN_LISTE_ELEMENT_EXPAND_REQUEST, this.handleExpandRequest.bind(this));
        document.addEventListener(EVEN_LISTE_ELEMENT_EXPANDED, this.handleExpanded.bind(this));
        document.addEventListener(EVEN_LISTE_ELEMENT_MODIFY_REQUEST, this.handleModifyRequest.bind(this));
        document.addEventListener(EVEN_LISTE_ELEMENT_MODIFIED, this.handleModified.bind(this));


        // this.contextMenuTarget.addEventListener(EVEN_ACTION_MENU_CONTEXTUEL, this.handleContextMenu.bind(this));
        // this.contextMenuTarget.addEventListener(EVEN_ACTION_DEVELOPPER, this.action_afficher_details.bind(this));
        // this.boundHideContextMenu = this.hideContextMenu.bind(this);
        // document.addEventListener(EVEN_ACTION_CLICK, this.boundHideContextMenu);
        // document.addEventListener(EVEN_ACTION_SCROLL, this.boundHideContextMenu); // Cacher si on scroll
        // window.addEventListener(EVEN_ACTION_RESIZE, this.boundHideContextMenu); // Cacher si la fenêtre est redimensionnée
        // this.menu.addEventListener(EVEN_ACTION_CLICK, (e) => e.stopPropagation());
    }
    
    disconnect(){
        console.log(this.nomControleur + " - Déconnecté - Suppression d'écouteurs.");
        document.removeEventListener(EVEN_LISTE_ELEMENT_CHECK_REQUEST, this.handleCheckRequest.bind(this));
        document.removeEventListener(EVEN_LISTE_ELEMENT_CHECKED, this.handleChecked.bind(this));
        document.removeEventListener(EVEN_LISTE_ELEMENT_DELETE_REQUEST, this.handleDeleteRequest.bind(this));
        document.removeEventListener(EVEN_LISTE_ELEMENT_DELETED, this.handleDeleted.bind(this));
        document.removeEventListener(EVEN_LISTE_ELEMENT_EXPAND_REQUEST, this.handleExpandRequest.bind(this));
        document.removeEventListener(EVEN_LISTE_ELEMENT_EXPANDED, this.handleExpanded.bind(this));
        document.removeEventListener(EVEN_LISTE_ELEMENT_MODIFY_REQUEST, this.handleModifyRequest.bind(this));
        document.removeEventListener(EVEN_LISTE_ELEMENT_MODIFIED, this.handleModified.bind(this));

        
        // this.contextMenuTarget.removeEventListener(EVEN_ACTION_MENU_CONTEXTUEL, this.handleContextMenu.bind(this));
        // this.contextMenuTarget.removeEventListener(EVEN_ACTION_DEVELOPPER, this.action_afficher_details.bind(this));
        // if (this.boundHideContextMenu) {
        //     document.removeEventListener(EVEN_ACTION_CLICK, this.boundHideMenu);
        //     document.removeEventListener(EVEN_ACTION_SCROLL, this.boundHideMenu); // Cacher si on scroll
        //     window.removeEventListener(EVEN_ACTION_RESIZE, this.boundHideMenu); // Cacher si la fenêtre est redimensionnée
        // }
    }

    handleCheckRequest(event){
        console.log(this.nomControleur + " - HandleCheckRequest");
    }

    handleChecked(event){
        console.log(this.nomControleur + " - HandleChecked");
    }

    handleDeleteRequest(event){
        console.log(this.nomControleur + " - HandleDeleteRequest");
    }

    handleDeleted(event){
        console.log(this.nomControleur + " - HandleDeleted");
    }

    handleExpandRequest(event){
        console.log(this.nomControleur + " - HandleExpandRequest");
    }

    handleExpanded(event){
        console.log(this.nomControleur + " - HandleExpanded");
    }

    handleModifyRequest(event){
        console.log(this.nomControleur + " - HandleModifyRequest");
    }

    handleModified(event){
        console.log(this.nomControleur + " - HandleModified");
    }


    
    hideContextMenu(){
        this.menu.style.display = 'none';
        console.log(this.nomControleur + " - CHECHER MENU CONTEXTUEL");
    }

    /**
     * Gère l'événement de clic droit sur l'élément cible.
     */
    handleContextMenu(event) {
        event.preventDefault(); // Empêche le menu contextuel natif du navigateur d'apparaître
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
        buildCustomEventForElement(this.listePrincipale, EVEN_ACTION_COCHER, true, true,{idCheckBox: "check_" + this.idobjetValue});
    }

    action_supprimer() {
        console.log(this.nomControleur + " - Action_supprimer ", this.idobjetValue);
        buildCustomEventForElement(this.listePrincipale, EVEN_ACTION_SUPPRIMER, true, true, {titre: "Suppression"});
    }

    action_modifier() {
        console.log(this.nomControleur + " - Action_modifier ", this.idobjetValue);
        buildCustomEventForElement(this.listePrincipale, EVEN_ACTION_MODIFIER, true, true, {titre: "Modification"});
    }


    /**
     * 
     * @param {MouseEvent} event 
     */
    action_afficher_details(event){
        event.preventDefault(); // Empêche la soumission classique du formulaire
        event.stopPropagation();
        console.log(this.nomControleur + " - ECOUTEUR DEVELOPPER");
        if (this.detailsVisible == true) {
            this.detailsTarget.style.display = "none";
            this.detailsVisible = false;
        } else {
            this.detailsTarget.style.display = "block";
            this.detailsVisible = true;
        }
    }
}