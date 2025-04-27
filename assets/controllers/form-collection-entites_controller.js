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
        const idFormulaireSaisie = elementCollection.firstElementChild.getAttribute("id");
        const idChampDeVisualisation = idFormulaireSaisie + "_" + this.viewFieldValue;
        var valeurDisplay = "Inconnu";
        if (document.getElementById(idChampDeVisualisation) != null) {
            valeurDisplay = document.getElementById(idChampDeVisualisation).getAttribute("value") + " ";
        }
        const formulaire = document.getElementById(idFormulaireSaisie);
        if (formulaire != null) {
            //on cache le formulaire
            formulaire.setAttribute("class", "cacherComposant");
        }
        //On lui charge d'autres elements utiles pour manipuler son contenu
        this.setBarreDeTitre(valeurDisplay, formulaire, elementCollection);
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
     * @param {string} valeurDisplay 
     */
    setBarreDeTitre = (valeurDisplay, formulaire, elementDeLaCollection) => {
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
        spanDisplayTexte.setAttribute("class", "fw-bold text-primary m-2");

        //Chargement de l'icone, choix entre serveur ou image stockée dans la mémoire locale
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

        //creation du div
        const barreDeTitre = document.createElement("nav");
        barreDeTitre.setAttribute("class", "navbar parent-a-options");
        //Ajout des elements de viesualisation au div
        barreDeTitre.append(spanDisplayTexte);
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
        this.index++;
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
