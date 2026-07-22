/*
 * Langue active de l'application, telle que le serveur l'a posée sur <html lang="…">.
 *
 * SOURCE UNIQUE côté navigateur : aucun module ne doit interroger la langue du
 * navigateur (navigator.language), sinon l'affichage diverge de celui du serveur
 * dès que l'utilisateur a choisi une langue différente de celle de son poste.
 */

/** Langue du document, repli sur le français. */
export function documentLocale() {
    return document.documentElement.lang || 'fr';
}
