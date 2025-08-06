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

    // var $menu_interactif = {
    //     colonne_1: {
    //         tableau_de_bord: {
    //             icone: "ant-design:dashboard-twotone", //source: https://ux.symfony.com/icons
    //             nom: "Tableau de bord",
    //             description: "Le tableau de bord vous présente la santé de votre société en un seul coup d'oeil.",
    //             composant_twig: "_tableau_de_bord_component.html.twig",
    //         },
    //         groupes: {
    //             "Finances": {   //Groupe Finances
    //                 icone: "tdesign:money-filled", //source: https://ux.symfony.com/icons
    //                 description: "Le groupe Finance présente les fonctions indispensables pour la gestion et le parametrage de tout ce qui a trait avec les finances au sein de votre société de courtage",
    //                 rubriques: {
    //                     "Monnaies": {
    //                         icone: "tdesign:money-filled", //source: https://ux.symfony.com/icons
    //                         composant_twig: "_monnaies_component.html.twig",
    //                     },
    //                     "Comptes bancaires": {
    //                         icone: "clarity:piggy-bank-solid", //source: https://ux.symfony.com/icons
    //                         composant_twig: "_comptes_bancaires_component.html.twig",
    //                     },
    //                     "Taxes": {
    //                         icone: "emojione-monotone:police-car-light", //source: https://ux.symfony.com/icons
    //                         composant_twig: "_comptes_bancaires_component.html.twig",
    //                     },
    //                     "Types Revenus": {
    //                         icone: "hugeicons:award-01", //source: https://ux.symfony.com/icons
    //                         composant_twig: "_comptes_bancaires_component.html.twig",
    //                     },
    //                     "Tranches": {
    //                         icone: "icon-park-solid:chart-proportion", //source: https://ux.symfony.com/icons
    //                         composant_twig: "_tranches_component.html.twig",
    //                     },
    //                     "Types Chargements": {
    //                         icone: "tabler:truck-loading", //source: https://ux.symfony.com/icons
    //                         composant_twig: "_types_chargements_component.html.twig",
    //                     },
    //                     "Notes": {
    //                         icone: "hugeicons:invoice-04", //source: https://ux.symfony.com/icons
    //                         composant_twig: "_notes_component.html.twig",
    //                     },
    //                     "Paiements": {
    //                         icone: "streamline:payment-10-solid", //source: https://ux.symfony.com/icons
    //                         composant_twig: "_paiements_component.html.twig",
    //                     },
    //                     "Bordereaux": {
    //                         icone: "gg:list", //source: https://ux.symfony.com/icons
    //                         composant_twig: "_bordereaux_component.html.twig",
    //                     },
    //                     "Revenus": {
    //                         icone: "hugeicons:money-bag-02", //source: https://ux.symfony.com/icons
    //                         composant_twig: "_revenus_component.html.twig",
    //                     },
    //                 },

    //             },
    //             "Marketing": {   //Groupe Marketing
    //                 icone: "tdesign:money-filled", //source: https://ux.symfony.com/icons
    //                 description: "Le groupe Marketing présente les fonctions indispensables pour la gestion et le parametrage de tout ce qui a trait avec vos interactions avec les clients mais aussi entre vous en termes des tâches et des feedbacks.",
    //                 rubriques: {
    //                     "Pistes": {
    //                         icone: "fa-solid:road", //source: https://ux.symfony.com/icons
    //                         composant_twig: "_pistes_component.html.twig",
    //                     },
    //                     "Tâches": {
    //                         icone: "mingcute:task-2-fill", //source: https://ux.symfony.com/icons
    //                         composant_twig: "_taches_component.html.twig",
    //                     },
    //                     "Feedbacks": {
    //                         icone: "fluent-mdl2:feedback", //source: https://ux.symfony.com/icons
    //                         composant_twig: "_feedbacks_component.html.twig",
    //                     },
    //                 },
    //             },
    //             "Production": {   //Groupe Production
    //                 icone: "tdesign:money-filled", //source: https://ux.symfony.com/icons
    //                 description: "Le groupe Production présente les fonctions indispensables pour la gestion et le parametrage de tout ce qui a trait avec votre production càd vos activités génératrices des revenus.",
    //                 rubriques: {
    //                     "Groupes": {
    //                         icone: "clarity:group-solid", //source: https://ux.symfony.com/icons
    //                         composant_twig: "_groupes_component.html.twig",
    //                     },
    //                     "Clients": {
    //                         icone: "fa-solid:house-user", //source: https://ux.symfony.com/icons
    //                         composant_twig: "_clients_component.html.twig",
    //                     },
    //                     "Assureurs": {
    //                         icone: "wpf:security-checked", //source: https://ux.symfony.com/icons
    //                         composant_twig: "_assureurs_component.html.twig",
    //                     },
    //                     "Contacts": {
    //                         icone: "hugeicons:contact-01", //source: https://ux.symfony.com/icons
    //                         composant_twig: "_contacts_component.html.twig",
    //                     },
    //                     "Risques": {
    //                         icone: "ep:goods", //source: https://ux.symfony.com/icons
    //                         composant_twig: "_risques_component.html.twig",
    //                     },
    //                     "Avenants": {
    //                         icone: "iconamoon:edit-fill", //source: https://ux.symfony.com/icons
    //                         composant_twig: "_avenants_component.html.twig",
    //                     },
    //                     "Intermédiaires": {
    //                         icone: "carbon:partnership", //source: https://ux.symfony.com/icons
    //                         composant_twig: "_intermediaires_component.html.twig",
    //                     },
    //                     "Propositions": {
    //                         icone: "streamline:store-computer-solid", //source: https://ux.symfony.com/icons
    //                         composant_twig: "_propositions_component.html.twig",
    //                     },
    //                 },
    //             },
    //             "Sinistre": {   //Groupe Sinistre
    //                 icone: "tdesign:money-filled", //source: https://ux.symfony.com/icons
    //                 description: "Le groupe Sinistre présente les fonctions indispensables pour la gestion et le parametrage de tout ce qui a trait avec les sinistres et leurs compensations.",
    //                 rubriques: {
    //                     "Types pièces": {
    //                         icone: "codex:file", //source: https://ux.symfony.com/icons
    //                         composant_twig: "_types_pièces_sinistres_component.html.twig",
    //                     },
    //                     "Notifications": {
    //                         icone: "emojione-monotone:fire", //source: https://ux.symfony.com/icons
    //                         composant_twig: "_notifications_sinistres_component.html.twig",
    //                     },
    //                     "Règlements": {
    //                         icone: "icon-park-outline:funds", //source: https://ux.symfony.com/icons
    //                         composant_twig: "_reglements-sinistres_component.html.twig",
    //                     },
    //                 },
    //             },
    //             "Administration": {   //Groupe Administration
    //                 icone: "tdesign:money-filled", //source: https://ux.symfony.com/icons
    //                 description: "Le groupe Administration présente les fonctions indispensables pour la gestion et le parametrage de tout ce qui a trait avec l'organisation des documents chargés sur votre compte ainsi que les personnes que vous inviterez pour travailler en équipe.",
    //                 rubriques: {
    //                     "Documents": {
    //                         icone: "famicons:document", //source: https://ux.symfony.com/icons
    //                         composant_twig: "_documents_component.html.twig",
    //                     },
    //                     "Classeurs": {
    //                         icone: "ic:baseline-folder", //source: https://ux.symfony.com/icons
    //                         composant_twig: "_classeurs_component.html.twig",
    //                     },
    //                     "Invités": {
    //                         icone: "raphael:user", //source: https://ux.symfony.com/icons
    //                         composant_twig: "_invites_component.html.twig",
    //                     },
    //                 },
    //             },
    //         },
    //         parametres: {
    //             "Mon Compte": {
    //                 icone: "material-symbols:settings", //source: https://ux.symfony.com/icons
    //                 composant_twig: "_mon_compte_component.html.twig",
    //             },
    //             "Licence": {
    //                 icone: "ci:arrows-reload-01", //source: https://ux.symfony.com/icons
    //                 composant_twig: "_licence_component.html.twig",
    //             },
    //         },
    //     },
    //     colonne_2: {
    //         logo: "images/entreprises/logofav.png", //source: dans le dossier "public/images/entreprises/logofav.png"
    //         titre: "JS Brokers",
    //         description: "Votre partenaire fiable pour l'optimisation de la gestion du porte-feuille client.",
    //         version: "1.1.0",
    //     },
    // };

}

