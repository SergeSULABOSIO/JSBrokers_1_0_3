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
        this.element.append(btn);
    }


    addElement = (e) => {
        e.preventDefault();
        // console.log('On vient de cliquer sur le bouton "Ajouter un élement".' + e);
    }
}
