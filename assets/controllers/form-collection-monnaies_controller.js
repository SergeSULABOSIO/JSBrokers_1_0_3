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
        btnAjouter.innerText = "Ajouter un élement";
        btnAjouter.addEventListener('click', this.addElement);
        
        this.element.childNodes.forEach(this.addDeleteButton);

        //Accordeon
        // const accordion = document.createElement("div");
        // accordion.setAttribute('class', "accordion");
        // accordion.setAttribute('id', "accordionExample");
        // const i = 1;
        // this.element.childNodes.forEach(elementCollection => {
        //     // this.addAccordion(i, accordion, elementCollection);
        //     i++;
        // });
        // this.element.append(accordion);



        // this.element.childNodes.forEach(this.addAccordion);
        // this.element.childNodes.forEach(this.addDeleteButton);
        this.element.append(btnAjouter);

        // this.element.innerText = "ECRASE!!!!!!!";
    }

    /**
     * @param {HTMLElement} elementDeLaCollection 
     */
    addAccordion = (i, accordion, elementDeLaCollection) => {
        // elementDeLaCollection.setAttribute('class', "border rounded border-secondary p-3 mb-2");
        // this.addDeleteButton(elementDeLaCollection);
        
        // const data = elementDeLaCollection.innerHTML;
        // elementDeLaCollection.innerHTML = "";
        
        //Accordeon-item
        const accordionItem = document.createElement("div");
        accordionItem.setAttribute('class', "accordion-item");
        //Accordeon-header
        const accordionHeader = document.createElement("h2");
        accordionHeader.setAttribute('class', "accordion-header");
        //Accordeon-button
        const accordionButton = document.createElement("button");
        accordionButton.setAttribute('class', "accordion-button collapsed");
        accordionButton.setAttribute('type', "button");
        accordionButton.setAttribute('data-bs-toggle', "collapse");
        accordionButton.setAttribute('data-bs-target', "#collapseTwo");
        accordionButton.setAttribute('aria-expanded', "false");
        accordionButton.setAttribute('aria-controls', "collapseTwo");
        accordionButton.innerText = "Accordion Item #1";
        //Accordeon-collapse
        const accordionCollapse = document.createElement("div");
        accordionCollapse.setAttribute('id', "collapseTwo");
        accordionCollapse.setAttribute('class', "accordion-collapse collapse");
        accordionCollapse.setAttribute('data-bs-parent', "#accordionExample");
        //Accordeon-body
        const accordionBody = document.createElement("div");
        accordionBody.setAttribute('class', "accordion-body");
        accordionBody.innerHTML = "<strong>Voici un petit exemple.</strong> Juste pour montrer que ça marche.";
        // accordionBody.innerHTML = data;

        //Construction
        accordionCollapse.append(accordionBody);
        accordionHeader.append(accordionButton);
        accordionItem.append(accordionHeader);
        accordionItem.append(accordionCollapse);
        accordion.append(accordionItem);

        // this.element.append(accordion);
        
        // elementDeLaCollection.innerHTML = data;
        // elementDeLaCollection.append(accordion);
    }


    /**
     * @param {HTMLElement} item 
     */
    addDeleteButton = (item) => {
        const btnSupprimer = document.createElement("button");
        btnSupprimer.setAttribute('class', "btn btn-danger mt-2");
        btnSupprimer.setAttribute('type', "button");
        btnSupprimer.innerText = "Supprimer";
        btnSupprimer.addEventListener('click', e => {
            e.preventDefault();
            item.remove();
        });
        item.append(btnSupprimer);
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
