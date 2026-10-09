<?php

namespace App\Services\Tranche;

use App\Entity\Avenant;
use App\Entity\Note;
use App\Entity\Tranche;
use App\Services\Canvas\Indicator\IndicatorCalculationHelper;

/**
 * QUELLES ÉCHÉANCES UNE ANNULATION OU UNE RÉSILIATION MET « À RÉGULARISER » — règle unique.
 *
 * RAISON D'ÊTRE. Après une résiliation en cours de période, le recouvrement continuait de
 * réclamer la prime de l'année entière : le client était relancé pour une prime qu'il ne
 * doit pas. Aucune ristourne n'est calculée ici — elle reste manuelle, par note de crédit,
 * et aucun montant d'échéance ne change. On sort seulement du recouvrement ce qui ne doit
 * plus y être.
 *
 * LA RÈGLE, énoncée une fois — écran, assistante (via MouvementAvenantBuilder) et commande
 * de rattrapage la partagent :
 *
 *   Une échéance de la police de base passe « à régulariser » si sa date (payableAt, à
 *   défaut echeanceAt) est le jour de la date d'effet de l'acte OU APRÈS — elle couvre une
 *   période où la police n'existe plus — ET qu'elle ne porte aucun encaissement de prime.
 *
 * Une échéance déjà encaissée, même en partie, n'est PAS touchée : elle est seulement
 * signalée comme pouvant donner lieu à ristourne. Une échéance dont la commission a déjà
 * été facturée à l'assureur passe bien « à régulariser », mais la note est nommée : c'est
 * auprès de l'assureur qu'il faudra la régulariser.
 */
final class RegularisationDesEcheances
{
    /** En deçà, un montant est tenu pour nul (arrondis des prorata). */
    private const CENTIME = 0.005;

    public function __construct(
        private readonly IndicatorCalculationHelper $helper,
    ) {
    }

    /** Date qui décide : l'exigibilité (payableAt), à défaut l'échéance (echeanceAt). */
    public static function dateDeReference(Tranche $tranche): ?\DateTimeImmutable
    {
        return $tranche->getPayableAt() ?? $tranche->getEcheanceAt();
    }

    /**
     * L'échéance couvre-t-elle une période où la police n'existe plus ? Comparaison au JOUR
     * et au sens LARGE : une échéance exigible le jour même de la date d'effet de l'acte est
     * concernée.
     */
    public static function estPosterieureALActe(Tranche $tranche, \DateTimeInterface $dateEffet): bool
    {
        $date = self::dateDeReference($tranche);

        return $date !== null
            && \DateTimeImmutable::createFromInterface($date)->setTime(0, 0)
                >= \DateTimeImmutable::createFromInterface($dateEffet)->setTime(0, 0);
    }

    /**
     * Ce qu'un acte à effet du $dateEffet ferait des échéances de la police $base.
     *
     * @return array{
     *     aRegulariser: list<array{tranche: Tranche, prime: float, commission: float, notesDeCommission: list<array{reference: string, encaissee: bool}>}>,
     *     ristournePossible: list<array{tranche: Tranche, prime: float, primePayee: float}>,
     *     montant: float
     * }
     */
    public function analyser(Avenant $base, \DateTimeInterface $dateEffet): array
    {
        $aRegulariser = [];
        $ristournePossible = [];
        $montant = 0.0;

        foreach ($base->getCotation()?->getTranches() ?? [] as $tranche) {
            if (!self::estPosterieureALActe($tranche, $dateEffet)) {
                continue;
            }

            $prime = round($this->helper->getTranchePrime($tranche), 2);
            $primePayee = round($this->helper->getTranchePrimePayee($tranche), 2);

            if ($primePayee > self::CENTIME) {
                $ristournePossible[] = ['tranche' => $tranche, 'prime' => $prime, 'primePayee' => $primePayee];
                continue;
            }

            $aRegulariser[] = [
                'tranche'           => $tranche,
                'prime'             => $prime,
                'commission'        => round($this->helper->getTrancheCommissionTtc($tranche), 2),
                'notesDeCommission' => $this->notesDeCommission($tranche),
            ];
            $montant += $prime;
        }

        return ['aRegulariser' => $aRegulariser, 'ristournePossible' => $ristournePossible, 'montant' => round($montant, 2)];
    }

    /**
     * Notes de COMMISSION (débit) déjà émises sur l'échéance — à régulariser auprès de
     * l'assureur. Les notes de crédit n'en sont pas : elles régularisent, justement.
     *
     * @return list<array{reference: string, encaissee: bool}>
     */
    public function notesDeCommission(Tranche $tranche): array
    {
        $notes = [];
        foreach ($tranche->getArticles() as $article) {
            $note = $article->getNote();
            if ($note === null || $note->getType() === Note::TYPE_NOTE_DE_CREDIT || isset($notes[$note->getId()])) {
                continue;
            }
            $notes[$note->getId()] = [
                'reference' => (string) ($note->getReference() ?: ('#' . $note->getId())),
                'encaissee' => !$note->getPaiements()->isEmpty(),
            ];
        }

        return array_values($notes);
    }

    /** La note de crédit qui a régularisé l'échéance, s'il y en a une. */
    public function noteDeCredit(Tranche $tranche): ?Note
    {
        foreach ($tranche->getArticles() as $article) {
            if ($article->getNote()?->getType() === Note::TYPE_NOTE_DE_CREDIT) {
                return $article->getNote();
            }
        }

        return null;
    }

    /** « Commission déjà facturée : note n° X (encaissée), à régulariser auprès de l'assureur. » */
    public static function phraseCommissionFacturee(array $notes): ?string
    {
        if ($notes === []) {
            return null;
        }

        return sprintf(
            'Commission déjà facturée : %s, à régulariser auprès de l’assureur.',
            implode(', ', array_map(
                static fn (array $n): string => sprintf('note n° %s%s', $n['reference'], $n['encaissee'] ? ' (encaissée)' : ''),
                $notes,
            )),
        );
    }
}
