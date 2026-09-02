import { Controller } from '@hotwired/stimulus';

/**
 * LA DATE DE FIN SUIT LA DATE DE DÉBUT.
 *
 * ── CE QUE CELA ÉVITE ───────────────────────────────────────────────────────────────
 * Déplacer son départ d'une semaine obligeait à recalculer soi-même la date de retour, en
 * tenant compte des week-ends, des jours fériés du cabinet et de son propre régime de
 * travail. Personne ne fait ce calcul de tête : on posait une date approximative, et l'on
 * découvrait le décompte réel à l'enregistrement.
 *
 * ── LA DURÉE EST CONSERVÉE, PAS REMISE À DIX ────────────────────────────────────────
 * Quelqu'un qui a ramené sa demande à trois jours puis décale son départ veut toujours
 * trois jours. On envoie donc au serveur la période TELLE QU'ELLE ÉTAIT avant le geste :
 * c'est lui qui en mesure la longueur et la reporte.
 *
 * ── AUCUN CALENDRIER N'EST REFAIT ICI ───────────────────────────────────────────────
 * Ce contrôleur ne sait pas ce qu'est un samedi, et c'est voulu. Le serveur seul connaît
 * les jours fériés du cabinet et le régime de l'intéressé ; une seconde réponse à « ce
 * jour compte-t-il ? » finirait par contredire le décompte annoncé à l'enregistrement.
 *
 * ── ⚠ LES CHAMPS SE NOMMENT SANS PRÉFIXE ────────────────────────────────────────────
 * DemandeCongeType rend `getBlockPrefix()` vide : les champs s'appellent `dateDebut` et
 * non `demande_conge[dateDebut]`. Un sélecteur à crochets ne trouverait rien, en silence.
 */
export default class extends Controller {
    static values = { url: String };

    connect() {
        this.debut = this.element.querySelector('[name="dateDebut"]');
        this.fin = this.element.querySelector('[name="dateFin"]');
        if (!this.debut || !this.fin) return;

        // L'état d'AVANT le geste : c'est lui qui porte la durée à conserver. On le tient
        // à jour nous-mêmes plutôt que de le relire dans les champs au moment du
        // changement — à cet instant, le champ porte déjà la nouvelle valeur.
        this.ancienDebut = this.debut.value;
        this.ancienneFin = this.fin.value;

        this.boundAjuster = () => this.ajuster();
        this.debut.addEventListener('change', this.boundAjuster);

        // La fin modifiée à la main devient la nouvelle référence de durée : sans cela, un
        // départ décalé après un retour raccourci rendrait les dix jours d'origine.
        this.boundMemoriser = () => { this.ancienneFin = this.fin.value; };
        this.fin.addEventListener('change', this.boundMemoriser);
    }

    disconnect() {
        if (this.debut && this.boundAjuster) this.debut.removeEventListener('change', this.boundAjuster);
        if (this.fin && this.boundMemoriser) this.fin.removeEventListener('change', this.boundMemoriser);
    }

    async ajuster() {
        const nouveauDebut = this.debut.value;

        // Une date incomplète (saisie au clavier, chiffre par chiffre) ne vaut pas un
        // aller-retour : on attend qu'elle soit lisible.
        if (!/^\d{4}-\d{2}-\d{2}$/.test(nouveauDebut) || !this.urlValue) return;

        const agent = this.element.querySelector('[name="agent"]');

        try {
            const response = await fetch(this.urlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({
                    debut: nouveauDebut,
                    ancienDebut: this.ancienDebut,
                    ancienneFin: this.ancienneFin,
                    agent: agent ? agent.value : null,
                }),
            });
            if (!response.ok) throw new Error(`Erreur serveur ${response.status}`);

            const data = await response.json();
            if (!data.fin) return;

            this.fin.value = data.fin;
            // On prévient les autres : la visibilité conditionnelle et les indicateurs du
            // dialogue écoutent `change`, et une valeur posée par script n'en émet aucun.
            this.fin.dispatchEvent(new Event('change', { bubbles: true }));

            this.ancienDebut = nouveauDebut;
            this.ancienneFin = data.fin;
        } catch (error) {
            // ON NE BLOQUE PAS LA SAISIE POUR UN AJUSTEMENT DE CONFORT. La date de fin
            // reste celle que l'utilisateur voit, il peut la corriger, et le décompte
            // définitif est de toute façon calculé à l'enregistrement.
            console.error('[CONGE-PERIODE] ajustement de la date de fin :', error);
        }
    }
}
