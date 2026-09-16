/**
 * Cœur PUR de la lecture de la VOIX de Ket (audio Gemini) : les octets reçus du
 * serveur deviennent des échantillons que la Web Audio API peut jouer au fil de l'eau.
 *
 * Le serveur envoie soit du PCM 16 bits brut (génération en cours, en flux), soit un
 * WAV complet (audio déjà mis en cache). Les deux passent par le même lecteur : le WAV
 * n'est que du PCM précédé d'un en-tête de 44 octets.
 *
 * Aucun DOM, aucune API audio : testable sous `node --test tests/js/`.
 */

/** Fréquence d'échantillonnage de la voix Gemini. */
export const TAUX_VOIX = 24000;

/** Taille de l'en-tête WAV canonique écrit par le cache serveur. */
export const ENTETE_WAV = 44;

/** Au-delà, sans réponse du serveur, la voix du navigateur prend le relais. */
export const DELAI_PREMIER_SON_MS = 20000;

/** Longueur maximale d'un texte envoyé en une seule génération. */
export const SEGMENT_VOIX_MAX = 3800;

/**
 * Octets PCM 16 bits signés petit-boutistes → échantillons flottants [-1, 1].
 *
 * Un morceau réseau peut couper un échantillon en deux : l'octet orphelin est rendu
 * dans `reste` et recollé en tête du morceau suivant.
 *
 * @param {Uint8Array} octets
 * @param {Uint8Array|null} reste octet orphelin du morceau précédent
 * @returns {{echantillons: Float32Array, reste: Uint8Array|null}}
 */
export function pcm16VersFloat32(octets, reste = null) {
    let source = octets ?? new Uint8Array(0);
    if (reste && reste.length) {
        const fusion = new Uint8Array(reste.length + source.length);
        fusion.set(reste, 0);
        fusion.set(source, reste.length);
        source = fusion;
    }
    const nb = Math.floor(source.length / 2);
    const echantillons = new Float32Array(nb);
    for (let i = 0; i < nb; i++) {
        const valeur = source[2 * i] | (source[2 * i + 1] << 8);
        echantillons[i] = (valeur >= 0x8000 ? valeur - 0x10000 : valeur) / 0x8000;
    }
    return {
        echantillons,
        reste: source.length % 2 ? source.slice(source.length - 1) : null,
    };
}

/**
 * Retire les `aSauter` premiers octets d'un flux découpé en morceaux (l'en-tête WAV
 * peut, en théorie, arriver en plusieurs fois).
 *
 * @param {Uint8Array} octets
 * @param {number} aSauter
 * @returns {{octets: Uint8Array, aSauter: number}}
 */
export function sauterOctets(octets, aSauter) {
    if (aSauter <= 0) return { octets, aSauter: 0 };
    if (octets.length <= aSauter) return { octets: new Uint8Array(0), aSauter: aSauter - octets.length };
    return { octets: octets.subarray(aSauter), aSauter: 0 };
}

/**
 * La réponse du serveur permet-elle d'écouter la voix de Ket ? Sinon (repli 503,
 * solde 402, erreur), c'est la voix du navigateur qui lit.
 *
 * @param {number} statut
 * @param {string|null} typeContenu
 * @returns {'flux'|'wav'|'repli'}
 */
export function modeDeLecture(statut, typeContenu) {
    if (statut !== 200) return 'repli';
    const type = String(typeContenu ?? '').toLowerCase();
    if (type.startsWith('audio/wav')) return 'wav';
    if (type.startsWith('audio/l16')) return 'flux';
    return 'repli';
}
