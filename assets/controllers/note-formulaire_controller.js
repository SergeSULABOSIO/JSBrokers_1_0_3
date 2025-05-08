import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = [
        'nom',
        'block_comptes',
        'block_assureur',
        'block_client',
        'block_partenaire',
        'block_autorite',
        'display',
        'btArticles'
    ];

    static values = {
        idnote: String,
        identreprise: String
    };

    connect() {
        this.isSaved = false;
        console.log("ID NOTE: " + this.idnoteValue);
        if (this.idnoteValue == null) {
            this.btArticlesTarget.style.display = 'none';
        } else {
            this.btArticlesTarget.style.display = 'inline';
        }
        // console.log('Le contrôleur note-formulaire est connecté !');
        // console.log("Formulaire:", this.element);

        //On écoute les boutons de soumission du formulaire
        this.element.addEventListener("click", event => this.enregistrer(event));
    }

    changerType = (event) => {
        event.preventDefault(); // Empêche la soumission classique du formulaire
        var selectedValue = event.target.selectedOptions[0].value;
        if (selectedValue == 1) {
            this.block_comptesTarget.style.display = 'none';
        } else {
            this.block_comptesTarget.style.display = 'block';
        }
    }

    changerAddressedTo = (event) => {
        event.preventDefault(); // Empêche la soumission classique du formulaire

        var selectedCotent = event.target.selectedOptions[0].textContent;
        var selectedValue = event.target.selectedOptions[0].value;

        switch (selectedValue) {
            case '0'://Client
                this.block_clientTarget.style.display = 'block';
                this.block_assureurTarget.style.display = 'none';
                this.block_partenaireTarget.style.display = 'none';
                this.block_autoriteTarget.style.display = 'none';
                break;
            case '1'://Assureur
                this.block_clientTarget.style.display = 'none';
                this.block_assureurTarget.style.display = 'block';
                this.block_partenaireTarget.style.display = 'none';
                this.block_autoriteTarget.style.display = 'none';
                break;
            case '2'://Partenaire
                this.block_clientTarget.style.display = 'none';
                this.block_assureurTarget.style.display = 'none';
                this.block_partenaireTarget.style.display = 'block';
                this.block_autoriteTarget.style.display = 'none';
                break;
            case '3'://Autorite
                this.block_clientTarget.style.display = 'none';
                this.block_assureurTarget.style.display = 'none';
                this.block_partenaireTarget.style.display = 'none';
                this.block_autoriteTarget.style.display = 'block';
                break;

            default:
                break;
        }

        // console.log("ECOUTEUR: Je viens d'écouter une action...", event.target, selectedCotent, selectedValue);
    }

    ajouterarticles = () => {
        event.preventDefault(); // Empêche la soumission classique du formulaire
        window.location.href = "/admin/tranche/index/" + this.identrepriseValue;
    }

    enregistrer = (event) => {

        if (event.target.innerText.toLowerCase() == "enregistrer") {
            event.preventDefault(); // Empêche la soumission classique du formulaire
            // console.log("ECOUTEUR: le bouton " + event.target.innerText + " vient d'être clické !");

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
                    this.isSaved = true;
                    // this.displayTarget.textContent = "Infos.";
                    this.displayTarget.style.display = 'block';

                    // console.log('Réponse du serveur :', data);
                    // Traitez la réponse ici
                    if (this.isSaved == true) {
                        this.btArticlesTarget.style.display = 'inline';
                        this.displayTarget.textContent = "Cliquez sur le bouton 'AJOUTER LES ARTICLES [...]' afin d'aller ajouter les articles dans la note.";
                    }

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
}