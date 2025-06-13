import { Controller } from '@hotwired/stimulus';
import { defineIcone, getIconeUrl } from './base_controller.js'; // après que l'importation soit automatiquement pas VS Code, il faut ajouter l'extension ".js" à la fin!!!!

export default class extends Controller {
    static targets = [
        'nom',
        'block_comptes',
        'block_assureur',
        'block_client',
        'block_partenaire',
        'block_autorite',
        'display',
        'btArticles',
        'btEnregistrer',
        'montantdue',
        'montantpaye',
        'montantsolde',
        'displayarticle',
        'displaypaiement',

    ];

    static values = {
        idnote: String,
        identreprise: String,
        conteneurpanier: String,
        corpspanier: String,
        nbarticles: Number,
        nbpaiements: Number,
    };

    connect() {
        this.isSaved = false;
        // console.log("ID NOTE: " + this.idnoteValue);
        if (this.idnoteValue == null) {
            this.btArticlesTarget.style.display = 'none';
        } else {
            this.btArticlesTarget.style.display = 'inline';
        }

        defineIcone(getIconeUrl(0, "tranche", 19), this.btArticlesTarget, "AJOUTER LES ARTICLES");
        defineIcone(getIconeUrl(1, "save", 19), this.btEnregistrerTarget, "ENREGISTRER");

        //On écoute les boutons de soumission du formulaire
        this.element.addEventListener("click", event => this.ecouterClick(event));
        
        //On initialise les badges des onglets
        this.initBadges(
            this.nbarticlesValue,
            this.nbpaiementsValue,
        );
        this.displayTarget.textContent = "Prêt.";
    }



    /**
     * 
     * @param {Number} nbArticles 
     * @param {Number} nbPaiements 
     */
    initBadges(nbArticles, nbPaiements) {
        this.displayarticleTarget.innerHTML = "";
        this.displaypaiementTarget.innerHTML = "";

        // Articles
        if (nbArticles != 0) {
            this.displayarticleTarget.append(this.generateSpanElement(nbArticles));
        }
        // Paiements
        if (nbPaiements != 0) {
            this.displaypaiementTarget.append(this.generateSpanElement(nbPaiements));
        }
    }


    /**
     * 
     * @param {string} texte 
     * @returns {HTMLSpanElement}
     */
    generateSpanElement = (texte) => {
        var span = document.createElement("span");
        span.setAttribute('class', "badge rounded-pill text-bg-warning m-2 fw-bold");
        span.innerText = texte;
        return span;
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
    }

    /**
     * 
     * @param {MouseEvent} event 
     */
    ajouterarticles = (event) => {
        event.preventDefault(); // Empêche la soumission classique du formulaire
        window.location.href = "/admin/tranche/index/" + this.identrepriseValue;
    }

    /**
     * 
     * @param {MouseEvent} event 
     */
    ecouterClick = (event) => {
        // console.log(event.target);
        
        if (event.target.type != undefined) {
            if (event.target.type == "datetime-local") {
                //On laisse le comportement par défaut
            }else if (event.target.type == "submit") {
                //Si le bouton cliqué contient la mention 'enregistrer' en minuscule
                if ((event.target.innerText.toLowerCase()).indexOf("enregistrer") != -1) {
                    // console.log("On a cliqué sur un bouton Enregistré.");
                    this.enregistrerNote(event);
                }
            }else{
                event.preventDefault(); // Empêche la soumission classique du formulaire
            }
        }
    }


    /**
     * 
     * @param {MouseEvent} event 
    */
    enregistrerNote = (event) => {
        event.preventDefault(); // Empêche la soumission classique du formulaire
        event.target.disabled = true;
        this.isSaved = true;
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
                // console.log(data);
                event.target.disabled = false;
                this.displayTarget.style.display = 'block';

                // Traitez la réponse ici
                if (this.isSaved == true) {
                    this.btArticlesTarget.style.display = 'inline';
                    this.displayTarget.textContent = "Cliquez sur le bouton 'AJOUTER LES ARTICLES' si vous voulez ajouter les articles dans cette note.";

                    //actualisation des autres composant du formulaire ainsi que du panier
                    const tabData = data.split("__1986__");
                    
                    this.montantdueTarget.value = tabData[0];
                    this.montantpayeTarget.value = tabData[1];
                    this.montantsoldeTarget.value = tabData[2];

                    this.initBadges(tabData[3], tabData[4]);

                    //On actualise le panier
                    if (this.conteneurpanierValue != null) {
                        this.actualiserPanier();
                    }
                }

            })
            .catch(errorMessage => {
                event.target.disabled = false;
                this.displayTarget.textContent = "Désolé, une erreur s'est produite, merci de vérifier vos données ou votre connexion Internet.";
                this.displayTarget.style.display = 'none';
                console.error("Réponse d'erreur du serveur :", errorMessage);
            });
    }


     /**
     * 
     */
    actualiserPanier = () => {
        var conteneurPanier = document.getElementById(this.conteneurpanierValue);
        var corpsPanier = document.getElementById(this.corpspanierValue);
        // console.log(corpsPanier);
        conteneurPanier.style.display = "block";
        // corpsPanier.innerHTML = "Actualisation du panier...";

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