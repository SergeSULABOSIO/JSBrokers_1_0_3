import { Controller } from '@hotwired/stimulus';

export default class extends Controller {

    static values = {
        addLabel: String,
        deleteLabel: String
    }

    connect() {
        this.index = this.element.childElementCount;
        // console.log("Nombre d'elements = " + this.index);
        const btnAjouter = document.createElement("button");
        btnAjouter.setAttribute('class', "btn btn-secondary");
        btnAjouter.setAttribute('type', "button");
        btnAjouter.innerHTML = this.addLabelValue || "Add";
        btnAjouter.addEventListener('click', this.addElement);
        
        //Pour chaque element de la collection
        this.element.childNodes.forEach(elementDeLaCollection => {
            elementDeLaCollection.setAttribute('class', "border shadow rounded border-secondary p-3 mb-2 bg-white");
            this.addDeleteButton(elementDeLaCollection);
        });
        this.element.append(btnAjouter);
        this.element.setAttribute('class', "border border-0 rounded p-3 mb-2 bg-light");
    }

    

    /**
     * @param {HTMLElement} elementDeLaCollection 
     */
    addDeleteButton = (elementDeLaCollection) => {
        const btnSupprimer = document.createElement("button");
        btnSupprimer.setAttribute('class', "btn btn-danger mt-2");
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
        // console.log('On vient de cliquer sur le bouton "Ajouter un élement".' + e);
        const elementPrototype = document.createRange().createContextualFragment(
            this.element.dataset['prototype'].replaceAll('__name__', this.index)
        ).firstElementChild;
        elementPrototype.setAttribute('class', "border rounded border-secondary p-3 mb-2 bg-white");
        this.addDeleteButton(elementPrototype);
        this.index++;
        e.currentTarget.insertAdjacentElement("beforebegin", elementPrototype);
    }
}
