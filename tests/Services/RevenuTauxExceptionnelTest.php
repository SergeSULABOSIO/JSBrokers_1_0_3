<?php

namespace App\Tests\Services;

use App\Constantes\Constante;
use App\Entity\Chargement;
use App\Entity\Client;
use App\Entity\ChargementPourPrime;
use App\Entity\Cotation;
use App\Entity\Piste;
use App\Entity\RevenuPourCourtier;
use App\Entity\Risque;
use App\Entity\TypeRevenu;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * CE QUI TARIFE UNE COMMISSION, ET DANS QUEL ORDRE.
 *
 * ── POURQUOI CE TEST EXISTE ─────────────────────────────────────────────────────────
 * ⚠ UN TAUX EXCEPTIONNEL ÉTAIT ENREGISTRÉ PUIS IGNORÉ. La cascade testait
 * `isAppliquerPourcentageDuRisque()` en PREMIER : sur un type adossé au risque —
 * « Commission Ordinaire » en tête, qui est le défaut de tout cabinet — un taux saisi sur
 * le revenu n'atteignait jamais le calcul. L'utilisateur le voyait à la fiche et ne le
 * retrouvait dans aucun montant, sans qu'aucune erreur ne le dise.
 *
 * Toute la reprise en dépend : chaque ligne de classeur apporte le taux de sa commission
 * (« Commission = 17,5 »), et c'est ce taux que la note d'ouverture facture. S'il ne
 * comptait pas, la note vaudrait zéro et son règlement la rendrait négative.
 *
 * ── CE QUI EST VÉRIFIÉ SANS BASE DE DONNÉES ─────────────────────────────────────────
 * `Revenu_getMontant_ht()` ne lit que des objets déjà hydratés : on les monte à la main,
 * ce qui rend l'ordre de la cascade lisible en un coup d'œil — et le test rapide.
 */
class RevenuTauxExceptionnelTest extends KernelTestCase
{
    private const PRIME_NETTE = 10000.0;

    /**
     * ⚠ LE CAS QUI A MOTIVÉ TOUT LE CHANTIER.
     *
     * Un taux saisi sur le revenu l'emporte sur celui du risque. C'est le sens du mot
     * « exceptionnel », et c'est ce que le formulaire promet déjà : « Privilégier le taux
     * du risque ? Oui, s'il existe ET QU'IL EST DIFFÉRENT DU POURCENTAGE DE CE REVENU ».
     */
    public function testUnTauxExceptionnelLEmporteSurLeTauxDuRisque(): void
    {
        $revenu = $this->revenu(
            typeAdosseAuRisque: true,
            tauxDuRisque: 10.0,
            tauxExceptionnel: 17.5,
        );

        self::assertSame(1750.0, $this->constante()->Revenu_getMontant_ht($revenu));
    }

    /** Sans dérogation, le taux du risque continue de s'appliquer : rien n'a changé pour lui. */
    public function testSansDerogationLeTauxDuRisqueSApplique(): void
    {
        $revenu = $this->revenu(
            typeAdosseAuRisque: true,
            tauxDuRisque: 10.0,
            tauxExceptionnel: null,
        );

        self::assertSame(1000.0, $this->constante()->Revenu_getMontant_ht($revenu));
    }

    /**
     * ⚠ L'ASYMÉTRIE EST VOULUE, ET CE TEST LA VERROUILLE.
     *
     * Le forfait du revenu, lui, n'a PAS été remonté au-dessus du taux du risque. Le
     * classeur de reprise ne transporte jamais de forfait — il ne porte que des taux —, et
     * mesuré sur le cabinet réel, 85 revenus sur 150 en portent un en base. Le remonter
     * réveillerait d'un coup 85 valeurs dormantes et déplacerait des commissions que
     * personne n'a demandé de changer.
     *
     * Ne « corrigez » donc pas cette asymétrie sans avoir d'abord compté, sur les données
     * réelles, combien de montants elle déplace.
     */
    public function testUnForfaitDeRevenuNeDetronePasLeTauxDuRisque(): void
    {
        $revenu = $this->revenu(
            typeAdosseAuRisque: true,
            tauxDuRisque: 10.0,
            tauxExceptionnel: null,
            forfaitExceptionnel: 5000.0,
        );

        self::assertSame(
            1000.0,
            $this->constante()->Revenu_getMontant_ht($revenu),
            'Le risque garde la main sur un forfait : asymétrie assumée, cf. le docbloc.',
        );
    }

    /**
     * ⚠ UN MONTANT FIXE S'AJOUTE, IL NE SE MULTIPLIE PAS.
     *
     * Le forfait porté par le TYPE était multiplié par l'assiette : un type « montant
     * fixe » à 5 000 sur une prime de 10 000 facturait cinquante millions. Le cas jumeau
     * du revenu l'ajoutait pourtant tel quel — deux lectures d'une même notion, dans la
     * même méthode.
     */
    public function testLeForfaitDUnTypeEstFactureTelQuel(): void
    {
        $revenu = $this->revenu(
            typeAdosseAuRisque: false,
            tauxDuRisque: 0.0,
            tauxExceptionnel: null,
            forfaitDuType: 5000.0,
        );

        self::assertSame(5000.0, $this->constante()->Revenu_getMontant_ht($revenu));
    }

