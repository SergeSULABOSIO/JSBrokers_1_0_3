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
        btnAjouter.innerText = this.addLabel || "Ajouter un élement";
        btnAjouter.addEventListener('click', this.addElement);
        
        //Pour chaque element de la collection
        this.element.childNodes.forEach(elementDeLaCollection => {
            elementDeLaCollection.setAttribute('class', "border rounded border-secondary p-3 mb-2");
            this.addDeleteButton(elementDeLaCollection);
        });
        this.element.append(btnAjouter);
    }

    

    /**
     * @param {HTMLElement} elementDeLaCollection 
     */
    addDeleteButton = (elementDeLaCollection) => {
        const btnSupprimer = document.createElement("button");
        btnSupprimer.setAttribute('class', "btn btn-danger mt-2");
        btnSupprimer.setAttribute('type', "button");
        btnSupprimer.innerText = this.deleteLabel || "Supprimer";
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
        const element = document.createRange().createContextualFragment(
            this.element.dataset['prototype'].replaceAll('__name__', this.index)
        ).firstElementChild;
        element.setAttribute('class', "border rounded border-secondary p-3 mb-2");
        this.addDeleteButton(element);
        this.index++;
        e.currentTarget.insertAdjacentElement("beforebegin", element);
    }
}
