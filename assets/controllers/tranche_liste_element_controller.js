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
        // delay: Number,
    };

    connect() {
        this.detailsVisible = false;
        this.afficherInfosStatus("Prêt.");
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
        // console.log("MODIFIER");
        // this.afficherInfosStatus("Modification...Chargement du formulaire d'édition");
        var url = "/admin/tranche/edit/" + this.identrepriseValue + "/" + this.idtrancheValue;
        window.location.href = url;
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
        this.afficherInfosStatus("Ajout de l'article dans le panier...");

        // #[Route('/mettredanslanote/{poste}/{montantPayable}/{idNote}/{idPoste}/{idTranche}/{idEntreprise}', name: 'mettredanslanote', requirements: [
        fetch(url) // L'URL de votre route Symfony
            .then(response => response.text())
            .then(reponseServeur => {
                // console.log("Options panier:", this.optionspanierTarget);
                this.afficherInfosStatus("Prêt.");
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
     * @param {MouseEvent} event 
     */
    retirerdelanote = (event) => {
        event.preventDefault(); // Empêche la soumission classique du formulaire

        const parametresCibles = event.currentTarget.dataset; //L'element 
        const idtranche = parametresCibles.idtranche;
        const identreprise = parametresCibles.identreprise;

        var url = "/admin/tranche/retirerdelanote/" + idtranche + "/" + identreprise;
        // console.log(url);
        this.afficherInfosStatus("Retrait de l'article du panier...");

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
        this.afficherInfosStatus("Chargement de données sur le panier...");
        fetch(url) // L'URL de votre route Symfony
            .then(response => response.text())
            .then(reponseServeur => {
                this.optionspanierTarget.innerHTML = reponseServeur;
                // console.log(reponseServeur);
                this.afficherInfosStatus("Prêt.");
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
        this.afficherInfosStatus("Actualisation du status sur la tranche...");
        fetch(url) // L'URL de votre route Symfony
            .then(response => response.text())
            .then(reponseServeur => {
                this.afficherInfosStatus("Prêt.");
                this.statuspanierTarget.innerHTML = reponseServeur;

                // console.log(reponseServeur);
            })
            .catch(error => {
                console.error('Erreur:', error);
            });
    }


    afficherInfosStatus = (texte) => {
        var conteneurInfosStatus = document.getElementById("infosstatus");
        conteneurInfosStatus.innerHTML = " | " + texte;
    }


    /**
     * 
     */
    actualiserPanier = () => {
        var conteneurPanier = document.getElementById(this.conteneurpanierValue);
        var corpsPanier = document.getElementById(this.corpspanierValue);
        // console.log(corpsPanier);
        conteneurPanier.style.display = "block";
        this.afficherInfosStatus("Actualisation du panier...");

        fetch('/admin/note/getpanier/' + this.identrepriseValue) // L'URL de votre route Symfony
            .then(response => response.text())
            .then(htmlData => {
                this.afficherInfosStatus("Prêt.");
                conteneurPanier.innerHTML = htmlData;
            })
            .catch(error => {
                conteneurPanier.innerHTML = "Désolé, une erreur s'est produite! Merci d'actualiser la page ou vérifier votre connexion Internet";
                console.error('Erreur lors du chargement du fragment:', conteneurPanier, error);
            });
    }
}