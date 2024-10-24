import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    connect() {
        this.index = this.element.childElementCount;
        // console.log("Nombre d'elements = " + this.index);
        const btn = document.createElement("button");
        btn.setAttribute('class', "btn btn-secondary");
        btn.setAttribute('type', "button");
        btn.innerText = "Ajouter un élement";
        btn.addEventListener('click', this.addElement);
        this.element.childNodes.forEach(this.addDeleteButton);
        this.element.append(btn);
    }


    /**
     * 
     * @param {HTMLElement} item 
     */
    addDeleteButton = (item) => {
        const btn = document.createElement("button");
        btn.setAttribute('class', "btn btn-danger");
        btn.setAttribute('type', "button");
        btn.innerText = "Supprimer";
        btn.addEventListener('click', e => {
            e.preventDefault();
            item.remove();
        });
        item.append(btn);
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
        this.addDeleteButton(element);
        this.index++;
        e.currentTarget.insertAdjacentElement("beforebegin", element);
    }
}
