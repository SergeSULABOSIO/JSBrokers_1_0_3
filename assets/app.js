import './bootstrap.js';
/*
 * Welcome to your app's main JavaScript file!
 *
 * This file will be included onto the page via the importmap() Twig function,
 * which should already be in your base.html.twig.
 */
import './styles/app.css';

console.log('This log comes from assets/app.js - welcome to AssetMapper! 🎉');

/**
 * @param {string} url 
 * @param {htmlElement} elementHtml 
 * @param {string} texteAccompagnement 
 */
export function defineIcone(url, elementHtml, texteAccompagnement) {
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
export function downloadIcone (elementHtml, texteAAjouter, inAction, icone, taille) {
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
export function updatTabIcones(url, htmlData) {
    if (this.getCookies(url) == null) {
        this.saveCookie(url, htmlData);
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
    return this.getCookies(url);
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