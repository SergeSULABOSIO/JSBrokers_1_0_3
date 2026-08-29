<?php

namespace App\Tests\Workspace;

use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\ReversementRetroAgent;
use App\Entity\Utilisateur;
use App\Services\Canvas\Indicator\ReversementRetroAgentIndicatorStrategy;
use App\Services\CanvasBuilder;
use App\Services\JSBDynamicSearchService;
use App\Services\Search\ReversementScope;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * UNE LIGNE = UN VIREMENT.
 *
 * ── CE QUE CE TEST FERME ────────────────────────────────────────────────────────────
 * La base tient une ligne par ÉCHÉANCE réglée. Un virement qui en solde trois y occupe
 * trois lignes, portant trois fois la même date, la même référence et le même compte —
 * dix lignes à l'écran pour quatre décaissements réels. C'est la vérité de la base, ce
 * n'est pas la façon dont on lit un relevé.
 *
 * La rubrique REPLIE donc chaque lot sur son porteur. Trois choses doivent tenir
 * ensemble, et c'est leur désaccord qui serait invisible :
 *
 *   1. la PAGE ne montre qu'une ligne par virement ;
 *   2. le COMPTE la suit — « 2 affiché(s) sur 2 », jamais « 2 sur 4 », ce qui ferait
 *      chercher deux lignes que l'écran ne montrera jamais ;
 *   3. la ligne annonce le total du VIREMENT — le total de la barre étant une somme des
 *      lignes affichées, s'en tenir au porteur l'aurait fait chuter sans rien signaler.
 *
 * Et le détail reste atteignable : le chip « Détail par échéance » rend l'écran d'avant.
 */
class ReversementListeRepliTest extends KernelTestCase
{
    private const ENTREPRISE_NOM = 'PHPUnit Repli SARL';
    private const OWNER_EMAIL = 'phpunit-repli-owner@test.local';

    /** Un lot de trois échéances : 10 + 20 + 30. */
    private const LOT = 'LOT-REPLI-A';

