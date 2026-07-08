/**
 * @file Correctif global des listes déroulantes Symfony UX Autocomplete (Tom Select).
 * @description Les champs relation des formulaires sont rendus dans des boîtes de
 * dialogue dont les conteneurs défilants (.form-column { overflow-y:auto },
 * .custom-modal-scroll .modal-body { overflow-y:hidden }) tronquent le menu
 * `.ts-dropdown` inséré par défaut dans le flux, juste après l'input : les
 * suggestions apparaissent « sous » le bloc du champ et sont incliquables.
 *
 * Le contrôleur du bundle dispatche `autocomplete:pre-connect` (événement Stimulus,
 * il bulle jusqu'à document) avec `detail.options` mutable juste avant
 * `new TomSelect(...)`. Un unique listener global corrige donc TOUS les champs
 * relation de l'application, présents et futurs, sans toucher aux form types PHP :
 *
 * 1. `dropdownParent: 'body'` — le menu est rattaché à <body> et échappe aux
 *    conteneurs défilants (posé seulement si non défini côté PHP).
 * 2. z-index dynamique à chaque ouverture du menu — les modales imbriquées montent
 *    à 1055 + n×20 (modal_controller.js) alors que `.ts-dropdown` est à 1060 fixe
 *    dans app.css : le menu passerait derrière une modale de 2ᵉ niveau (1075+).
 *    On pose donc, inline et en !important (pour gagner sur le !important de
 *    app.css), un z-index au-dessus de la modale ouverte la plus haute.
 */
document.addEventListener('autocomplete:pre-connect', (event) => {
    const options = event.detail && event.detail.options;
    if (!options) return;

    if (!options.dropdownParent) {
        options.dropdownParent = 'body';
    }

    const onDropdownOpenInitial = options.onDropdownOpen;
    options.onDropdownOpen = function (dropdown) {
        let zIndexMax = 0;
        document.querySelectorAll('.modal.show').forEach((modal) => {
            const z = parseInt(window.getComputedStyle(modal).zIndex, 10);
            if (!isNaN(z) && z > zIndexMax) zIndexMax = z;
        });
        if (zIndexMax > 0) {
            dropdown.style.setProperty('z-index', String(zIndexMax + 5), 'important');
        }
        if (typeof onDropdownOpenInitial === 'function') {
            onDropdownOpenInitial.call(this, dropdown);
        }
    };
});
