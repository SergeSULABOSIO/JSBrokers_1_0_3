/**
 * @file Lecture d'un flux NDJSON, ligne à ligne, PENDANT que le serveur travaille.
 *
 * Le serveur de ce projet ne diffuse pas pour faire joli : il n'a qu'un seul processus PHP
 * en développement, si bien qu'une requête de sondage attendrait sagement la fin de celle
 * qu'elle interroge — et la barre resterait figée jusqu'à ce qu'il n'y ait plus rien à
 * afficher. La progression voyage donc DANS la requête qui travaille (cf. FluxNdjson côté
 * PHP), une ligne JSON par pulsation, la dernière portant le résultat.
 *
 * ⚠ CE LECTEUR EST PARTAGÉ, ET IL DOIT L'ÊTRE. Sa subtilité tient en trois lignes — une
 * ligne JSON peut arriver coupée en deux paquets, et il faut garder le reste pour le tour
 * suivant — et la recopier ailleurs, c'est se condamner à ne corriger qu'une des copies.
 * L'échange de données et la suppression en chaîne s'en servent l'un comme l'autre.
 */

/**
 * Lit un flux NDJSON et rend sa DERNIÈRE ligne de résultat.
 *
 * @param {string} url
 * @param {RequestInit} options
 * @param {(charge: object) => void} surLigne appelé pour CHAQUE ligne lue ; lever une
 *        erreur depuis ce rappel interrompt la lecture (c'est ainsi qu'une ligne
 *        « erreur » arrête le travail au lieu de passer pour un résultat ordinaire).
 * @returns {Promise<object|null>} la dernière ligne qui n'est pas une pulsation.
 */
export async function lireFluxNdjson(url, options, surLigne) {
    const response = await fetch(url, {
        ...options,
        headers: { 'X-Requested-With': 'XMLHttpRequest', ...(options.headers || {}) },
    });

    if (!response.ok) {
        // Un refus survient AVANT le flux (droits, format) : le corps est alors du
        // JSON ordinaire, et son message vaut mieux qu'un code HTTP nu.
        const texte = (await response.text()).trim();
        let message = texte;
        try {
            message = JSON.parse(texte).message || texte;
        } catch {
            // Corps non JSON : on relaie le texte brut.
        }
        throw new Error(message || `HTTP ${response.status}`);
    }

    const lecteur = response.body.getReader();
    const decodeur = new TextDecoder();
    let tampon = '';
    let dernier = null;

    const consommer = (ligne) => {
        const texte = (ligne || '').trim();
        if (!texte) return;

        let charge;
        try {
            charge = JSON.parse(texte);
        } catch {
            return; // Ligne illisible : on ne casse pas le flux pour autant.
        }

        surLigne(charge);

        // La convention du serveur : `type: 'progres'` est une pulsation, tout le reste
        // est un résultat. On retient donc le dernier résultat vu.
        if (charge.type !== 'progres') {
            dernier = charge;
        }
    };

    for (;;) {
        const { done, value } = await lecteur.read();
        if (done) break;

        tampon += decodeur.decode(value, { stream: true });

        // Une ligne peut arriver coupée en deux paquets : on ne traite que celles
        // qui sont complètes, et on garde le reste pour le tour suivant.
        const lignes = tampon.split('\n');
        tampon = lignes.pop() ?? '';
        lignes.forEach(consommer);
    }

    // Dernière ligne éventuellement restée dans le tampon.
    consommer(tampon);

    return dernier;
}