export const EVEN_CODE_ACTION_AJOUT = 0;
export const EVEN_CODE_ACTION_MODIFICATION = 1;
export const EVEN_CODE_ACTION_SUPPRESSION = 2;
export const EVEN_CODE_RESULTAT_OK = 0;
export const EVEN_CODE_RESULTAT_ECHEC = 1;

export const EVEN_NAVIGATION_RUBRIQUE_OPEN_REQUEST = 'app:menu-navigation:rubrique-open-request';
export const EVEN_NAVIGATION_RUBRIQUE_OPENNED = 'app:menu-navigation:rubrique-opened';

//Evenements - Moteur de recherche
export const EVEN_MOTEUR_RECHERCHE_CRITERES_REQUEST = "app:moteur-recherche:criteres-request";
export const EVEN_MOTEUR_RECHERCHE_SEARCH_REQUEST = "app:moteur-recherche:search-request";
export const EVEN_MOTEUR_RECHERCHE_CRITERES_DEFINED = "app:moteur-recherche:criteres-defined";

//Evenements - Base de données
export const EVEN_DATA_BASE_SELECTION_REQUEST = "app:base-données:sélection-request";
export const EVEN_DATA_BASE_SELECTION_EXECUTED = "app:base-données:sélection-executed";
export const EVEN_DATA_BASE_DONNEES_LOADED = "app:base-données:données-loaded";

