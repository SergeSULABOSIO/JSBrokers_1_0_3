import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['nom', 'display'];

    connect() {
        console.log('Le contrôleur note-formulaire est connecté !');
    }

    precedent(event) {
        event.preventDefault(); // Empêche la soumission classique du formulaire

        console.log('Le bouton Précédent a été cliqué !');
        console.log(event.target);

        event.target.disabled = true;

        this.displayTarget.textContent = "Enregistrement de " + this.nomTarget.value + " en cours...";
        this.displayTarget.style.display = 'block';

        // Ici, vous pouvez ajouter votre logique AJAX, de validation, etc.
        const formData = new FormData(this.element); // 'this.element' fait référence à l'élément <form>
        fetch(this.element.action, {
            method: this.element.method,
            body: formData,
        })
            .then(response => response.text()) //.json()
            .then(data => {
                event.target.disabled = false;
                
                this.displayTarget.textContent = "Infos.";
                this.displayTarget.style.display = 'none';

                console.log('Réponse du serveur :', data);
                // Traitez la réponse ici
            })
            .catch(errorMessage => {
                event.target.disabled = false;

                this.displayTarget.textContent = "Désolé, une erreur s'est produite, merci de vérifier vos données ou votre connexion Internet.";
                this.displayTarget.style.display = 'none';

                console.error("Réponse d'erreur du serveur :", errorMessage);
                // Traitez la réponse ici
            });
    }
}