/**
 * Cœur PUR du rendu des TABLEAUX de l'assistant : aucune dépendance, aucun DOM,
 * aucun marked. Même séparation que assistant-chart-spec.js face à
 * assistant-chart-render.js — c'est ce qui rend ces règles testables sous
 * `node --test tests/js/`, alors que le renderer complet exige marked + DOMPurify,
 * tous deux vendorisés pour l'importmap et absents de node_modules.
 *
 * ── LE DÉFAUT QUE CE FICHIER CORRIGE ────────────────────────────────────────────
 * marked rend l'alignement GFM (`|---:|`) en ATTRIBUT : `<td align="right">`. Or
 * l'allowlist de sanitisation du chat n'autorise que `class` — DOMPurify supprimait
 * donc l'attribut, et le `text-align:left` de la feuille s'appliquait à tout, montants
 * compris. Mesuré sur la capture du 2026-08-10 : un tableau de primes signalées dont
 * les montants s'alignaient comme du texte, impossibles à comparer d'un coup d'œil.
 * L'alignement était écrit dans le markdown, compris par le parseur, puis jeté à la
 * toute dernière étape.
 *
 * Traduire l'alignement en CLASSE le fait survivre à la sanitisation SANS élargir
 * l'allowlist d'un seul attribut : la frontière de sécurité reste intacte.
 *
 * ── SPEC PARTAGÉE ───────────────────────────────────────────────────────────────
 * Ces règles sont celles du contrat de présentation du prompt système
 * (App\Ai\AiContextBuilder::reglesDeMiseEnForme) et doivent rester alignées sur le
 * parseur d'export (App\Ai\Export\MessageMarkdownParser), qui rend les mêmes tableaux
 * en PDF, Word et e-mail. Toute évolution ici se répercute dans ces deux fichiers.
 */

/** Alignement GFM → classe CSS. `left` n'en reçoit aucune : c'est déjà le défaut. */
export const CLASSE_ALIGNEMENT = Object.freeze({
    right: 'aic-md-num',
    center: 'aic-md-center',
});

/**
 * La ligne de TOTAUX telle que le contrat l'impose : première cellule en gras portant
 * « TOTAL ». Sans marquage, le chiffre qui résume tout le tableau se lisait comme une
 * ligne de données de plus.
 */
const LIGNE_DE_TOTAUX = /^<t[dh][^>]*>\s*<strong>\s*TOTAL\b/i;

/** Classe d'alignement d'une cellule, ou chaîne vide quand il n'y en a pas. */
export function classeAlignement(align) {
    return CLASSE_ALIGNEMENT[align] ?? '';
}

/** Cette ligne (déjà rendue en cellules HTML) est-elle la ligne de totaux ? */
export function estLigneDeTotaux(cellulesHtml) {
    return LIGNE_DE_TOTAUX.test(String(cellulesHtml ?? '').trimStart());
}

/**
 * Une cellule de tableau. `interieur` est de l'HTML DÉJÀ rendu par marked (gras,
 * pastilles) : cette fonction ne l'échappe pas et ne doit jamais recevoir de texte
 * brut — la sanitisation finale reste le garde-fou.
 */
export function celluleTableau(interieur, { header = false, align = null } = {}) {
    const balise = header ? 'th' : 'td';
    const classe = classeAlignement(align);

    return `<${balise}${classe ? ` class="${classe}"` : ''}>${interieur}</${balise}>\n`;
}

/** Une ligne de tableau, marquée quand elle porte les totaux. */
export function ligneTableau(cellulesHtml) {
    const classe = estLigneDeTotaux(cellulesHtml) ? ' class="aic-md-total"' : '';

    return `<tr${classe}>\n${cellulesHtml}</tr>\n`;
}
