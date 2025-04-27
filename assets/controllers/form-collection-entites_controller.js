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
        this.index = 0;
        this.collection = this.element;
        this.tailleCollection = this.collection.childElementCount;
        // console.log("Nombre d'elements existants = " + this.nbElement);

        this.setylerElementsDeLaCollection(this.collection);
        this.setBoutonAjouter(this.collection);
    }



    /**
     * @param {HTMLElement} objetCollection
     */
    setylerElementsDeLaCollection = (objetCollection) => {
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
        this.setIcone(btnAjouterElementCollection, " " + this.addLabelValue || "Add", 1, "add", 20);
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
    setIcone = (elementHtml, texteAAjouter, inAction, icone, taille) => {
        //Chargement de l'icones du bouton
        fetch('/admin/entreprise/geticon/' + inAction + '/' + icone + '/' + taille) // L'URL de votre route Symfony
            .then(response => response.text())
            .then(html => {
                elementHtml.innerHTML = html + texteAAjouter;
                // console.log("Pour icone: " + donneeHtmlExistant);
            })
            .catch(error => {
                console.error('Erreur lors du chargement du fragment:', error);
            });
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
        btnSupprimer.innerHTML = this.setIcone(btnSupprimer, "", 1, "delete", 18);
        btnSupprimer.setAttribute('type', "button");
        btnSupprimer.innerHTML = this.deleteLabelValue || "Delete";

        const barreOutilDisplay = document.createElement("div");
        barreOutilDisplay.setAttribute('class', "options-du-parent");
        barreOutilDisplay.append(btnSupprimer);

        //creation du span
        const spanDisplayTexte = document.createElement("span");
        spanDisplayTexte.setAttribute("class", "fw-bold text-secondary p-2");
        spanDisplayTexte.innerHTML = this.setIcone(spanDisplayTexte, " " + valeurDisplay, this.dossieractionValue || 0, this.iconeValue || "invite", 25);
        spanDisplayTexte.innerHTML = valeurDisplay;

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
     * @param {HTMLElement} elementDeLaCollection 
     */
    addDeleteButton = (elementDeLaCollection) => {
        //creation du span
        const spanElement = document.createElement("span");
        spanElement.setAttribute("class", "gras");
        spanElement.innerHTML = this.newElementLabelValue || "Add"; //"New collection element";
        //creation du div
        const divElement = document.createElement("nav");
        divElement.setAttribute("class", "navbar sensible rounded p-2");
        //creation du bouton supprimer
        const btnSupprimer = document.createElement("button");
        btnSupprimer.setAttribute('class', "btn border-0 btn-outline-danger");
        btnSupprimer.setAttribute('type', "button");
        btnSupprimer.innerHTML = this.deleteLabelValue || "Delete";
        btnSupprimer.addEventListener('click', e => {
            e.preventDefault();
            elementDeLaCollection.remove();
        });
        //Ajout du span dans le div
        divElement.innerHTML = "";
        //Ajout des elements de viesualisation au div
        divElement.append(spanElement);
        const div = document.createElement("div");
        div.append(btnSupprimer);
        // div.append(btnEdit);
        divElement.append(div);
        elementDeLaCollection.append(divElement);
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
