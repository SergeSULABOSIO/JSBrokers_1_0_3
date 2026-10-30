<?php

namespace App\Service\Workspace;

use App\Entity\Article;
use App\Entity\Avenant;
use App\Entity\ChargementPourPrime;
use App\Entity\Cotation;
use App\Entity\Feedback;
use App\Entity\OffreIndemnisationSinistre;
use App\Entity\Operation;
use App\Entity\PaiementPrime;
use App\Entity\PeriodeBlocage;
use App\Entity\PieceSinistre;
use App\Entity\RevenuPourCourtier;
use App\Entity\RolesEnAdministration;
use App\Entity\RolesEnFinance;
use App\Entity\RolesEnMarketing;
use App\Entity\RolesEnProduction;
use App\Entity\RolesEnSinistre;
use App\Entity\Tranche;
use Doctrine\Persistence\Proxy;

/**
 * LES ENTITÉS QUI NE NAISSENT QUE RATTACHÉES À LEUR PARENT — entité => champ du parent.
 *
 * Une tranche sans proposition, un avenant sans proposition, un rôle sans invité… ne
 * veulent rien dire : persistés seuls, ce sont des orphelins que rien ne retrouve ni ne
 * compte (Serge, 2026-10-30). Ils se créent donc DEPUIS LEUR PARENT — l'onglet de sa
 * fiche, ou l'onglet contextuel de sa rubrique —, où le rattachement est posé par
 * construction. Jamais depuis leur liste principale, qui ne sait pas à quoi les rattacher.
 *
 * Une règle, trois surfaces :
 * - l'écran : FormCanvasProvider pose `creation_depuis_le_parent` dans le canevas ; la
 *   barre d'outils, le menu contextuel et l'état vide de la liste PRINCIPALE n'offrent
 *   alors pas « Ajouter » (ajout-regles.js) ;
 * - le serveur : handleFormSubmission refuse l'écriture d'une création sans parent ;
 * - Ket et la reprise : WorkspaceMutationService refuse de même.
 *
 * ⚠ UNE ENTITÉ DÉPENDANTE NOUVELLE SE DÉCLARE ICI. ParentObligatoireTest compare ce registre
 * aux collections des canevas : un enfant dont le formulaire ne laisse pas choisir le
 * parent doit y figurer, ou être exempté avec son motif.
 */
final class ParentObligatoire
{
    /** @var array<class-string, array{champ: string, refus: string}> */
    public const PARENTS = [
        Cotation::class => ['champ' => 'piste', 'refus' => "Une proposition se crée depuis sa piste, dans l'onglet « Cotations »."],
        Tranche::class => ['champ' => 'cotation', 'refus' => "Une tranche se crée depuis sa proposition, dans l'onglet « Tranches »."],
        Avenant::class => ['champ' => 'cotation', 'refus' => "Un avenant se crée depuis sa proposition, dans l'onglet « Avenants »."],
        RevenuPourCourtier::class => ['champ' => 'cotation', 'refus' => "Un revenu se crée depuis sa proposition, dans l'onglet « Revenus »."],
        PaiementPrime::class => ['champ' => 'tranche', 'refus' => "Un paiement de prime se signale depuis sa tranche, par l'action « Signaler un paiement de prime »."],
        ChargementPourPrime::class =>['champ' => 'cotation', 'refus' => "Un chargement se crée depuis sa proposition, dans l'onglet « Chargements »."],
        Article::class => ['champ' => 'note', 'refus' => "Un article se crée depuis sa note, dans l'onglet « Articles »."],
        Operation::class => ['champ' => 'bordereau', 'refus' => "Une opération se crée depuis son bordereau, dans l'onglet « Opérations »."],
        PieceSinistre::class => ['champ' => 'notificationSinistre', 'refus' => "Une pièce se crée depuis sa notification de sinistre, dans l'onglet « Pièces »."],
        OffreIndemnisationSinistre::class => ['champ' => 'notificationSinistre', 'refus' => "Une offre d'indemnisation se crée depuis sa notification de sinistre, dans l'onglet « Offres d'indemnisation »."],
        Feedback::class => ['champ' => 'tache', 'refus' => "Un feedback se crée depuis sa tâche, dans l'onglet « Feedbacks »."],
        PeriodeBlocage::class => ['champ' => 'parametres', 'refus' => "Une période de blocage se crée depuis les paramètres des congés, dans l'onglet « Périodes de blocage »."],
        RolesEnFinance::class => ['champ' => 'invite', 'refus' => "Un rôle se crée depuis la fiche de l'invité, dans l'onglet « Finance »."],
        RolesEnMarketing::class => ['champ' => 'invite', 'refus' => "Un rôle se crée depuis la fiche de l'invité, dans l'onglet « Marketing »."],
        RolesEnProduction::class => ['champ' => 'invite', 'refus' => "Un rôle se crée depuis la fiche de l'invité, dans l'onglet « Production »."],
        RolesEnSinistre::class => ['champ' => 'invite', 'refus' => "Un rôle se crée depuis la fiche de l'invité, dans l'onglet « Sinistre »."],
        RolesEnAdministration::class => ['champ' => 'invite', 'refus' => "Un rôle se crée depuis la fiche de l'invité, dans l'onglet « Administration »."],
    ];

    /** Le champ du parent, si l'entité ne naît que rattachée. */
    public static function champ(string|object $entite): ?string
    {
        return self::PARENTS[self::classe($entite)]['champ'] ?? null;
    }

    /**
     * Le refus d'une CRÉATION sans parent, juste avant de l'écrire ; null si elle peut l'être.
     *
     * `$soumis` : les données de la requête de l'écran. Le parent doit y être NOMMÉ — un
     * rôle reçoit d'office l'invité connecté comme auteur (champ `invite` d'AuditableTrait,
     * le même que son parent) : sans cette exigence, un rôle créé sans invité serait
     * rattaché en silence à celui qui le crée.
     *
     * @param array<string, mixed>|null $soumis
     */
    public static function refus(object $entite, ?array $soumis = null): ?string
    {
        $regle = self::PARENTS[self::classe($entite)] ?? null;
        if ($regle === null) {
            return null;
        }
        if ($soumis !== null && ($refus = self::refusSiNonNomme($entite, $soumis)) !== null) {
            return $refus;
        }

        return $entite->{'get' . ucfirst($regle['champ'])}() === null ? $regle['refus'] : null;
    }

    /**
     * Le refus d'une demande de création qui ne NOMME pas le parent — l'analyse d'un plan de
     * Ket, avant toute écriture : le parent peut y être un identifiant, ou une création
     * promise par une opération précédente du même plan.
     *
     * @param array<string, mixed> $soumis
     */
    public static function refusSiNonNomme(string|object $entite, array $soumis): ?string
    {
        $regle = self::PARENTS[self::classe($entite)] ?? null;

        return $regle !== null && empty($soumis[$regle['champ']]) ? $regle['refus'] : null;
    }

    private static function classe(string|object $entite): string
    {
        if (is_string($entite)) {
            return $entite;
        }

        return $entite instanceof Proxy ? (string) get_parent_class($entite) : $entite::class;
    }
}
