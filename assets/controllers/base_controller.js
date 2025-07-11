import { Controller } from '@hotwired/stimulus';


export default class extends Controller {
    connect() {
        // this.element.textContent = 'Hello Stimulus! Edit me in assets/controllers/hello_controller.js';
    }
}


/**
 * @param {string} url 
 * @param {htmlElement} elementHtml 
 * @param {string} texteAccompagnement 
 */
export function defineIcone(url, elementHtml, texteAccompagnement) {
    var iconeData = getIconeLocale(url);
    if (iconeData != null) {
        setIcone(iconeData, elementHtml, texteAccompagnement);
    } else {
        var data = url.split('/'); // var url = '/admin/entreprise/geticon/1/add/20';
        downloadIcone(elementHtml, " " + texteAccompagnement, data[4], data[5], data[6]);
    }
}



/**
 * @param {HTMLElement} htmlElement 
 * @param {string} valeurDisplay 
 * @param {string} icone 
 */
export function setIcone(icone, htmlElement, texteAccompagnement) {
    htmlElement.innerHTML = icone + " " + texteAccompagnement;
}



/**
 * 
 * @param {HTMLElement} elementHtml 
 * @param {int} inAction 
 * @param {string} icone 
 * @param {string} texteAAjouter 
 * @param {int} taille
 *  
 */
export function downloadIcone(elementHtml, texteAAjouter, inAction, icone, taille) {
    //Chargement de l'icones du bouton
    var url = getIconeUrl(inAction, icone, taille); //'/admin/entreprise/geticon/' + inAction + '/' + icone + '/' + taille;
    fetch(url) // L'URL de votre route Symfony
        .then(response => response.text())
        .then(htmlData => {
            updatTabIcones(url, htmlData);
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
export function updatTabIcones(url, htmlData) {
    if (getCookies(url) == null) {
        saveCookie(url, htmlData);
    }
}


/**
 * 
 * @param {string} nom 
*/
export function getCookies(nom) {
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
export function getIconeUrl(dossier, image, taille) {
    return '/admin/entreprise/geticon/' + dossier + '/' + image + '/' + taille;
}



/**
 * 
 * @param {string} url 
 */
export function getIconeLocale(url) {
    return getCookies(url);
}



/**
 * @param {string} nom 
 * @param {string} valeur 
 */
export function saveCookie(nom, valeur) {
    var dateExpiration = new Date();
    dateExpiration.setDate(dateExpiration.getDate() + 7)
    document.cookie = nom + "=9111986" + valeur + "; expires=" + dateExpiration.toUTCString + "; path=/";
}


/**
 * 
 * @param {htmlElement} htmlElement 
 * @param {string} nomEvent 
 * @param {boolean} canBubble 
 * @param {boolean} canCompose 
 * @param {Array} detailTab 
 */
export function buildCustomEventForElement(htmlElement, nomEvent, canBubble, canCompose, detailTab) {
    const event = new CustomEvent(nomEvent, {
        bubbles: canBubble, composed: canCompose, detail: detailTab
    });
    htmlElement.dispatchEvent(event);
}

export const EVEN_CODE_ACTION_AJOUT = 0;
export const EVEN_CODE_ACTION_MODIFICATION = 1;
export const EVEN_CODE_ACTION_SUPPRESSION = 2;
export const EVEN_CODE_RESULTAT_OK = 0;
export const EVEN_CODE_RESULTAT_ECHEC = 1;
export const EVEN_ACTION_DIALOGUE_OUVRIR = "app:liste-principale:dialogueCanAjouter";
export const EVEN_ACTION_DIALOGUE_FERMER = "app:dialogue:fermer_boite";
export const EVEN_ACTION_MENU_CONTEXTUEL = "contextmenu";
export const EVEN_ACTION_RESIZE = "resize";
export const EVEN_ACTION_SCROLL = "scroll";
export const EVEN_ACTION_CLICK = "click";
export const EVEN_ACTION_AJOUTER = "app:liste-principale:ajouter";
export const EVEN_ACTION_NOTIFIER_SELECTION = "app:liste-principale:publier-selection";
export const EVEN_ACTION_DEVELOPPER = "app:liste-element:developper";
export const EVEN_ACTION_COCHER = "app:liste-principale:cocher";
export const EVEN_ACTION_QUITTER = "app:liste-principale:quitter";
export const EVEN_ACTION_PARAMETRER = "app:liste-principale:parametrer";
export const EVEN_ACTION_RECHARGER = "app:liste-principale:recharger";
export const EVEN_ACTION_MODIFIER = "app:liste-principale:modifier";
export const EVEN_ACTION_SUPPRIMER = "app:liste-principale:supprimer";
export const EVEN_QUESTION_SUPPRIMER = "app:liste-principale:dialogueCanSupprimer";
export const EVEN_ACTION_SELECTIONNER = "app:liste-principale:selection";
export const EVEN_ACTION_AFFICHER_MESSAGE = "app:liste-principale:afficher_message";
export const EVEN_ACTION_COCHER_TOUT = "app:liste-principale:tout_cocher";
export const EVEN_ACTION_ENREGISTRER = "app:formulaire:enregistrer";
export const EVEN_QUESTION_OK = "app:liste-principale:dialog_ok";
export const EVEN_QUESTION_NO = "app:liste-principale:dialog_no";
export const EVEN_RESULTAT_SUCCESS = "app:liste-principale:formulaire_ajout_modification_reussi";
export const EVEN_RESULTAT_ECHEC = "app:liste-principale:formulaire_ajout_modification_echoue";
