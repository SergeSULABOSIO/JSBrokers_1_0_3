import { Controller } from '@hotwired/stimulus';

export default class extends Controller {

    //Les données passées depuis le template HTML à utiliser à travers tout le controlleur
    static values = {
        addLabel: String,
        deleteLabel: String,
        editLabel: String,
        closeLabel: String,
        newElementLabel: String,
        viewField: String,
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
        const idFormulaireSaisie = elementCollection.firstElementChild.getAttribute("id");
        const formulaire = document.getElementById(idFormulaireSaisie);
        //Champ Text, considéré comme principal display
        const idChampDeVisualisation = idFormulaireSaisie + "_" + this.viewFieldValue;
        var valeurDisplay = "Inconnu";
        const champSaisieDisplay = document.getElementById(idChampDeVisualisation);
        if (champSaisieDisplay != null) {
            valeurDisplay = champSaisieDisplay.getAttribute("value");
        }
        
        //on cache le formulaire
        if (formulaire != null) {
            formulaire.setAttribute("class", "cacherComposant");
        }
        //On lui charge d'autres elements utiles pour manipuler son contenu
        this.setBarreDeTitre(valeurDisplay, formulaire, elementCollection, champSaisieDisplay);
    }


    /**
     * @param {int} idFormulaireSaisie 
     */
    parcourirFormulaire = (idFormulaireSaisie) => {
        if (idFormulaireSaisie != null) {
            const formulaireEncours = document.getElementById(idFormulaireSaisie);
            if (formulaireEncours != null) {
                // console.log(formulaireEncours);

                const champs = formulaireEncours.querySelectorAll('input, select, textarea, button');
                //parcours des elements du formulaire
                console.log("(" + this.index + ")***" + idFormulaireSaisie + "***");
                champs.forEach(champ => {
                    console.log("\t\tId: " + champ.id);
                    console.log("\t\tType: " + champ.tagName.toLowerCase());
                    console.log("\t\tNom: " + champ.name);
                    console.log("\t\tValeur: " + document.getElementById(champ.id).getAttribute("value"));
                    console.log("\t\t*******");
                    // Vous pouvez accéder à d'autres propriétés comme champ.value, champ.type, etc.
                });
            }
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
     * @param {HTMLElement} champDeSaisieDisplay 
     * @param {HTMLElement} elementDeLaCollection 
     * @param {string} valeurDisplay 
     */
    setBarreDeTitre = (valeurDisplay, formulaire, elementDeLaCollection, champDeSaisieDisplay) => {
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
        spanDisplayTexte.innerHTML = valeurDisplay;

        const spanDisplayIcon = document.createElement("span");
        spanDisplayIcon.setAttribute("class", "fw-bold text-primary");

        //Chargement de l'icone, choix entre serveur ou image stockée dans la mémoire locale
        this.setIconSpanDisplay(spanDisplayIcon, "");

        //creation du div
        const barreDeTitre = document.createElement("nav");
        barreDeTitre.setAttribute("class", "navbar parent-a-options");
        //Ajout des elements de viesualisation au div


        const groupDisplay = document.createElement("span");
        groupDisplay.setAttribute("class", "fw-bold text-primary m-2");
        groupDisplay.append(spanDisplayIcon);
        groupDisplay.append(spanDisplayTexte);

        barreDeTitre.append(groupDisplay);
        barreDeTitre.append(barreOutilDisplay);

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

        btnSupprimer.addEventListener('click', e => {
            e.preventDefault();
            elementDeLaCollection.remove();
        });

        elementDeLaCollection.append(barreDeTitre);

        if (champDeSaisieDisplay != null) {
            champDeSaisieDisplay.addEventListener("change", function (event) {
                if (spanDisplayTexte != null) {
                    spanDisplayTexte.innerHTML = event.target.value;
                }
            })
        }

        //On parcours le formulaire
        this.parcourirFormulaire(formulaire.getAttribute('id'));

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
