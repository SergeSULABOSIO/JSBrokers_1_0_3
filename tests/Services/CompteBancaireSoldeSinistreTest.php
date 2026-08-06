<?php

namespace App\Tests\Services;

use App\Entity\CompteBancaire;
use App\Entity\Note;
use App\Entity\OffreIndemnisationSinistre;
use App\Entity\Paiement;
use App\Services\Canvas\Indicator\CompteBancaireIndicatorStrategy;
use PHPUnit\Framework\TestCase;

/**
 * Solde d'un compte bancaire du cabinet.
 *
 * LE COURTIER N'INDEMNISE PAS LES SINISTRES. Un paiement rattaché à une offre
 * d'indemnisation est un SIGNALEMENT — c'est l'assureur qui règle l'assuré —, exactement
 * comme un PaiementPrime trace un règlement de prime encaissé par l'assureur. Il ne doit
 * donc toucher ni le solde, ni les entrées, ni les sorties, ni le compte de transactions.
 *
 * Il était classé en SORTIE : le solde affiché était minoré de tout l'indemnitaire déclaré.
 * Le tableau de bord (offreIndemnisationSinistre IS NULL) et la comptabilité du courtier
 * (écritures dérivées des seuls paiements de note) les écartaient déjà : seul ce solde
 * divergeait.
 */
class CompteBancaireSoldeSinistreTest extends TestCase
{
    private function paiement(float $montant, ?Note $note, ?OffreIndemnisationSinistre $offre): Paiement
    {
        return (new Paiement())
            ->setMontant($montant)
            ->setPaidAt(new \DateTimeImmutable('-1 day'))
            ->setNote($note)
            ->setOffreIndemnisationSinistre($offre);
    }

    private function note(int $type): Note
    {
        return (new Note())->setType($type);
    }

    private function compte(Paiement ...$paiements): CompteBancaire
    {
        $compte = new CompteBancaire();
        foreach ($paiements as $paiement) {
            $compte->addPaiement($paiement);
        }

        return $compte;
    }

    /** @return array<string, mixed> */
    private function indicateurs(CompteBancaire $compte): array
    {
        return (new CompteBancaireIndicatorStrategy())->calculate($compte);
    }

    public function testUnReglementDeSinistreNeSortPasDeLaTresorerieDuCabinet(): void
    {
        $encaissement = $this->paiement(1000.0, $this->note(Note::TYPE_NOTE_DE_DEBIT), null);
        $indemnisation = $this->paiement(4000.0, null, new OffreIndemnisationSinistre());

        $avecSinistre = $this->indicateurs($this->compte($encaissement, $indemnisation));
        $sansSinistre = $this->indicateurs($this->compte($this->paiement(1000.0, $this->note(Note::TYPE_NOTE_DE_DEBIT), null)));

        $this->assertSame(
            $sansSinistre['soldeActuel'],
            $avecSinistre['soldeActuel'],
            'Le règlement de sinistre ne doit RIEN changer au solde du cabinet.'
        );
        $this->assertSame(1000.0, $avecSinistre['soldeActuel']);
        $this->assertSame(0.0, $avecSinistre['totalSorties'], 'Ce n\'est pas une sortie : le courtier ne paie pas de sa poche.');
        $this->assertSame(1, $avecSinistre['nombreTransactions'], 'Il ne compte pas non plus comme une transaction du cabinet.');
    }

    /**
     * Les vraies sorties — une note de CRÉDIT payée par le cabinet — restent comptées :
     * la correction ne doit pas neutraliser les décaissements légitimes.
     */
    public function testLesDecaissementsReelsRestentComptes(): void
    {
        $indicateurs = $this->indicateurs($this->compte(
            $this->paiement(1000.0, $this->note(Note::TYPE_NOTE_DE_DEBIT), null),
            $this->paiement(300.0, $this->note(Note::TYPE_NOTE_DE_CREDIT), null),
            $this->paiement(4000.0, null, new OffreIndemnisationSinistre()),
        ));

        $this->assertSame(1000.0, $indicateurs['totalEntrees']);
        $this->assertSame(300.0, $indicateurs['totalSorties']);
        $this->assertSame(700.0, $indicateurs['soldeActuel']);
        $this->assertSame(2, $indicateurs['nombreTransactions']);
        $this->assertSame(650.0, $indicateurs['moyenneTransaction'], 'La moyenne porte sur les seules transactions du cabinet.');
    }

    public function testUnCompteNePortantQueDesSinistresResteAZero(): void
    {
        $indicateurs = $this->indicateurs($this->compte(
            $this->paiement(4000.0, null, new OffreIndemnisationSinistre()),
            $this->paiement(1500.0, null, new OffreIndemnisationSinistre()),
        ));

        $this->assertSame(0.0, $indicateurs['soldeActuel']);
        $this->assertSame(0, $indicateurs['nombreTransactions']);
        $this->assertSame(0.0, $indicateurs['moyenneTransaction']);
        $this->assertNull($indicateurs['dateDerniereTransaction'], 'Aucune transaction du cabinet : pas de « dernier mouvement ».');
    }
}
