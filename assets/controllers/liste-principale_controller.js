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
    ];
    static values = {
        controleurphp: String,
        objet: Number,
        identreprise: Number,
        idutilisateur: Number,
        nbelements: Number,
        rubrique: String,
    };


    connect() {
        this.tabSelectedCheckBoxs = [];
        console.log("Liste " + this.rubriqueValue);
        this.updateMessage("Prêt.");

        //On défini les écouteurs ici
        this.btToutCocherTarget.addEventListener('change', (event) => this.cocherTousElements(event));
        this.initialiserBarreDoutils();

        //On initialise le tooltips
        const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
        const tooltipList = [...tooltipTriggerList].map(tooltipTriggerEl => new bootstrap.Tooltip(tooltipTriggerEl));
    }


    initialiserBarreDoutils() {
        this.outilbtexitTarget.style.display = "block";
        this.outilbtsettingsTarget.style.display = "block";
        this.outilbtaddTarget.style.display = "block";

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
        // console.log("Coché?:" + isChecked);
        const checkBoxes = this.donneesTarget.querySelectorAll('input[type="checkbox"]');
        // console.log(event);
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
        // console.log("checkBox", event.currentTarget);
        const idSelectedCheckBox = event.currentTarget.getAttribute("id");
        const indexOfSelectedCheckBox = this.tabSelectedCheckBoxs.indexOf(idSelectedCheckBox);
        if (indexOfSelectedCheckBox == -1) {
            this.tabSelectedCheckBoxs.push(idSelectedCheckBox);
        } else {
            this.tabSelectedCheckBoxs.splice(indexOfSelectedCheckBox, 1);
        }
        // console.log(this.tabSelectedCheckBox);
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
    outils_ajouter(event) {
        const dialogue_controler = this.getDialogueController();
        event.currentTarget.dataset.itemAction = 0;
        event.currentTarget.dataset.itemObjet = -1;
        event.currentTarget.dataset.itemNomcontrolerphp = this.controleurphpValue;
        event.currentTarget.dataset.itemNomcontrolerstimulus = this.controleurphpValue + "-formulaire";
        event.currentTarget.dataset.itemTitre = this.rubriqueValue + " - Nouveau";
        console.log("DataSet pour ajout:", event.currentTarget.dataset);
        dialogue_controler.open(event);
    }


    /**
     * 
     * @param {Event} event 
     */
    outils_modifier(event) {
        if (this.tabSelectedCheckBoxs.length == 1) {
            const objetSelected = (this.tabSelectedCheckBoxs[0].split("_"))[1];
            if (objetSelected != -1) {
                const dialogue_controler = this.getDialogueController();
                event.currentTarget.dataset.itemAction = 1;
                event.currentTarget.dataset.itemObjet = objetSelected;
                event.currentTarget.dataset.itemNomcontrolerstimulus = this.controleurphpValue + "-formulaire";
                event.currentTarget.dataset.itemNomcontrolerphp = this.controleurphpValue;
                event.currentTarget.dataset.itemTitre = this.rubriqueValue + " - Edition";
                dialogue_controler.open(event);
            }
        } else {
            this.updateMessage("Impossible d'effectuer cette opération.");
        }
    }


    /**
    * 
    * @param {Event} event 
    */
    outils_supprimer(event) {
        console.log("Action Barre d'outils:", event.currentTarget);
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