<?php

namespace App\Tests\Ai;

use App\Ai\AiRequest;
use App\Ai\Engine\SimulatedAiEngine;
use App\Ai\Scope\AiScope;
use App\Ai\Tool\AiToolResult;
use App\Ai\Tool\PaiementsPrimeTool;
use App\Entity\Assureur;
use App\Entity\Avenant;
use App\Entity\ChargementPourPrime;
use App\Entity\Client;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\PaiementPrime;
use App\Entity\Piste;
use App\Entity\Portefeuille;
use App\Entity\RevenuPourCourtier;
use App\Entity\Taxe;
use App\Entity\Tranche;
use App\Entity\TypeRevenu;
use App\Entity\Utilisateur;
use App\Services\Search\PortefeuilleScope;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Outil « paiements_prime » sur la VRAIE base : ce que les tests unitaires ne peuvent pas
 * prouver — le chemin de relations du périmètre portefeuille
 * (tranche.cotation.piste.client.portefeuille.gestionnaire) produit bien le SQL attendu,
 * le scoping entreprise tient, et le mode ciblé restitue les signalements d'une tranche.
 */
class PaiementsPrimeToolIntegrationTest extends KernelTestCase
{
    private const OWNER_EMAIL = 'phpunit-paiementsprime-owner@test.local';
    private const ENTREPRISE_NOM = 'PHPUnit PaiementsPrime SARL';
    private const ENTREPRISE_B_NOM = 'PHPUnit PaiementsPrime Autre SARL';

    /** Commission de courtage due par l'ASSUREUR, en montant fixe (assiette des taxes). */
    private const COMMISSION = 100.0;

    /** Taxes SUR LA COMMISSION : TVA due par l'assureur, ARCA due par le courtier. */
    private const TAUX_TVA = 16.0;
    private const TAUX_ARCA = 2.0;

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

    private function tool(): PaiementsPrimeTool
    {
        return static::getContainer()->get(PaiementsPrimeTool::class);
    }

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();
        $noms = [self::ENTREPRISE_NOM, self::ENTREPRISE_B_NOM];