    /** Le taux du type reste le dernier recours, appliqué à l'assiette. */
    public function testLeTauxDuTypeSAppliqueEnDernierRecours(): void
    {
        $revenu = $this->revenu(
            typeAdosseAuRisque: false,
            tauxDuRisque: 0.0,
            tauxExceptionnel: null,
            pourcentageDuType: 12.0,
        );

        self::assertSame(1200.0, $this->constante()->Revenu_getMontant_ht($revenu));
    }

    /**
     * LE TARIF EFFECTIF DIT LA MÊME CHOSE QUE LE CALCUL, ET DIT D'OÙ IL VIENT.
     *
     * ⚠ C'EST CE QUI PERMET À L'EXPORT DE NE PAS MENTIR. La colonne « Commission ·
     * Revenus » écrit désormais le taux réel de chaque revenu ; s'il divergeait du taux
     * qui calcule, on lirait 10 % sur une commission facturée à 17,5.
     */
    public function testLeTarifEffectifSuitLaMemeCascadeQueLeCalcul(): void
    {
        $constante = $this->constante();

        $derogation = $constante->Revenu_getTarif_effectif(
            $this->revenu(typeAdosseAuRisque: true, tauxDuRisque: 10.0, tauxExceptionnel: 17.5),
        );
        self::assertSame(17.5, $derogation['taux']);
        self::assertSame('revenu', $derogation['origine'], 'Une dérogation appartient au revenu.');

        $herite = $constante->Revenu_getTarif_effectif(
            $this->revenu(typeAdosseAuRisque: true, tauxDuRisque: 10.0, tauxExceptionnel: null),
        );
        self::assertSame(10.0, $herite['taux']);
        self::assertSame('risque', $herite['origine'], 'Hérité : à marquer, donc à ne pas recopier.');

        $duType = $constante->Revenu_getTarif_effectif(
            $this->revenu(typeAdosseAuRisque: false, tauxDuRisque: 0.0, tauxExceptionnel: null, pourcentageDuType: 12.0),
        );
        self::assertSame(12.0, $duType['taux']);
        self::assertSame('type', $duType['origine']);
    }

    /** Un risque sans taux prescrit ne tarife rien — et le dire vaut mieux qu'écrire un zéro. */
    public function testUnRisqueSansTauxNeTarifeRien(): void
    {
        $tarif = $this->constante()->Revenu_getTarif_effectif(
            $this->revenu(typeAdosseAuRisque: true, tauxDuRisque: 0.0, tauxExceptionnel: null),
        );

        self::assertNull($tarif['taux']);
        self::assertNull($tarif['forfait']);
    }

    /**
     * Un revenu monté à la main, assis sur une prime nette de 10 000.
     *
     * L'assiette est appariée par le TYPE de chargement, jamais par son nom
     * (`Cotation_getMontant_chargement_prime`) : le même objet `Chargement` doit donc
     * servir des deux côtés, sans quoi l'assiette vaut zéro et tous les cas rendent 0.
     */
    private function revenu(
        bool $typeAdosseAuRisque,
        float $tauxDuRisque,
        ?float $tauxExceptionnel,
        ?float $forfaitExceptionnel = null,
        ?float $pourcentageDuType = null,
        ?float $forfaitDuType = null,
        bool $clientExonere = false,
        bool $risqueImposable = true,
    ): RevenuPourCourtier {
        $primeNette = (new Chargement())->setNom('Prime nette')->setFonction(Chargement::FONCTION_PRIME_NETTE);

        $type = (new TypeRevenu())
            ->setNom('Commission Ordinaire')
            ->setTypeChargement($primeNette)
            ->setAppliquerPourcentageDuRisque($typeAdosseAuRisque);
        if ($pourcentageDuType !== null) {
            $type->setPourcentage($pourcentageDuType);
        }
        if ($forfaitDuType !== null) {
            $type->setMontantflat($forfaitDuType);
        }

        $risque = (new Risque())->setNomComplet('RC Aviation')->setCode('RCA')->setImposable($risqueImposable);
        $risque->setPourcentageCommissionSpecifiqueHT($tauxDuRisque);

        $client = (new Client())->setNom('KIN AVIA');
        $client->setExonere($clientExonere);

        $cotation = new Cotation();
        $cotation->setPiste((new Piste())->setRisque($risque)->setClient($client));
        $cotation->addChargement(
            (new ChargementPourPrime())->setNom('Prime nette')->setType($primeNette)
                ->setMontantFlatExceptionel(self::PRIME_NETTE),
        );

        $revenu = (new RevenuPourCourtier())->setNom('Commission')->setTypeRevenu($type);
        if ($tauxExceptionnel !== null) {
            $revenu->setTauxExceptionel($tauxExceptionnel);
        }
        if ($forfaitExceptionnel !== null) {
            $revenu->setMontantFlatExceptionel($forfaitExceptionnel);
        }
        $cotation->addRevenu($revenu);

        return $revenu;
    }

    /**
     * La god-class du calcul porte seize dépendances : on la prend au conteneur plutôt que
     * de la monter à la main. Le noyau démarre une fois pour tout le fichier de test.
     */
    private function constante(): Constante
    {
        self::bootKernel();

        return static::getContainer()->get(Constante::class);
    }
}
