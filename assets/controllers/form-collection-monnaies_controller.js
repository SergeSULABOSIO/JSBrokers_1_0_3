import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    connect() {
        const btn = document.createElement("button")
        btn.setAttribute('class', "btn btn-secondary")
        btn.setAttribute('type', "button")
        btn.innerText = "Ajouter un élement"
        btn.addEventListener('click', this.addElement)
        this.element.append(btn)
        // console.log('hello, je suis le form collection controller stumilus!')
    }


    addElement = (e) => {
        e.preventDefault()
    }
}
