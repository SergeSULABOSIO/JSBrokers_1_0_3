import { Controller } from '@hotwired/stimulus';

export default class extends Controller {

    //Les données passées depuis le template HTML à utiliser à travers tout le controlleur
    static values = {
        addLabel: String,
        deleteLabel: String,
        editLabel: String,
        closeLabel: String,
        newElementLabel: String,
        icone: String,
        dossieraction: String
    }


    connect() {
        //DECLARATION DES VARIABLES
        this.tabDownloadedIcones = new Map();
        this.index = 0;
        this.collection = this.element;
        this.tailleCollection = this.collection.childElementCount;
        // console.log("Nombre d'elements existants = " + this.nbElement);

        this.stylerElementsDeLaCollection(this.collection);
        this.setBoutonAjouter(this.collection);
    }



    /**
     * @param {HTMLElement} objetCollection
     */
    stylerElementsDeLaCollection = (objetCollection) => {
        objetCollection.childNodes.forEach(elementDeLaCollection => {
            //On lui attribut une bordure stylée
            this.appliquerStyle(elementDeLaCollection);
        });
    }


    /**
     * @param {HTMLElement} elementCollection
     */
    appliquerStyle(elementCollection) {
        //On lui attribut une bordure stylée
        elementCollection.setAttribute('class', "shadow-sm rounded mb-2 sensible bg-white");
        //Formulaire de saisie
        const formulaire = document.getElementById(elementCollection.firstElementChild.getAttribute("id"));
        if (formulaire != null) {
            //on cache le formulaire
            formulaire.setAttribute("class", "cacherComposant");
        }
        //On construit la barre de titre qui transport les textes display principal et secondaire
        this.setBarreDeTitre(formulaire, elementCollection);
    }


    /**
     * @param {int} idFormulaireSaisie 
     * @param {HTMLElement} champDisplayPrincipal 
     */
    ecouterFormulaire = (idFormulaireSaisie, champDisplayPrincipal) => {
        if (idFormulaireSaisie != null) {
            const formulaireEncours = document.getElementById(idFormulaireSaisie);
            if (formulaireEncours != null) {
                console.log(formulaireEncours);
                const champs = formulaireEncours.querySelectorAll('input, select, textarea, button');
                //parcours des elements du formulaire
                champs.forEach(champ => {
                    //ecouter tout changement de valeur
                    champ.addEventListener("change", (event) => this.enCasDeChangement(event, champ, formulaireEncours, champDisplayPrincipal));
                });
            }
        }
    }

    /**
     * @param {Event} event 
     * @param {HTMLElement} champ 
     * @param {HTMLFormElement} formulaire 
     * @param {HTMLElement} champDisplayPrincipal 
     * 
     */
    enCasDeChangement = (event, champ, formulaire, champDisplayPrincipal) => {
        event.preventDefault();

        // console.log("\tFormulaire: " + formulaire.getAttribute("id"));
        // console.log("\t\tChamp " + champ.name + ", valeur = " + event.target.value + ", type = " + champ.getAttribute("type"));

        switch (champ.getAttribute("type")) {
            case "text":
                champ.setAttribute("value", event.target.value);
                break;
            case "checkbox":
                champ.setAttribute("checked", event.target.checked);
                break;
            default:
                break;
        }
        console.log("Target: ", event.target);

        //on actualise l'affichage sur le display
        this.actualiserDonneesDisplay(formulaire, champDisplayPrincipal);
        // console.log(formulaire);
    }

