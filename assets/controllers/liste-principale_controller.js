import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = [
        'display',          //Champ d'affichage d'informations
        'donnees',          //Liste conténant des élements
    ];
    static values = {
        identreprise: Number,
        idutilisateur: Number,
        nbelements: Number,
        rubrique: String,
        controleurphp: String,
        controleursitimulus: String,
    };


    connect() {
        this.nomControleur = "LISTE-PRINCIPALE";
        console.log(this.nomControleur + " - Connecté");
        this.init();
    }

    

    init() {
        this.listePrincipale = document.getElementById("liste");
        this.tabSelectedCheckBoxs = [];
        this.initToolTips();
        this.updateMessage("Prêt.");
        this.setEcouteurs();
    }


    setEcouteurs() {
        //On attache les écouteurs d'Evenements personnalisés à la liste principale
        this.listePrincipale.addEventListener("app:liste-principale:ajouter", this.handleItemAjouter.bind(this));
        this.listePrincipale.addEventListener("app:liste-principale:quitter", this.handleQuitter.bind(this));
        this.listePrincipale.addEventListener("app:liste-principale:parametrer", this.handleParametrer.bind(this));
        this.listePrincipale.addEventListener("app:liste-principale:recharger", this.handleRecharger.bind(this));
        this.listePrincipale.addEventListener("app:liste-principale:modifier", this.handleItemModifier.bind(this));
        this.listePrincipale.addEventListener("app:liste-principale:supprimer", this.handleItemSupprimer.bind(this));
        this.listePrincipale.addEventListener("app:liste-principale:selection", this.handleItemSelectionner.bind(this));
        this.listePrincipale.addEventListener("app:liste-principale:dialog_ok", this.handleDialog_ok.bind(this));
    }

    disconnect() {
        console.log(this.nomControleur + " - Déconnecté - Suppression d'écouteurs.");
        //On attache les écouteurs d'Evenements personnalisés à la liste principale
        this.listePrincipale.removeEventListener("app:liste-principale:ajouter", this.handleItemAjouter.bind(this));
        this.listePrincipale.removeEventListener("app:liste-principale:quitter", this.handleQuitter.bind(this));
        this.listePrincipale.removeEventListener("app:liste-principale:parametrer", this.handleParametrer.bind(this));
        this.listePrincipale.removeEventListener("app:liste-principale:recharger", this.handleRecharger.bind(this));
        this.listePrincipale.removeEventListener("app:liste-principale:modifier", this.handleItemModifier.bind(this));
        this.listePrincipale.removeEventListener("app:liste-principale:supprimer", this.handleItemSupprimer.bind(this));
        this.listePrincipale.removeEventListener("app:liste-principale:selection", this.handleItemSelectionner.bind(this));
        this.listePrincipale.removeEventListener("app:liste-principale:dialog_ok", this.handleDialog_ok.bind(this));
    }

    /**
     * @description Gère l'événement d'ajout.
     * @param {CustomEvent} event L'événement personnalisé déclenché.
     */
    handleDialog_ok(event) {
        const { titre, message, action, data } = event.detail; // Récupère les données de l'événement
        console.log(this.nomControleur + " - EVENEMENT RECU: " + titre, titre, message, action, data);
        // Tu peux aussi prévenir la propagation de l'événement si nécessaire
        event.stopPropagation();
    }


    /**
     * @description Gère l'événement d'ajout.
     * @param {CustomEvent} event L'événement personnalisé déclenché.
     */
    handleItemSelectionner(event) {
        const { titre, idobjet, isChecked, selectedCheckbox } = event.detail; // Récupère les données de l'événement
        console.log("EVENEMENT RECU: " + titre, "ID Objet: " + idobjet, "Checked: " + isChecked, "Selected Check Box: " + selectedCheckbox);

        let currentSelectedCheckBoxes = new Set(this.tabSelectedCheckBoxs);
        if (isChecked == true) {
            currentSelectedCheckBoxes.add(String(selectedCheckbox));
        }else{
            currentSelectedCheckBoxes.delete(String(selectedCheckbox));
        }
        this.tabSelectedCheckBoxs = Array.from(currentSelectedCheckBoxes);
        this.updateMessageSelectedCheckBoxes();
        this.publierSelection();
        // Tu peux aussi prévenir la propagation de l'événement si nécessaire
        event.stopPropagation();
    }


    /**
     * @description Gère l'événement d'ajout.
     * @param {CustomEvent} event L'événement personnalisé déclenché.
     */
    handleItemAjouter(event) {
        const { titre } = event.detail; // Récupère les données de l'événement
        console.log("EVENEMENT RECU: " + titre);
        // Tu peux aussi prévenir la propagation de l'événement si nécessaire
        event.stopPropagation();
    }

    /**
     * @description Gère l'événement de la fermeture de l'espace de travail.
     * @param {CustomEvent} event L'événement personnalisé déclenché.
     */
    handleQuitter(event) {
        console.log("EVENEMENT RECU: QUITTER CET ESPACE DE TRAVAIL");
        // Tu peux aussi prévenir la propagation de l'événement si nécessaire
        event.stopPropagation();
    }

    /**
     * @description Gère l'événement de parametrage de la liste.
     * @param {CustomEvent} event L'événement personnalisé déclenché.
     */
    handleParametrer(event) {
        console.log("EVENEMENT RECU: PAREMETRER CETTE LISTE");
        // Tu peux aussi prévenir la propagation de l'événement si nécessaire
        event.stopPropagation();
    }

    /**
     * @description Gère l'événement de la recharge ou actualisation de la liste.
     * @param {CustomEvent} event L'événement personnalisé déclenché.
     */
    handleRecharger(event) {
        console.log("EVENEMENT RECU: RECHARGER CETTE LISTE");
        // Tu peux aussi prévenir la propagation de l'événement si nécessaire
        event.stopPropagation();
    }

    /**
     * @description Gère l'événement de modification.
     * @param {CustomEvent} event L'événement personnalisé déclenché.
     */
    handleItemModifier(event) {
        console.log("EVENEMENT RECU: MODIFICATION DE L'ELEMENT DE LA LISTE");
        // Tu peux aussi prévenir la propagation de l'événement si nécessaire
        event.stopPropagation();
    }

    /**
     * @description Gère l'événement de modification.
     * @param {CustomEvent} event L'événement personnalisé déclenché.
     */
    handleItemSupprimer(event) {
        event.stopPropagation();
        console.log("EVENEMENT RECU: SUPPRESSION D'ELEMENT(S).");
        var question = "Etes-vous sûr de vouloir supprimer cet élement?";
        if (this.tabSelectedCheckBoxs.length > 1) {
            question = "Etes-vous sûr de vouloir supprimer ces " + this.tabSelectedCheckBoxs.length + " élements séléctionnés?";
        }
        // Tu peux aussi prévenir la propagation de l'événement si nécessaire
        console.log("On lance un evenement dialogueCanSupprimer");
        this.buildCustomEvent("app:liste-principale:dialogueCanSupprimer", 
            true, 
            true,
            {
                titre: event.detail.titre,
                message: question,
                tabSelectedCheckBoxes: this.tabSelectedCheckBoxs,
            }
        );
    }

    /**
     * @description Gère l'événement de séléction.
     * @param {CustomEvent} event L'événement personnalisé déclenché.
     */
    handleItemCocher(event) {
        this.appliquerSelection(event, true);
    }

    appliquerSelection(event, depuisCheckBox) {
        const { idobjet } = event.detail; // Récupère les données de l'événement
        const idSelectedCheckBox = "check_" + idobjet;
        if (depuisCheckBox == false) {
            var checkBox = document.getElementById(idSelectedCheckBox);
            checkBox.checked = checkBox.checked == true ? false : true;
        }
        console.log("EVENEMENT RECU: SELECTION. [id.=" + idobjet + "]", idSelectedCheckBox);
        const indexOfSelectedCheckBox = this.tabSelectedCheckBoxs.indexOf(idSelectedCheckBox);
        if (indexOfSelectedCheckBox == -1) {
            this.tabSelectedCheckBoxs.push(idSelectedCheckBox);
        } else {
            this.tabSelectedCheckBoxs.splice(indexOfSelectedCheckBox, 1);
        }
        this.updateMessageSelectedCheckBoxes();
        this.publierSelection();
        event.stopPropagation();
    }

    publierSelection() {
        console.log("Action_publier séléction - lancée.");
        this.buildCustomEvent(
            "app:liste-principale:publier-selection",
            true,
            true,
            {
                selection: this.tabSelectedCheckBoxs,
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

    initToolTips() {
        //On initialise le tooltips
        const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
        const tooltipList = [...tooltipTriggerList].map(tooltipTriggerEl => new bootstrap.Tooltip(tooltipTriggerEl));
    }

    /**
     * 
     * @param {string} newMessage 
     */
    updateMessage(newMessage) {
        this.displayTarget.innerHTML = "Résultat: " + this.nbelementsValue + " élement(s) | " + newMessage;
    }


    /**
     * 
     * @param {Event} event 
      */
    handleItemToutCocher(event) {
        event.stopPropagation();
        const isChecked = event.target.checked;
        const checkBoxes = this.donneesTarget.querySelectorAll('input[type="checkbox"]');
        this.tabSelectedCheckBoxs = [];
        checkBoxes.forEach(currentCheckBox => {
            currentCheckBox.checked = isChecked;
            if (isChecked == true) {
                this.tabSelectedCheckBoxs.push(currentCheckBox.getAttribute("id"));
            } else {
                this.tabSelectedCheckBoxs.splice(this.tabSelectedCheckBoxs.indexOf(currentCheckBox.getAttribute("id")), 1);
            }
        });
        this.updateMessageSelectedCheckBoxes();
        this.publierSelection();
    }

    /**
     * 
     */
    updateMessageSelectedCheckBoxes() {
        if (this.tabSelectedCheckBoxs.length != 0) {
            this.updateMessage(this.tabSelectedCheckBoxs.length + " éléments cochés.");
        } else {
            this.updateMessage("");
        }
    }


    /**
     * 
     * @param {Event} event 
     */
    outils_quitter(event) {
        console.log("Action Barre d'outils:", event.currentTarget);
    }


    /**
     * 
     * @param {Event} event 
     */
    outils_parametrer(event) {
        console.log("Action Barre d'outils:", event.currentTarget);
    }


    /**
     * 
     * @param {Event} event 
     */
    outils_recharger(event) {
        console.log("Action Barre d'outils - RECHARGE DES VALEURS:", event.currentTarget);
        this.updateMessage("Actualisation des données...");
        this.donneesTarget.disabled = true;
        // this.donneesTarget.innerHTML = "J'actualise cette liste";
        const url = '/admin/' + this.controleurphpValue + '/reload/' + this.identrepriseValue;
        fetch(url) // Remplacez par l'URL de votre formulaire
            .then(response => response.text())
            .then(html => {
                this.donneesTarget.innerHTML = html;
                this.donneesTarget.disabled = false;

                const maintenant = new Date();
                const dateHeureLocaleSimple = maintenant.toLocaleString();
                this.updateMessage("Dernière actualisation " + dateHeureLocaleSimple);
                this.tabSelectedCheckBoxs = [];
                this.updateMessageSelectedCheckBoxes();
            });
    }


    getDialogueController() {
        const liste = document.getElementById("liste");
        // Vérifie que l'élément 'form' est bien défini comme target
        if (liste) {
            return this.application.getControllerForElementAndIdentifier(liste, "dialogue");
        }
        return null;
    }
}