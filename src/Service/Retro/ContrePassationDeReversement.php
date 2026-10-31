<?php

namespace App\Service\Retro;

use App\Entity\Invite;
use App\Entity\ReversementRetroAgent;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * CONTRE-PASSER UN VIREMENT : l'annuler par des lignes de montant OPPOSÉ (Serge, 2026-10-30).
 *
 * Un reversement enregistré est une pièce comptable ({@see ReversementIntangible}) : on ne le
 * corrige pas, on l'annule. Le geste porte sur le VIREMENT ENTIER — la règle de lot de
 * {@see LotDeVersement}, celle de la fenêtre du virement — : une ligne de montant opposé par
 * ligne du virement, datée du jour de la contre-passation, reliée à celle qu'elle annule
 * (`contrePassationDe`), au même bénéficiaire, à la même échéance, au même compte. Les soldes
 * s'additionnent partout (rapport de production, exigible, journal) : le net revient de lui-même,
 * et l'original reste lisible à côté de son annulation.
 *
 * Une source unique pour l'écran (RetroAgentController) et pour Ket.
 */
final class ContrePassationDeReversement
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LotDeVersement $lots,
    ) {}

    /**
     * Le motif qui refuse de contre-passer ce virement ; null s'il peut l'être.
     */
    public function refus(ReversementRetroAgent $membre): ?string
    {
        foreach ($this->lots->membresDuLot($membre) as $ligne) {
            if (($motif = ReversementIntangible::refusDeContrePassation($ligne, $this->lots->estContrePasse($ligne))) !== null) {
                return $motif;
            }
        }

        return null;
    }

    /**
     * Contre-passe le virement auquel appartient ce reversement.
     *
     * @return array{lignes: int, total: float, reference: string, porteurId: int|null}
     *
     * @throws ReversementIntangibleException si le virement est déjà contre-passé, ou s'il en est une
     */
    public function contrePasser(ReversementRetroAgent $membre, Invite $auteur, ?\DateTimeImmutable $le = null): array
    {
        if (($motif = $this->refus($membre)) !== null) {
            throw new ReversementIntangibleException($motif);
        }
        $membres = $this->lots->membresDuLot($membre);
        $date = $le ?? new \DateTimeImmutable('today');
        $porteur = $this->lots->porteurParmi($membres) ?? $membre;
        $referenceOrigine = (string) ($porteur->getReference() ?: ('#' . $porteur->getId()));
        $reference = 'CP-' . $referenceOrigine;
        $lot = trim((string) $porteur->getLotReference());

        $annulations = [];
        $total = 0.0;
        foreach ($membres as $ligne) {
            $annulation = (new ReversementRetroAgent())
                ->setAgent($ligne->getAgent())
                ->setPartenaire($ligne->getPartenaire())
                ->setTranche($ligne->getTranche())
                ->setAvenant($ligne->getAvenant())
                ->setMontant(-round((float) $ligne->getMontant(), 2))
                ->setPaidAt($date)
                ->setReference($reference)
                ->setLotReference($lot !== '' ? 'CP-' . $lot : null)
                ->setCompteBancaire($ligne->getCompteBancaire())
                ->setDescription(sprintf(
                    'Contre-passation du virement « %s » du %s.',
                    $referenceOrigine,
                    $ligne->getPaidAt()?->format('d/m/Y') ?? '?',
                ))
                ->setContrePassationDe($ligne);
            $annulation->setEntreprise($ligne->getEntreprise())->setInvite($auteur);
            $this->em->persist($annulation);
            $annulations[] = $annulation;
            $total += (float) $annulation->getMontant();
        }
        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            // DEUX GESTES SIMULTANÉS (deux clics, l'écran et Ket) : tous deux ont passé `refus()`,
            // l'index unique de `contre_passation_de_id` refuse le second — rien n'est écrit.
            throw new ReversementIntangibleException(ReversementIntangible::DEJA_CONTRE_PASSE);
        }

        return [
            'lignes' => count($annulations),
            'total' => round($total, 2),
            'reference' => $reference,
            'porteurId' => $this->lots->porteurParmi($annulations)?->getId(),
        ];
    }
}