    /**
     * 
     * @param {HTMLFormElement} formulaire 
     * @param {HTMLElement} champDisplayPrincipal 
     * @param {HTMLElement} champDisplaySecondaire 
     * 
     */
    actualiserDonneesDisplay = (formulaire, champDisplayPrincipal, champDisplaySecondaire) => {
        if (formulaire != null) {
            const champs = formulaire.querySelectorAll('input, select, textarea, button');
            //le premier champs de type Text à affecter au display principal
            var texteDisplayPrincipal = "";
            var idChampDisplayPrincipal = null;
            champs.forEach(champ => {
                if (champ.getAttribute('type') == "text") {
                    idChampDisplayPrincipal = champ.getAttribute("id");
                    texteDisplayPrincipal = champ.getAttribute("value");
                }
            })
            champDisplayPrincipal.innerHTML = texteDisplayPrincipal;

            //on cherche ensuite le reste pour affecter au display secondaire
            var texteDisplaySecondaire = "";
            var nbChampsSecondaire = 0;
            champs.forEach(champ => {
                if (champ.getAttribute('id') != idChampDisplayPrincipal) {
                    switch (champ.getAttribute("type")) {
                        case "text":
                            texteDisplaySecondaire += champ.getAttribute("id") + ": " + champ.getAttribute("type") + "=" + champ.getAttribute("value") + " | ";
                            break;
                        case "checkbox":
                            texteDisplaySecondaire += champ.getAttribute("id") + ": Name = " + champ.getAttribute("name") + ", " + champ.getAttribute("type") + "=" + champ.getAttribute("value") + "("+ champ.getAttribute("checked") +") |\n ";
                            break;

                        default:
                            break;
                    }
                    nbChampsSecondaire++;
                }
            })
            champDisplaySecondaire.innerHTML = texteDisplaySecondaire;
            // console.log("");
            // console.log("\tFormulaire: " + formulaire.getAttribute("id"));
            // console.log("\tTexte principal: " + texteDisplayPrincipal);
            // console.log("\tTexte secondaire (" + nbChampsSecondaire + "): \n" + texteDisplaySecondaire);
        }
    }




    /**
     * @param {HTMLElement} objetCollection
    */
    setBoutonAjouter = (objetCollection) => {
        //creation du bouton
        const btnAjouterElementCollection = document.createElement("button");
        btnAjouterElementCollection.setAttribute('class', "btn btn-outline-secondary");
        btnAjouterElementCollection.setAttribute('type', "button");
        btnAjouterElementCollection.innerHTML = this.addLabelValue || "Add";
        //definir l'icone
        this.downloadIcone(btnAjouterElementCollection, " " + this.addLabelValue || "Add", 1, "add", 20);
        //definir l'ecouteur de clic
        btnAjouterElementCollection.addEventListener('click', this.creerNewElementCollection);
        //ajoute le bouton en bas de la collection
        objetCollection.append(btnAjouterElementCollection);
    }






    /**
     * 
     * @param {HTMLElement} elementDeLaCollection 
     * @param {int} inAction 
     * @param {string} icone 
     * @param {string} texteAAjouter 
     * @param {int} taille
     *  
     */
    downloadIcone = (elementHtml, texteAAjouter, inAction, icone, taille) => {
        //Chargement de l'icones du bouton
        var url = '/admin/entreprise/geticon/' + inAction + '/' + icone + '/' + taille;
        fetch(url) // L'URL de votre route Symfony
            .then(response => response.text())
            .then(htmlData => {
                this.updatTabIcones(url, htmlData);
                elementHtml.innerHTML = htmlData + texteAAjouter;
            })
            .catch(error => {
                console.error('Erreur lors du chargement du fragment:', error);
            });
    }

    /**
     * 
     * @param {string} url 
     * @param {HTMLElement} htmlData 
     */
    updatTabIcones(url, htmlData) {
        if (this.tabDownloadedIcones.has(url) == false) {
            this.tabDownloadedIcones.set(url, htmlData);
        }
        // console.log(this.tabDownloadedIcones);
    }

    /**
     * 
     * @param {string} url 
     */
    getIconeLocale(url) {
        // console.log(url);
        var icone = null;
        for (const paire of this.tabDownloadedIcones.entries()) {
            if (paire[0] == url) {
                icone = paire[1];
            }
        }
        return icone;
    }


