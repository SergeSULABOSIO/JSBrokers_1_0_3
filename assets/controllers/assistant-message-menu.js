/**
 * Logique PURE des actions d'une bulle du chat Ket — AUCUN accès au DOM ni au
 * réseau. Testable sous Node (`node --test tests/js/`), comme
 * `assistant-theme.js` et `menu-flottant.js`.
 *
 * Le contrôleur Stimulus ne connaît AUCUNE URL en dur : les trois routes
 * serveur des actions de bulle sont suffixées à `sendUrl`, qui vaut déjà
 * `/admin/assistant-ia/api/messages/{idEntreprise}/{idConversation}` (valeur
 * Stimulus `sendUrlValue` posée par le partial). C'est ce qui évite d'ajouter
 * trois `static values` — et de les oublier le jour où le préfixe change.
 *
 * Correspondance avec le PHP, à garder alignée :
 *   FORMAT_PDF / FORMAT_WORD / FORMAT_MARKDOWN  ⇄  App\Ai\Export\MessageExporter::FORMATS
 * FORMAT_IMAGE est délibérément ABSENT de FORMATS_SERVEUR : la capture PNG est
 * produite par le navigateur (fidélité des graphiques Chart.js) et ne transite
 * jamais par le serveur.
 */

export const FORMAT_PDF = 'pdf';
export const FORMAT_WORD = 'word';
export const FORMAT_MARKDOWN = 'markdown';
export const FORMAT_IMAGE = 'image';

/** Formats servis par la route d'export — miroir de MessageExporter::FORMATS. */
export const FORMATS_SERVEUR = [FORMAT_PDF, FORMAT_WORD, FORMAT_MARKDOWN];

/** Clés `data-menu-key` reconnues par le contrôleur (le libellé vit en Twig). */
export const CLE_REPONDRE = 'repondre';
export const CLE_COPIE = 'copier-image';
export const CLE_EMAIL = 'envoyer-email';
export const CLE_EXPORT = {
    [FORMAT_PDF]: 'export-pdf',
    [FORMAT_WORD]: 'export-word',
    [FORMAT_MARKDOWN]: 'export-markdown',
    [FORMAT_IMAGE]: 'export-image',
};

/**
 * Base d'URL des routes d'un message, sans slash final parasite (`sendUrl` peut
 * en porter un selon la génération d'URL).
 * @param {string} sendUrl
 * @param {number|string} idMessage
 * @returns {string}
 */
function baseMessage(sendUrl, idMessage) {
    return `${String(sendUrl).replace(/\/+$/, '')}/${idMessage}`;
}

/**
 * URL de téléchargement d'un message dans un format servi par le serveur.
 * @param {string} sendUrl
 * @param {number|string} idMessage
 * @param {'pdf'|'word'|'markdown'} format
 * @returns {string}
 */
export function urlExportMessage(sendUrl, idMessage, format) {
    return `${baseMessage(sendUrl, idMessage)}/export/${format}`;
}

/**
 * URL du picker de destinataires (renvoie du HTML, comme les autres pickers).
 * @param {string} sendUrl
 * @param {number|string} idMessage
 * @returns {string}
 */
export function urlDestinatairesMessage(sendUrl, idMessage) {
    return `${baseMessage(sendUrl, idMessage)}/destinataires`;
}

/**
 * Nom de fichier d'une capture PNG. Miroir du nommage serveur
 * (`MessageExporter::nomFichier`) : aucune donnée utilisateur, donc rien à
 * assainir.
 * @param {number|string} idMessage
 * @param {Date} maintenant horloge injectée (testabilité, cf. `formatInstant`)
 * @returns {string}
 */
export function nomFichierImage(idMessage, maintenant) {
    const aaaa = maintenant.getFullYear();
    const mm = String(maintenant.getMonth() + 1).padStart(2, '0');
    const jj = String(maintenant.getDate()).padStart(2, '0');

    return `message-ia-${idMessage}-${aaaa}${mm}${jj}.png`;
}
