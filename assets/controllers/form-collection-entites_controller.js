import { Controller } from '@hotwired/stimulus';

export default class extends Controller {

    static values = {
        addLabel: String,
        deleteLabel: String
    }

    connect() {
        //this.element contient la collection elle-même
        this.index = this.element.childElementCount;
        console.log("Nombre d'elements = " + this.index);

        //Construction de l'élement Bouton pour la supression de l'element de la collection
        const btnAjouter = document.createElement("button");
        btnAjouter.setAttribute('class', "btn btn-secondary");
        btnAjouter.setAttribute('type', "button");
        btnAjouter.innerHTML = this.addLabelValue || "Add";
        btnAjouter.addEventListener('click', this.addElement);
        
        //On ajoute le bouton SUPPRIMER sur chaque element de la collection
        this.element.childNodes.forEach(elementDeLaCollection => {
            elementDeLaCollection.setAttribute('class', "border shadow rounded border-secondary p-3 mb-2 bg-white");
            // this.addDeleteButton(elementDeLaCollection);
            this.addViewPanel(elementDeLaCollection);

            //Analyses
            var idElementCollection = elementDeLaCollection.firstElementChild.getAttribute("id");
            console.log(" - Bloc cible: id = " + idElementCollection);
            var cibleACacher = document.getElementById(idElementCollection);
            cibleACacher.setAttribute("class", "cacherComposant");
            console.log(" - Bloc cible: id = " + idElementCollection + " | class = " + cibleACacher.getAttribute("class"));

            //Fin d'analyse
        });
        this.element.append(btnAjouter);
    }


    /**
     * 
     * @param {HTMLElement} elementDeLaCollection 
     */
    addViewPanel = (elementDeLaCollection) => {
        //creation du div
        const divElement = document.createElement("nav");
        divElement.setAttribute("class", "navbar");
        //creation du span
        const spanElement = document.createElement("span");
        spanElement.setAttribute("class", "");
        spanElement.innerHTML = "Texte représentant l'element de la collection";
        //creation du bouton edit
        const btnEdit = document.createElement("button");
        btnEdit.setAttribute('class', "btn border-0 btn-outline-secondary");
        btnEdit.setAttribute('type', "button");
        btnEdit.innerHTML = "Edit";
        btnEdit.addEventListener('click', e => {
            e.preventDefault();
            // elementDeLaCollection.remove();
            var formulaire = document.getElementById(elementDeLaCollection.firstElementChild.getAttribute("id"));
            if(formulaire.hasAttribute("class", "cacherComposant")){
                formulaire.removeAttribute("class", "cacherComposant");
                btnEdit.innerHTML = "Close";
            }else{
                formulaire.setAttribute("class", "cacherComposant");
                btnEdit.innerHTML = "Edit";
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
        divElement.innerHTML = "";
        //Ajout des elements de viesualisation au div
        divElement.append(spanElement);
        const div = document.createElement("div");
        div.append(btnSupprimer);
        div.append(btnEdit);
        divElement.append(div);
        // divElement.append(btnSupprimer);
        // divElement.append(btnEdit);
        elementDeLaCollection.append(divElement);
    }
    

    /**
     * @param {HTMLElement} elementDeLaCollection 
     */
    addDeleteButton = (elementDeLaCollection) => {
        const btnSupprimer = document.createElement("button");
        btnSupprimer.setAttribute('class', "btn btn-danger");
        btnSupprimer.setAttribute('type', "button");
        btnSupprimer.innerHTML = this.deleteLabelValue || "Delete";
        btnSupprimer.addEventListener('click', e => {
            e.preventDefault();
            elementDeLaCollection.remove();
        });
        elementDeLaCollection.append(btnSupprimer);
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
        this.addDeleteButton(elementPrototype);
        this.index++;
        e.currentTarget.insertAdjacentElement("beforebegin", elementPrototype);
    }
}
