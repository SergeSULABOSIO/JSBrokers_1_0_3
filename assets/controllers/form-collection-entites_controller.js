import { Controller } from '@hotwired/stimulus';

export default class extends Controller {

    //Les données passées depuis le template HTML à utiliser à travers tout le controlleur
    static values = {
        data: String
    }


    connect() {
        this.donneesInitiales = JSON.parse(this.dataValue);
        // console.log(this.donneesInitiales);
        // console.log("COOKIE:", document.cookie);

        //DECLARATION DES VARIABLES
        this.blocAlert = document.createElement("small");
        this.spanAlert = document.createElement("span");
        this.btnAjouterElementCollection = document.createElement("button");
        this.index = 0;
        this.collection = this.element;
        this.tailleCollection = this.collection.childElementCount;
        // console.log("Nombre d'elements existants = " + this.nbElement);

        this.stylerElementsDeLaCollection(this.collection);

        this.setBoutonAjouter(this.collection);
        this.setBlocAlert(this.collection);
    }



    /**
     * @param {HTMLElement} objetCollection
     */
    stylerElementsDeLaCollection = (objetCollection) => {
        objetCollection.childNodes.forEach(elementDeLaCollection => {
            //On lui attribut une bordure stylée
            this.appliquerStyle(elementDeLaCollection);
        });
    }


    /**
     * @param {HTMLElement} elementCollection
     */
    appliquerStyle(elementCollection) {
        //On lui attribut une bordure stylée
        elementCollection.setAttribute('class', "shadow-sm rounded mb-2 sensible");
        //Formulaire de saisie
        const formulaire = document.getElementById(elementCollection.firstElementChild.getAttribute("id"));
        if (formulaire != null) {
            //on cache le formulaire
            formulaire.setAttribute("class", "cacherComposant");
        }
        //On met l'icone sur le bouton Enregistrer
        this.setIconBoutonEnregistrer(formulaire);

        //On construit la barre de titre qui transport les textes display principal et secondaire
        this.setBarreDeTitre(formulaire, elementCollection);
    }


    /**
     * @param {int} idFormulaireSaisie 
     * @param {HTMLElement} champDisplayPrincipal 
     * @param {HTMLElement} champDisplaySecondaire 
     */
    ecouterFormulaire = (idFormulaireSaisie, champDisplayPrincipal, champDisplaySecondaire) => {
        if (idFormulaireSaisie != null) {
            const formulaireEncours = document.getElementById(idFormulaireSaisie);
            if (formulaireEncours != null) {
                // console.log(formulaireEncours);
                const champs = formulaireEncours.querySelectorAll('input, select, textarea, button');
                //parcours des elements du formulaire
                champs.forEach(champ => {
                    //ecouter tout changement de valeur
                    champ.addEventListener("change", (event) => this.enCasDeChangement(event, champ, formulaireEncours, champDisplayPrincipal, champDisplaySecondaire));
                });
            }
        }
    }

    /**
     * @param {Event} event 
     * @param {HTMLElement} champ 
     * @param {HTMLFormElement} formulaire 
     * @param {HTMLElement} champDisplayPrincipal 
     * @param {HTMLElement} champDisplaySecondaire
     */
    enCasDeChangement = (event, champ, formulaire, champDisplayPrincipal, champDisplaySecondaire) => {
        event.preventDefault();

        if (champ.getAttribute("type") == "text") {
            champ.setAttribute("value", event.target.value);
        }
        if (champ.getAttribute("type") == "checkbox") {
            champ.setAttribute("checked", event.target.checked);
        }
        // console.log("Target: ", event.target);
        //on actualise l'affichage sur le display
        this.actualiserDonneesDisplay(formulaire, champDisplayPrincipal, champDisplaySecondaire);
    }


    /**
     * @param {htmlElement} formulaire 
     */
    setIconBoutonEnregistrer = (formulaire) => {
        // console.log("\tMise de l'icone sur le bouton Enregister");
        if (formulaire != null) {
            const champs = formulaire.querySelectorAll('input, select, textarea, button');
            champs.forEach(champ => {
                if (champ.getAttribute("type") == "submit") {
                    // console.log("Bonton ** ", champ);
                    var iconeSave = this.getIconeLocale(this.getIconeUrl(1, "save", 15)); //'/admin/entreprise/geticon/1/save/15'
                    if (iconeSave != null) {
                        champ.innerHTML = iconeSave + " " + champ.innerHTML; //Ok!
                    } else {
                        champ.innerHTML = this.downloadIcone(champ, " " + champ.innerHTML, 1, "save", 15);
                    }
                }
            });
        }
    }


