/**
 * Qui possède quoi sur le bus `cerveau:event` — cœur PUR, sans DOM.
 *
 * POURQUOI CE FICHIER EXISTE. Le bus est écouté par le Cerveau ET par une dizaine
 * de contrôleurs qui y guettent leur propre type (l'assistant, la liste des
 * conversations, les éditeurs de la console…). Le Cerveau, lui, avertissait en
 * console « Aucun gestionnaire défini » pour tout type absent de son switch — donc
 * pour ces délégations parfaitement légitimes. Chaque nouvelle conversation ouverte
 * produisait ainsi un avertissement rouge qui ne signalait rien.
 *
 * Le remède n'est pas de rajouter un `case` vide à chaque fois (c'était la parade
 * en place pour `ket:mutation.execute`) : c'est de DÉCLARER la délégation. Le
 * silence devient alors une décision documentée — on sait qui traite l'événement —
 * et l'avertissement retrouve son sens : il ne reste allumé que pour un type que
 * PERSONNE ne traite, ce qui est toujours un défaut.
 */

/**
 * Type d'événement => contrôleur qui le traite. Le propriétaire est documenté
 * plutôt que sous-entendu : c'est ce qui permet de retrouver le code responsable
 * sans fouiller, et de savoir quoi supprimer le jour où l'un d'eux disparaît.
 *
 * @type {Readonly<Record<string, string>>}
 */
export const TYPES_DELEGUES = Object.freeze({
    'ket:mutation.execute': 'assistant-chat',
    'ket:conversation.new': 'assistant-chat',
    'assistant:message.envoye': 'assistant-chat',
    'ia:conversation.delete-execute': 'assistant-ia',
    'app:soa-ctx.delete-execute': 'soa-context-menu',
    'app:workspace.logout-execute': 'workspace-manager',
    'packs:delete': 'packs-editor',
    'weights:delete': 'weights-editor',
});

/**
 * Préfixes des types ENGENDRÉS à l'exécution, qu'on ne peut pas énumérer.
 * `confirm-action` numérote ses instances (`confirm-action:3`) pour que deux
 * boutons de la même page ne se confondent pas : seul le préfixe est stable.
 *
 * @type {ReadonlyArray<string>}
 */
export const PREFIXES_DELEGUES = Object.freeze(['confirm-action:']);

/**
 * Cet événement est-il traité par un AUTRE contrôleur que le Cerveau ?
 *
 * @param {string} type
 * @returns {boolean}
 */
export function estDelegue(type) {
    if (typeof type !== 'string' || type === '') {
        return false;
    }
    if (Object.prototype.hasOwnProperty.call(TYPES_DELEGUES, type)) {
        return true;
    }

    return PREFIXES_DELEGUES.some((prefixe) => type.startsWith(prefixe));
}

/**
 * Le contrôleur responsable d'un type délégué, pour le journal de mise au point.
 *
 * @param {string} type
 * @returns {string|null}
 */
export function proprietaireDe(type) {
    if (typeof type !== 'string') {
        return null;
    }
    if (Object.prototype.hasOwnProperty.call(TYPES_DELEGUES, type)) {
        return TYPES_DELEGUES[type];
    }
    const prefixe = PREFIXES_DELEGUES.find((p) => type.startsWith(p));

    return prefixe ? prefixe.slice(0, -1) : null;
}
