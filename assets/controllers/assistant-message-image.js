/**
 * Capture PNG d'une bulle du chat, mise en forme INTACTE — graphiques compris.
 *
 * Pourquoi côté client : Chart.js peint dans un <canvas>, que le navigateur est
 * seul à savoir rasteriser. Aucun rendu serveur (DomPDF ou autre) ne peut le
 * reproduire ; l'export PDF/Word transcrit donc le graphique en TABLEAU de
 * données (sans perte), tandis que cette capture rend la fidélité au pixel.
 *
 * html2canvas est retenu précisément parce qu'il redessine le contenu des
 * <canvas> — ce qu'une sérialisation <foreignObject> ne sait pas faire.
 * Il est chargé en import DYNAMIQUE : la librairie ne pèse sur le chat que
 * lorsqu'on exporte réellement une image.
 *
 * Le fichier ne transite JAMAIS par le serveur : il est téléchargé localement.
 * C'est délibéré — l'application n'accepte aucun binaire fabriqué par le client
 * pour un envoi sous la marque JS Brokers.
 */

/** Marge blanche autour de la bulle, en pixels CSS. */
const MARGE = 12;

/**
 * Rasterise une bulle en PNG.
 *
 * @param {HTMLElement} bulle élément `.aic-msg`
 * @param {{theme?: 'light'|'dark', echelle?: number}} options
 * @returns {Promise<Blob|null>}
 */
export async function capturerBulle(bulle, { theme = 'light', echelle = 2 } = {}) {
    if (!bulle) return null;

    const { default: html2canvas } = await import('html2canvas');

    const canvas = await html2canvas(bulle, {
        // Sans fond explicite, le PNG est transparent : illisible sur fond clair
        // après une capture en thème sombre (et inversement). On reprend le fond
        // réel du panneau, résolu depuis les jetons --aic-*.
        backgroundColor: fondDuChat(bulle, theme),
        scale: echelle,
        useCORS: true,
        logging: false,
        // Le bouton ⋮ est un affordance d'interface, pas du contenu : il n'a
        // rien à faire dans un document qu'on transmet.
        ignoreElements: (element) => element.classList?.contains('aic-msg-menu-btn'),
        onclone: (documentClone, elementClone) => {
            // La bulle est capturée hors de son fil : on lui rend une largeur
            // stable et une marge, sinon html2canvas rogne au plus juste.
            elementClone.style.margin = `${MARGE}px`;
            elementClone.style.width = `${bulle.getBoundingClientRect().width}px`;
            // Le clone sort de la cascade s'il est déplacé : on lui réaffirme le
            // thème pour que les jetons --aic-* résolvent comme à l'écran.
            documentClone.querySelectorAll('.jsb-ai-chat').forEach((chat) => {
                chat.setAttribute('data-aic-theme', theme);
            });
        },
    });

    return await new Promise((resolve) => canvas.toBlob(resolve, 'image/png'));
}

/**
 * Fond effectif du chat : jeton `--aic-bg` s'il est résoluble, sinon repli sur
 * les deux valeurs déclarées dans le partial (clair #f8f9fa, sombre #16181b).
 */
function fondDuChat(bulle, theme) {
    const racine = bulle.closest('.jsb-ai-chat');
    if (racine) {
        const jeton = getComputedStyle(racine).getPropertyValue('--aic-bg').trim();
        if (jeton !== '') {
            return jeton;
        }
    }

    return theme === 'dark' ? '#16181b' : '#f8f9fa';
}