    /**
     * @param {HTMLFormElement} formulaire 
     * @param {HTMLElement} champDisplayPrincipal 
     * @param {HTMLElement} champDisplaySecondaire 
     */
    actualiserDonneesDisplay = (formulaire, champDisplayPrincipal, champDisplaySecondaire) => {
        if (formulaire != null) {
            const champs = formulaire.querySelectorAll('input, select, textarea, button');

            //Identification des noms des champs.
            var mapChamps = new Map();
            champs.forEach(champ => {
                var id = champ.getAttribute("id");
                var tabData = id.split("_");
                var nomChamp = "";
                if (tabData.length != 0) {
                    // console.log("\t" + champ.tagName.toLocaleLowerCase());
                    if (champ.tagName.toLocaleLowerCase() == "input") {
                        if (champ.getAttribute("type") == "text") {
                            nomChamp = tabData[tabData.length - 1];
                            if (mapChamps.get(nomChamp) == null) {
                                mapChamps.set(nomChamp, []);
                            }
                        }
                        if (champ.getAttribute("type") == "checkbox") {
                            nomChamp = tabData[tabData.length - 2];
                            if (mapChamps.get(nomChamp) == null) {
                                mapChamps.set(nomChamp, []);
                            }
                        }
                    }
                    if (champ.tagName.toLocaleLowerCase() == "select") {
                        nomChamp = tabData[tabData.length - 1];
                        if (mapChamps.get(nomChamp) == null) {
                            mapChamps.set(nomChamp, []);
                        }
                    }

                }
            })

            //Récupération des valeurs des champs
            for (const [nomDuChamp, tabvaleurDuChamp] of mapChamps) {
                champs.forEach(champ => {
                    var id = champ.getAttribute("id");
                    if (id.indexOf("_" + nomDuChamp) != -1) {
                        if (champ.tagName.toLocaleLowerCase() == "input") {
                            var value = champ.getAttribute("value");
                            if (champ.getAttribute("type") == "text") {
                                tabvaleurDuChamp.push(value);
                            }
                            if (champ.getAttribute("type") == "checkbox") {
                                var checked = champ.getAttribute("checked");
                                if (checked == "checked" || checked == "true") {
                                    tabvaleurDuChamp.push(value);
                                }
                            }
                        }
                        if (champ.tagName.toLocaleLowerCase() == "select") {
                            var selectedOptions = champ.selectedOptions;
                            var optionsText = "";
                            var i = 1;
                            for (const selectedOption of selectedOptions){
                                var sepa = ", ";
                                if (i==1 && selectedOptions.length == 1) {
                                    sepa = "";
                                }
                                optionsText += selectedOption.textContent + sepa;
                                i++;
                            }
                            tabvaleurDuChamp.push(optionsText);
                        }
                    }
                })
            }

            //Chargement des données sur le display
            let isPremier = true;
            let maxLengthSecondaire = 3;
            for (const [nomDuChamp, valeurDuChamp] of mapChamps) {
                if (isPremier) {
                    if (valeurDuChamp != "") {
                        champDisplayPrincipal.innerHTML = valeurDuChamp;
                    } else {
                        champDisplayPrincipal.innerHTML = "Element n°" + this.index;
                    }
                    champDisplaySecondaire.innerHTML = "";
                    isPremier = false;
                } else {
                    if (maxLengthSecondaire != 0) {
                        if (valeurDuChamp == null || valeurDuChamp.length == 0 || valeurDuChamp == "") {
                            // console.log("LE CHAMP " + nomDuChamp + " EST VIDE!!!!!");
                            champDisplaySecondaire.innerHTML += "";
                        } else {
                            champDisplaySecondaire.innerHTML += " • " + nomDuChamp + "[" + valeurDuChamp + "]";
                        }
                        maxLengthSecondaire--;
                    }
                }
            }
        }
    }




