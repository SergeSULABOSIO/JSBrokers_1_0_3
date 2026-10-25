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
import { noterCoupureConfirmee, noterReponseRecue } from './controllers/rafraichissement-de-fond.js';

if (!window.__repriseReseauInstallee && typeof window.fetch === 'function') {
    window.__repriseReseauInstallee = true;

    const fetchOriginal = window.fetch;
    window.fetch = async function (entree, options) {
        try {
            const reponse = await fetchOriginal.call(this, entree, options);
            // Une réponse — quelle qu'elle soit — prouve que le réseau répond : la pause des mises
            // à jour automatiques est levée (rafraichissement-de-fond.js).
            noterReponseRecue();

            return reponse;
        } catch (erreur) {
            const signal = signalDe(entree, options);
            const adresse = adresseDe(entree);
            // L'état du réseau, s'il est connu (un environnement sans `navigator` : inconnu).
            const enLigne = typeof navigator !== 'undefined' && 'onLine' in navigator ? navigator.onLine : undefined;
            if (!doitReprendre({ methode: methodeDe(entree, options), erreur, signal, adresse, origine: window.location.origin, enLigne })) {
                throw erreur;
            }
            console.warn('Connexion coupée — nouvelle tentative :', adresse);
            await new Promise((resolve) => setTimeout(resolve, DELAI_AVANT_REPRISE_MS));
            if (signal?.aborted) {
                throw erreur;
            }

            try {
                const reponse = await fetchOriginal.call(this, entree, options);
                noterReponseRecue();

                return reponse;
            } catch (erreurDeReprise) {
                // Coupée DEUX fois : le réseau est muet (Wi-Fi connecté, Internet absent) — les
                // mises à jour automatiques se mettent en pause, plus longue à chaque coupure.
                if (erreurDeReprise instanceof TypeError) noterCoupureConfirmee();
                throw erreurDeReprise;
            }
        }
    };
}
