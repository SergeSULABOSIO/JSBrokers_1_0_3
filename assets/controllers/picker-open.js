/**
 * Ouverture d'un picker AUTONOME : récupère son fragment HTML et l'insère dans
 * la bonne surface, où Stimulus connecte alors son contrôleur dédié.
 *
 * Extrait de `cerveau_controller._openStandalonePicker()` pour que le chat de
 * l'assistant puisse ouvrir le même genre de picker sans dépendre du Cerveau
 * (le chat vit en colonne 4, le Cerveau n'y est pas forcément présent). Le
 * cerveau en reste l'appelant principal : il passe simplement SES callbacks, et
 * son comportement est inchangé à l'identique.
 *
 * Les effets de bord (indicateur de chargement, notification d'erreur) sont
 * injectés, jamais décidés ici : chaque hôte les rend à sa façon.
 */

/**
 * @param {string} url endpoint renvoyant le fragment HTML du picker
 * @param {{
 *   controllerName: string,
 *   errorLabel: string,
 *   onErreur?: (message: string) => void,
 *   onChargement?: (actif: boolean) => void,
 * }} options
 * @returns {Promise<HTMLElement|null>} le picker inséré, ou null en cas d'échec
 */
export async function ouvrirPickerAutonome(url, { controllerName, errorLabel, onErreur, onChargement } = {}) {
    const signalerErreur = (message) => {
        if (typeof onErreur === 'function') onErreur(message);
    };
    const signalerChargement = (actif) => {
        if (typeof onChargement === 'function') onChargement(actif);
    };

    if (!url) {
        console.error(`[picker-open] ${controllerName} : URL manquante.`);
        signalerErreur(`Impossible d'ouvrir ${errorLabel} : URL manquante.`);
        return null;
    }
    // Garde anti double-ouverture (double clic / menu contextuel + toolbar).
    if (document.querySelector(`[data-controller~="${controllerName}"]`)) return null;

    try {
        signalerChargement(true);
        const response = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        if (!response.ok) throw new Error(`Erreur serveur ${response.status}`);
        const html = await response.text();

        const holder = document.createElement('div');
        holder.innerHTML = html.trim();
        const picker = holder.firstElementChild;
        if (!picker) throw new Error('Contenu du picker vide.');

        // Hôte = la modale ouverte la plus HAUTE (dernière .modal.show réellement
        // visible), sinon <body>. On filtre sur la visibilité : un `.show` résiduel
        // sur une modale masquée détournerait le picker vers un conteneur invisible.
        const host = Array.from(document.querySelectorAll('.modal.show'))
            .filter((m) => getComputedStyle(m).display !== 'none')
            .pop() || document.body;
        host.appendChild(picker); // → connect() du contrôleur Stimulus dédié

        return picker;
    } catch (error) {
        console.error(`[picker-open] ${controllerName} failed:`, error);
        signalerErreur(error.message || `Impossible d'ouvrir ${errorLabel}.`);

        return null;
    } finally {
        signalerChargement(false);
    }
}