    /**
     * @param {HTMLElement} objetCollection
    */
    setBoutonAjouter = (objetCollection) => {
        var textBtn = this.donneesInitiales.addLabel || "Add";
        this.btnAjouterElementCollection.setAttribute('class', "btn btn-outline-secondary");
        this.btnAjouterElementCollection.setAttribute('type', "button");
        this.btnAjouterElementCollection.innerHTML = textBtn;
        //definir l'icone
        this.defineIcone(this.getIconeUrl(1, "add", 20), this.btnAjouterElementCollection, textBtn);
        //definir l'ecouteur de clic
        this.btnAjouterElementCollection.addEventListener('click', this.creerNewElementCollection);
        //ajoute le bouton en bas de la collection
        objetCollection.append(this.btnAjouterElementCollection);
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
     * 
     * @returns boolean
     */
    canAddElementDansCollection = () => {
        var reponse = true;
        if (this.index == 0) {
            reponse = true;
        } else {
            reponse = this.donneesInitiales.tailleMax > this.index;//1 0
        }
        // console.log(this.index, reponse);
        return reponse;
    }


    /**
     * @param {htmlElement} objetCollection 
     */
    setBlocAlert = (objetCollection) => {
        // console.log("Taille Max: " + this.donneesInitiales.tailleMax, "Index: " + this.index);
        this.blocAlert.setAttribute('class', "text-secondary fw-italic");
        var spanIconeAlert = document.createElement("span");
        spanIconeAlert.setAttribute('class', "m-2 fw-bold");
        //definir l'icone
        this.spanAlert.innerHTML = "...";
        this.defineIcone(this.getIconeUrl(1, "alert", 15), spanIconeAlert, "");

        this.blocAlert.append(spanIconeAlert);
        this.blocAlert.append(this.spanAlert);
        objetCollection.append(this.blocAlert);

        this.afficherAlertEtGererBoutonAjoutElement();
    }



    afficherAlertEtGererBoutonAjoutElement() {
        var messageAlert = "Vous ne pouvez enregistrer " + (this.donneesInitiales.tailleMax == 1 ? " qu'un seul élément" : "que " + this.donneesInitiales.tailleMax + " éléments") + " dans cette collection [" + this.index + "/" + this.donneesInitiales.tailleMax + "].";
        if (this.canAddElementDansCollection() == false) {
            this.blocAlert.setAttribute('class', "text-danger");
            messageAlert = "Vous ne pouvez plus ajouter d'éléments car la limite maximale définie à " + this.donneesInitiales.tailleMax + " élément(s) est atteinte.";
            this.btnAjouterElementCollection.style.display = 'none';
            this.blocAlert.style.display = 'none';
        } else {
            this.blocAlert.setAttribute('class', "text-secondary");
            this.btnAjouterElementCollection.style.display = 'inline';
            this.blocAlert.style.display = 'inline';

            // console.log("On doit reafficher le bouton Ajout", this.btnAjouterElementCollection);
        }
        this.spanAlert.innerHTML = messageAlert;
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
     * @param {string} nom 
     * @param {string} valeur 
     */
    saveCookie(nom, valeur) {
        var dateExpiration = new Date();
        dateExpiration.setDate(dateExpiration.getDate() + 7)
        document.cookie = nom + "=9111986" + valeur + "; expires=" + dateExpiration.toUTCString + "; path=/";
    }

    /**
     * 
     * @param {string} url 
     */
    getIconeLocale(url) {
        return this.getCookies(url);
    }


    /**
     * 
     * @param {HTMLElement} formulaire 
     * @param {HTMLElement} elementDeLaCollection 
     */
    setBarreDeTitre = (formulaire, elementDeLaCollection) => {
        //creation du bouton supprimer
        const btnSupprimer = document.createElement("button");
        btnSupprimer.setAttribute('class', "btn border-0 btn-outline-danger");
        btnSupprimer.setAttribute('type', "button");

        //Chargement de l'icone, choix entre serveur ou image stockée dans la mémoire locale
        var iconeSupprimer = this.getIconeLocale(this.getIconeUrl(1, "delete", 18)); //'/admin/entreprise/geticon/1/delete/18'+ 
        if (iconeSupprimer != null) {
            btnSupprimer.innerHTML = iconeSupprimer; //Ok!
        } else {
            btnSupprimer.innerHTML = this.downloadIcone(btnSupprimer, "", 1, "delete", 18);
            btnSupprimer.innerHTML = this.donneesInitiales.deleteLabel || "Delete";
        }

        const barreOutilDisplay = document.createElement("div");
        barreOutilDisplay.setAttribute('class', "options-du-parent");
        barreOutilDisplay.append(btnSupprimer);

        //creation du span
        const spanDisplayTexte = document.createElement("span");
        spanDisplayTexte.setAttribute("class", "fw-bold text-primary");
        spanDisplayTexte.innerHTML = "...";

        const spanDisplayIcon = document.createElement("span");
        spanDisplayIcon.setAttribute("class", "fw-bold text-primary");

        //Chargement de l'icone, choix entre serveur ou image stockée dans la mémoire locale
        this.setIconObjet(spanDisplayIcon, "");

        //creation du div
        const barreDeTitre = document.createElement("nav");
        barreDeTitre.setAttribute("class", "navbar parent-a-options rounded p-2 bg-white");
        //Ajout des elements de viesualisation au div

        //Description
        const spanDescriptionIcon = document.createElement("span");
        spanDescriptionIcon.setAttribute("class", "text-secondary");
        this.setIconDescription(spanDescriptionIcon);

        const spanDisplaySecondaire = document.createElement("span");
        spanDisplaySecondaire.setAttribute("class", "text-secondary m-2");
        spanDisplaySecondaire.innerHTML = "Brève description d'au moins 3 premiers paramètres de l'élémenet.";

        const groupDisplay = document.createElement("span");
        groupDisplay.setAttribute("class", "");
        groupDisplay.append(spanDisplayIcon);
        groupDisplay.append(spanDisplayTexte);
        groupDisplay.append(document.createElement("br"));
        groupDisplay.append(spanDescriptionIcon);
        groupDisplay.append(spanDisplaySecondaire);

        barreDeTitre.append(groupDisplay);
        barreDeTitre.append(barreOutilDisplay);
        //on ecoute les clicks sur la barre de titre
        barreDeTitre.addEventListener('click', e => {
            e.preventDefault();
            // elementDeLaCollection.remove();
            if (formulaire != null) {
                if (formulaire.hasAttribute("class", "cacherComposant")) {
                    formulaire.removeAttribute("class", "cacherComposant");
                } else {
                    formulaire.setAttribute("class", "cacherComposant");
                }
            }
        });
        //on ecoute le bouton supprimer
        btnSupprimer.addEventListener('click', e => {
            e.preventDefault();
            elementDeLaCollection.remove();
            // console.log("SUPPRESSION DE L'ELEMENT");
            this.index--;
            this.afficherAlertEtGererBoutonAjoutElement();
        });

        elementDeLaCollection.append(barreDeTitre);
        //On active les ecouteurs sur tous les champs du formulaire
        this.ecouterFormulaire(formulaire.getAttribute('id'), spanDisplayTexte, spanDisplaySecondaire);
        this.actualiserDonneesDisplay(formulaire, spanDisplayTexte, spanDisplaySecondaire);
        this.index++;
    }

    /**
     * @param {HTMLElement} htmlElement 
     * @param {string} valeurDisplay 
     */
    setIconObjet(htmlElement, texteAccompagnement) {
        var dossier = this.donneesInitiales.dossieractions || "0";
        var nomIcone = this.donneesInitiales.icone || "invite";
        var iconeDisplay = this.getIconeLocale(this.getIconeUrl(dossier, nomIcone, 19)); //"/admin/entreprise/geticon/" + dossier + "/" + nomIcone + "/19"
        if (iconeDisplay != null) {
            htmlElement.innerHTML = iconeDisplay + " " + texteAccompagnement; //Ok!
        } else {
            htmlElement.innerHTML = this.downloadIcone(htmlElement, " " + texteAccompagnement, dossier, nomIcone, 19);
            htmlElement.innerHTML = texteAccompagnement;
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
     * @param {HTMLElement} htmlElement
     */
    setIconDescription(htmlElement) {
        var dossier = "1";
        var nomIcone = "description";
        var iconeDisplay = this.getIconeLocale(this.getIconeUrl(dossier, nomIcone, 16)); //"/admin/entreprise/geticon/" + dossier + "/" + nomIcone + "/16"
        if (iconeDisplay != null) {
            htmlElement.innerHTML = iconeDisplay; //Ok!
        } else {
            htmlElement.innerHTML = this.downloadIcone(htmlElement, "", dossier, nomIcone, 16);
            htmlElement.innerHTML = "...";
        }
    }




    /**
     * @param {MouseEvent} e
     */
    creerNewElementCollection = (e) => {
        e.preventDefault();

        const elementPrototype = document.createRange().createContextualFragment(
            this.element.dataset['prototype'].replaceAll('__name__', this.index)
        ).firstElementChild;
        e.currentTarget.insertAdjacentElement("beforebegin", elementPrototype);

        this.appliquerStyle(elementPrototype);

        this.afficherAlertEtGererBoutonAjoutElement();
    }
}
