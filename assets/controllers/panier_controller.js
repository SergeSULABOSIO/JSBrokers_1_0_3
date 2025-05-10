import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = [
        'corps',
    ];

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
        // console.log("ON ME DEMANDE DE DETRUIRE LE PANIER!!!!!", window.location.href);

        event.target.disabled = true;
        this.corpsTarget.innerHTML = "Destruction du panier en cours...";

        fetch('/admin/note/viderpanier/' + this.identrepriseValue + "/" + window.location.href) // L'URL de votre route Symfony
            .then(response => response.text())
            .then(reponseServeur => {
                if (reponseServeur == "ok") {
                    this.corpsTarget.innerHTML = "Le panier a été détruit.";
                    this.element.style.display = "none";
                }else{
                    this.corpsTarget.innerHTML = "Une erreur s'est produite! Merci d'actualiser cette page.";
                }
                event.target.disabled = false;
            })
            .catch(error => {
                console.error('Erreur', conteneurPanier, error);
            });
    }
}