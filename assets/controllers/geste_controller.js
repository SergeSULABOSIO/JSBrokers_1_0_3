import { Controller } from '@hotwired/stimulus';

/**
 * LES GESTES SIMPLES — sans une ligne de code dans le HTML.
 *
 * La politique de sécurité (chantier 9, `PolitiqueCsp`) refuse tout code écrit dans un
 * attribut onclick : c'est précisément ce qu'une donnée piégée y glisserait. Les
 * gestes génériques qu'on y écrivait passent donc par ce contrôleur, posé UNE fois sur
 * <body> (base.html.twig, analyse de bordereau) : il couvre tout ce qui est dans la page,
 * y compris les fragments chargés plus tard (onglets, panneaux) et les dialogues ajoutés
 * au <body> — Stimulus relie les actions des éléments qui arrivent après coup.
 *
 *   data-action="click->geste#imprimer"            window.print()
 *   data-action="click->geste#fermer"              window.close()
 *   data-action="mousedown->geste#arreter"         event.stopPropagation()
 *   data-action="change->geste#soumettre"          this.form.submit()
 *   data-action="submit->geste#confirmer"          return confirm(…)
 *     data-geste-message-param="…"
 *   data-action="click->geste#naviguer:stop"       workspace:navigate-to (:prevent pour un lien)
 *     data-geste-component-param / -group-param / -entity-param
 *   data-action="click->geste#rechargerOnglet"     app:workspace.reload-active-tab
 *
 * Conversion fidèle (décidée avec Serge) : `return false` → `preventDefault()`, `this` →
 * `event.currentTarget`, et `form.submit()` reste `submit()` (pas `requestSubmit()` : ni
 * validation ni événement `submit` de plus qu'avant).
 *
 * Les pages autonomes sans Stimulus (aperçu de note, relevés client) ont le même balisage,
 * servi par `assets/gestes-autonomes.js`.
 */
export default class extends Controller {
    imprimer() {
        window.print();
    }

    fermer() {
        window.close();
    }

    /** Ne rien faire d'autre que retenir l'événement : un champ dans un bloc déplaçable, un lien dans une carte cliquable. */
    arreter(event) {
        event.stopPropagation();
    }

    soumettre(event) {
        event.currentTarget.form?.submit();
    }

    confirmer(event) {
        if (!window.confirm(event.params.message ?? '')) {
            event.preventDefault();
        }
    }

    naviguer(event) {
        const { component, group, entity } = event.params;
        document.dispatchEvent(new CustomEvent('workspace:navigate-to', { bubbles: true, detail: { component, group, entity } }));
    }

    rechargerOnglet() {
        document.dispatchEvent(new CustomEvent('app:workspace.reload-active-tab'));
    }
}
