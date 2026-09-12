<?php

namespace App\Tests\Ai;

use App\Constantes\Constante;
use App\Entity\Chargement;
use App\Entity\ChargementPourPrime;
use App\Entity\Client;
use App\Entity\Cotation;
use App\Entity\Piste;
use App\Entity\RevenuPourCourtier;
use App\Entity\Risque;
use App\Entity\TypeRevenu;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * CE QUE KET ANNONCE DOIT ÊTRE CE QUE L'APPLICATION FACTURE.
 *
 * ── POURQUOI CE TEST EXISTE ─────────────────────────────────────────────────────────
 * ⚠ DEUX LECTURES D'UNE MÊME CASCADE, ET RIEN NE LES TENAIT ENSEMBLE.
 * `Constante::Revenu_getMontant_ht()` CALCULE la rémunération du courtier ;
 * `RevenuCourtierPrescrit::prescription()` l'ANNONCE à l'utilisateur avant écriture
 * (« Rémunération du courtier : "Commission Ordinaire" — 10,00 % (taux prescrit par le
 * risque) »). La seconde se déclare « dans l'ordre exact de la cascade » de la première —
 * une promesse tenue par un commentaire, c'est-à-dire par personne.
 *
 * Elle a d'ailleurs été rompue : l'ordre du calcul a changé le 2026-09-12 (le taux
 * exceptionnel du revenu passe avant celui du risque, et le forfait d'un type s'ajoute au
 * lieu d'être multiplié par l'assiette) sans que rien n'oblige le miroir à suivre.
 *
 * ── CE QUE LA PARITÉ SIGNIFIE ICI ───────────────────────────────────────────────────
 * Pour une même configuration, le taux ANNONCÉ appliqué à l'assiette doit rendre
 * exactement le montant CALCULÉ. Si les deux divergent, Ket promet une commission que la
 * facture ne portera pas — et c'est l'utilisateur qui découvre l'écart, au relevé.
 *
 * ⚠ LA PREMIÈRE BRANCHE DE LA CASCADE VIT AILLEURS, et c'est normal : `prescription()` dit
 * ce que la configuration prescrit À DÉFAUT de dérogation, puisque le revenu n'existe pas
 * encore. Le taux DICTÉ est porté par `deriver()`. Le dernier cas ci-dessous vérifie que
 * cette moitié-là tient aussi : un taux dicté doit être ce que le calcul retient.
 */
class RevenuCourtierPrescritPariteTest extends KernelTestCase
{
    private const PRIME_NETTE = 10000.0;

    /**
     * @dataProvider configurations
     */
    public function testLeTauxAnnonceEstCeluiQuiCalcule(
        bool $adosseAuRisque,
        float $tauxDuRisque,
        ?float $pourcentageDuType,
        ?float $forfaitDuType,
        float $montantAttendu,
    ): void {
        $revenu = $this->revenu($adosseAuRisque, $tauxDuRisque, $pourcentageDuType, $forfaitDuType);

        $calcule = $this->constante()->Revenu_getMontant_ht($revenu);
        self::assertSame($montantAttendu, $calcule, 'Le calcul lui-même doit être celui attendu.');

        // ⚠ ON PASSE PAR LE TARIF EFFECTIF, jumeau déclaré de la cascade et source de ce
        // que l'export et l'écran affichent. S'il divergeait du calcul, tout ce qui
        // annonce un taux à l'utilisateur mentirait de la même façon.
        $tarif = $this->constante()->Revenu_getTarif_effectif($revenu);

        $annonce = $tarif['taux'] !== null
            ? self::PRIME_NETTE * ($tarif['taux'] / 100.0)
            : (float) ($tarif['forfait'] ?? 0.0);

        self::assertEqualsWithDelta(
            $calcule,
            $annonce,
            0.005,
            'Ce qui est annoncé à l\'utilisateur doit produire ce que l\'application facture.',
        );
    }

    public static function configurations(): iterable
    {
        // Le taux du risque, quand le type s'y adosse.
        yield 'taux du risque' => [true, 10.0, null, null, 1000.0];

        // Le taux propre au type, quand il ne s'adosse pas au risque.
        yield 'taux du type' => [false, 0.0, 12.0, null, 1200.0];

        // ⚠ LE FORFAIT D'UN TYPE S'AJOUTE, IL NE SE MULTIPLIE PAS. Il était multiplié par
        // l'assiette : 5 000 sur une prime de 10 000 facturait cinquante millions, quand
        // le miroir annonçait « montant forfaitaire de 5 000 ». C'est cet écart-là qui a
        // révélé lequel des deux disait vrai.
        yield 'forfait du type' => [false, 0.0, null, 5000.0, 5000.0];
    }

    /**
     * ⚠ ET LA MOITIÉ PORTÉE PAR `deriver()` : UN TAUX DICTÉ L'EMPORTE.
     *
     * Ket écrit alors un `tauxExceptionel` sur le revenu. Avant la correction du 2026-09-12,
     * ce taux était enregistré puis IGNORÉ dès que le type s'adossait au risque — Ket
     * annonçait « taux exceptionnel dicté de 17,50 % » et l'application facturait les 10 %
     * du risque. La promesse de l'assistant était fausse, et rien ne le disait.
     */
    public function testUnTauxDicteParKetEstCeluiQuiCalcule(): void
    {
        $revenu = $this->revenu(adosseAuRisque: true, tauxDuRisque: 10.0);
        $revenu->setTauxExceptionel(17.5);

        self::assertSame(
            1750.0,
            $this->constante()->Revenu_getMontant_ht($revenu),
            'Un taux dicté par l\'assistant doit être celui qui facture.',
        );
        self::assertSame(17.5, $this->constante()->Revenu_getTarif_effectif($revenu)['taux']);
    }

    /** Une affaire montée en mémoire, assise sur une prime nette de 10 000. */
    private function revenu(
        bool $adosseAuRisque,
        float $tauxDuRisque,
        ?float $pourcentageDuType = null,
        ?float $forfaitDuType = null,
    ): RevenuPourCourtier {
        $primeNette = (new Chargement())->setNom('Prime nette')->setFonction(Chargement::FONCTION_PRIME_NETTE);

        $type = (new TypeRevenu())->setNom('Commission Ordinaire')->setTypeChargement($primeNette)
            ->setAppliquerPourcentageDuRisque($adosseAuRisque);
        if ($pourcentageDuType !== null) {
            $type->setPourcentage($pourcentageDuType);
        }
        if ($forfaitDuType !== null) {
            $type->setMontantflat($forfaitDuType);
        }

        $risque = (new Risque())->setNomComplet('RC Aviation')->setCode('RCA')->setImposable(true);
        $risque->setPourcentageCommissionSpecifiqueHT($tauxDuRisque);

        $cotation = new Cotation();
        $cotation->setPiste((new Piste())->setRisque($risque)->setClient((new Client())->setNom('KIN AVIA')));
        $cotation->addChargement(
            (new ChargementPourPrime())->setNom('Prime nette')->setType($primeNette)
                ->setMontantFlatExceptionel(self::PRIME_NETTE),
        );

        $revenu = (new RevenuPourCourtier())->setNom('Commission')->setTypeRevenu($type);
        $cotation->addRevenu($revenu);

        return $revenu;
    }

    private function constante(): Constante
    {
        self::bootKernel();

        return static::getContainer()->get(Constante::class);
    }
}