    protected function setUp(): void
    {
        static::bootKernel();
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        parent::tearDown();
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();
        foreach (['reversement_retro_agent', 'invite'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :nom",
                ['nom' => self::ENTREPRISE_NOM],
            );
        }
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :nom', ['nom' => self::ENTREPRISE_NOM]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :email', ['email' => self::OWNER_EMAIL]);
        $this->em()->clear();
    }

    /**
     * Un lot de trois échéances (10 + 20 + 30) et un versement isolé (5).
     *
     * @return array{entreprise: Entreprise, porteurId: int, soloId: int}
     */
    private function semer(): array
    {
        $em = $this->em();

        $owner = (new Utilisateur())->setEmail(self::OWNER_EMAIL)->setNom('Repli')
            ->setVerified(true)->setPassword('x');
        $em->persist($owner);

        $entreprise = (new Entreprise())->setNom(self::ENTREPRISE_NOM)->setLicence('LIC')
            ->setAdresse('1 rue')->setTelephone('+2430000')->setRccm('R')->setIdnat('I')->setNumimpot('N')
            ->setUtilisateur($owner);
        $em->persist($entreprise);

        $agent = (new Invite())->setNom('Alice Repli')->setProprietaire(false)->setEntreprise($entreprise);
        $em->persist($agent);
        $em->flush();

        $ecrire = static function (float $montant, ?string $lot) use ($em, $entreprise, $agent): ReversementRetroAgent {
            $r = (new ReversementRetroAgent())
                ->setAgent($agent)
                ->setMontant($montant)
                ->setPaidAt(new \DateTimeImmutable('2026-08-01'))
                ->setReference($lot ?? 'SOLO-REPLI')
                ->setLotReference($lot);
            $r->setEntreprise($entreprise)->setInvite($agent);
            $em->persist($r);

            return $r;
        };

        $membres = [$ecrire(10.0, self::LOT), $ecrire(20.0, self::LOT), $ecrire(30.0, self::LOT)];
        $solo = $ecrire(5.0, null);
        $em->flush();

        // LE PORTEUR EST LE PLUS PETIT ID — la règle de LotDeVersement::porteurParmi(),
        // que le repli ne fait que redire dans le dialecte de la requête.
        $ids = array_map(static fn (ReversementRetroAgent $r) => $r->getId(), $membres);
        sort($ids);

        return [
            'entreprise' => $entreprise,
            'porteurId' => $ids[0],
            'soloId' => $solo->getId(),
        ];
    }

    /**
     * @param array<string, mixed> $criteres
     *
     * @return array{data: ReversementRetroAgent[], totalItems: int}
     */
    private function chercher(Entreprise $entreprise, array $criteres = []): array
    {
        /** @var JSBDynamicSearchService $service */
        $service = static::getContainer()->get(JSBDynamicSearchService::class);

        return $service->search(ReversementRetroAgent::class, $criteres, $entreprise);
    }

    public function testUnLotDeTroisEcheancesNeFaitQuUneLigne(): void
    {
        $s = $this->semer();

        $resultat = $this->chercher($s['entreprise']);

        // Quatre reversements en base, DEUX virements à l'écran.
        self::assertCount(2, $resultat['data'], 'Le lot est replié sur son porteur.');

        $ids = array_map(static fn (ReversementRetroAgent $r) => $r->getId(), $resultat['data']);
        sort($ids);
        self::assertSame([$s['porteurId'], $s['soloId']], $ids, 'Le porteur est le plus petit id du lot.');
    }

    /**
     * LE COMPTE SUIT LA PAGE.
     *
     * Le repli est posé avant les critères, donc appliqué à la requête de COMPTAGE comme à
     * celle de la page : sans cela l'écran aurait annoncé « 2 affiché(s) sur 4 », et fait
     * chercher deux lignes qu'il ne montrera jamais.
     */
    public function testLeComptageSuitLeRepli(): void
    {
        $s = $this->semer();

        self::assertSame(2, $this->chercher($s['entreprise'])['totalItems']);
    }

    /**
     * LA LIGNE PORTE LES CHIFFRES DU VIREMENT, PAS DE SON PORTEUR.
     *
     * Le total de la barre est une somme des lignes AFFICHÉES : afficher 10 — le montant du
     * porteur — au lieu de 60 aurait fait chuter le total au sixième de la réalité, sans
     * erreur ni avertissement.
     */
    public function testLaLignePorteLeTotalDuVirementEtSonNombreDEcheances(): void
    {
        $s = $this->semer();

        // ON PASSE PAR LA RECHERCHE, et il le faut : c'est elle qui déclare la maille de
        // lecture. Interroger l'indicateur sans elle, c'est demander ce que vaut une ligne
        // sans dire de quelle liste elle vient — et la réponse est alors son propre
        // montant, ce qui est juste.
        $this->chercher($s['entreprise']);

        /** @var ReversementRetroAgentIndicatorStrategy $strategie */
        $strategie = static::getContainer()->get(ReversementRetroAgentIndicatorStrategy::class);
        $porteur = $this->em()->getRepository(ReversementRetroAgent::class)->find($s['porteurId']);
        $solo = $this->em()->getRepository(ReversementRetroAgent::class)->find($s['soloId']);

        $duLot = $strategie->calculate($porteur);
        self::assertSame(60.0, $duLot['montantAffiche'], '10 + 20 + 30, et non les 10 du porteur.');
        self::assertSame('3 échéances réglées', $duLot['echeancesDuVirement']);

        // Un versement isolé est son propre virement : la règle n'a pas deux cas.
        $duSolo = $strategie->calculate($solo);
        self::assertSame(5.0, $duSolo['montantAffiche']);
        self::assertSame('1 échéance réglée', $duSolo['echeancesDuVirement']);

        // LA POLICE ET L'ÉCHÉANCE SE TAISENT : sur une ligne qui en couvre six, nommer
        // celle du porteur aurait désigné une affaire sur six comme si c'était la seule.
        self::assertNull($duLot['policeReference']);
        self::assertNull($duLot['echeanceLibelle']);
    }

    /**
     * ET SUR LA VUE DÉTAILLÉE, C'EST L'INVERSE : chaque ligne ne vaut qu'elle-même, et
     * redit quelle affaire et quelle échéance elle règle.
     */
    public function testLaVueDetailleeRendSaMailleAChaqueLigne(): void
    {
        $s = $this->semer();

        $this->chercher($s['entreprise'], [
            ReversementScope::CLE_VIREMENT => ['operator' => '=', 'value' => ReversementScope::DETAIL],
        ]);

        /** @var ReversementRetroAgentIndicatorStrategy $strategie */
        $strategie = static::getContainer()->get(ReversementRetroAgentIndicatorStrategy::class);
        $porteur = $this->em()->getRepository(ReversementRetroAgent::class)->find($s['porteurId']);

        $ligne = $strategie->calculate($porteur);
        self::assertSame(10.0, $ligne['montantAffiche'], 'Sa part, et non celle du virement.');
        self::assertNull($ligne['echeancesDuVirement'], 'Le compte du virement n\'a plus d\'objet.');
        self::assertNotNull($ligne['echeanceLibelle'], 'L\'échéance, elle, redevient nommable.');
    }

    /**
     * ⚠ LA BARRE DES TOTAUX ADDITIONNE CE QUE L'ÉCRAN MONTRE — DANS LES DEUX MODES.
     *
     * C'est le point où le repli pouvait mentir sans rien casser. La barre a son PROPRE
     * fournisseur : il lisait `getMontant()`, la part de la ligne, ce qui était juste tant
     * que la rubrique montrait une ligne par échéance. Repliée, elle n'aurait additionné
     * que les parts des porteurs — 10 + 5 au lieu de 65, un total d'apparence normale et
     * faux. Et rendre toujours le total du virement l'aurait TRIPLÉ sur la vue détaillée.
     *
     * Les deux modes sont donc vérifiés, et leur somme doit être LA MÊME : c'est le même
     * argent, lu à deux mailles.
     */
    public function testLaBarreDesTotauxDonneLeMemeDecaissementDansLesDeuxModes(): void
    {
        $s = $this->semer();
        /** @var CanvasBuilder $canvas */
        $canvas = static::getContainer()->get(CanvasBuilder::class);

        $sommer = static fn (array $numerique): float => round(array_sum(array_map(
            static fn (array $attributs) => $attributs['montant']['value'] / 100,
            $numerique,
        )), 2);

        // 1. VUE REPLIÉE : deux lignes, et pourtant tout l'argent.
        $replie = $this->chercher($s['entreprise']);
        $numeriqueReplie = $canvas->getNumericAttributesAndValuesForCollection($replie['data']);
        self::assertCount(2, $numeriqueReplie, 'Deux lignes totalisées.');
        self::assertSame(65.0, $sommer($numeriqueReplie), '(10 + 20 + 30) + 5, jamais 10 + 5.');

        // 2. VUE DÉTAILLÉE : quatre lignes, le même argent.
        $detail = $this->chercher($s['entreprise'], [
            ReversementScope::CLE_VIREMENT => ['operator' => '=', 'value' => ReversementScope::DETAIL],
        ]);
        $numeriqueDetail = $canvas->getNumericAttributesAndValuesForCollection($detail['data']);
        self::assertCount(4, $numeriqueDetail, 'Quatre lignes totalisées.');
        self::assertSame(65.0, $sommer($numeriqueDetail), 'Le même décaissement, jamais 185.');
    }

    /**
     * LE TOTAL DE LA SÉLECTION SUIT LA MÊME RÈGLE.
     *
     * Le contrôleur de la barre filtre les mêmes données par identifiant sélectionné :
     * cocher la ligne d'un virement doit annoncer le virement entier, puisque c'est ce que
     * la ligne représente et ce que sa colonne affiche.
     */
    public function testLeTotalDeLaSelectionVautLeVirementCoche(): void
    {
        $s = $this->semer();
        /** @var CanvasBuilder $canvas */
        $canvas = static::getContainer()->get(CanvasBuilder::class);

        $replie = $this->chercher($s['entreprise']);
        $numerique = $canvas->getNumericAttributesAndValuesForCollection($replie['data']);

        // La sélection, côté navigateur, n'est qu'un filtre par identifiant sur ces données.
        self::assertSame(60.0, $numerique[$s['porteurId']]['montant']['value'] / 100);
        self::assertSame(5.0, $numerique[$s['soloId']]['montant']['value'] / 100);
    }

    /**
     * LE DÉTAIL N'EST PAS PERDU : le chip le rend, et lui seul déplie.
     */
    public function testLeChipDetailRendChaqueEcheance(): void
    {
        $s = $this->semer();

        $detail = $this->chercher($s['entreprise'], [
            ReversementScope::CLE_VIREMENT => ['operator' => '=', 'value' => ReversementScope::DETAIL],
        ]);

        self::assertCount(4, $detail['data'], 'Les quatre lignes de la base.');
        self::assertSame(4, $detail['totalItems']);
    }

    /**
     * LE CHIP CHOISIT UNE MAILLE, IL NE FILTRE RIEN.
     *
     * « Groupé » — la valeur vide, donc le défaut — montre les VERSEMENTS tels qu'ils ont
     * été faits au bénéficiaire ; « Détail par échéance » les ventile par tranche. Aucun
     * des deux ne retire de l'argent de la liste : c'est ce que le test suivant vérifie sur
     * les totaux, et c'est la propriété qui définit ce chip.
     */
    public function testLeChipChoisitLaMailleEtNeRetireRien(): void
    {
        $s = $this->semer();

        // « Groupé » explicite : exactement le défaut.
        $groupe = $this->chercher($s['entreprise'], [
            ReversementScope::CLE_VIREMENT => ['operator' => '=', 'value' => ''],
        ]);
        self::assertCount(2, $groupe['data'], 'Les deux virements, comme sans critère.');

        $ids = array_map(static fn (ReversementRetroAgent $r) => $r->getId(), $groupe['data']);
        sort($ids);
        self::assertSame([$s['porteurId'], $s['soloId']], $ids);
    }
}
