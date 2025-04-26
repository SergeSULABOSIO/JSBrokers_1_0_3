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
        this.collection = this.element;
        this.tailleCollection = this.collection.childElementCount;
        this.btnAjouter = document.createElement("button");




        // console.log("Nombre d'elements existants = " + this.nbElement);

        //Construction de l'élement Bouton d'ajout
        // const btnAjouter = document.createElement("button");
        this.btnAjouter.setAttribute('class', "btn btn-outline-secondary");//btn-secondary
        this.btnAjouter.setAttribute('type', "button");
        this.btnAjouter.innerHTML = this.addLabelValue || "Add";
        this.btnAjouter.addEventListener('click', this.addElement);
        
        //Boucle: Pour chaque element de la collection
        this.element.childNodes.forEach(elementDeLaCollection => {
            //On lui attribut une bordure stylée
            elementDeLaCollection.setAttribute('class', "border p-3 shadow-sm rounded mb-2 bg-white");
            //On lui charge d'autres elements utiles pour manipuler son contenu
            this.setBarreDeTitre(elementDeLaCollection);
        });
        this.collection.append(this.btnAjouter);
    }


    /**
     * 
     * @param {HTMLElement} elementDeLaCollection 
     */
    setBarreDeTitre = (elementDeLaCollection) => {
        //Analyses
        var label = "Inconnu";
        var idForm = elementDeLaCollection.firstElementChild.getAttribute("id");
        var idChampDeVisualisation = idForm + "_" + this.viewFieldValue;
        var formulaire = document.getElementById(idForm);
        formulaire.setAttribute("class", "cacherComposant");
        // console.log(" - BBBBBBloc cible: id = " + idChampDeVisualisation);
        if(document.getElementById(idChampDeVisualisation) != null){
            // console.log("J'ai trouvé '" + document.getElementById(idChampDeVisualisation).getAttribute("value") + "'.");
            label = document.getElementById(idChampDeVisualisation).getAttribute("value");
        }

        //creation du div
        const barreDeTitre = document.createElement("nav");
        barreDeTitre.setAttribute("class", "navbar sensible rounded bg-danger");
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
