import { Controller } from '@hotwired/stimulus';

export default class extends Controller {

    static values = {
        addLabel: String,
        deleteLabel: String,
        editLabel: String,
        closeLabel: String,
        newElementLabel: String,
        viewField: String
    }

    connect() {
        //this.element contient la collection elle-même
        this.index = this.element.childElementCount;
        console.log("Nombre d'elements = " + this.index);

        //Construction de l'élement Bouton pour la supression de l'element de la collection
        const btnAjouter = document.createElement("button");
        btnAjouter.setAttribute('class', "btn btn-outline-secondary");//btn-secondary
        btnAjouter.setAttribute('type', "button");
        btnAjouter.innerHTML = this.addLabelValue || "Add";
        btnAjouter.addEventListener('click', this.addElement);
        
        //On ajoute le bouton SUPPRIMER et EDITER sur chaque element de la collection
        this.element.childNodes.forEach(elementDeLaCollection => {
            elementDeLaCollection.setAttribute('class', "border shadow rounded border-secondary p-3 mb-2 bg-white");
            // this.addDeleteButton(elementDeLaCollection);
            this.addViewPanel(elementDeLaCollection);
        });
        this.element.append(btnAjouter);
    }


    /**
     * 
     * @param {HTMLElement} elementDeLaCollection 
     */
    addViewPanel = (elementDeLaCollection) => {
        //Analyses
        var label = "Inconnu";
        var idForm = elementDeLaCollection.firstElementChild.getAttribute("id");
        var idChampDeVisualisation = idForm + "_" + this.viewFieldValue;
        var formulaire = document.getElementById(idForm);
        formulaire.setAttribute("class", "cacherComposant");
        console.log(" - Bloc cible: id = " + idChampDeVisualisation);
        if(document.getElementById(idChampDeVisualisation) != null){
            // console.log("J'ai trouvé '" + document.getElementById(idChampDeVisualisation).getAttribute("value") + "'.");
            label = document.getElementById(idChampDeVisualisation).getAttribute("value");
        }

        //creation du div
        const divElement = document.createElement("nav");
        divElement.setAttribute("class", "navbar sensible rounded p-2");
        //creation du span
        const spanElement = document.createElement("span");
        spanElement.setAttribute("class", "gras");
        spanElement.innerHTML = label;
        //creation du bouton edit
        const btnEdit = document.createElement("button");
        btnEdit.setAttribute('class', "btn border-0 btn-outline-secondary");
        btnEdit.setAttribute('type', "button");
        btnEdit.innerHTML = this.editLabelValue || "Edit"; //"Edit";
        btnEdit.addEventListener('click', e => {
            e.preventDefault();
            // elementDeLaCollection.remove();
           
            if(formulaire.hasAttribute("class", "cacherComposant")){
                formulaire.removeAttribute("class", "cacherComposant");
                btnEdit.innerHTML = this.closeLabelValue || "Close"; //"Close";
            }else{
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
        divElement.innerHTML = "";
        //Ajout des elements de viesualisation au div
        divElement.append(spanElement);
        const div = document.createElement("div");
        div.append(btnSupprimer);
        div.append(btnEdit);
        divElement.append(div);
        elementDeLaCollection.append(divElement);
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
        this.addDeleteButton(elementPrototype);
        this.index++;
        e.currentTarget.insertAdjacentElement("beforebegin", elementPrototype);
    }
}
