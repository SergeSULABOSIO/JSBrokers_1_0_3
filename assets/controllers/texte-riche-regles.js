/**
 * LE TEXTE RICHE — RÈGLES PURES (testées en node).
 *
 * Tout <textarea> d'un dialogue est un éditeur Quill : ces champs stockent du HTML. On
 * n'en laisse passer, à l'affichage, que ce que la barre d'outils produit
 * (rich-text_controller.js) : paragraphes, gras, italique, souligné, barré, listes,
 * titres 1 à 3, couleur et fond, alignement. Le reste — lien, image, cadre, script,
 * gestionnaire d'événement — disparaît. Le serveur appliquera la même liste (chantier
 * d'échappement, C6) ; ce module en est le pendant navigateur, en seconde ligne.
 */

export const BALISES = ['p', 'br', 'strong', 'em', 'u', 's', 'ol', 'ul', 'li', 'h1', 'h2', 'h3', 'span'];

/** Les formats Quill que la barre d'outils produit (rich-text_controller.js) — et eux seuls. */
export const FORMATS_DE_LA_BARRE = ['bold', 'italic', 'underline', 'strike', 'color', 'background', 'list', 'align', 'header'];
export const ATTRIBUTS = ['class', 'style', 'data-list', 'contenteditable'];

/**
 * PAS DE PIÈGE CLAVIER (WCAG 2.1.2 — décision de Serge, 2026-10-21). Quill attachait Tab à
 * l'insertion d'une tabulation et à l'indentation des listes : au clavier, on entrait dans
 * l'éditeur sans pouvoir en sortir. Ces raccourcis sont retirés (`null` = Quill ne les pose
 * pas) : Tab et Maj+Tab font ce qu'ils font partout — passer au champ suivant ou précédent,
 * liste comprise. Une indentation passerait par des boutons de la barre (aucun aujourd'hui).
 * Tous les raccourcis Tab de la copie de Quill sont tenus par tests/js/texte-riche-tabulation.
 */
export const SANS_TABULATION = Object.freeze({
    tab: null,
    indent: null,
    outdent: null,
    'remove tab': null,
    'table tab': null,
});

const COULEUR = /^(#[0-9a-f]{3,8}|rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*(,\s*(0|1|0?\.\d+)\s*)?\))$/i;

/** Ne garde, d'un attribut style, que `color` et `background-color` à valeur de couleur. */
export function styleAdmis(style) {
    return String(style ?? '')
        .split(';')
        .map((declaration) => declaration.split(':'))
        .filter(([propriete, ...valeur]) => {
            const nom = (propriete || '').trim().toLowerCase();
            return (nom === 'color' || nom === 'background-color') && COULEUR.test(valeur.join(':').trim());
        })
        .map(([propriete, ...valeur]) => `${propriete.trim().toLowerCase()}: ${valeur.join(':').trim()}`)
        .join('; ');
}

/** Ne garde que les classes de Quill (`ql-align-center`, `ql-ui`…). */
export function classesAdmises(classes) {
    return String(classes ?? '').split(/\s+/).filter((c) => /^ql-[a-z0-9-]+$/.test(c)).join(' ');
}

/**
 * Cette valeur est-elle le HTML de l'éditeur riche ? Quill enveloppe TOUJOURS son contenu
 * dans un bloc (<p>, <ol>, <ul>, <h1>-<h3>). Un texte simple — un nom, une référence —
 * n'est jamais lu comme du HTML, même s'il contient des chevrons : il s'affiche tel quel.
 * (Le registre des champs riches, côté serveur, remplacera ce critère.)
 */
export function estTexteRiche(valeur) {
    return typeof valeur === 'string' && /^\s*<(p|ol|ul|h[1-3])[\s>]/i.test(valeur);
}

/** `data-list` : seulement les deux valeurs de la barre d'outils. */
export function listeAdmise(valeur) {
    return ['ordered', 'bullet'].includes(valeur) ? valeur : '';
}
