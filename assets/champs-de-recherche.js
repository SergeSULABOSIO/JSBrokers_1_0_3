/**
 * LE FILET CONTRE LE REMPLISSAGE AUTOMATIQUE DES CHAMPS DE RECHERCHE (chantier 16).
 *
 * Chrome et Edge écrivent l'adresse e-mail de l'utilisateur dans un champ de recherche qu'ils
 * prennent pour un identifiant, malgré `autocomplete="off"` ; la liste se filtrait sur ce texte.
 * Les attributs (ChampRechercheExtension) écartent la plupart des cas ; ce filet rattrape le reste.
 *
 * ── IL NE RÉAGIT QU'AU REMPLISSAGE DU NAVIGATEUR ────────────────────────────
 * Jamais à « une valeur présente sans frappe » : l'application restaure elle-même des filtres
 * (onglets rétablis à l'identique, chantier 15), et ce filet ne doit JAMAIS les effacer. Le
 * navigateur signale son propre remplissage par la pseudo-classe `:autofill`
 * (`:-webkit-autofill`) : app.css y attache une animation `jsb-remplissage-auto` d'une
 * milliseconde, dont l'`animationstart` arrive ici. Une valeur posée par le code ne déclenche
 * aucune animation : elle survit.
 *
 * Seuls les champs marqués `data-champ-recherche` sont concernés : un vrai champ d'identifiant
 * (connexion) garde son remplissage.
 */
import { doitEffacer, NOM_ANIMATION } from './champs-de-recherche-regles.js';
import { evenementInterne } from './controllers/evenement-interne.js';

function effacer(champ) {
    champ.value = '';
    // Les listes écoutent `input` (tableau de bord, filtres) : on rejoue l'événement pour
    // qu'elles reviennent à leur état non filtré.
    champ.dispatchEvent(evenementInterne('input'));
}

if (!window.__champsDeRechercheInstalles) {
    window.__champsDeRechercheInstalles = true;

    document.addEventListener('animationstart', (event) => {
        if (doitEffacer({ nomAnimation: event.animationName, cible: event.target })) {
            effacer(event.target);
        }
    }, true);

    const remplisParLeNavigateur = (champ) => {
        for (const selecteur of [':autofill', ':-webkit-autofill']) {
            try {
                if (champ.matches(selecteur)) return true;
            } catch { /* sélecteur inconnu de ce navigateur */ }
        }
        return false;
    };

    // Un remplissage survenu AVANT le chargement de ce module : la pseudo-classe le dit encore.
    for (const champ of document.querySelectorAll('input[data-champ-recherche]')) {
        if (champ.value !== '' && remplisParLeNavigateur(champ)) {
            effacer(champ);
        }
    }

    // Chrome peut CACHER au script la valeur qu'il a remplie jusqu'au premier geste de
    // l'utilisateur : à l'animation, la valeur lue est vide et rien n'est effacé. Quand elle se
    // révèle (événement `input` du champ encore marqué `:autofill`), on la vide. Une frappe de
    // l'utilisateur retire `:autofill` : ce qu'il tape n'est jamais touché.
    document.addEventListener('input', (event) => {
        const champ = event.target;
        if (champ instanceof HTMLInputElement && champ.matches('[data-champ-recherche]') && champ.value !== '' && remplisParLeNavigateur(champ)) {
            effacer(champ);
        }
    }, true);
}

export { NOM_ANIMATION };
