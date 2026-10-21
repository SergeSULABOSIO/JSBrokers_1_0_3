/**
 * LE STOCKAGE DU NAVIGATEUR N'APPARTIENT QU'À SON UTILISATEUR — branchement (chantier 15).
 *
 * Importé par app.js AVANT bootstrap.js : la purge a lieu avant que Stimulus ne restaure le
 * moindre onglet. Le serveur écrit sur <html> :
 *   — `data-jsb-utilisateur` : l'identifiant numérique de l'utilisateur connecté ;
 *   — `data-jsb-deconnexion` : la page de connexion atteinte par une déconnexion.
 * Les règles (quoi effacer, quand) vivent dans stockage-de-travail-regles.js, éprouvées
 * sans navigateur.
 */
import { surArrivee, surDeconnexion } from './stockage-de-travail-regles.js';

try {
    const racine = document.documentElement;
    if (racine.dataset.jsbDeconnexion !== undefined) {
        surDeconnexion(localStorage, sessionStorage);
    }
    surArrivee(localStorage, sessionStorage, racine.dataset.jsbUtilisateur);
} catch { /* stockage indisponible (navigation privée, quota) : rien à garder, rien à effacer */ }
