/**
 * LE TEXTE RICHE DANS LE NAVIGATEUR — deux lectures, une seule source de règles.
 *
 *  - texteRicheSur(html)  : le HTML de l'éditeur, assaini (BALISES / ATTRIBUTS de
 *                           texte-riche-regles.js), pour un affichage qui garde la mise
 *                           en forme (panneau d'attributs, chargement de l'éditeur) ;
 *  - texteRicheEnTexte(v) : la substance, sans balises ni entités, pour une phrase ou un
 *                           libellé — à échapper ensuite comme tout texte.
 *
 * Instance DOMPurify PROPRE : ses filtres d'attributs ne touchent pas celle du chat de Ket.
 */
import DOMPurify from 'dompurify';
import { ATTRIBUTS, BALISES, classesAdmises, listeAdmise, styleAdmis } from './texte-riche-regles.js';

const purificateur = DOMPurify(window);
purificateur.addHook('uponSanitizeAttribute', (_noeud, donnees) => {
    const filtres = { style: styleAdmis, class: classesAdmises, 'data-list': listeAdmise };
    if (donnees.attrName === 'contenteditable') {
        donnees.attrValue = 'false';
    } else if (filtres[donnees.attrName]) {
        donnees.attrValue = filtres[donnees.attrName](donnees.attrValue);
    }
    if (donnees.attrValue === '') {
        donnees.keepAttr = false;
    }
});

/** @param {unknown} html  @returns {string} du HTML sûr */
export function texteRicheSur(html) {
    return purificateur.sanitize(String(html ?? ''), { ALLOWED_TAGS: BALISES, ALLOWED_ATTR: ATTRIBUTS });
}

/**
 * La substance d'un texte riche. DOMParser rend un document INERTE : rien ne s'y charge
 * ni ne s'y exécute (une image piégée n'y déclenche pas son onerror).
 *
 * @param {unknown} valeur  @returns {string} du TEXTE
 */
export function texteRicheEnTexte(valeur) {
    const html = String(valeur ?? '').replace(/<\/(p|div|li|h[1-3])>|<br\s*\/?>/gi, ' ');
    const texte = new DOMParser().parseFromString(html, 'text/html').body.textContent ?? '';

    return texte.replace(/\s+/g, ' ').trim();
}
