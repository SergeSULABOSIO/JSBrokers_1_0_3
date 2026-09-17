/**
 * Cœur PUR du mode Live : transformer ce que le micro a capté en un fichier WAV que
 * les oreilles de Ket savent lire.
 *
 * Le micro donne des flottants au taux du système (souvent 48 kHz). Les fournisseurs
 * de transcription travaillent très bien à 16 kHz, et le fichier y est trois fois plus
 * léger — ce qui compte, puisqu'il part par le réseau à chaque phrase.
 *
 * Aucun DOM, aucune API audio : testable sous `node --test tests/js/`.
 */

import { ENTETE_WAV } from './assistant-voix-pcm.js';

/** Taux attendu par les oreilles (cf. FournisseurDOreille::TAUX_ECHANTILLONNAGE). */
export const TAUX_OREILLE = 16000;

/**
 * Ré-échantillonne des flottants vers `tauxCible`, par moyenne des échantillons de
 * chaque intervalle (plus fidèle qu'une simple sélection, et sans dépendance).
 *
 * @param {Float32Array} echantillons
 * @returns {Float32Array}
 */
export function reechantillonner(echantillons, tauxSource, tauxCible = TAUX_OREILLE) {
    const source = echantillons ?? new Float32Array(0);
    if (!tauxSource || tauxSource === tauxCible || source.length === 0) {
        return source instanceof Float32Array ? source : Float32Array.from(source);
    }
    const rapport = tauxSource / tauxCible;
    const sortie = new Float32Array(Math.floor(source.length / rapport));
    for (let i = 0; i < sortie.length; i++) {
        const debut = Math.floor(i * rapport);
        const fin = Math.min(source.length, Math.floor((i + 1) * rapport));
        let somme = 0;
        let nb = 0;
        for (let j = debut; j < fin; j++) {
            somme += source[j];
            nb++;
        }
        sortie[i] = nb ? somme / nb : source[debut] ?? 0;
    }
    return sortie;
}

/** Met bout à bout les trames d'une phrase. */
export function assembler(trames) {
    const total = (trames ?? []).reduce((n, t) => n + t.length, 0);
    const tout = new Float32Array(total);
    let position = 0;
    for (const trame of trames ?? []) {
        tout.set(trame, position);
        position += trame.length;
    }
    return tout;
}

/**
 * Flottants [-1, 1] → fichier WAV PCM 16 bits mono.
 *
 * @returns {Uint8Array}
 */
export function encoderWav(echantillons, taux = TAUX_OREILLE) {
    const source = echantillons ?? new Float32Array(0);
    const octets = new Uint8Array(ENTETE_WAV + source.length * 2);
    const vue = new DataView(octets.buffer);
    const ecrire = (position, texte) => {
        for (let i = 0; i < texte.length; i++) vue.setUint8(position + i, texte.charCodeAt(i));
    };

    ecrire(0, 'RIFF');
    vue.setUint32(4, 36 + source.length * 2, true);
    ecrire(8, 'WAVE');
    ecrire(12, 'fmt ');
    vue.setUint32(16, 16, true); // taille du bloc de format
    vue.setUint16(20, 1, true); // PCM
    vue.setUint16(22, 1, true); // mono
    vue.setUint32(24, taux, true);
    vue.setUint32(28, taux * 2, true); // octets par seconde
    vue.setUint16(32, 2, true); // alignement
    vue.setUint16(34, 16, true); // bits par échantillon
    ecrire(36, 'data');
    vue.setUint32(40, source.length * 2, true);

    for (let i = 0; i < source.length; i++) {
        const borne = Math.max(-1, Math.min(1, source[i]));
        vue.setInt16(ENTETE_WAV + i * 2, Math.round(borne * 32767), true);
    }

    return octets;
}

/** Durée, en secondes, d'une phrase capturée. */
export function duree(echantillons, taux = TAUX_OREILLE) {
    return (echantillons?.length ?? 0) / taux;
}
