/**
 * LA DÉCISION D'AFFICHAGE DES FICHIERS À TÉLÉCHARGER, isolée du DOM.
 *
 * POURQUOI UN MODULE À PART. Le panneau de téléchargement n'est plus une simple liste
 * de boutons : il choisit entre deux présentations selon ce que le serveur renvoie —
 * une CARTE détaillée pour un fichier unique, un TABLEAU NUMÉROTÉ dès qu'il y en a
 * plusieurs. Ce choix, le formatage des tailles et des dates, et la liste des colonnes
 * sont exactement le genre de logique qui se casse en silence et qu'on ne remarque que
 * sur une capture d'écran. Ici, elle se teste en `node --test tests/js/`, sans marked,
 * sans DOMPurify et sans navigateur — même parti pris que assistant-markdown-table.js.
 *
 * Aucun de ces exports ne fabrique de HTML : le rendu appartient au contrôleur Stimulus,
 * qui pose chaque libellé via textContent. Un nom de fichier vient de la base, donc de
 * la saisie d'un utilisateur ; il n'a jamais rien à faire dans du HTML brut.
 */

/**
 * Les colonnes du tableau, DANS L'ORDRE. Elles reprennent une à une les clés que
 * l'outil serveur déclare dans sa « presentation » : le tableau que Ket écrit en prose
 * et celui que rend ce panneau montrent ainsi les mêmes colonnes, dans le même ordre.
 */
export const COLONNES = [
    { cle: 'n', libelle: '#', classe: 'aic-fdl-c-num' },
    { cle: 'nom', libelle: 'Nom', classe: 'aic-fdl-c-nom' },
    { cle: 'format', libelle: 'Format', classe: 'aic-fdl-c-fmt' },
    { cle: 'taille', libelle: 'Taille', classe: 'aic-fdl-c-taille' },
    { cle: 'chargeLe', libelle: 'Chargé le', classe: 'aic-fdl-c-date' },
    { cle: 'rattacheA', libelle: 'Rattaché à', classe: 'aic-fdl-c-lien' },
];

/**
 * Les entrées réellement affichables : celles qui portent une URL.
 *
 * Une entrée sans URL produirait une ligne de tableau avec un bouton mort — pire qu'une
 * ligne absente, parce qu'elle promet un fichier qu'aucun clic ne rapportera.
 */
export function fichiersValides(fichiers) {
    return (Array.isArray(fichiers) ? fichiers : []).filter(
        (f) => f && typeof f.url === 'string' && f.url !== '',
    );
}

/**
 * Carte ou tableau ?
 *
 * Un seul fichier dans un tableau, c'est un en-tête de colonnes pour une ligne : le
 * lecteur lit six libellés pour six valeurs. La carte dit la même chose en une phrase.
 * À partir de deux, l'inverse devient vrai — l'œil compare par colonnes.
 */
export function modeAffichage(fichiers) {
    const valides = fichiersValides(fichiers);
    if (valides.length === 0) return 'aucun';
    return valides.length === 1 ? 'carte' : 'tableau';
}

/** Taille de fichier lisible (o / Ko / Mo). */
export function formatTaille(octets) {
    const n = Number(octets);
    if (!Number.isFinite(n) || n < 0) return '';
    if (n < 1024) return `${n} o`;
    if (n < 1024 * 1024) return `${(n / 1024).toFixed(1)} Ko`;
    return `${(n / (1024 * 1024)).toFixed(1)} Mo`;
}

/**
 * Date en jj/mm/aaaa — JAMAIS en ISO.
 *
 * Le serveur envoie « 2026-08-14 » parce que c'est la seule forme non ambiguë à
 * transporter ; l'afficher telle quelle est une faute que le contrat de présentation
 * relève déjà pour les tableaux du chat. La chaîne est découpée, non passée à Date() :
 * `new Date('2026-08-14')` s'interprète en UTC et recule d'un jour à l'ouest de
 * Greenwich — un fichier chargé le 14 s'afficherait « 13/08/2026 ».
 */
export function formatDate(iso) {
    const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(iso ?? ''));
    return m ? `${m[3]}/${m[2]}/${m[1]}` : '';
}

/**
 * Le modèle d'une ligne : que des chaînes, prêtes à poser en textContent.
 *
 * Les valeurs absentes deviennent « — » plutôt que du vide : une cellule vide se lit
 * comme un oubli d'affichage, un tiret comme une absence constatée.
 */
export function ligneFichier(fichier, index) {
    return {
        n: String(index + 1),
        nom: String(fichier.nom || 'fichier'),
        format: String(fichier.format || '—'),
        taille: formatTaille(fichier.taille) || '—',
        chargeLe: formatDate(fichier.chargeLe) || '—',
        rattacheA: String(fichier.rattacheA || '—'),
        url: fichier.url,
    };
}

/** Le titre du panneau : il annonce le nombre, qui est l'information utile. */
export function titrePanneau(nombre) {
    return nombre === 1 ? 'Document à télécharger' : `${nombre} documents à télécharger`;
}
