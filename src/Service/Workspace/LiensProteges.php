<?php

namespace App\Service\Workspace;

use App\Ai\Mouvement\MouvementAvenant;
use App\Entity\Article;
use App\Entity\Avenant;
use App\Entity\Paiement;
use App\Entity\PaiementPrime;
use App\Entity\Piste;
use Doctrine\ORM\EntityManagerInterface;

/**
 * LES LIENS QU'UNE SUPPRESSION NE DOIT JAMAIS REMONTER.
 *
 * Doctrine ne connaît qu'une direction : une cascade `remove` s'applique quel que
 * soit le sens métier de la relation. Or certaines relations to-one RATTACHENT un
 * enfant à un parent bien vivant, et les suivre détruirait ce parent.
 *
 * Le cas fondateur — et pour l'instant le seul — est `Piste::avenantDeBase`. Une
 * opportunité dérivée (renouvellement, prorogation…) pointe la POLICE qu'elle fait
 * évoluer, en `OneToOne(cascade: ['persist','remove'])`. Supprimer l'opportunité
 * emporterait donc la police elle-même, ses propositions, ses échéanciers et ses
 * paiements : exactement l'inverse de l'intention, qui est d'ABANDONNER le projet
 * de renouvellement et de GARDER la police.
 *
 * `AvenantController::deletePisteDerivee` dissociait déjà les deux sens à la main
 * avant de supprimer. Cette connaissance vivait dans ce seul contrôleur : tout
 * autre chemin de suppression — au premier rang desquels les plans d'écriture de
 * l'assistant, qui suppriment par `MutationOperation` générique — retombait dans
 * le piège. La règle est donc énoncée ICI, une fois, et appliquée par le moteur de
 * mutation ET par l'analyse d'impact (qui doit annoncer la portée RÉELLE, pas la
 * portée théorique : promettre la destruction d'une police qui survivra serait un
 * mensonge aussi grave que l'inverse).
 */
final class LiensProteges
{
    /**
     * Nom court d'entité => champs to-one dissociés AVANT la suppression, avec le
     * champ réciproque à neutraliser sur la cible (les deux sens du lien sont
     * indépendants : n'en couper qu'un laisse l'autre entretenir la cascade).
     *
     * @var array<string, array<string, ?string>> entité => [champ => champ réciproque|null]
     */
    private const AVANT_SUPPRESSION = [
        'Piste' => ['avenantDeBase' => 'pisteDeRenouvellement'],
    ];

    /**
     * ABANDONNER UN MOUVEMENT N'EFFACE JAMAIS DE L'ARGENT. — règle UNIQUE, écran et Ket.
     *
     * Supprimer l'opportunité dérivée d'un mouvement (renouvellement, prorogation,
     * annulation, résiliation) emporte, en cascade, son avenant successeur — et avec lui
     * tout ce qui s'y rattache. Tant que ce successeur n'a vécu aucun mouvement financier,
     * c'est un retour arrière propre. Dès qu'une prime a été encaissée, qu'une note a été
     * émise ou qu'une commission a été encaissée sur lui, la suppression effacerait des
     * écritures réelles : elle est refusée, en disant lesquelles et quoi faire à la place.
     *
     * L'opportunité se reconnaît à son TYPE de mouvement, pas au lien avenantDeBase : les
     * deux chemins de suppression coupent ce lien (dissocier()) avant de planifier, et la
     * règle doit tenir quel que soit l'état du lien en mémoire.
     *
     * Appelée par SuppressionEnCascade::planifier() — point de passage de TOUTE suppression,
     * annonce comme exécution, écran comme assistante — et par les deux appelants de
     * dissocier() AVANT de couper quoi que ce soit : un refus ne doit laisser aucune
     * dissociation en mémoire, qu'un flush ultérieur de la requête écrirait.
     *
     * @return string|null le motif du refus, rédigé pour l'utilisateur ; null si permis
     */
    public static function refusDeSuppression(object $entity, EntityManagerInterface $em): ?string
    {
        if (!$entity instanceof Piste || $entity->getId() === null
            || MouvementAvenant::depuisTypeAvenant($entity->getTypeAvenant()) === null) {
            return null;
        }

        // Successeurs : interrogés en base, jamais lus dans les collections en mémoire
        // (Cotation::setPiste est unidirectionnel).
        $successeurs = $em->createQueryBuilder()
            ->select('a.id, a.numero, IDENTITY(a.cotation) AS cotation')
            ->from(Avenant::class, 'a')
            ->join('a.cotation', 'c')
            ->where('c.piste = :piste')
            ->setParameter('piste', $entity)
            ->getQuery()
            ->getArrayResult();
        if ($successeurs === []) {
            return null;
        }
        $cotations = array_values(array_unique(array_map(static fn (array $s) => (int) $s['cotation'], $successeurs)));

        $encaissementsPrime = (int) $em->createQueryBuilder()
            ->select('COUNT(pp.id)')
            ->from(PaiementPrime::class, 'pp')
            ->join('pp.tranche', 't')
            ->where('IDENTITY(t.cotation) IN (:cotations)')
            ->setParameter('cotations', $cotations)
            ->getQuery()->getSingleScalarResult();

        // Une note (facture, note de débit ou de crédit) porte ses lignes sur une tranche
        // (prime) ou sur un revenu (commission). Les encaissements de commission passent
        // par la note : ils se comptent à partir d'elle.
        $notes = $em->createQueryBuilder()
            ->select('DISTINCT IDENTITY(ar.note) AS note')
            ->from(Article::class, 'ar')
            ->leftJoin('ar.tranche', 't')
            ->leftJoin('ar.revenuFacture', 'r')
            ->where('IDENTITY(t.cotation) IN (:cotations) OR IDENTITY(r.cotation) IN (:cotations)')
            ->andWhere('ar.note IS NOT NULL')
            ->setParameter('cotations', $cotations)
            ->getQuery()->getSingleColumnResult();
        $encaissementsNote = $notes === [] ? 0 : (int) $em->createQueryBuilder()
            ->select('COUNT(p.id)')
            ->from(Paiement::class, 'p')
            ->where('IDENTITY(p.note) IN (:notes)')
            ->setParameter('notes', $notes)
            ->getQuery()->getSingleScalarResult();

        $faits = array_filter([
            $encaissementsPrime > 0 ? sprintf('%d encaissement%s de prime', $encaissementsPrime, $encaissementsPrime > 1 ? 's' : '') : null,
            count($notes) > 0 ? sprintf('%d note%s émise%s', count($notes), count($notes) > 1 ? 's' : '', count($notes) > 1 ? 's' : '') : null,
            $encaissementsNote > 0 ? sprintf('%d encaissement%s de note', $encaissementsNote, $encaissementsNote > 1 ? 's' : '') : null,
        ]);
        if ($faits === []) {
            return null;
        }

        $noms = implode(', ', array_map(
            static fn (array $s) => sprintf('#%d (n° %s)', $s['id'], $s['numero'] ?? '—'),
            $successeurs,
        ));

        return sprintf(
            'Impossible d’abandonner ce mouvement : l’avenant qui en est issu, %s, porte déjà des mouvements '
            . 'financiers (%s). Supprimer l’opportunité dérivée les effacerait avec lui. Annulez d’abord ces '
            . 'écritures, ou enregistrez plutôt une annulation de la police qui en est issue.',
            $noms,
            implode(', ', $faits),
        );
    }

