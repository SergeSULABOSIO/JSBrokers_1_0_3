/**
 * Retente UNE fois une lecture coupée par le réseau — pour tout l'application.
 *
 * La règle (quelles requêtes, quelles erreurs) vit dans `reprise-reseau-regles.js`.
 * Global pour la même raison que `cabinet-ferme.js` : 44 contrôleurs appellent `fetch`,
 * aucun ne retentait ; un onglet restait vide sur une coupure passagère.
 *
 * Chargé par app.js AVANT `cabinet-ferme.js` : celui-ci enveloppe ce `fetch`-ci et voit
 * donc la réponse finale, reprise comprise.
 *
 * `XMLHttpRequest` n'est pas couvert : aucune coupure observée par ce transport, et le
 * rejouer demanderait de reconstruire l'objet de l'appelant.
 */
import { adresseDe, DELAI_AVANT_REPRISE_MS, doitReprendre, methodeDe, signalDe } from './reprise-reseau-regles.js';

if (!window.__repriseReseauInstallee && typeof window.fetch === 'function') {
    window.__repriseReseauInstallee = true;

    const fetchOriginal = window.fetch;
    window.fetch = async function (entree, options) {
        try {
            return await fetchOriginal.call(this, entree, options);
        } catch (erreur) {
            const signal = signalDe(entree, options);
            const adresse = adresseDe(entree);
            if (!doitReprendre({ methode: methodeDe(entree, options), erreur, signal, adresse, origine: window.location.origin })) {
                throw erreur;
            }
            console.warn('Connexion coupée — nouvelle tentative :', adresse);
            await new Promise((resolve) => setTimeout(resolve, DELAI_AVANT_REPRISE_MS));
            if (signal?.aborted) {
                throw erreur;
            }

            return fetchOriginal.call(this, entree, options);
        }
    };
}
