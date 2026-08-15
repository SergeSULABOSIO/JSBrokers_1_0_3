/*
 * LE TITRE D'UNE CONVERSATION S'AFFICHE À QUATRE ENDROITS À LA FOIS.
 *
 * Renommer, ce n'est pas écrire un libellé : c'est le réécrire partout où il est
 * déjà. Une conversation ouverte se voit simultanément dans la liste de la
 * colonne 3, dans les jeux de données des trois boutons de sa ligne, sur
 * l'onglet de la colonne 4, et dans le localStorage qui restaure cet onglet
 * après un rechargement de page.
 *
 * En oublier un ne casse rien bruyamment — ça produit un affichage qui se
 * contredit. C'était le cas du localStorage : le renommage depuis la colonne 3
 * mettait bien à jour la ligne et l'onglet, mais pas l'entrée mémorisée, si
 * bien qu'un F5 ressuscitait l'ancien titre sur l'onglet restauré.
 *
 * D'où ce module : UN seul endroit qui sait où vit un titre. Le renommage
 * depuis la liste et le renommage depuis l'onglet l'appellent tous les deux.
 */

/** Le préfixe qui relie un onglet de la colonne 4 à sa conversation. */
export const PREFIXE_ONGLET = 'ia-conv-';

/** L'onglet col-4 de cette conversation, s'il est ouvert. */
export function ongletDeConversation(convId) {
    return document.querySelector(
        `[data-entity-id='${PREFIXE_ONGLET}${convId}'][data-entity-type='html']`
    );
}

/** L'identifiant de conversation porté par un onglet, ou null si ce n'en est pas un. */
export function conversationDeLOnglet(tabElement) {
    if (!tabElement || tabElement.dataset.entityType !== 'html') return null;
    const cle = tabElement.dataset.entityId || '';

    return cle.startsWith(PREFIXE_ONGLET) ? cle.slice(PREFIXE_ONGLET.length) : null;
}

/**
 * Écrit le nouveau titre partout où il se voit.
 *
 * @param {string|number} convId
 * @param {string} titre
 * @param {string|number} [idEntreprise] Sans lui, le localStorage n'est pas
 *        touché — l'appelant ne connaît pas toujours l'entreprise, et une clé
 *        approximative vaudrait moins que pas de mise à jour du tout.
 */
export function appliquerTitre(convId, titre, idEntreprise = null) {
    // 1. La ligne de la liste (colonne 3), si elle est rendue.
    const item = document.querySelector(`.ai-conv-item[data-conv-id='${convId}']`);
    if (item) {
        const span = item.querySelector('[data-role="conv-titre"]');
        if (span) span.textContent = titre;
        // Les trois boutons de la ligne (ouvrir / renommer / supprimer) portent
        // chacun le titre : sans cela, le prochain renommage repartirait de
        // l'ancien, et la confirmation de suppression nommerait le mauvais fil.
        item.querySelectorAll('[data-conv-titre]').forEach((el) => { el.dataset.convTitre = titre; });
    }

    // 2. L'onglet de la colonne 4, s'il est ouvert.
    const onglet = ongletDeConversation(convId);
    if (onglet) {
        const span = onglet.querySelector('[data-role="tab-title"]');
        if (span) span.textContent = titre;
        // L'infobulle native : c'est elle qui donne le titre en entier quand la
        // largeur de l'onglet l'a tronqué.
        onglet.title = titre;
    }

    // 3. L'onglet MÉMORISÉ, restauré au prochain chargement de la page.
    if (idEntreprise === null) return;
    const cle = `visualizationHtmlTab_${idEntreprise}`;
    try {
        const memorise = JSON.parse(localStorage.getItem(cle) || 'null');
        if (memorise && memorise.tabKey === `${PREFIXE_ONGLET}${convId}`) {
            memorise.title = titre;
            localStorage.setItem(cle, JSON.stringify(memorise));
        }
    } catch (error) {
        // Un localStorage illisible ne doit pas faire échouer un renommage qui,
        // lui, est déjà enregistré côté serveur.
        console.warn('AssistantTitre - onglet mémorisé illisible :', error);
    }
}

/**
 * Renommage INLINE d'un libellé : le texte laisse place à un champ de saisie.
 *
 * Motif repris de la liste des conversations (assistant-ia#startRename), et
 * partagé pour que les deux points d'entrée se comportent EXACTEMENT pareil —
 * un renommage qui valide sur Entrée ici et sur Échap là serait un piège.
 *
 * Entrée valide, Échap annule, la perte de focus valide. Échap annule parce que
 * c'est la convention de toute l'application (la citation du chat s'annule déjà
 * ainsi) et parce qu'une saisie ne doit jamais s'enregistrer par la touche qui,
 * partout ailleurs, sert à s'échapper.
 *
 * @param {object} options
 * @param {HTMLElement} options.hote        Élément qui reçoit le champ.
 * @param {HTMLElement} options.aMasquer    Élément remplacé le temps de la saisie.
 * @param {string} options.valeur           Titre courant.
 * @param {string} options.classe           Classes CSS du champ.
 * @param {(nouveau: string) => Promise<void>} options.enregistrer
 */
export function editerEnPlace({ hote, aMasquer, valeur, classe, enregistrer }) {
    if (!hote || hote.querySelector('.jsb-edit-titre')) return; // déjà en édition

    const input = document.createElement('input');
    input.type = 'text';
    input.className = `jsb-edit-titre ${classe}`.trim();
    // Borne du serveur (renameConversation) : refuser ici évite un aller-retour
    // pour un 400 que l'on sait d'avance.
    input.maxLength = 120;
    input.value = valeur || '';
    input.setAttribute('aria-label', 'Nouveau titre de la conversation');

    const affichagePrecedent = aMasquer ? aMasquer.style.display : null;
    if (aMasquer) aMasquer.style.display = 'none';
    hote.insertBefore(input, aMasquer || null);
    input.focus();
    input.select();

    let done = false; // Entrée déclenche aussi blur : une seule issue.
    const finish = async (save) => {
        if (done) return;
        done = true;

        const nouveau = input.value.trim();
        if (save && nouveau !== '' && nouveau !== (valeur || '')) {
            await enregistrer(nouveau);
        }

        input.remove();
        if (aMasquer) {
            aMasquer.style.display = affichagePrecedent;
            if (typeof aMasquer.focus === 'function') aMasquer.focus();
        }
    };

    input.addEventListener('keydown', (e) => {
        // Le champ vit dans un onglet cliquable et dans une liste qui écoute le
        // clavier : sans stopPropagation, chaque frappe remonterait à eux.
        e.stopPropagation();
        if (e.key === 'Enter') { e.preventDefault(); finish(true); }
        else if (e.key === 'Escape') { finish(false); }
    });
    input.addEventListener('blur', () => finish(true));
    // Un clic dans le champ ne doit pas activer l'onglet ni rouvrir la conversation.
    input.addEventListener('click', (e) => e.stopPropagation());
    input.addEventListener('dblclick', (e) => e.stopPropagation());
}
