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
        'btArticles',
        'btEnregistrer',
        'montantdue',
        'montantpaye',
        'montantsolde',
    ];

    static values = {
        idnote: String,
        identreprise: String,
        conteneurpanier: String
    };

    connect() {
        this.isSaved = false;
        console.log("ID NOTE: " + this.idnoteValue);
        if (this.idnoteValue == null) {
            this.btArticlesTarget.style.display = 'none';
        } else {
            this.btArticlesTarget.style.display = 'inline';
        }

        this.defineIcone(this.getIconeUrl(0, "tranche", 19), this.btArticlesTarget, "AJOUTER LES ARTICLES");
        this.defineIcone(this.getIconeUrl(1, "save", 19), this.btEnregistrerTarget, "ENREGISTRER");

        //On écoute les boutons de soumission du formulaire
        this.element.addEventListener("click", event => this.ecouterClick(event));
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
        event.preventDefault(); // Empêche la soumission classique du formulaire
        if (event.target.innerText) {
            //Si le bouton cliqué contient la mention 'enregistrer' en minuscule
            if ((event.target.innerText.toLowerCase()).indexOf("enregistrer") != -1) {
                this.enregistrerNote(event);
            }
        }
    }


    /**
     * 
     * @param {MouseEvent} event 
     */
    enregistrerNote = (event) => {
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
                console.log(data);
                event.target.disabled = false;
                this.displayTarget.style.display = 'block';

                // Traitez la réponse ici
                if (this.isSaved == true) {
                    this.btArticlesTarget.style.display = 'inline';
                    this.displayTarget.textContent = "Cliquez sur le bouton 'AJOUTER LES ARTICLES [...]' afin d'aller ajouter les articles dans la note.";

                    //actualisation des autres composant du formulaire ainsi que du panier
                    this.montantdueTarget.value = data.split("___")[0] * 1;
                    this.montantpayeTarget.value = data.split("___")[1];
                    this.montantsoldeTarget.value = data.split("___")[2];

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


    actualiserPanier = () => {
        var conteneurPanier = document.getElementById(this.conteneurpanierValue);
        conteneurPanier.style.display = "block";
        conteneurPanier.firstElementChild.firstElementChild.firstElementChild.innerHTML = "Actualisation du panier...";

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


    /**
     * @param {string} url 
     * @param {htmlElement} elementHtml 
     * @param {string} texteAccompagnement 
     */
    defineIcone(url, elementHtml, texteAccompagnement) {
        var iconeData = this.getIconeLocale(url);
        if (iconeData != null) {
            this.setIcone(iconeData, elementHtml, texteAccompagnement);
        } else {
            var data = url.split('/'); // var url = '/admin/entreprise/geticon/1/add/20';
            this.downloadIcone(elementHtml, " " + texteAccompagnement, data[4], data[5], data[6]);
        }
    }

    /**
     * @param {HTMLElement} htmlElement 
     * @param {string} valeurDisplay 
     * @param {string} icone 
     */
    setIcone(icone, htmlElement, texteAccompagnement) {
        htmlElement.innerHTML = icone + " " + texteAccompagnement;
    }

    /**
     * 
     * @param {HTMLElement} elementDeLaCollection 
     * @param {int} inAction 
     * @param {string} icone 
     * @param {string} texteAAjouter 
     * @param {int} taille
     *  
     */
    downloadIcone = (elementHtml, texteAAjouter, inAction, icone, taille) => {
        //Chargement de l'icones du bouton
        var url = this.getIconeUrl(inAction, icone, taille); //'/admin/entreprise/geticon/' + inAction + '/' + icone + '/' + taille;
        fetch(url) // L'URL de votre route Symfony
            .then(response => response.text())
            .then(htmlData => {
                this.updatTabIcones(url, htmlData);
                elementHtml.innerHTML = htmlData + texteAAjouter;
            })
            .catch(error => {
                console.error('Erreur lors du chargement du fragment:', error);
            });
    }

    /**
     * 
     * @param {string} url 
     * @param {HTMLElement} htmlData 
     */
    updatTabIcones(url, htmlData) {
        if (this.getCookies(url) == null) {
            this.saveCookie(url, htmlData);
        }
    }


    /**
     * 
     * @param {string} nom 
    */
    getCookies(nom) {
        const nomEQ = nom + "=9111986";
        const ca = document.cookie.split(';');
        for (let i = 0; i < ca.length; i++) {
            let c = ca[i];
            while (c.charAt(0) === ' ') {
                c = c.substring(1, c.length);
            }
            if (c.indexOf(nomEQ) === 0) {
                return c.substring(nomEQ.length, c.length);
            }
        }
        return null;
    }

    /**
     * 
     * @param {int} dossier 
     * @param {string} image 
     * @param {int} taille 
     * @returns {string}
     */
    getIconeUrl(dossier, image, taille) {
        return '/admin/entreprise/geticon/' + dossier + '/' + image + '/' + taille;
    }

    /**
     * 
     * @param {string} url 
     */
    getIconeLocale(url) {
        return this.getCookies(url);
    }

    /**
     * @param {string} nom 
     * @param {string} valeur 
     */
    saveCookie(nom, valeur) {
        var dateExpiration = new Date();
        dateExpiration.setDate(dateExpiration.getDate() + 7)
        document.cookie = nom + "=9111986" + valeur + "; expires=" + dateExpiration.toUTCString + "; path=/";
    }
}