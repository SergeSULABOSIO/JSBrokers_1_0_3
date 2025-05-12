import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = [
        'texteprincipal',
        'textesecondaire',
        'details',
        'optionspanier',
        'statuspanier',
    ];

    static values = {
        idtranche: String,
        identreprise: String,
        conteneurpanier: String,
        corpspanier: String,
    };

    connect() {
        this.detailsVisible = false;
        // console.log("Texte principal: ", this.textesecondaireTarget);
    }



    /**
     * 
     * @param {MouseEvent} event 
     */
    afficherdetails = (event) => {
        event.preventDefault(); // Empêche la soumission classique du formulaire
        if (this.detailsVisible == true) {
            this.detailsTarget.style.display = "none";
            this.detailsVisible = false;
        } else {
            this.detailsTarget.style.display = "block";
            this.detailsVisible = true;
        }
    }



    /**
     * 
     * @param {MouseEvent} event 
     */
    modifier = (event) => {
        event.preventDefault(); // Empêche la soumission classique du formulaire
        console.log("MODIFIER");
        var url = "/admin/tranche/edit/" + this.identrepriseValue + "/" + this.idtrancheValue;
        window.location.href = url;
        // href="{{ path('admin.tranche.edit', {'idEntreprise': entreprise.id, 'idTranche':tranche.id })}}" 
    }


    /**
     * 
     * @param {MouseEvent} event 
     */
    mettredanslanote = (event) => {
        event.preventDefault(); // Empêche la soumission classique du formulaire

        const parametresCibles = event.currentTarget.dataset; //L'element 
        const poste = parametresCibles.poste;
        const montantpayable = parametresCibles.montantpayable;
        const idposte = parametresCibles.idposte;
        const idnote = parametresCibles.idnote;
        const idtranche = parametresCibles.idtranche;
        const identreprise = parametresCibles.identreprise;

        var url = "/admin/tranche/mettredanslanote/" + poste + "/" + montantpayable + "/" + idnote + "/" + idposte + "/" + idtranche + "/" + identreprise;
        console.log(url);

        // #[Route('/mettredanslanote/{poste}/{montantPayable}/{idNote}/{idPoste}/{idTranche}/{idEntreprise}', name: 'mettredanslanote', requirements: [
        fetch(url) // L'URL de votre route Symfony
            .then(response => response.text())
            .then(reponseServeur => {
                // console.log("Options panier:", this.optionspanierTarget);
                console.log(reponseServeur);
                this.getOptionsPanier(idtranche);

                //On actualise le panier
                if (this.conteneurpanierValue != null) {
                    this.actualiserPanier();
                }
            })
            .catch(error => {
                console.error('Erreur:', error);
            });
    }



    /**
     * 
     * @param {MouseEvent} event 
     */
    retirerdelanote = (event) => {
        event.preventDefault(); // Empêche la soumission classique du formulaire

        const parametresCibles = event.currentTarget.dataset; //L'element 
        const idtranche = parametresCibles.idtranche;
        const identreprise = parametresCibles.identreprise;

        var url = "/admin/tranche/retirerdelanote/" + idtranche + "/" + identreprise;
        console.log(url);

        fetch(url) // L'URL de votre route Symfony
            .then(response => response.text())
            .then(reponseServeur => {
                // console.log(reponseServeur);
                this.getOptionsPanier(idtranche);
                
                //On actualise le panier
                if (this.conteneurpanierValue != null) {
                    this.actualiserPanier();
                }
            })
            .catch(error => {
                console.error('Erreur:', error);
            });
    }

    /**
     * 
     * @param {string} idTranche 
     */
    getOptionsPanier = (idTranche) => {
        const url = "/admin/tranche/getoptionspanier/" + idTranche + "/" + this.identrepriseValue;
        fetch(url) // L'URL de votre route Symfony
            .then(response => response.text())
            .then(reponseServeur => {
                this.optionspanierTarget.innerHTML = reponseServeur;
                // console.log(reponseServeur);
                this.getStatusPanier(idTranche);
            })
            .catch(error => {
                console.error('Erreur:', error);
            });
    }



    /**
     * 
     * @param {string} idTranche 
     */
    getStatusPanier = (idTranche) => {
        const url = "/admin/tranche/getstatuspanier/" + idTranche + "/" + this.identrepriseValue;
        fetch(url) // L'URL de votre route Symfony
            .then(response => response.text())
            .then(reponseServeur => {
                this.statuspanierTarget.innerHTML = reponseServeur;
                // console.log(reponseServeur);
            })
            .catch(error => {
                console.error('Erreur:', error);
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
     * @param {HTMLElement} elementDeLaCollection 
     * @param {int} inAction 
     * @param {string} icone 
     * @param {string} texteAAjouter 
     * @param {int} taille
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
     * @param {string} url 
     * @param {HTMLElement} htmlData 
     */
    updatTabIcones(url, htmlData) {
        if (this.getCookies(url) == null) {
            this.saveCookie(url, htmlData);
        }
    }


    /**
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
     * @param {int} dossier 
     * @param {string} image 
     * @param {int} taille 
     * @returns {string}
     */
    getIconeUrl(dossier, image, taille) {
        return '/admin/entreprise/geticon/' + dossier + '/' + image + '/' + taille;
    }



    /**
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