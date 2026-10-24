/**
 * L'INFOBULLE UNIQUE DES PAGES AUTONOMES, qui n'ont pas Stimulus (relevé de compte autonome et
 * PUBLIC : elles n'appellent pas importmap('app')). Même moteur que le contrôleur `infobulle` de
 * l'application — un seul comportement, une seule apparence (styles/infobulle.css).
 *
 * Chargé par `{{ importmap('infobulles-autonomes', {nonce: csp_nonce()}) }}`, AVANT tout autre
 * module de la page : son point d'entrée dans importmap.php — le moteur importe ses modules par
 * chemin relatif, que seule l'importmap résout (un simple <script src> les cherchait sans
 * empreinte : 404). Compromis connu : l'importmap rendue liste les chemins de TOUS les assets
 * (publics de toute façon — rien de secret, un peu de poids).
 *
 * NAVIGATEURS ANCIENS (le relevé public est ouvert par des clients) : le moteur reste en ES2017
 * (infobulle-es2017.test.mjs), mais un navigateur SANS importmap native ne le charge pas — le
 * chargeur du polyfill rendu par AssetMapper est lui-même trop récent pour lui. D'où le REPLI :
 * chaque aide porte aussi un `title` natif, que ce module retire dès qu'il a pris la main (sinon
 * deux bulles). Sans moteur, l'aide reste lisible par l'infobulle du navigateur.
 *
 * ⚠ Jamais sur une page qui a Stimulus : le <body> y porte déjà le contrôleur `infobulle`.
 */
import { MoteurInfobulle } from './controllers/infobulle-moteur.js';

new MoteurInfobulle(document.body).connecter();

// Le moteur a pris la main : le `title` de repli (navigateurs sans importmap) ferait doublon.
Array.prototype.forEach.call(document.querySelectorAll('[data-infobulle][title]'), (e) => e.removeAttribute('title'));