    /**
     * Champs protégés d'une entité, ou tableau vide si elle n'en a aucun.
     *
     * @return array<string, ?string>
     */
    public static function champs(object $entity): array
    {
        $court = substr(strrchr('\\' . $entity::class, '\\') ?: '', 1);

        return self::AVANT_SUPPRESSION[$court] ?? [];
    }

    /**
     * Coupe les liens protégés de l'entité, DANS LES DEUX SENS, juste avant sa
     * suppression. Sans effet — et sans erreur — sur une entité qui n'en porte pas.
     *
     * @return string[] noms des champs effectivement dissociés (pour le journal)
     */
    public static function dissocier(object $entity): array
    {
        $coupes = [];

        foreach (self::champs($entity) as $champ => $reciproque) {
            $getter = 'get' . ucfirst($champ);
            if (!method_exists($entity, $getter)) {
                continue;
            }
            $cible = $entity->{$getter}();
            if ($cible === null) {
                continue;
            }

            if ($entity instanceof Piste && $cible instanceof Avenant) {
                self::restituerStatutDeLaBase($entity, $cible);
            }

            // Sens retour d'abord : c'est lui qui porte la clé étrangère côté cible.
            if ($reciproque !== null) {
                $setterCible = 'set' . ucfirst($reciproque);
                if (method_exists($cible, $setterCible)) {
                    $cible->{$setterCible}(null);
                }
            }

            $setter = 'set' . ucfirst($champ);
            if (method_exists($entity, $setter)) {
                $entity->{$setter}(null);
                $coupes[] = $champ;
            }
        }

        return $coupes;
    }

    /**
     * ABANDONNER UN MOUVEMENT REND À LA POLICE LE STATUT QU'ELLE PORTAIT AVANT LUI.
     *
     * Le mouvement avait écrit sur la base « Renouvelé », « Prorogé » ou « Annulé /
     * résilié ». L'opportunité partie, son avenant successeur part avec elle (cascade
     * Piste → Cotation → Avenant) : laisser la base sous ce statut la ferait disparaître
     * des polices actives et des agrégats du tableau de bord sans rien pour la remplacer.
     *
     * On restaure le statut MÉMORISÉ (Piste::$statutBaseAvantMouvement), pas un « En
     * cours » supposé, et seulement si la base porte encore celui que CE mouvement a
     * écrit : un statut changé à la main depuis est une décision humaine, on n'y touche pas.
     */
    private static function restituerStatutDeLaBase(Piste $piste, Avenant $base): void
    {
        $avant = $piste->getStatutBaseAvantMouvement();
        $ecrit = MouvementAvenant::depuisTypeAvenant($piste->getTypeAvenant())?->statutDeLaBase();

        if ($avant !== null && $ecrit !== null && $base->getRenewalStatus() === $ecrit) {
            $base->setRenewalStatus($avant);
        }
    }
}