        // Enfants avant parents. `avenant` précède `cotation` (FK cotation_id) et
        // `assureur` la suit (FK assureur_id portée par la cotation).
        $tables = [
            'paiement_prime', 'tranche', 'chargement_pour_prime', 'revenu_pour_courtier',
            'avenant', 'cotation', 'type_revenu', 'assureur', 'piste', 'client',
            'portefeuille', 'invite', 'taxe',
        ];
        foreach ($tables as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom IN (:noms)",
                ['noms' => $noms],
                ['noms' => \Doctrine\DBAL\ArrayParameterType::STRING]
            );
        }
        // Dénoue la FK utilisateur.connected_to_id ↔ entreprise avant suppression.
        $conn->executeStatement(
            'UPDATE utilisateur SET connected_to_id = NULL WHERE email = :email',
            ['email' => self::OWNER_EMAIL]
        );
        $conn->executeStatement(
            'DELETE FROM entreprise WHERE nom IN (:noms)',
            ['noms' => $noms],
            ['noms' => \Doctrine\DBAL\ArrayParameterType::STRING]
        );
        $conn->executeStatement(
            'DELETE FROM utilisateur WHERE email = :email',
            ['email' => self::OWNER_EMAIL]
        );
    }

    private function makeEntreprise(string $nom, Utilisateur $owner): Entreprise
    {
        $entreprise = (new Entreprise())
            ->setNom($nom)
            ->setLicence('LIC-PP')
            ->setAdresse('1 rue des Primes')
            ->setTelephone('+243000000002')
            ->setRccm('RCCM-PP')
            ->setIdnat('IDNAT-PP')
            ->setNumimpot('IMP-PP')
            ->setUtilisateur($owner);
        $this->em()->persist($entreprise);

        return $entreprise;
    }

    /**
     * Entreprise A : un portefeuille géré par le propriétaire, son client, sa tranche et
     * DEUX signalements de paiement de prime. Un second invité ne gère aucun portefeuille.
     * Entreprise B : une tranche et un signalement, jamais visibles depuis A.
     *
     * @return array{entreprise: Entreprise, gestionnaire: Invite, sansPortefeuille: Invite, tranche: Tranche}
     */
    private function seed(): array
    {
        $em = $this->em();

        $ownerUser = (new Utilisateur())
            ->setEmail(self::OWNER_EMAIL)
            ->setNom('PHPUnit PaiementsPrime')
            ->setVerified(true)
            ->setPassword('irrelevant');
        $em->persist($ownerUser);

        $entreprise = $this->makeEntreprise(self::ENTREPRISE_NOM, $ownerUser);
        $ownerUser->setConnectedTo($entreprise); // pour la construction du FormType (autocomplete) de l'action.
        $gestionnaire = (new Invite())->setNom('Propriétaire PP');
        $gestionnaire->setUtilisateur($ownerUser)->setEntreprise($entreprise)->setProprietaire(true);
        $em->persist($gestionnaire);

        $sansPortefeuille = (new Invite())->setNom('Collaborateur sans portefeuille');
        $sansPortefeuille->setEntreprise($entreprise)->setProprietaire(true);
        $em->persist($sansPortefeuille);

        // Les deux taxes SUR LA COMMISSION du cabinet (paramétrage d'entreprise) : sans
        // elles, la commission TTC vaudrait le HT et les taux resteraient à zéro.
        $this->makeTaxe($entreprise, 'TVA-PP', Taxe::REDEVABLE_ASSUREUR, self::TAUX_TVA);
        $this->makeTaxe($entreprise, 'ARCA-PP', Taxe::REDEVABLE_COURTIER, self::TAUX_ARCA);

        $tranche = $this->makeChaine($entreprise, $gestionnaire, 'A', 1000.0, true);
        $this->makeSignalement($entreprise, $tranche, 'PP-A-001', 400.0, '-40 days');
        $this->makeSignalement($entreprise, $tranche, 'PP-A-002', 200.0, '-5 days');

        $entrepriseB = $this->makeEntreprise(self::ENTREPRISE_B_NOM, $ownerUser);
        $ownerB = (new Invite())->setNom('Propriétaire B');
        $ownerB->setUtilisateur($ownerUser)->setEntreprise($entrepriseB)->setProprietaire(true);
        $em->persist($ownerB);
        $trancheB = $this->makeChaine($entrepriseB, $ownerB, 'B', 800.0, true);
        $this->makeSignalement($entrepriseB, $trancheB, 'PP-B-001', 800.0, '-2 days');

        $em->flush();
        $em->clear(); // EM partagé : on repart d'entités fraîches.

        return [
            'entreprise'       => $em->getRepository(Entreprise::class)->find($entreprise->getId()),
            'gestionnaire'     => $em->getRepository(Invite::class)->find($gestionnaire->getId()),
            'sansPortefeuille' => $em->getRepository(Invite::class)->find($sansPortefeuille->getId()),
            'tranche'          => $em->getRepository(Tranche::class)->find($tranche->getId()),
        ];
    }

    /** Portefeuille → client → piste → cotation (avec prime) → tranche. */
    private function makeChaine(Entreprise $entreprise, Invite $gestionnaire, string $suffixe, float $prime, bool $avecPortefeuille): Tranche
    {
        $em = $this->em();

        $client = (new Client())->setNom('Client PP ' . $suffixe)->setExonere(false);
        $client->setEntreprise($entreprise);
        if ($avecPortefeuille) {
            $portefeuille = (new Portefeuille())->setNom('Portefeuille PP ' . $suffixe)->setGestionnaire($gestionnaire);
            $portefeuille->setEntreprise($entreprise);
            $em->persist($portefeuille);
            $client->setPortefeuille($portefeuille);
        }
        $em->persist($client);

        $piste = (new Piste())
            ->setNom('Piste PP ' . $suffixe)
            ->setTypeAvenant(0)
            ->setDescriptionDuRisque('Risque de test paiements de prime')
            ->setExercice(2026)
            ->setClient($client);
        $piste->setEntreprise($entreprise)->setInvite($gestionnaire);
        $em->persist($piste);

        // ASSUREUR et AVENANT : sans eux, la liste transversale ne pouvait annoncer ni la
        // compagnie ni la police, et Ket comblait ces colonnes de son propre chef (cf.
        // l'incident du 2026-08-10 et PaiementsPrimeTool::projeter).
        $assureur = (new Assureur())
            ->setNom('Assureur PP ' . $suffixe)
            ->setEmail(sprintf('assureur-pp-%s@example.test', strtolower($suffixe)))
            ->setNumimpot('IMP-PP-' . $suffixe)
            ->setIdnat('NAT-PP-' . $suffixe)
            ->setRccm('RCCM-PP-' . $suffixe);
        $assureur->setEntreprise($entreprise);
        $em->persist($assureur);

        $cotation = (new Cotation())->setNom('Cotation PP ' . $suffixe)->setDuree(365);
        $cotation->setPiste($piste)->setAssureur($assureur);
        $cotation->setEntreprise($entreprise);
        $em->persist($cotation);

        $avenant = (new Avenant())
            ->setReferencePolice('POL-PP-' . $suffixe)
            ->setNumero('0')
            ->setDescription('Police de test paiements de prime')
            ->setStartingAt(new \DateTimeImmutable('-60 days'))
            ->setEndingAt(new \DateTimeImmutable('+305 days'));
        $avenant->setEntreprise($entreprise)->setInvite($gestionnaire);
        $cotation->addAvenant($avenant);
        $em->persist($avenant);

        $chargement = (new ChargementPourPrime())
            ->setNom('Prime PP ' . $suffixe)
            ->setMontantFlatExceptionel($prime)
            ->setCotation($cotation);
        $chargement->setEntreprise($entreprise);
        $em->persist($chargement);

        // COMMISSION due par l'ASSUREUR : la seconde dette, celle dont le débiteur n'est
        // pas l'assuré, et l'assiette des deux taxes sur la commission.
        $typeRevenu = (new TypeRevenu())
            ->setNom('Commission PP ' . $suffixe)
            ->setMontantflat(self::COMMISSION)
            ->setShared(false)
            ->setMultipayments(true)
            ->setRedevable(TypeRevenu::REDEVABLE_ASSUREUR);
        $typeRevenu->setEntreprise($entreprise);
        $em->persist($typeRevenu);

        $revenu = (new RevenuPourCourtier())
            ->setNom('Revenu PP ' . $suffixe)
            ->setTypeRevenu($typeRevenu)
            ->setCotation($cotation);
        $revenu->setEntreprise($entreprise);
        $em->persist($revenu);

        $tranche = (new Tranche())
            ->setNom('Tranche PP ' . $suffixe)
            ->setPourcentage(100.0) // 100 % en POINTS (convention pourcentage, pas fraction)
            ->setPayableAt(new \DateTimeImmutable('-60 days'))
            ->setEcheanceAt(new \DateTimeImmutable('-10 days'));
        $tranche->setCotation($cotation);
        $tranche->setEntreprise($entreprise);
        $em->persist($tranche);

        return $tranche;
    }

    /**
     * Une taxe SUR LA COMMISSION. Le même taux est posé en IARD et en VIE : la piste de
     * ce fixture n'a pas de risque, la branche retombe donc sur VIE (cf. isIARD) — un
     * détail de fixture qui n'a rien à dire sur le comportement testé ici.
     */
    private function makeTaxe(Entreprise $entreprise, string $code, int $redevable, float $taux): Taxe
    {
        $taxe = (new Taxe())
            ->setCode($code)
            ->setDescription('Taxe de test ' . $code)
            ->setRedevable($redevable)
            ->setTauxIARD((string) $taux)
            ->setTauxVIE((string) $taux);
        $taxe->setEntreprise($entreprise);
        $this->em()->persist($taxe);

        return $taxe;
    }

    /**
     * Le calcul des MONTANTS de taxe passe par ServiceTaxes, qui — hors contexte
     * explicite — lit le paramétrage de l'entreprise ACTIVE de l'utilisateur connecté.
     * Le chat, lui, est toujours authentifié : on pose donc le jeton comme il le fait.
     */
    private function connecter(): void
    {
        $ownerUser = $this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => self::OWNER_EMAIL]);
        static::getContainer()->get('security.token_storage')->setToken(
            new UsernamePasswordToken($ownerUser, 'main', $ownerUser->getRoles()),
        );
    }

    private function makeSignalement(Entreprise $entreprise, Tranche $tranche, string $reference, float $montant, string $quand): PaiementPrime
    {
        $paiement = (new PaiementPrime())
            ->setReference($reference)
            ->setMontant($montant)
            ->setPaidAt(new \DateTimeImmutable($quand))
            ->setDescription('Avis de règlement ' . $reference)
            ->setTranche($tranche);
        $paiement->setEntreprise($entreprise);
        $this->em()->persist($paiement);

        return $paiement;
    }

    public function testModeCibleListeLesSignalementsDeLaTranche(): void
    {
        ['entreprise' => $entreprise, 'gestionnaire' => $invite, 'tranche' => $tranche] = $this->seed();

        $result = $this->tool()->execute(
            ['trancheId' => $tranche->getId()],
            new AiScope($entreprise, $invite),
        );

        $this->assertSame(AiToolResult::STATUS_OK, $result->status);
        $this->assertSame(2, $result->data['total']);
        $this->assertSame(
            ['PP-A-002', 'PP-A-001'],
            array_column($result->data['signalements'], 'reference'),
            'Les plus récemment signalés d\'abord (id DESC), comme les listes du workspace.'
        );
        // Part SIGNALÉE = somme des PaiementPrime, calculée par le moteur d'indicateurs.
        $this->assertSame(600.0, $result->data['prime']['signalee']);
        $this->assertSame(1000.0, $result->data['prime']['totale']);
        $this->assertSame(400.0, $result->data['prime']['solde']);
    }

    public function testTrancheDUneAutreEntrepriseIntrouvable(): void
    {
        ['entreprise' => $entreprise, 'gestionnaire' => $invite] = $this->seed();
        $trancheB = $this->em()->getRepository(Tranche::class)->findOneBy(['nom' => 'Tranche PP B']);

        $result = $this->tool()->execute(
            ['trancheId' => $trancheB->getId()],
            new AiScope($entreprise, $invite),
        );

        $this->assertSame(AiToolResult::STATUS_INTROUVABLE, $result->status);
    }

    public function testPerimetrePortefeuilleFiltreReellementEnSql(): void
    {
        ['entreprise' => $entreprise, 'gestionnaire' => $gestionnaire, 'sansPortefeuille' => $autre] = $this->seed();

        // Le gestionnaire du portefeuille voit les 2 signalements : preuve que le chemin
        // tranche.cotation.piste.client.portefeuille.gestionnaire est valide et joint.
        $vuGestionnaire = $this->tool()->execute([], new AiScope($entreprise, $gestionnaire));
        $this->assertSame(AiToolResult::STATUS_OK, $vuGestionnaire->status);
        $this->assertSame(2, $vuGestionnaire->data['total']);
        $this->assertSame('Portefeuille PP A', $vuGestionnaire->data['perimetre']);
        $this->assertSame(600.0, $vuGestionnaire->data['montantPage']);

        // Un invité qui ne gère aucun portefeuille ne voit rien — et le périmètre le dit,
        // pour que l'assistant explique le zéro au lieu de l'annoncer sèchement.
        $vuAutre = $this->tool()->execute([], new AiScope($entreprise, $autre));
        $this->assertSame(0, $vuAutre->data['total']);
        $this->assertSame('aucun portefeuille', $vuAutre->data['perimetre']);

        // Élargi à l'entreprise : les signalements reviennent, ceux de l'entreprise B non.
        $vuEntreprise = $this->tool()->execute(
            ['perimetre' => PortefeuilleScope::PERIMETRE_ENTREPRISE],
            new AiScope($entreprise, $autre),
        );
        $this->assertSame(2, $vuEntreprise->data['total']);
        $this->assertSame(PortefeuilleScope::LIBELLE_ENTREPRISE, $vuEntreprise->data['perimetre']);
    }

    /**
     * L'INCIDENT DU 2026-08-10, contre la VRAIE base.
     *
     * La liste transversale ne rendait ni le client ni l'assureur ; Ket a donc rempli ces
     * colonnes elle-même — un « Client » découpé dans le préfixe des références, un
     * « Assureur Partenaire » posé en bouche-trou. Ce test remonte les trois colonnes
     * depuis le graphe réel (tranche → cotation → assureur, cotation → piste → client,
     * cotation → avenant pour la police), joint en SQL et non deviné.
     */
    public function testLaListeTransversaleRemonteClientAssureurEtPoliceDepuisLaBase(): void
    {
        ['entreprise' => $entreprise, 'gestionnaire' => $gestionnaire] = $this->seed();

        $result = $this->tool()->execute([], new AiScope($entreprise, $gestionnaire));

        $this->assertSame(AiToolResult::STATUS_OK, $result->status);
        $this->assertCount(2, $result->data['items']);
        foreach ($result->data['items'] as $ligne) {
            $this->assertSame('Client PP A', $ligne['client']);
            $this->assertSame('Assureur PP A', $ligne['assureur']);
            $this->assertSame('POL-PP-A', $ligne['police']);
            $this->assertSame('Tranche PP A', $ligne['tranche'], 'Le rattachement est aplati, jamais imbriqué.');
        }

        // La présentation déclarée voyage avec les données : c'est elle qui fixe l'ordre
        // des colonnes, l'alignement des montants et la ligne de totaux.
        $this->assertSame(
            ['date', 'client', 'assureur', 'police', 'reference', 'montant'],
            array_keys($result->data['presentation']['colonnes']),
        );
        $this->assertSame(['montant'], $result->data['presentation']['totaliser']);
    }

    /**
     * LE COÛT DE CES TROIS COLONNES : borné, et indépendant du nombre de lignes.
     *
     * Sans préchargement, chaque ligne parcourrait seule tranche → cotation → assureur,
     * → piste → client et → avenants : une soixantaine de requêtes pour vingt lignes.
     * prechargerContexte() hydrate le graphe to-one en UNE requête et les avenants en
     * une seconde. On vérifie donc que passer de deux lignes à deux lignes de plus ne
     * coûte AUCUNE requête supplémentaire — la seule preuve qui vaille, un compte absolu
     * dépendant du reste du moteur de recherche.
     */
    public function testLeContexteDesLignesEstPrechargeEtNonChargeLigneParLigne(): void
    {
        ['entreprise' => $entrepriseSemee, 'gestionnaire' => $gestionnaireSeme, 'tranche' => $trancheSemee] = $this->seed();
        [$entrepriseId, $gestionnaireId, $trancheId] = [
            $entrepriseSemee->getId(), $gestionnaireSeme->getId(), $trancheSemee->getId(),
        ];
        $em = $this->em();

        // Le compteur VIDE l'EM avant de mesurer : sans cela, le graphe déjà hydraté par
        // le semis masquerait tout chargement paresseux. Il repart donc d'entités
        // fraîches, à chaque passage — d'où le travail par identifiants.
        $compter = function () use ($entrepriseId, $gestionnaireId, $em): int {
            $em->clear();
            // Les services du moteur d'indicateurs mémorisent leurs agrégats coûteux
            // (commissions, couverture des bordereaux, paramétrage fiscal) le temps d'une
            // requête HTTP, et le noyau de test en partage UNE seule instance entre les
            // deux mesures. Sans ce reset — que le conteneur fait lui-même à chaque
            // requête réelle — la seconde mesure repartirait d'un cache chaud et
            // coûterait, artificiellement, MOINS : on comparerait le froid au chaud.
            static::getContainer()->get('services_resetter')->reset();
            $entreprise = $em->getRepository(Entreprise::class)->find($entrepriseId);
            $gestionnaire = $em->getRepository(Invite::class)->find($gestionnaireId);

            $logger = new class implements \Doctrine\DBAL\Logging\SQLLogger {
                public int $nb = 0;

                public function startQuery($sql, ?array $params = null, ?array $types = null): void
                {
                    ++$this->nb;
                }

                public function stopQuery(): void
                {
                }
            };
            $config = $em->getConnection()->getConfiguration();
            $precedent = $config->getSQLLogger();
            $config->setSQLLogger($logger);

            try {
                $this->tool()->execute([], new AiScope($entreprise, $gestionnaire));
            } finally {
                $config->setSQLLogger($precedent);
            }

            return $logger->nb;
        };

        $avecDeuxLignes = $compter();

        $em->clear();
        $entreprise = $em->getRepository(Entreprise::class)->find($entrepriseId);
        $tranche = $em->getRepository(Tranche::class)->find($trancheId);
        $this->makeSignalement($entreprise, $tranche, 'PP-A-003', 150.0, '-3 days');
        $this->makeSignalement($entreprise, $tranche, 'PP-A-004', 250.0, '-2 days');
        $em->flush();

        $avecQuatreLignes = $compter();

        $this->assertSame(
            $avecDeuxLignes,
            $avecQuatreLignes,
            'Doubler les lignes ne doit coûter aucune requête de plus : le contexte est préchargé.',
        );
    }

    /**
     * LE COÛT DE L'ÉCONOMIE, mesuré par TRANCHE et non par ligne.
     *
     * Hydrater les indicateurs d'une tranche fait parcourir tout le graphe de sa cotation
     * (revenus, chargements, articles, avenants) : sans préchargement, chaque tranche de
     * la page paierait sa propre rafale. preloadTrancheRelations la groupe pour TOUTES les
     * tranches en un nombre fixe de requêtes ; ne reste, par tranche, que ce que la
     * stratégie de calcul va chercher hors du graphe (le paramétrage des taxes).
     *
     * On mesure donc le coût MARGINAL d'une tranche supplémentaire et on le plafonne. Un
     * plafond, pas une égalité : le contraire de la mesure ligne à ligne, où l'on exige
     * zéro. C'est le compromis assumé — l'information manquait, elle coûte quelque chose.
     */
    public function testLeCoutMarginalDUneTrancheSupplementaireResteBorne(): void
    {
        ['entreprise' => $entrepriseSemee, 'gestionnaire' => $gestionnaireSeme] = $this->seed();
        [$entrepriseId, $gestionnaireId] = [$entrepriseSemee->getId(), $gestionnaireSeme->getId()];
        $em = $this->em();

        $compter = function () use ($entrepriseId, $gestionnaireId, $em): int {
            $em->clear();
            static::getContainer()->get('services_resetter')->reset();
            $entreprise = $em->getRepository(Entreprise::class)->find($entrepriseId);
            $gestionnaire = $em->getRepository(Invite::class)->find($gestionnaireId);

            $logger = new class implements \Doctrine\DBAL\Logging\SQLLogger {
                public int $nb = 0;

                public function startQuery($sql, ?array $params = null, ?array $types = null): void
                {
                    ++$this->nb;
                }

                public function stopQuery(): void
                {
                }
            };
            $config = $em->getConnection()->getConfiguration();
            $precedent = $config->getSQLLogger();
            $config->setSQLLogger($logger);

            try {
                $this->tool()->execute([], new AiScope($entreprise, $gestionnaire));
            } finally {
                $config->setSQLLogger($precedent);
            }

            return $logger->nb;
        };

        $uneTranche = $compter();

        // Deux tranches de PLUS, chacune avec son signalement : trois tranches distinctes.
        $em->clear();
        $entreprise = $em->getRepository(Entreprise::class)->find($entrepriseId);
        $gestionnaire = $em->getRepository(Invite::class)->find($gestionnaireId);
        foreach (['C', 'D'] as $suffixe) {
            $autre = $this->makeChaine($entreprise, $gestionnaire, $suffixe, 500.0, true);
            $this->makeSignalement($entreprise, $autre, 'PP-' . $suffixe . '-001', 500.0, '-1 day');
        }
        $em->flush();

        $troisTranches = $compter();

        // Mesuré : 18 requêtes pour une tranche, 20 pour trois — UNE requête marginale par
        // tranche. C'était CINQ avant la mémoïsation du paramétrage fiscal dans
        // TrancheIndicatorStrategy (quatre findOneBy par tranche pour relire deux lignes
        // de configuration invariantes). Le plafond garde un cran de marge.
        $marginal = ($troisTranches - $uneTranche) / 2;
        $this->assertLessThanOrEqual(
            2,
            $marginal,
            sprintf(
                'Coût marginal par tranche : %.1f requêtes (%d → %d). Au-delà, c\'est que le '
                . 'préchargement du graphe de cotation ne joue plus.',
                $marginal,
                $uneTranche,
                $troisTranches,
            ),
        );
    }

    /**
     * L'INCIDENT DU 2026-08-11, contre la VRAIE base et le VRAI moteur d'indicateurs.
     *
     * « Ajoute une colonne pour afficher les commissions exigibles relatives à toutes ces
     * tranches » — Ket répondait que ces commissions ne figuraient pas dans les résultats.
     * Elles y figurent désormais, calculées par la source unique du projet (celle qui
     * alimente la rubrique Tranches), et non recalculées à côté.
     *
     * Les chiffres attendus tiennent au fixture : commission fixe de 100, TVA assureur à
     * 16 % (donc TTC 116), ARCA courtier à 2 % — et surtout la commission est EXIGIBLE,
     * puisque la prime de 1000 a été intégralement signalée (400 + 200 ne suffiraient pas,
     * d'où le troisième signalement).
     */
    public function testLaListeTransversaleRendLaCommissionSesTaxesEtSonExigibilite(): void
    {
        ['entreprise' => $entreprise, 'gestionnaire' => $gestionnaire, 'tranche' => $tranche] = $this->seed();
        $this->makeSignalement($entreprise, $tranche, 'PP-A-003', 400.0, '-1 day'); // prime soldée
        $this->em()->flush();
        $this->connecter();

        $result = $this->tool()->execute([], new AiScope($entreprise, $gestionnaire));

        $this->assertSame(AiToolResult::STATUS_OK, $result->status);
        $this->assertCount(3, $result->data['items']);

        foreach ($result->data['items'] as $ligne) {
            $this->assertSame($tranche->getId(), $ligne['trancheId']);
            $this->assertSame(1000.0, $ligne['primeTranche']);
            $this->assertSame(1000.0, $ligne['primeSignalee']);
            $this->assertSame(0.0, $ligne['primeSolde']);

            // Deux mondes de taxes, jamais confondus : celles-ci portent sur la
            // COMMISSION, et le TTC n'inclut que la taxe de l'assureur.
            $this->assertSame(self::COMMISSION, $ligne['commissionHt']);
            $this->assertSame(16.0, $ligne['taxeAssureur']);
            $this->assertSame(self::TAUX_TVA, $ligne['tauxTaxeAssureur']);
            $this->assertSame(116.0, $ligne['commissionTtc']);
            $this->assertSame(2.0, $ligne['taxeCourtier']);
            $this->assertSame(self::TAUX_ARCA, $ligne['tauxTaxeCourtier']);

            // Prime intégralement réglée et commission non collectée : elle est exigible
            // auprès de l'assureur — c'est très exactement la colonne demandée.
            $this->assertSame(0.0, $ligne['commissionEncaissee']);
            $this->assertSame(116.0, $ligne['commissionSolde']);
            $this->assertSame(116.0, $ligne['commissionExigible']);
            $this->assertSame('Prime payée, commission due', $ligne['statutTranche']);
        }

        // TROIS lignes, UNE tranche : les cumuls ne comptent la commission qu'une fois.
        $this->assertSame(1, $result->data['economiePage']['nbTranches']);
        $this->assertSame(116.0, $result->data['economiePage']['commissionTtc']);
        $this->assertSame(116.0, $result->data['economiePage']['commissionExigible']);
        // Le montant des RÈGLEMENTS, lui, s'additionne bien ligne à ligne.
        $this->assertSame(1000.0, $result->data['montantPage']);
    }

    /**
     * Le mode ciblé rend la même matière, mais STRUCTURÉE : une seule tranche décrite,
     * rien ne sera rendu en tableau, et le taux d'une taxe reste collé à son montant.
     */
    public function testLeModeCibleRendLaCommissionStructuree(): void
    {
        ['entreprise' => $entreprise, 'gestionnaire' => $invite, 'tranche' => $tranche] = $this->seed();
        $this->connecter();

        $data = $this->tool()->execute(['trancheId' => $tranche->getId()], new AiScope($entreprise, $invite))->data;

        $this->assertSame('Client PP A', $data['tranche']['client']);
        $this->assertSame('Assureur PP A', $data['tranche']['assureur']);
        $this->assertSame('POL-PP-A', $data['tranche']['police']);
        $this->assertSame(self::COMMISSION, $data['commission']['ht']);
        $this->assertSame(16.0, $data['commission']['taxeAssureur']);
        $this->assertSame(self::TAUX_TVA, $data['commission']['tauxTaxeAssureur']);
        $this->assertSame(116.0, $data['commission']['ttc']);
        $this->assertSame(self::TAUX_ARCA, $data['commission']['tauxTaxeCourtier']);
        $this->assertSame(116.0, $data['commission']['solde']);

        // Prime non soldée (600 sur 1000) : la commission N'EST PAS encore exigible, et on
        // ne la proratise surtout pas sur ce qui a été réglé. C'est 0, et 0 se dit.
        $this->assertSame(400.0, $data['prime']['solde']);
        $this->assertSame(0.0, $data['commission']['exigible']);
        $this->assertArrayNotHasKey('commissionExigible', $data);
    }

    /**
     * Le moteur simulé retient le PREMIER outil dont le match aboutit : il ne suffit pas
     * que paiements_prime sache répondre, encore faut-il qu'aucun outil générique ne lui
     * coupe l'herbe sous le pied dans l'ordre réel du conteneur. C'est très exactement le
     * défaut constaté (Ket répondait à côté, sur la rubrique Paiements/trésorerie).
     */
    public function testLeMoteurSimuleRouteVersLOutilDedie(): void
    {
        ['entreprise' => $entreprise, 'gestionnaire' => $invite, 'tranche' => $tranche] = $this->seed();
        $engine = static::getContainer()->get(SimulatedAiEngine::class);

        $questions = [
            sprintf('Quels paiements de prime ont été signalés sur la tranche %d ?', $tranche->getId()),
            sprintf('La prime de la tranche %d a-t-elle été payée ?', $tranche->getId()),
            'Montre-moi les paiements de prime signalés',
        ];

        foreach ($questions as $question) {
            $reply = $engine->reply(new AiRequest(
                systemContext: [
                    'assistantNom'   => 'Ket',
                    'entrepriseNom'  => self::ENTREPRISE_NOM,
                    'perimetre'      => ['owner' => true, 'gestionnaire' => true, 'modules' => []],
                    'date'           => (new \DateTimeImmutable('now'))->format('Y-m-d'),
                    'objetsAttaches' => [],
                ],
                messages: [['role' => 'user', 'content' => $question]],
                scope: new AiScope($entreprise, $invite),
            ));

            $this->assertSame('paiements_prime', $reply->toolUsed, $question);
            $this->assertFalse($reply->refused, $question);
        }

        // L'ACTION reste à l'outil d'action : la lecture ne lui vole pas la question.
        // signaler_paiement_prime prépare désormais un plan (délégation à
        // preparer_operations) → il construit le PaiementPrimeType, dont l'autocomplete
        // exige un utilisateur connecté : on le pose comme le fait le chat authentifié.
        $ownerUser = $this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => self::OWNER_EMAIL]);
        static::getContainer()->get('security.token_storage')->setToken(
            new UsernamePasswordToken($ownerUser, 'main', $ownerUser->getRoles()),
        );
        $action = $engine->reply(new AiRequest(
            systemContext: [
                'assistantNom'   => 'Ket',
                'entrepriseNom'  => self::ENTREPRISE_NOM,
                'perimetre'      => ['owner' => true, 'gestionnaire' => true, 'modules' => []],
                'date'           => (new \DateTimeImmutable('now'))->format('Y-m-d'),
                'objetsAttaches' => [],
            ],
            messages: [['role' => 'user', 'content' => sprintf('Signale le paiement de la prime de la tranche %d', $tranche->getId())]],
            scope: new AiScope($entreprise, $invite),
        ));
        $this->assertSame('signaler_paiement_prime', $action->toolUsed);
    }

    public function testRattachementClientEtPeriodeFiltrentEnSql(): void
    {
        ['entreprise' => $entreprise, 'gestionnaire' => $invite] = $this->seed();
        $client = $this->em()->getRepository(Client::class)->findOneBy(['nom' => 'Client PP A']);

        $parClient = $this->tool()->execute(
            ['lieA' => ['entite' => 'Client', 'id' => $client->getId()]],
            new AiScope($entreprise, $invite),
        );
        $this->assertSame(AiToolResult::STATUS_OK, $parClient->status);
        $this->assertSame(2, $parClient->data['total'], 'Chemin tranche.cotation.piste.client valide.');

        // Fenêtre de règlement : seul le signalement récent (-5 j) doit remonter.
        $recent = $this->tool()->execute(
            ['du' => (new \DateTimeImmutable('-10 days'))->format('Y-m-d')],
            new AiScope($entreprise, $invite),
        );
        $this->assertSame(1, $recent->data['total']);
        $this->assertSame('PP-A-002', $recent->data['items'][0]['reference']);
    }
}
