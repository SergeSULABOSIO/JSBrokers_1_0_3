/**
 * Capteur audio du mode Live, dans le THREAD AUDIO.
 *
 * Il remplace ScriptProcessorNode, déprécié et exécuté sur le fil principal : quand ce
 * fil est occupé — et il l'est, entre le rendu du chat et les requêtes réseau —, les
 * trames arrivent en retard ou par paquets, ce qui fait manquer des débuts de phrase
 * sur téléphone. Un worklet tourne à part et livre ses trames à l'heure.
 *
 * Il ne DÉCIDE rien : il ne fait que transmettre les échantillons au contrôleur, qui
 * les confie au détecteur de parole (ket-live-parole.js). Toute la logique reste
 * testable hors navigateur.
 */
class CapteurDeParole extends AudioWorkletProcessor {
    process(entrees) {
        const canal = entrees?.[0]?.[0];
        if (canal && canal.length) {
            // Une COPIE : le tampon du worklet est réutilisé d'une trame à l'autre.
            this.port.postMessage(Float32Array.from(canal));
        }

        return true; // vivant tant que le contexte l'est
    }
}

registerProcessor('ket-live-capteur', CapteurDeParole);