//Evenements - Liste Principale
export const EVEN_LISTE_PRINCIPALE_NOTIFY = "app:liste-principale:notify";
export const EVEN_LISTE_PRINCIPALE_ADD_REQUEST = "app:liste-principale:add-request";
export const EVEN_LISTE_PRINCIPALE_ADDED = "app:liste-principale:added";
export const EVEN_LISTE_PRINCIPALE_REFRESH_REQUEST = "app:liste-principale:refresh-request";
export const EVEN_LISTE_PRINCIPALE_REFRESHED = "app:liste-principale:refreshed";
export const EVEN_LISTE_PRINCIPALE_ALL_CHECK_REQUEST = "app:liste-principale:all-check-request";
export const EVEN_LISTE_PRINCIPALE_ALL_CHECKED = "app:liste-principale:all-checked";
export const EVEN_LISTE_PRINCIPALE_SETTINGS_REQUEST = "app:liste-principale:settings-request";
export const EVEN_LISTE_PRINCIPALE_SETTINGS_UPDATED = "app:liste-principale:settings-updated";
export const EVEN_LISTE_PRINCIPALE_CLOSE_REQUEST = "app:liste-principale:close-request";
export const EVEN_LISTE_PRINCIPALE_CLOSED = "app:liste-principale:closed";

//Evenements - Element Liste
export const EVEN_LISTE_ELEMENT_DELETE_REQUEST = "app:liste-element:delete-request";
export const EVEN_LISTE_ELEMENT_DELETED = "app:liste-element:deleted";
export const EVEN_LISTE_ELEMENT_MODIFY_REQUEST = "app:liste-element:modify-request";
export const EVEN_LISTE_ELEMENT_MODIFIED = "app:liste-element:modified";
export const EVEN_LISTE_ELEMENT_CHECK_REQUEST = "app:liste-element:check-request";
export const EVEN_LISTE_ELEMENT_CHECKED = "app:liste-element:checked";
export const EVEN_LISTE_ELEMENT_EXPAND_REQUEST = "app:liste-element:expand-request";
export const EVEN_LISTE_ELEMENT_EXPANDED = "app:liste-element:expanded";
export const EVEN_LISTE_ELEMENT_OPEN_REQUEST = "app:liste-element:open-request";
export const EVEN_LISTE_ELEMENT_OPENNED = "app:liste-element:details-openned";

//Evenements - Liste Principale
export const EVEN_MENU_CONTEXTUEL_INIT_REQUEST = "app:menu-contextuel:init-request";
export const EVEN_MENU_CONTEXTUEL_INITIALIZED = "app:menu-contextuel:initialized";
export const EVEN_MENU_CONTEXTUEL_SHOW = "app:menu-contextuel:show";
export const EVEN_MENU_CONTEXTUEL_HIDE = "app:menu-contextuel:hide";

//Evenements - Barre d'Outils
export const EVEN_BARRE_OUTILS_INIT_REQUEST = "app:barre-outils:init-request";
export const EVEN_BARRE_OUTILS_INITIALIZED = "app:barre-outils:initialized";

//Toast - Message de notification sur l'écran
export const EVEN_SHOW_TOAST = "app:message-toast:show";


//Evenements - Boîte de dialogue
export const EVEN_BOITE_DIALOGUE_INIT_REQUEST = "app:boite-dialogue:init-request";
export const EVEN_BOITE_DIALOGUE_INITIALIZED = "app:boite-dialogue:initialized";
export const EVEN_BOITE_DIALOGUE_SUBMIT_REQUEST = "app:boite-dialogue:submit-request";
export const EVEN_BOITE_DIALOGUE_SUBMITTED = "app:boite-dialogue:submitted";
export const EVEN_BOITE_DIALOGUE_CANCEL_REQUEST = "app:boite-dialogue:cancel-request";
export const EVEN_BOITE_DIALOGUE_CANCELLED = "app:boite-dialogue:cancelled";
export const EVEN_BOITE_DIALOGUE_CLOSE = "app:boite-dialogue:close";

//Evenement - Serveur
export const EVEN_SERVER_RESPONSED = "app:serveur-distant:responded";

//Evenement - CheckBox
export const EVEN_CHECKBOX_ELEMENT_CHECK_REQUEST = "app:checkbox-element:check-request";
export const EVEN_CHECKBOX_ELEMENT_CHECKED = "app:checkbox-element:checked";
export const EVEN_CHECKBOX_ELEMENT_UNCHECKED = "app:checkbox-element:unchecked";
export const EVEN_CHECKBOX_PUBLISH_SELECTION = "app:checkbox-element:publier-selection";




export const EVEN_ACTION_DIALOGUE_OUVRIR = "app:liste-principale:dialogueCanAjouter";
export const EVEN_ACTION_DIALOGUE_FERMER = "app:dialogue:fermer_boite";
export const EVEN_ACTION_MENU_CONTEXTUEL = "contextmenu";
export const EVEN_ACTION_RESIZE = "resize";
export const EVEN_ACTION_SCROLL = "scroll";
export const EVEN_ACTION_CLICK = "click";
export const EVEN_ACTION_CHANGE = "change";
export const EVEN_ACTION_AJOUTER = "app:liste-principale:ajouter";
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
