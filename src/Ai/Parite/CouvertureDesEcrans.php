<?php

namespace App\Ai\Parite;

/**
 * @file CE QUE L'ÉCRAN SAIT FAIRE, ET COMMENT KET LE FAIT AUSSI.
 * @description Manifeste de la PARITÉ entre les actions d'interface et les outils
 * de l'assistant.
 *
 * ── POURQUOI CE FICHIER EXISTE ─────────────────────────────────────────────
 * Depuis que le terminal choisit la surface, un téléphone ne reçoit QUE la
 * conversation. Tout ce que l'écran sait faire et que Ket ne sait pas faire
 * devient donc, littéralement, impossible en ambulatoire. La parité n'est plus
 * une intention : c'est la frontière du produit sur mobile.
 *
 * La parité entité par entité est déjà verrouillée ailleurs :
 * `KetPeripherePariteTest` exige que toute entité LISIBLE soit MUTABLE, sur
 * l'intégralité de la carte d'accès. Ce qui manquait, c'est l'inventaire des
 * ACTIONS D'ÉCRAN — ces boutons de barre d'outils et de menu contextuel déclarés
 * en `attribute_actions` dans les canevas, qui ne sont ni des lectures ni des
 * écritures ordinaires : envoyer un SOA, marquer une police non renouvelable,
 * décider d'un congé, signaler un paiement.
 *
 * ── COMMENT LIRE CE MANIFESTE ──────────────────────────────────────────────
 * Chaque action d'écran est identifiée par l'ÉVÉNEMENT qu'elle émet (la clé
 * `event` de sa déclaration : c'est son nom technique stable, celui que le
 * cerveau route). Elle figure dans exactement une des deux listes :
 *
 *  - {@see self::COUVERTES} — l'action a une contrepartie conversationnelle
 *    nommée. Le courtier obtient le même résultat en parlant à Ket.
 *  - {@see self::ECRAN_SEULEMENT} — l'action n'existe qu'à l'écran, et le MOTIF
 *    est écrit. Cette liste est la réponse exacte à « que ne peut-on pas faire
 *    depuis un téléphone ? ». Elle doit rester courte, et chaque entrée doit
 *    pouvoir être défendue.
 *
 * `PariteEcranKetTest` refuse toute action absente des deux listes. Ajouter un
 * bouton à une barre d'outils oblige donc désormais à déclarer sa contrepartie
 * Ket, ou à assumer l'exception par écrit. C'est là que la règle de parité
 * devient opposable, au lieu de dépendre de la vigilance.
 *
 * ⚠ CE MANIFESTE NE FILTRE RIEN À L'EXÉCUTION. Il ne retire ni n'ajoute aucune
 * capacité : c'est un CONSTAT, tenu à jour par un test. Les droits restent où
 * ils sont — dans les gardes de périmètre des outils et des contrôleurs.
 */
final class CouvertureDesEcrans
{
    /**
     * Actions d'écran ayant une contrepartie chez Ket : événement → nom
     * technique de l'outil.
     *
     * Le nom de l'outil est vérifié contre le catalogue réel : le renommer sans
     * toucher ici fait échouer le test, plutôt que de laisser une parité
     * fantôme.
     *
     * @var array<string, string>
     */
    public const COUVERTES = [
        // ── Relevé de compte client (SOA) ──────────────────────────────────
        'ui:soa.send-request' => 'preparer_envoi_soa',
        'ui:soa.docs-picker-request' => 'telecharger_documents',

        // ── Documents rattachés (toute entité métier) ──────────────────────
        'ui:documents.liste-request' => 'telecharger_documents',
        'ui:documents.download-request' => 'telecharger_documents',
        'ui:documents.attach-request' => 'attacher_fichier',

        // ── Vie d'une police ───────────────────────────────────────────────
        // Les quatre mouvements (avenant, renouvellement, résiliation, remise en
        // vigueur) passent tous par la piste dérivée, côté écran comme côté Ket.
        'ui:avenant.mouvement-request' => 'preparer_mouvement_avenant',
        'ui:avenant.piste-derivee-form-request' => 'preparer_mouvement_avenant',
        'ui:avenant.non-renouvelable-request' => 'preparer_marquage_non_renouvelable',
        // Dissocier une piste dérivée est une SUPPRESSION : le plan d'écriture
        // générique la porte, avec sa confirmation et son garde-fou de cascade
        // (la piste ne doit pas emporter la police).
        'ui:avenant.delete-piste-derivee' => 'preparer_operations',

        // ── Encaissements et reversements ──────────────────────────────────
        'ui:tranche.signaler-paiement-prime' => 'signaler_paiement_prime',
        'ui:retroagent.reversement-request' => 'signaler_reversement_retro_agent',
        'ui:production.reversement-request' => 'signaler_reversement_retro_agent',
        'ui:production.versements-request' => 'retrocommissions',

        // ── Portefeuilles ──────────────────────────────────────────────────
        // Affecter ou retirer un client d'un portefeuille, côté client comme côté
        // portefeuille ou invité : ce sont des écritures ordinaires, portées par
        // le plan d'écriture générique de Ket.
        'ui:client.portefeuille-picker-request' => 'preparer_operations',
        'ui:client.retirer-portefeuille' => 'preparer_operations',
        'ui:portefeuille.client-picker-request' => 'preparer_operations',
        'ui:invite.portefeuille-form-request' => 'preparer_operations',
        'ui:invite.delete-portefeuille' => 'preparer_operations',

        // ── Partage des revenus d'une affaire ──────────────────────────────
        'ui:partage.picker-request' => 'preparer_operations',

        // ── Congés ─────────────────────────────────────────────────────────
        'ui:conge.compteurs-request' => 'conges',
        'ui:conge.calendrier-request' => 'conges',
        'ui:conge.decision-request' => 'preparer_decision_conge',

        // ── Pièces imprimables ─────────────────────────────────────────────
        // La note se visualise par sa route d'export (note_pdf), que Ket ouvre.
        'ui:note.preview-request' => 'exporter_etat',
        'ui:bordereau.edit-linked-note' => 'ouvrir_dialogue',
    ];

