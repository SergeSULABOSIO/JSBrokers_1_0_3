/**
 * LE TRI D'UN LOT DE FICHIERS avant envoi — logique pure, aucun DOM.
 *
 * Elle vit à part du contrôleur pour une raison simple : c'est la seule partie qui
 * DÉCIDE quelque chose, et c'est donc la seule qu'il faille pouvoir vérifier sans
 * navigateur. Le contrôleur, lui, ne fait qu'afficher ce qu'elle rend.
 *
 * ⚠️ CE TRI N'EST PAS UNE SÉCURITÉ. Le serveur revalide chaque fichier et reste seul
 * juge (DocumentController::motifDeRefus). Ce qui se joue ici est le CONFORT : dire
 * tout de suite « ce format n'est pas accepté » plutôt qu'après un téléversement de
 * dix mégaoctets. Les bornes appliquées sont celles que le serveur publie
 * (FichierAttachePolicy::limitesFront), jamais des valeurs recopiées.
 */

/** Taille lisible, même échelle que celle affichée par le chat et les fiches. */
export function tailleLisible(octets) {
    if (typeof octets !== 'number' || Number.isNaN(octets)) return 'inconnue';
    if (octets < 1024) return `${octets} o`;
    if (octets < 1024 * 1024) return `${(octets / 1024).toFixed(1).replace('.', ',')} Ko`;
    return `${(octets / (1024 * 1024)).toFixed(1).replace('.', ',')} Mo`;
}

/** L'extension en minuscules, sans point ; chaîne vide si le nom n'en porte pas. */
export function extensionDe(nom) {
    const point = String(nom || '').lastIndexOf('.');
    return point > 0 ? String(nom).slice(point + 1).toLowerCase() : '';
}

/**
 * Pourquoi ce fichier serait refusé, ou null s'il passe.
 *
 * @param {{name: string, size: number}} fichier
 * @param {{maxSize?: number, extensions?: string[]}} limites
 */
export function motifDeRefus(fichier, limites = {}) {
    const extensions = limites.extensions || [];
    const maxSize = limites.maxSize || 0;

    if (!fichier || typeof fichier.size !== 'number' || fichier.size <= 0) {
        return 'fichier vide';
    }
    if (maxSize > 0 && fichier.size > maxSize) {
        return `dépasse ${tailleLisible(maxSize)}`;
    }
    const ext = extensionDe(fichier.name);
    if (extensions.length > 0 && !extensions.includes(ext)) {
        return `format « ${ext || 'inconnu'} » non accepté`;
    }
    return null;
}

/**
 * Range un lot en retenus / refusés, en écartant les DOUBLONS de nom.
 *
 * Le doublon est écarté parce qu'un utilisateur qui glisse deux fois le même fichier
 * ne veut pas deux documents identiques dans son dossier — il croit corriger un oubli.
 * Le fichier déjà présent gagne : c'est celui qu'il voit à l'écran.
 *
 * @param {Array} nouveaux    fichiers qui viennent d'être choisis
 * @param {Array} dejaChoisis fichiers déjà dans la liste (objets {name})
 * @returns {{retenus: Array, refuses: Array<{nom: string, motif: string}>}}
 */
export function trierLot(nouveaux, dejaChoisis = [], limites = {}) {
    const noms = new Set((dejaChoisis || []).map((f) => f.name));
    const retenus = [];
    const refuses = [];

    (nouveaux || []).forEach((fichier) => {
        if (noms.has(fichier.name)) {
            refuses.push({ nom: fichier.name, motif: 'déjà dans la liste' });
            return;
        }
        const motif = motifDeRefus(fichier, limites);
        if (motif) {
            refuses.push({ nom: fichier.name, motif });
            return;
        }
        noms.add(fichier.name);
        retenus.push(fichier);
    });

    return { retenus, refuses };
}
