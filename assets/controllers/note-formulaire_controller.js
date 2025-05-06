import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    connect() {
        console.log('Le contrôleur note-formulaire est connecté !');
    }

    precedent(event) {
        event.preventDefault(); // Empêche la soumission classique du formulaire

        console.log('Le bouton Précédent a été cliqué !');
        // Ici, vous pouvez ajouter votre logique AJAX, de validation, etc.
        // const formData = new FormData(this.element); // 'this.element' fait référence à l'élément <form>
        // fetch(this.element.action, {
        //     method: this.element.method,
        //     body: formData,
        // })
        // .then(response => response.json())
        // .then(data => {
        //     console.log('Réponse du serveur :', data);
        //     // Traitez la réponse ici
        // });
    }
}