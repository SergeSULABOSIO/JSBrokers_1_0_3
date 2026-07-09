import PickerBaseController from './picker-base_controller.js';

/**
 * Picker « Documents de la police » du SOA workspace (menu contextuel de la
 * section Polices → « Documents de la police »).
 *
 * Le HTML (components/soa/_documents_picker.html.twig) est chargé et inséré dans
 * le DOM par le cerveau (handleSoaDocsPickerRequest) ; ce contrôleur s'auto-connecte
 * à l'insertion. Tout le comportement de coque (focus, fermeture ✕/backdrop/Échap,
 * filtrage) vient du socle picker-base ; aucune action métier : les téléchargements
 * sont de simples liens <a> vers la route admin de téléchargement (VichUploader
 * répond en Content-Disposition attachment, sans navigation).
 */
export default class extends PickerBaseController {
    static pickerName = 'SOA-DOCS-PICKER';
}
