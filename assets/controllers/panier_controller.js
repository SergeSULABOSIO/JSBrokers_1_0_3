import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    // static targets = [
    //     'nom',
    // ];

    static values = {
        identreprise: String,
    };

    connect() {
        console.log("Controleur du panier connecté!", "Entreprise = " + this.identrepriseValue);
        console.log(this.element);
    }

    /**
     * 
     * @param {MouseEvent} event 
     */
    detruirepanier = (event) => {
        event.preventDefault(); // Empêche la soumission classique du formulaire
        console.log("ON ME DEMANDE DE DETRUIRE LE PANIER!!!!!", window.location.href);
        // window.location.href = "/admin/tranche/index/" + this.identrepriseValue;

        fetch('/admin/note/getpanier/' + this.identrepriseValue) // L'URL de votre route Symfony
            .then(response => response.text())
            .then(htmlData => {
                conteneurPanier.innerHTML = htmlData;
            })
            .catch(error => {
                conteneurPanier.innerHTML = "Désolé, une erreur s'est produite! Merci d'actualiser la page ou vérifier votre connexion Internet";
                console.error('Erreur lors du chargement du fragment:', conteneurPanier, error);
            });
    }
}