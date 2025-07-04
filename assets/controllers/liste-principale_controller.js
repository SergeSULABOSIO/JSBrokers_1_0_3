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
        this.listePrincipale.addEventListener("app:liste-principale:cocher", this.handleItemCocher.bind(this));
        this.listePrincipale.addEventListener("app:liste-principale:quitter", this.handleQuitter.bind(this));
        this.listePrincipale.addEventListener("app:liste-principale:parametrer", this.handleParametrer.bind(this));
        this.listePrincipale.addEventListener("app:liste-principale:recharger", this.handleRecharger.bind(this));
        this.listePrincipale.addEventListener("app:liste-principale:modifier", this.handleItemModifier.bind(this));
        this.listePrincipale.addEventListener("app:liste-principale:supprimer", this.handleItemSupprimer.bind(this));
        this.listePrincipale.addEventListener("app:liste-principale:selection", this.handleItemSelectionner.bind(this));
        this.listePrincipale.addEventListener("app:liste-principale:dialog_ok", this.handleDialog_ok.bind(this));
        this.listePrincipale.addEventListener("app:liste-principale:dialog_no", this.handleDialog_no.bind(this));
        this.listePrincipale.addEventListener("app:liste-principale:afficher_message", this.handleDisplayMessage.bind(this));
    }

    disconnect() {
        console.log(this.nomControleur + " - Déconnecté - Suppression d'écouteurs.");
        //On attache les écouteurs d'Evenements personnalisés à la liste principale
        this.listePrincipale.removeEventListener("app:liste-principale:ajouter", this.handleItemAjouter.bind(this));
        this.listePrincipale.removeEventListener("app:liste-principale:cocher", this.handleItemCocher.bind(this));
        this.listePrincipale.removeEventListener("app:liste-principale:quitter", this.handleQuitter.bind(this));
        this.listePrincipale.removeEventListener("app:liste-principale:parametrer", this.handleParametrer.bind(this));
        this.listePrincipale.removeEventListener("app:liste-principale:recharger", this.handleRecharger.bind(this));
        this.listePrincipale.removeEventListener("app:liste-principale:modifier", this.handleItemModifier.bind(this));
        this.listePrincipale.removeEventListener("app:liste-principale:supprimer", this.handleItemSupprimer.bind(this));
        this.listePrincipale.removeEventListener("app:liste-principale:selection", this.handleItemSelectionner.bind(this));
        this.listePrincipale.removeEventListener("app:liste-principale:dialog_ok", this.handleDialog_ok.bind(this));
        this.listePrincipale.removeEventListener("app:liste-principale:dialog_no", this.handleDialog_no.bind(this));
        this.listePrincipale.removeEventListener("app:liste-principale:afficher_message", this.handleDisplayMessage.bind(this));
    }

    /**
     * @description Gère l'événement d'ajout.
     * @param {CustomEvent} event L'événement personnalisé déclenché.
     */
    handleDialog_ok(event) {
        const { titre, message, action, data } = event.detail; // Récupère les données de l'événement
        console.log(this.nomControleur + " - EVENEMENT RECU: " + titre, titre, message, action, data);
        //ACTION AJOUT = 0
        if (action == 0) {
            //On exécute la suppression des données.
            this.execution_ajout(event);
        }
        if (action == 1) {
            //On exécute la suppression des données.
            this.execution_modification(event);
        }
        //ACTION SUPPRESSION = 2
        if (action == 2) {
            //On exécute la suppression des données.
            this.execution_suppression(event);
        }
        
        // Tu peux aussi prévenir la propagation de l'événement si nécessaire
        event.stopPropagation();
    }


    execution_ajout(event) {
        const { titre, message, action, data } = event.detail; // Récupère les données de l'événement
        console.log(this.nomControleur + " - Exécution de l'ajout", event.detail);
    }


    execution_modification(event) {
        const { titre, message, action, data } = event.detail; // Récupère les données de l'événement
        console.log(this.nomControleur + " - Exécution de la modification", event.detail);
    }


    execution_suppression(event) {
        const { titre, message, action, data } = event.detail; // Récupère les données de l'événement
        // #[Route('/remove_many/{idEntreprise}/{tabIDString}', name: 'remove_many', requirements: ['idEntreprise' => Requirement:: DIGITS])]
        let tabIds = [];
        data.forEach(dataElement => {
            tabIds.push(dataElement.split("check_")[1]);
        });

        const url = '/admin/' + this.controleurphpValue + '/remove_many/' + this.identrepriseValue + '/' + tabIds;
        console.log(this.nomControleur + " - Exécution de la suppression", data, url);
        this.updateMessage("Suppression en cours... Merci de patienter.");
        fetch(url) // Remplacez par l'URL de votre formulaire
            .then(response => response.json())
            .then(ServerJsonData => {
                const serverJsonObject = JSON.parse(ServerJsonData);
                console.log(this.nomControleur + " - Réponse du serveur: ", serverJsonObject);
                if (serverJsonObject.reponse == "Ok") {
                    serverJsonObject.deletedIds.forEach(deletedId => {
                        let elementToDelete = document.getElementById("liste_row_" + deletedId);
                        let parentElement = elementToDelete.parentNode;
                        if (elementToDelete) {
                            if (parentElement) {
                                parentElement.removeChild(elementToDelete);
                                this.tabSelectedCheckBoxs.splice(this.tabSelectedCheckBoxs.indexOf("check_" + deletedId), 1);
                            }
                        }
                    });
                    this.updateMessage("Bien fait: " + serverJsonObject.message);
                }
            });
    }


    /**
     * @description Gère l'événement d'ajout.
     * @param {CustomEvent} event L'événement personnalisé déclenché.
     */
    handleDialog_no(event) {
        const { titre, message, action, data } = event.detail; // Récupère les données de l'événement
        event.stopPropagation();
        console.log(this.nomControleur + " - EVENEMENT RECU: " + titre, titre, message, action, data, "ON NE FAIT RIEN.");
        console.log(this.nomControleur + " - ON NE FAIT RIEN.");
        this.updateMessage("Boît de dialogue fermée.");
        // Tu peux aussi prévenir la propagation de l'événement si nécessaire
    }


    /**
     * @description Gère l'événement d'ajout.
     * @param {CustomEvent} event L'événement personnalisé déclenché.
     */
    handleDisplayMessage(event) {
        const { titre, message } = event.detail; // Récupère les données de l'événement
        event.stopPropagation();
        console.log(this.nomControleur + " - EVENEMENT RECU: " + titre, message);
        this.updateMessage(titre + ": " + message);
    }


    /**
     * @description Gère l'événement d'ajout.
     * @param {CustomEvent} event L'événement personnalisé déclenché.
     */
    handleItemSelectionner(event) {
        const { titre, idobjet, isChecked, selectedCheckbox } = event.detail; // Récupère les données de l'événement
        console.log(this.nomControleur + " - EVENEMENT RECU: " + titre, "ID Objet: " + idobjet, "Checked: " + isChecked, "Selected Check Box: " + selectedCheckbox);

        let currentSelectedCheckBoxes = new Set(this.tabSelectedCheckBoxs);
        if (isChecked == true) {
            currentSelectedCheckBoxes.add(String(selectedCheckbox));
        } else {
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
        console.log(this.nomControleur + " - EVENEMENT RECU: " + titre);
        // Tu peux aussi prévenir la propagation de l'événement si nécessaire
        event.stopPropagation();

        console.log(this.nomControleur + " - On lance un evenement dialogueCanAjouter");
        this.buildCustomEvent("app:liste-principale:dialogueCanAjouter",
            true,
            true,
            {
                titre: "Ajout - " + this.rubriqueValue,
                entreprise: this.identrepriseValue,
                utilisateur: this.utilisateurValue,
                rubrique: this.rubriqueValue,
                controleurphp: this.controleurphpValue,
                controleursitimulus: this.controleursitimulusValue,
            }
        );
    }

    /**
     * @description Gère l'événement de la fermeture de l'espace de travail.
     * @param {CustomEvent} event L'événement personnalisé déclenché.
     */
    handleQuitter(event) {
        console.log(this.nomControleur + " - EVENEMENT RECU: QUITTER CET ESPACE DE TRAVAIL");
        // Tu peux aussi prévenir la propagation de l'événement si nécessaire
        event.stopPropagation();
    }

    /**
     * @description Gère l'événement de parametrage de la liste.
     * @param {CustomEvent} event L'événement personnalisé déclenché.
     */
    handleParametrer(event) {
        console.log(this.nomControleur + " - EVENEMENT RECU: PAREMETRER CETTE LISTE");
        // Tu peux aussi prévenir la propagation de l'événement si nécessaire
        event.stopPropagation();
    }

    /**
     * @description Gère l'événement de la recharge ou actualisation de la liste.
     * @param {CustomEvent} event L'événement personnalisé déclenché.
     */
    handleRecharger(event) {
        console.log(this.nomControleur + " - EVENEMENT RECU: RECHARGER CETTE LISTE");
        // Tu peux aussi prévenir la propagation de l'événement si nécessaire
        event.stopPropagation();
    }

    /**
     * @description Gère l'événement de modification.
     * @param {CustomEvent} event L'événement personnalisé déclenché.
     */
    handleItemModifier(event) {
        console.log(this.nomControleur + " - EVENEMENT RECU: MODIFICATION DE L'ELEMENT DE LA LISTE");
        // Tu peux aussi prévenir la propagation de l'événement si nécessaire
        event.stopPropagation();
    }

    /**
     * @description Gère l'événement de modification.
     * @param {CustomEvent} event L'événement personnalisé déclenché.
     */
    handleItemSupprimer(event) {
        event.stopPropagation();
        console.log(this.nomControleur + " - EVENEMENT RECU: SUPPRESSION D'ELEMENT(S).");
        var question = "Etes-vous sûr de vouloir supprimer cet élement?";
        if (this.tabSelectedCheckBoxs.length > 1) {
            question = "Etes-vous sûr de vouloir supprimer ces " + this.tabSelectedCheckBoxs.length + " élements séléctionnés?";
        }
        // Tu peux aussi prévenir la propagation de l'événement si nécessaire
        console.log(this.nomControleur + " - On lance un evenement dialogueCanSupprimer");
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
        const { idCheckBox } = event.detail; // Récupère les données de l'événement

        this.tabSelectedCheckBoxs = [];
        const checkBoxes = this.donneesTarget.querySelectorAll('input[type="checkbox"]');
        checkBoxes.forEach(currentCheckBox => {
            currentCheckBox.checked = false;
        });

        var checkBox = document.getElementById(idCheckBox);
        checkBox.checked = true;
        this.tabSelectedCheckBoxs.push(idCheckBox);
        console.log(this.nomControleur + " - EVENEMENT RECU: SELECTION. [id.=" + idCheckBox.split("check_")[1] + "]", idCheckBox);

        this.updateMessageSelectedCheckBoxes();
        this.publierSelection();
        event.stopPropagation();
    }


    publierSelection() {
        console.log(this.nomControleur + " - Action_publier séléction - lancée.");
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
        console.log(this.nomControleur + " - Action Barre d'outils:", event.currentTarget);
    }


    /**
     * 
     * @param {Event} event 
     */
    outils_parametrer(event) {
        console.log(this.nomControleur + " - Action Barre d'outils:", event.currentTarget);
    }


    /**
     * 
     * @param {Event} event 
     */
    outils_recharger(event) {
        console.log(this.nomControleur + " - Action Barre d'outils - RECHARGE DES VALEURS:", event.currentTarget);
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