    /**
     * Actions qui n'existent QU'À L'ÉCRAN, et pourquoi.
     *
     * C'est la liste de ce qu'un téléphone ne peut pas faire. Chaque motif doit
     * dire pourquoi la conversation n'est pas le bon endroit — « pas encore
     * écrit » est un motif recevable, mais il doit être écrit comme tel.
     *
     * @var array<string, string>
     */
    public const ECRAN_SEULEMENT = [
        // ── Navigation pure ────────────────────────────────────────────────
        // Ouvrir une rubrique n'a pas d'objet sans colonnes. Ket possède bien
        // l'outil correspondant (`ouvrir_rubrique`), mais il n'est justement pas
        // déclaré en mode Ket, cf. App\Ai\Tool\ExigeLesColonnes. Compter cette
        // action comme « couverte » serait un mensonge sur mobile.
        'ui:production.rubrique-request' => "Navigation vers une rubrique : sans colonnes, il n'y a "
            . "aucun endroit où l'ouvrir. Ket restitue les mêmes chiffres dans le fil "
            . '(effort_commercial_agent, retrocommissions).',

        // ── Ce qui alimente Ket depuis l'écran ─────────────────────────────
        'ui:assistant.add-to-chat' => "Cette action va DE l'écran VERS le chat : elle attache une "
            . 'sélection de lignes au contexte de la conversation. Elle ne peut pas avoir de '
            . "contrepartie dans le chat — c'est son point de départ. En conversation, on "
            . "désigne l'objet par son nom, ce que Ket sait résoudre.",

        // ── Atelier de rapprochement des bordereaux ────────────────────────
        // Un écran de travail ligne à ligne : on compare des centaines de lignes
        // d'un fichier de l'assureur à la production enregistrée, on arbitre les
        // écarts un à un. Le fil de conversation n'est pas la bonne forme, et le
        // reproduire en dialogue coûterait plus qu'il ne rendrait.
        'ui:bordereau.analysis-request' => "Atelier de rapprochement ligne à ligne d'un bordereau "
            . "d'assureur : arbitrage visuel de centaines d'écarts. Ket sait en revanche LIRE un "
            . 'bordereau attaché et en préparer la saisie (analyser_fichier_pour_saisie).',
        'bordereau:create-avenant' => "Geste interne à l'atelier de bordereau : il crée la police "
            . "correspondant à une ligne du rapprochement, dans le contexte de cet écran.",
        'bordereau:update-avenant' => "Geste interne à l'atelier de bordereau : il corrige la police "
            . "d'une ligne du rapprochement, dans le contexte de cet écran.",

        // ── Accès et identité : gestes délibérément gardés à l'écran ───────
        // Ces trois actions font sortir un ACCÈS de l'application (un lien
        // public, un courriel d'invitation) ou le retirent. Les exposer au chat
        // élargirait la surface d'attaque par injection de prompt sans rien
        // apporter : ce sont des gestes rares, faits une fois, depuis un poste.
        'ui:soa.copy-link-request' => "Copie d'un lien PUBLIC d'accès au relevé, valable 30 jours "
            . "sans authentification. Un jeton d'accès ne doit pas pouvoir sortir par le chat "
            . '(surface de prompt injection), et le presse-papiers est un geste de poste.',
        'ui:soa.revoke-request' => "Révocation d'un accès client : geste de sécurité irréversible, "
            . "à faire en voyant l'état du lien à l'écran.",
        'ui:invite.resend-request' => "Renvoi d'un courriel d'invitation, donc émission d'un accès "
            . "vers un tiers. Réservé à l'écran pour la même raison que les liens de SOA.",

        // ── Consultation du relevé de compte ───────────────────────────────
        'ui:soa.view-request' => "Consultation du relevé de compte d'un client sous sa forme "
            . "tabulaire complète. Ket sait l'ENVOYER (preparer_envoi_soa) et sait répondre sur "
            . "les impayés (suivi_impayes), mais ne restitue pas encore le relevé lui-même dans "
            . 'le fil : reste à faire.',
    ];

    /** @return list<string> toutes les actions d'écran inventoriées */
    public static function actionsDeclarees(): array
    {
        return array_merge(
            array_keys(self::COUVERTES),
            array_keys(self::ECRAN_SEULEMENT),
        );
    }
}