    /**
     * 
     * @param {HTMLElement} formulaire 
     * @param {HTMLElement} elementDeLaCollection 
     */
    setBarreDeTitre = (formulaire, elementDeLaCollection) => {
        //creation du bouton supprimer
        const btnSupprimer = document.createElement("button");
        btnSupprimer.setAttribute('class', "btn border-0 btn-outline-danger");
        btnSupprimer.setAttribute('type', "button");

        //Chargement de l'icone, choix entre serveur ou image stockée dans la mémoire locale
        var iconeSupprimer = this.getIconeLocale('/admin/entreprise/geticon/1/delete/18');
        if (iconeSupprimer != null) {
            btnSupprimer.innerHTML = iconeSupprimer; //Ok!
        } else {
            btnSupprimer.innerHTML = this.downloadIcone(btnSupprimer, "", 1, "delete", 18);
            btnSupprimer.innerHTML = this.deleteLabelValue || "Delete";
        }

        const barreOutilDisplay = document.createElement("div");
        barreOutilDisplay.setAttribute('class', "options-du-parent");
        barreOutilDisplay.append(btnSupprimer);

        //creation du span
        const spanDisplayTexte = document.createElement("span");
        spanDisplayTexte.setAttribute("class", "fw-bold text-primary");
        spanDisplayTexte.innerHTML = "...";

        const spanDisplayIcon = document.createElement("span");
        spanDisplayIcon.setAttribute("class", "fw-bold text-primary");

        //Chargement de l'icone, choix entre serveur ou image stockée dans la mémoire locale
        this.setIconSpanDisplay(spanDisplayIcon, "");

        //creation du div
        const barreDeTitre = document.createElement("nav");
        barreDeTitre.setAttribute("class", "navbar parent-a-options");
        //Ajout des elements de viesualisation au div

        //Description
        const spanDescriptionIcon = document.createElement("span");
        spanDescriptionIcon.setAttribute("class", "text-secondary");
        this.setIconSpanDescriptionDisplay(spanDescriptionIcon);

        const spanDisplaySecondaire = document.createElement("span");
        spanDisplaySecondaire.setAttribute("class", "text-secondary m-2");
        spanDisplaySecondaire.innerHTML = "Brève description d'au moins 3 premiers paramètres de l'élémenet.";

        const groupDisplay = document.createElement("span");
        groupDisplay.setAttribute("class", "");
        groupDisplay.append(spanDisplayIcon);
        groupDisplay.append(spanDisplayTexte);
        groupDisplay.append(document.createElement("br"));
        groupDisplay.append(spanDescriptionIcon);
        groupDisplay.append(spanDisplaySecondaire);

        barreDeTitre.append(groupDisplay);
        barreDeTitre.append(barreOutilDisplay);
        //on ecoute les clicks sur la barre de titre
        barreDeTitre.addEventListener('click', e => {
            e.preventDefault();
            // elementDeLaCollection.remove();
            if (formulaire != null) {
                if (formulaire.hasAttribute("class", "cacherComposant")) {
                    formulaire.removeAttribute("class", "cacherComposant");
                } else {
                    formulaire.setAttribute("class", "cacherComposant");
                }
            }
        });
        //on ecoute le bouton supprimer
        btnSupprimer.addEventListener('click', e => {
            e.preventDefault();
            elementDeLaCollection.remove();
        });

        elementDeLaCollection.append(barreDeTitre);
        //On active les ecouteurs sur tous les champs du formulaire
        this.ecouterFormulaire(formulaire.getAttribute('id'), spanDisplayTexte);
        this.actualiserDonneesDisplay(formulaire, spanDisplayTexte);
        this.index++;
    }

    /**
     * 
     * @param {HTMLElement} spanDisplayTexte 
     * @param {string} valeurDisplay 
     */
    setIconSpanDisplay(spanDisplayTexte, valeurDisplay) {
        var dossier = "0";
        if (this.dossieractionValue != null) {
            dossier = this.dossieractionValue;
        }
        var nomIcone = "invite";
        if (this.iconeValue != null) {
            nomIcone = this.iconeValue;
        }
        var iconeDisplay = this.getIconeLocale("/admin/entreprise/geticon/" + dossier + "/" + nomIcone + "/19");
        if (iconeDisplay != null) {
            spanDisplayTexte.innerHTML = iconeDisplay + " " + valeurDisplay; //Ok!
        } else {
            spanDisplayTexte.innerHTML = this.downloadIcone(spanDisplayTexte, " " + valeurDisplay, dossier, nomIcone, 19);
            spanDisplayTexte.innerHTML = valeurDisplay;
        }
    }

    setIconSpanDescriptionDisplay(spanDisplayTexte) {
        var dossier = "1";
        var nomIcone = "description";
        var iconeDisplay = this.getIconeLocale("/admin/entreprise/geticon/" + dossier + "/" + nomIcone + "/16");
        if (iconeDisplay != null) {
            spanDisplayTexte.innerHTML = iconeDisplay; //Ok!
        } else {
            spanDisplayTexte.innerHTML = this.downloadIcone(spanDisplayTexte, "", dossier, nomIcone, 16);
            spanDisplayTexte.innerHTML = "...";
        }
    }


    /**
     * @param {MouseEvent} e
     */
    creerNewElementCollection = (e) => {
        e.preventDefault();

        const elementPrototype = document.createRange().createContextualFragment(
            this.element.dataset['prototype'].replaceAll('__name__', this.index)
        ).firstElementChild;
        e.currentTarget.insertAdjacentElement("beforebegin", elementPrototype);

        this.appliquerStyle(elementPrototype);
    }
}
