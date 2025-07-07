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
        this.app_liste_principale_ajouter = "app:liste-principale:ajouter";
        this.app_liste_principale_cocher = "app:liste-principale:cocher";
        this.app_liste_principale_quitter = "app:liste-principale:quitter";
        this.app_liste_principale_parametrer = "app:liste-principale:parametrer";
        this.app_liste_principale_recharger = "app:liste-principale:recharger";
        this.app_liste_principale_modifier = "app:liste-principale:modifier";
        this.app_liste_principale_supprimer = "app:liste-principale:supprimer";
        this.app_liste_principale_selection = "app:liste-principale:selection";
        this.app_liste_principale_dialogue_ok = "app:liste-principale:dialog_ok";
        this.app_liste_principale_dialogue_no = "app:liste-principale:dialog_no";
        this.app_liste_principale_afficher_message = "app:liste-principale:afficher_message";
        this.app_liste_principale_edition_reussie = "app:liste-principale:formulaire_ajout_modification_reussi";

        this.listePrincipale = document.getElementById("liste");
        this.tabSelectedCheckBoxs = [];
        this.initToolTips();
        this.updateMessage("Prêt.");
        this.setEcouteurs();
    }


    setEcouteurs() {
        //On attache les écouteurs d'Evenements personnalisés à la liste principale
        this.listePrincipale.addEventListener(this.app_liste_principale_ajouter, this.handleItemAjouter.bind(this));
        this.listePrincipale.addEventListener(this.app_liste_principale_cocher, this.handleItemCocher.bind(this));
        this.listePrincipale.addEventListener(this.app_liste_principale_quitter, this.handleQuitter.bind(this));
        this.listePrincipale.addEventListener(this.app_liste_principale_parametrer, this.handleParametrer.bind(this));
        this.listePrincipale.addEventListener(this.app_liste_principale_recharger, this.handleRecharger.bind(this));
        this.listePrincipale.addEventListener(this.app_liste_principale_modifier, this.handleItemModifier.bind(this));
        this.listePrincipale.addEventListener(this.app_liste_principale_supprimer, this.handleItemSupprimer.bind(this));
        this.listePrincipale.addEventListener(this.app_liste_principale_selection, this.handleItemSelectionner.bind(this));
        this.listePrincipale.addEventListener(this.app_liste_principale_dialogue_ok, this.handleDialog_ok.bind(this));
        this.listePrincipale.addEventListener(this.app_liste_principale_dialogue_no, this.handleDialog_no.bind(this));
        this.listePrincipale.addEventListener(this.app_liste_principale_afficher_message, this.handleDisplayMessage.bind(this));
        this.listePrincipale.addEventListener(this.app_liste_principale_edition_reussie, this.handleFormulaireAjoutModifReussi.bind(this));
    }

    disconnect() {
        console.log(this.nomControleur + " - Déconnecté - Suppression d'écouteurs.");
        //On attache les écouteurs d'Evenements personnalisés à la liste principale
        this.listePrincipale.removeEventListener(this.app_liste_principale_ajouter, this.handleItemAjouter.bind(this));
        this.listePrincipale.removeEventListener(this.app_liste_principale_cocher, this.handleItemCocher.bind(this));
        this.listePrincipale.removeEventListener(this.app_liste_principale_quitter, this.handleQuitter.bind(this));
        this.listePrincipale.removeEventListener(this.app_liste_principale_parametrer, this.handleParametrer.bind(this));
        this.listePrincipale.removeEventListener(this.app_liste_principale_recharger, this.handleRecharger.bind(this));
        this.listePrincipale.removeEventListener(this.app_liste_principale_modifier, this.handleItemModifier.bind(this));
        this.listePrincipale.removeEventListener(this.app_liste_principale_supprimer, this.handleItemSupprimer.bind(this));
        this.listePrincipale.removeEventListener(this.app_liste_principale_selection, this.handleItemSelectionner.bind(this));
        this.listePrincipale.removeEventListener(this.app_liste_principale_dialogue_ok, this.handleDialog_ok.bind(this));
        this.listePrincipale.removeEventListener(this.app_liste_principale_dialogue_no, this.handleDialog_no.bind(this));
        this.listePrincipale.removeEventListener(this.app_liste_principale_afficher_message, this.handleDisplayMessage.bind(this));
        this.listePrincipale.removeEventListener(this.app_liste_principale_edition_reussie, this.handleFormulaireAjoutModifReussi.bind(this));
    }

    /**
     * @description Gère l'événement d'ajout.
     * @param {CustomEvent} event L'événement personnalisé déclenché.
     */
    handleFormulaireAjoutModifReussi(event) {
        const { idObjet, code, message } = event.detail; // Récupère les données de l'événement
        console.log(this.nomControleur + " - ENREGISTREMENT REUSSI - On recharge la liste");
        if (code == 0) { //Ok(0), Erreur(1)
            //On demande de fermer la boite de dialogue
            this.buildCustomEvent("app:dialogue:fermer_boite", true, true, {});
            //On recharge la liste principale
            this.outils_recharger(event);
        } else {
            this.updateMessage(code + ": " + message);
        }
        // Tu peux aussi prévenir la propagation de l'événement si nécessaire
        event.stopPropagation();
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
        this.buildCustomEvent("app:formulaire:enregistrer", true, true, {});
    }


    execution_modification(event) {
        const { titre, message, action, data } = event.detail; // Récupère les données de l'événement
        console.log(this.nomControleur + " - Exécution de la modification", event.detail);
        this.buildCustomEvent("app:formulaire:enregistrer", true, true, {});
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
        this.action_afficherMessage("Suppression", "Suppression en cours... Merci de patienter.");
        fetch(url) // Remplacez par l'URL de votre formulaire
            .then(response => response.json())
            .then(ServerJsonData => {
                const serverJsonObject = JSON.parse(ServerJsonData);
                console.log(this.nomControleur + " - Réponse du serveur: ", serverJsonObject);
                if (serverJsonObject.reponse == "Ok") {
                    //On demande de fermer la boite de dialogue
                    this.buildCustomEvent("app:dialogue:fermer_boite", true, true, {});
                    //On actualise la liste sans consulter le serveur
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
                    this.action_afficherMessage("Suppression", "Bien fait: " + serverJsonObject.message);
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
        this.updateMessage("Boîte de dialogue fermée.");
        // Tu peux aussi prévenir la propagation de l'événement si nécessaire
    }

    /**
     * 
     * @param {String} titre 
     * @param {String} textMessage 
     */
    action_afficherMessage(titre, textMessage) {
        this.buildCustomEvent(
            "app:liste-principale:afficher_message",
            true,
            true,
            {
                titre: titre,
                message: textMessage,
            }
        );
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
        console.log(this.nomControleur + " - Titre: " + titre);
        // Tu peux aussi prévenir la propagation de l'événement si nécessaire
        event.stopPropagation();

        console.log(this.nomControleur + " - On lance un evenement dialogueCanAjouter");
        this.buildCustomEvent("app:liste-principale:dialogueCanAjouter", true, true,
            {
                titre: "Ajout - " + this.rubriqueValue,
                idObjet: -1,
                action: 0, //Ajout
                entreprise: this.identrepriseValue,
                utilisateur: this.utilisateurValue,
                rubrique: this.rubriqueValue,
                controleurphp: this.controleurphpValue,
                controleursitimulus: this.controleursitimulusValue,
            }
        );
    }


    /**
     * @description Gère l'événement de modification.
     * @param {CustomEvent} event L'événement personnalisé déclenché.
     */
    handleItemModifier(event) {
        const { titre } = event.detail; // Récupère les données de l'événement
        console.log(this.nomControleur + " - Titre: " + titre + ", Selected checkbox: " + this.tabSelectedCheckBoxs);
        // Tu peux aussi prévenir la propagation de l'événement si nécessaire
        event.stopPropagation();

        console.log(this.nomControleur + " - On lance un evenement dialogueCanAjouter");
        this.buildCustomEvent("app:liste-principale:dialogueCanAjouter", true, true,
            {
                titre: "Edition - " + this.rubriqueValue,
                idObjet: this.tabSelectedCheckBoxs[0].split("check_")[1],
                action: 1, // Modification
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

        this.action_afficherMessage("Fermeture", "Redirection à la page d'acceuil...");
        window.location.href = "/admin/entreprise";
    }

    /**
     * @description Gère l'événement de parametrage de la liste.
     * @param {CustomEvent} event L'événement personnalisé déclenché.
     */
    handleParametrer(event) {
        console.log(this.nomControleur + " - EVENEMENT RECU: PAREMETRER CETTE LISTE");
        // Tu peux aussi prévenir la propagation de l'événement si nécessaire
        event.stopPropagation();
        
        this.action_afficherMessage("Paramètres", "Redirection vers la page des paramètres du compte...");
        window.location.href = "/register";
    }

    /**
     * @description Gère l'événement de la recharge ou actualisation de la liste.
     * @param {CustomEvent} event L'événement personnalisé déclenché.
     */
    handleRecharger(event) {
        console.log(this.nomControleur + " - EVENEMENT RECU: RECHARGER CETTE LISTE");
        // Tu peux aussi prévenir la propagation de l'événement si nécessaire
        event.stopPropagation();
        //On lance le rechargement de la page / liste
        this.outils_recharger(event);
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
        console.log(this.nomControleur + " - Actualisation de la liste principale", event.currentTarget);
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
                this.publierSelection();
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