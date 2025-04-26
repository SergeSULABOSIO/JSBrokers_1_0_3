import { Controller } from '@hotwired/stimulus';

export default class extends Controller {

    //Les données passées depuis le template HTML à utiliser à travers tout le controlleur
    static values = {
        addLabel: String,
        deleteLabel: String,
        editLabel: String,
        closeLabel: String,
        newElementLabel: String,
        viewField: String
    }




    connect() {
        //DECLARATION DES VARIABLES
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
            elementDeLaCollection.setAttribute('class', "shadow-sm rounded mb-2 sensible bg-white");
            const idFormulaireSaisie = elementDeLaCollection.firstElementChild.getAttribute("id");
            const idChampDeVisualisation = idFormulaireSaisie + "_" + this.viewFieldValue;
            var valeurDisplay = "Inconnu";
            if (document.getElementById(idChampDeVisualisation) != null) {
                valeurDisplay = document.getElementById(idChampDeVisualisation).getAttribute("value") + " ";
            }
            const formulaire = document.getElementById(idFormulaireSaisie);
            //on cache le formulaire
            formulaire.setAttribute("class", "cacherComposant");

            
            //On lui charge d'autres elements utiles pour manipuler son contenu
            this.setBarreDeTitre(valeurDisplay, formulaire, elementDeLaCollection);
        });
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
        this.setIcone(btnAjouterElementCollection, true, "add", 20);
        //definir l'ecouteur de clic
        btnAjouterElementCollection.addEventListener('click', this.addElement);
        //ajoute le bouton en bas de la collection
        objetCollection.append(btnAjouterElementCollection);
    }






    /**
     * 
     * @param {HTMLElement} elementDeLaCollection 
     * @param {boolean} inAction 
     * @param {string} icone 
     * @param {int} taille
     *  
     */
    setIcone = (elementHtml, inAction, icone, taille) => {
        //Chargement de l'icones du bouton
        fetch('/admin/entreprise/geticon/' + inAction + '/' + icone + '/' + taille) // L'URL de votre route Symfony
            .then(response => response.text())
            .then(html => {
                var donneeHtmlExistant = elementHtml.innerHTML;
                // console.log(donneeHtmlExistant);
                elementHtml.innerHTML = html + " " + donneeHtmlExistant;
                // elementHtml.append(barreDeTitre);
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
        //creation du div
        const barreDeTitre = document.createElement("nav");
        barreDeTitre.setAttribute("class", "navbar");
        //creation du span
        const spanElement = document.createElement("span");
        spanElement.setAttribute("class", "fw-bold text-secondary");
        spanElement.innerHTML = " "+ valeurDisplay;
        spanElement.innerHTML = " " + this.setIcone(spanElement, false, "invite", 25);
        //creation du bouton edit
        const btnEdit = document.createElement("button");
        btnEdit.setAttribute('class', "btn border-0 btn-outline-secondary");
        btnEdit.setAttribute('type', "button");
        btnEdit.innerHTML = this.editLabelValue || "Edit"; //"Edit";
        btnEdit.addEventListener('click', e => {
            e.preventDefault();
            // elementDeLaCollection.remove();

            if (formulaire.hasAttribute("class", "cacherComposant")) {
                formulaire.removeAttribute("class", "cacherComposant");
                btnEdit.innerHTML = this.closeLabelValue || "Close"; //"Close";
            } else {
                formulaire.setAttribute("class", "cacherComposant");
                btnEdit.innerHTML = this.editLabelValue || "Edit"; //"Edit";
            }
        });
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
        barreDeTitre.innerHTML = "";
        //Ajout des elements de viesualisation au div
        barreDeTitre.append(spanElement);
        const div = document.createElement("div");
        div.append(btnSupprimer);
        div.append(btnEdit);
        barreDeTitre.append(div);
        elementDeLaCollection.append(barreDeTitre);
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
    addElement = (e) => {
        e.preventDefault();
        // console.log('On vient de cliquer sur le bouton "Ajouter un élement".' + e + ". Son id est = " + this.element.getAttribute("id"));
        const elementPrototype = document.createRange().createContextualFragment(
            this.element.dataset['prototype'].replaceAll('__name__', this.index)
        ).firstElementChild;
        elementPrototype.setAttribute('class', "border rounded border-secondary p-3 mb-2 bg-white");
        // elementPrototype.setAttribute('class', "border rounded bg-white");
        this.addDeleteButton(elementPrototype);
        this.index++;
        e.currentTarget.insertAdjacentElement("beforebegin", elementPrototype);
    }
}
