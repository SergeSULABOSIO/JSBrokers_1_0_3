/**
 * Les gestes « imprimer » et « fermer » des PAGES AUTONOMES, qui n'ont pas Stimulus
 * (aperçu de note, relevé client autonome et public : elles n'appellent pas importmap()).
 *
 * Même balisage que le contrôleur `geste` (`data-action="click->geste#imprimer"`) : un
 * bouton s'écrit de la même façon partout, et la politique de sécurité n'admet plus de
 * code dans un attribut `onclick`. Chargé par `<script type="module" src="{{ asset(…) }}">`
 * — un fichier de chez nous, sans nonce à poser.
 *
 * Seuls « imprimer » et « fermer » existent ici (GestesSansCodeTest refuse tout autre geste
 * sur une page autonome). La page publique du relevé est ouverte par les clients, sur des
 * navigateurs parfois anciens : rien de plus récent qu'ES2017 ici (pas d'`Object.hasOwn`,
 * inconnu de Safari avant 15.4 — chaque clic lèverait une erreur et le bouton serait mort).
 *
 * ⚠ Jamais sur une page qui a Stimulus : le geste partirait deux fois.
 */
const GESTES = {
    'geste#imprimer': () => window.print(),
    'geste#fermer': () => window.close(),
};

/**
 * `click->geste#imprimer:prevent` → { nom: 'geste#imprimer', options: ['prevent'] } ; une action
 * sans événement vaut `click` pour un bouton. Null si ce n'est pas un geste connu au clic.
 */
function gesteDuClic(action) {
    const [evenement, cible] = action.includes('->') ? action.split('->') : ['click', action];
    if (evenement !== 'click') {
        return null;
    }
    const [nom, ...options] = cible.split(':');

    return Object.prototype.hasOwnProperty.call(GESTES, nom) ? { nom, options } : null;
}

document.addEventListener('click', (event) => {
    // Tous les ancêtres qui portent des actions, comme Stimulus les déclencherait.
    for (let element = event.target instanceof Element ? event.target : null; element; element = element.parentElement) {
        const actions = element.getAttribute('data-action');
        if (!actions) {
            continue;
        }
        for (const action of actions.trim().split(/\s+/)) {
            const geste = gesteDuClic(action);
            if (!geste) {
                continue;
            }
            if (geste.options.includes('prevent')) {
                event.preventDefault();
            }
            GESTES[geste.nom]();
        }
    }
});
