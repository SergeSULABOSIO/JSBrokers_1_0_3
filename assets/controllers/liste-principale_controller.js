import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = [
        'display',  //Champ d'affichage d'informations
        'donnees',  //Liste conténant des élements
        'btToutCocher',
        // Les boutons de la barre d'outils
        'outilbtexit',
        'outilbtsettings',
        'outilbtadd',
        'outilbtedit',
        'outilbtdelete',
        'outilbtrecharger',
    ];
    static values = {
        controleurphp: String,
        controleursitimulus: String,
        objet: Number,
        identreprise: Number,
        idutilisateur: Number,
        nbelements: Number,
        rubrique: String,
    };


    connect() {
        this.init();
    }

    init() {
        this.tabSelectedCheckBoxs = [];
        this.controleurDeLaBoiteDeDialogue = this.getDialogueController();
        //il doit se faire connaitre au près du controleur parent.
        this.controleurDeLaBoiteDeDialogue.controleurDeLaListePrincipale = this;

        //On défini les écouteurs ici
        this.btToutCocherTarget.addEventListener('change', (event) => this.cocherTousElements(event));
        this.initialiserBarreDoutils();
        this.initToolTips();
        this.updateMessage("Prêt.");
    }

    initToolTips() {
        //On initialise le tooltips
        const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
        const tooltipList = [...tooltipTriggerList].map(tooltipTriggerEl => new bootstrap.Tooltip(tooltipTriggerEl));
    }


    initialiserBarreDoutils() {
        this.outilbtexitTarget.style.display = "block";
        this.outilbtsettingsTarget.style.display = "block";
        this.outilbtaddTarget.style.display = "block";
        this.outilbtrechargerTarget.style.display = "block";

        if (this.tabSelectedCheckBoxs.length != 0) {
            this.outilbtdeleteTarget.style.display = "block";
            if (this.tabSelectedCheckBoxs.length >= 2) {
                this.outilbteditTarget.style.display = "none";
            } else {
                this.outilbteditTarget.style.display = "block";
            }
        } else {
            this.outilbteditTarget.style.display = "none";
            this.outilbtdeleteTarget.style.display = "none";
        }
    }


    /**
     * @param {Event} event 
    */
    selectionnerElement(event) {
        const cible = event.currentTarget;
        this.objetValue = cible.dataset.itemObjet;
        this.updateMessage("Séléction [id.=" + this.objetValue + "]");
        // console.log("Liste : Element selectionné: ", event.currentTarget, this.objetValue);
    }

    /**
     * @param {number} idObjet 
    */
    supprimerElement(idObjet) {
        this.objetValue = idObjet;
        this.updateMessage("Suppression de " + idObjet + " en cours... Patientez svp.");
        const url = '/admin/' + this.controleurphpValue + '/remove/' + this.identrepriseValue + '/' + this.objetValue;
        fetch(url) // Remplacez par l'URL de votre formulaire
            .then(response => response.json())
            .then(data => {
                const serverJsonObject = JSON.parse(data);
                // this.formTarget.innerHTML = html;
                if (serverJsonObject.reponse == "Ok") {
                    this.nbelementsValue--;
                    const elementDeleted = document.getElementById("liste_row_" + this.objetValue);
                    const parentElementDeleted = elementDeleted.parentElement;
                    parentElementDeleted.removeChild(elementDeleted);
                    this.updateMessage("Suppression réussie.");
                } else {
                    this.updateMessage("Suppression échouée. Merci de bien vérifier votre connexion Internet.");
                }
            });
    }


    /**
     * @param {Array} tabIDS 
    */
    supprimerElements(tabIDS) {
        const message = "Suppression de " + tabIDS.length + " éléments en cours... Patientez svp.";
        this.updateMessage(message);
        const url = '/admin/' + this.controleurphpValue + '/remove_many/' + this.identrepriseValue + '/' + tabIDS;
        fetch(url) // Remplacez par l'URL de votre formulaire
            .then(response => response.json())
            .then(data => {
                const serverJsonObject = JSON.parse(data);
                if (serverJsonObject.reponse == "Ok") {
                    this.nbelementsValue = this.nbelementsValue - serverJsonObject.deletedIds.length;
                    serverJsonObject.deletedIds.forEach(deletedID => {
                        const elementDeleted = document.getElementById("liste_row_" + deletedID);
                        const parentElementDeleted = elementDeleted.parentElement;
                        parentElementDeleted.removeChild(elementDeleted);
                        this.updateMessage("Suppression de " + deletedID + ".");
                    });
                    this.updateMessage("Suppression de " + serverJsonObject.deletedIds.length + " éléments réussie.");
                    this.tabSelectedCheckBoxs = [];
                } else {
                    this.updateMessage("Suppression échouée. Merci de bien vérifier votre connexion Internet.");
                }
            });
    }


    /**
    * 
    * @param {Event} event 
    */
    outils_supprimer(event) {
        //on doit le faire en boucle
        const tabIdObjetsToDelete = [];
        this.tabSelectedCheckBoxs.forEach(currentCheckBox => {
            tabIdObjetsToDelete.push(currentCheckBox.split("_")[1]);
        });
        this.supprimerElements(tabIdObjetsToDelete);
    }



    /**
     * 
     * @param {number} idObjet 
     */
    actualiserElement(idObjet) {
        this.updateMessage("Actualisation de l'élement " + idObjet + " en cours...");
        const url = '/admin/' + this.controleurphpValue + '/getlistelementdetails/' + this.identrepriseValue + "/" + idObjet;
        fetch(url) // Remplacez par l'URL de votre formulaire
            .then(response => response.text())
            .then(data => {
                const elementUpdated = document.getElementById("liste_row_" + idObjet);
                elementUpdated.innerHTML = data;
                this.updateMessage("La mise a jour réussie.");

                //On doit rétirer cet objet de la liste des séléction car il viendra du serveur avec une checkbox décochée.
                this.tabSelectedCheckBoxs.splice(this.tabSelectedCheckBoxs.indexOf(idObjet), 1);
                this.updateMessageSelectedCheckBoxes();
            })
            .catch(errorMessage => {
                this.updateMessage("La mise a jour échouée. Prière de bien vérifier votre connexion Internet.");
                console.error(errorMessage);
            });
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
    cocherTousElements(event) {
        const isChecked = this.btToutCocherTarget.checked;
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
    }



    /**
     * 
     * @param {Event} event 
     */
    cocherElement(event) {
        const idSelectedCheckBox = event.currentTarget.getAttribute("id");
        const indexOfSelectedCheckBox = this.tabSelectedCheckBoxs.indexOf(idSelectedCheckBox);
        if (indexOfSelectedCheckBox == -1) {
            this.tabSelectedCheckBoxs.push(idSelectedCheckBox);
        } else {
            this.tabSelectedCheckBoxs.splice(indexOfSelectedCheckBox, 1);
        }
        this.updateMessageSelectedCheckBoxes();
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
        this.initialiserBarreDoutils();
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


    /**
     * 
     * @param {Event} event 
     */
    outils_ajouter(event) {
        event.currentTarget.dataset.itemAction = 0;
        event.currentTarget.dataset.itemObjet = -1;
        event.currentTarget.dataset.itemTitre = this.rubriqueValue + " - Nouveau";
        this.controleurDeLaBoiteDeDialogue.open(event);
    }


    /**
     * 
     * @param {Event} event 
     */
    outils_modifier(event) {
        if (this.tabSelectedCheckBoxs.length == 1) {
            const objetSelected = (this.tabSelectedCheckBoxs[0].split("_"))[1];
            if (objetSelected != -1) {
                event.currentTarget.dataset.itemAction = 1;
                event.currentTarget.dataset.itemObjet = objetSelected;
                event.currentTarget.dataset.itemTitre = this.rubriqueValue + " - Edition";
                this.controleurDeLaBoiteDeDialogue.open(event);
            }
        } else {
            this.updateMessage("Impossible d'effectuer cette opération.");
        }
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