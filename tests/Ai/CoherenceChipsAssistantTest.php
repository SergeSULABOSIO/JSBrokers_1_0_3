<?php

namespace App\Tests\Ai;

use App\Ai\Scope\AiScope;
use App\Ai\Tool\AiToolResult;
use App\Ai\Tool\CompterEntitesTool;
use App\Ai\Tool\RechercherEntitesTool;
use App\Ai\Tool\VigieEcheancesTool;
use App\Entity\Avenant;
use App\Entity\ChargementPourPrime;
use App\Entity\Client;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Piste;
use App\Entity\Portefeuille;
use App\Entity\Tranche;
use App\Entity\Utilisateur;
use App\Services\DashboardDataProvider;
use App\Services\JSBDynamicSearchService;
use App\Services\Search\AvenantEcheanceScope;
use App\Services\Search\CotationSouscriptionScope;
use App\Services\Search\PisteTransformationScope;
use App\Services\Search\PortefeuilleCritereFactory;
use App\Services\Search\PortefeuilleScope;
use App\Services\Search\TranchePaiementScope;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * COHÉRENCE barre de chips (UI) ⇔ assistant IA (Ket).
 *
 * Un utilisateur qui clique un chip et un utilisateur qui pose la même question à Ket
 * doivent obtenir LE MÊME résultat. La garantie est structurelle : les outils génériques
 * de l'assistant (compter_entites / rechercher_entites) injectent les MÊMES critères que
 * la rubrique (AvenantEcheanceScope / TranchePaiementScope pour les chips,
 * PortefeuilleCritereFactory pour le badge « Mon portefeuille ») et traversent donc le
 * MÊME moteur (JSBDynamicSearchService). Ce test le vérifie de bout en bout, sur les deux
 * rubriques concernées, pour CHAQUE valeur de chip.
 *
 * RÈGLE DE CONCEPTION DE CE TEST : la référence « ce que l'utilisateur voit » n'est JAMAIS
 * écrite à la main ici — elle est construite par les mêmes fabriques de critères que
 * ControllerUtilsTrait::getInitialSearchCriteria. Une version antérieure de ce fichier
 * fabriquait sa référence avec le seul critère de chip : elle a laissé passer une
 * divergence de périmètre (Ket comptait les avenants de TOUS les portefeuilles, l'écran
 * ceux du seul gestionnaire connecté). Toute nouvelle dimension de filtrage par défaut est
 * désormais couverte automatiquement.
 */
class CoherenceChipsAssistantTest extends KernelTestCase
{
    private const OWNER_EMAIL = 'phpunit-coherence-ia@test.local';
    private const ENTREPRISE_NOM = 'PHPUnit Coherence IA SARL';

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

    private function service(): JSBDynamicSearchService
    {
        return static::getContainer()->get(JSBDynamicSearchService::class);
    }

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();

        // GOTCHA — le double lien Piste::avenantDeBase ⇄ Avenant::pisteDeRenouvellement est
        // un cycle de clés étrangères : il faut le DISSOCIER avant tout DELETE, sinon la
        // suppression des avenants se heurte à piste.avenant_de_base_id.
        foreach ([
            'UPDATE piste p JOIN entreprise e ON p.entreprise_id = e.id SET p.avenant_de_base_id = NULL WHERE e.nom = :nom',
            'UPDATE avenant a JOIN entreprise e ON a.entreprise_id = e.id SET a.piste_de_renouvellement_id = NULL WHERE e.nom = :nom',
        ] as $dissociation) {
            $conn->executeStatement($dissociation, ['nom' => self::ENTREPRISE_NOM]);
        }

        foreach (['avenant', 'tranche', 'chargement_pour_prime', 'cotation', 'piste', 'client', 'portefeuille', 'invite'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :nom",
                ['nom' => self::ENTREPRISE_NOM]
            );
        }
        $conn->executeStatement("DELETE FROM entreprise WHERE nom = :nom", ['nom' => self::ENTREPRISE_NOM]);
        $conn->executeStatement("DELETE FROM utilisateur WHERE email = :email", ['email' => self::OWNER_EMAIL]);
    }

    /**
     * Avenants répartis dans les quatre fenêtres d'échéance + deux tranches (une échue
     * impayée, une à échoir impayée) sur une cotation à prime réelle — le tout rattaché à un
     * portefeuille géré par l'invité.
     *
     * S'y ajoute un SECOND client, hors de ce portefeuille (géré par un autre collaborateur),
     * porteur de son propre avenant échu et de sa propre tranche impayée : c'est la
     * reproduction exacte de l'écart signalé en production (Ket annonçait 6 avenants échus
     * quand la rubrique en affichait 5). Sans cette donnée « hors périmètre », le test ne
     * distingue pas un outil correctement scopé d'un outil qui ne l'est pas.
     *
     * @return array{entreprise: Entreprise, invite: Invite}
     */
    private function seed(): array
    {
        $em = $this->em();

        $owner = new Utilisateur();
        $owner->setEmail(self::OWNER_EMAIL)->setNom('PHPUnit Coherence')->setVerified(true)->setPassword('irrelevant');
        $em->persist($owner);

        $entreprise = new Entreprise();
        $entreprise->setNom(self::ENTREPRISE_NOM)->setLicence('LIC-CO')->setAdresse('1 rue Cohérence')
            ->setTelephone('+243000000011')->setRccm('RCCM-CO')->setIdnat('IDNAT-CO')->setNumimpot('IMP-CO');
        $entreprise->setUtilisateur($owner);
        $em->persist($entreprise);

        // Propriétaire : contourne le fail-closed des droits (les outils IA sont fail-closed).
        $invite = new Invite();
        $invite->setNom('Propriétaire Cohérence')->setUtilisateur($owner)->setEntreprise($entreprise)->setProprietaire(true);
        $em->persist($invite);

        // Portefeuille de l'invité : c'est lui que la rubrique filtre par défaut.
        $portefeuille = (new Portefeuille())->setNom('Portefeuille Cohérence');
        $portefeuille->setGestionnaire($invite);
        $portefeuille->setEntreprise($entreprise);
        $em->persist($portefeuille);

        $client = (new Client())->setNom('Client Cohérence')->setExonere(false);
        $client->setEntreprise($entreprise);
        $portefeuille->addClient($client);
        $em->persist($client);

        $piste = (new Piste())->setNom('Piste Cohérence')->setTypeAvenant(0)
            ->setDescriptionDuRisque('Risque cohérence')->setExercice(2026)
            ->setClient($client)->setEntreprise($entreprise)->setInvite($invite);
        $em->persist($piste);

        $cotation = (new Cotation())->setNom('Cotation Cohérence')->setDuree(365);
        $cotation->setPiste($piste);
        $cotation->setEntreprise($entreprise);
        $em->persist($cotation);

        $chargement = (new ChargementPourPrime())->setNom('Prime Cohérence')
            ->setMontantFlatExceptionel(1000.0)->setCotation($cotation);
        $chargement->setEntreprise($entreprise);
        $em->persist($chargement);

        // Cotation concurrente CADUQUE (AUCUN avenant) sur la MÊME piste que la souscrite : la
        // cotation ci-dessus deviendra « souscrite » (ses 4 avenants), donc sa piste est « bound »
        // et cette proposition rivale a perdu le marché (« caduque », plus « en attente »).
        $cotationCaduque = (new Cotation())->setNom('Cotation Caduque')->setDuree(365);
        $cotationCaduque->setPiste($piste);
        $cotationCaduque->setEntreprise($entreprise);
        $em->persist($cotationCaduque);

        // Un avenant par fenêtre d'échéance (échu / sous 30 j / 31-60 j / au-delà de 60 j).
        foreach ([['ECHU', '-10 days'], ['J10', '+10 days'], ['J45', '+45 days'], ['J90', '+90 days']] as [$ref, $delta]) {
            $fin = new \DateTimeImmutable($delta);
            $avenant = new Avenant();
            $avenant->setCotation($cotation)->setReferencePolice('POL-' . $ref)->setNumero('0')
                ->setDescription('Avenant ' . $ref)
                ->setStartingAt($fin->modify('-365 days'))->setEndingAt($fin);
            $avenant->setEntreprise($entreprise);
            $avenant->setInvite($invite);
            $em->persist($avenant);
        }

        // Police SIGNALÉE non renouvelable, dans le portefeuille de l'invité. Sans elle, le
        // cinquième chip serait comparé à 0 = 0 : vrai, mais sans rien prouver. Elle sort des
        // QUATRE fenêtres de dates et forme à elle seule le groupe des décisions.
        $avenantMarque = new Avenant();
        $avenantMarque->setCotation($cotation)->setReferencePolice('POL-NON-RENOUV')->setNumero('0')
            ->setDescription('Avenant non renouvelable')
            ->setStartingAt(new \DateTimeImmutable('-380 days'))
            ->setEndingAt(new \DateTimeImmutable('-15 days'));
        $avenantMarque->setEntreprise($entreprise);
        $avenantMarque->setInvite($invite);
        $avenantMarque->setNonRenouvelable(true);
        $avenantMarque->setNonRenouvelableMotif('Le client a vendu le véhicule.');
        $avenantMarque->setNonRenouvelablePar($invite);
        $em->persist($avenantMarque);

        // Piste « en cours » (aucune cotation transformée) DANS le portefeuille de l'invité : la
        // piste ci-dessus deviendra « transformée » (ses cotations à avenants), celle-ci reste
        // « en cours ». De quoi éprouver les deux chips de transformation de la rubrique Pistes.
        $pisteEnCours = (new Piste())->setNom('Piste En Cours')->setTypeAvenant(0)
            ->setDescriptionDuRisque('Risque en cours')->setExercice(2026)
            ->setClient($client)->setEntreprise($entreprise)->setInvite($invite);
        $em->persist($pisteEnCours);

        // Cotation VRAIMENT « en attente » : non souscrite, sur une piste NON bound (aucune
        // sœur souscrite) → encore en course. Distincte de la caduque ci-dessus. Ne change pas
        // le statut de pisteEnCours (toujours sans avenant = « en cours »).
        $cotationEnAttente = (new Cotation())->setNom('Cotation En Attente')->setDuree(365);
        $cotationEnAttente->setPiste($pisteEnCours);
        $cotationEnAttente->setEntreprise($entreprise);
        $em->persist($cotationEnAttente);

        // Deux tranches impayées : une échue, une à échoir (statut dérivé, filtre en mémoire).
        foreach ([['Tranche échue', 50, '-10 days'], ['Tranche à échoir', 0.5, '+10 days']] as [$nom, $pct, $delta]) {
            $tranche = (new Tranche())->setNom($nom)->setPourcentage($pct)
                ->setPayableAt(new \DateTimeImmutable('-60 days'))
                ->setEcheanceAt(new \DateTimeImmutable($delta));
            $tranche->setCotation($cotation);
            $tranche->setEntreprise($entreprise);
            $em->persist($tranche);
        }

        // HORS PÉRIMÈTRE : même entreprise, mais portefeuille d'un autre gestionnaire. Un
        // avenant échu et une tranche impayée qui ne doivent JAMAIS apparaître dans les
        // réponses de l'assistant tant que l'utilisateur n'élargit pas explicitement.
        $autreGestionnaire = new Invite();
        $autreGestionnaire->setNom('Autre Gestionnaire')->setEntreprise($entreprise)->setProprietaire(false);
        $em->persist($autreGestionnaire);

        $autrePortefeuille = (new Portefeuille())->setNom('Portefeuille Voisin');
        $autrePortefeuille->setGestionnaire($autreGestionnaire);
        $autrePortefeuille->setEntreprise($entreprise);
        $em->persist($autrePortefeuille);

        $clientVoisin = (new Client())->setNom('Client Voisin')->setExonere(false);
        $clientVoisin->setEntreprise($entreprise);
        $autrePortefeuille->addClient($clientVoisin);
        $em->persist($clientVoisin);

        $pisteVoisine = (new Piste())->setNom('Piste Voisine')->setTypeAvenant(0)
            ->setDescriptionDuRisque('Risque voisin')->setExercice(2026)
            ->setClient($clientVoisin)->setEntreprise($entreprise)->setInvite($autreGestionnaire);
        $em->persist($pisteVoisine);

        $cotationVoisine = (new Cotation())->setNom('Cotation Voisine')->setDuree(365);
        $cotationVoisine->setPiste($pisteVoisine);
        $cotationVoisine->setEntreprise($entreprise);
        $em->persist($cotationVoisine);

        $chargementVoisin = (new ChargementPourPrime())->setNom('Prime Voisine')
            ->setMontantFlatExceptionel(1000.0)->setCotation($cotationVoisine);
        $chargementVoisin->setEntreprise($entreprise);
        $em->persist($chargementVoisin);

        $finVoisin = new \DateTimeImmutable('-20 days');
        $avenantVoisin = new Avenant();
        $avenantVoisin->setCotation($cotationVoisine)->setReferencePolice('POL-VOISIN')->setNumero('0')
            ->setDescription('Avenant Voisin')
            ->setStartingAt($finVoisin->modify('-365 days'))->setEndingAt($finVoisin);
        $avenantVoisin->setEntreprise($entreprise);
        $avenantVoisin->setInvite($autreGestionnaire);
        $em->persist($avenantVoisin);

        $trancheVoisine = (new Tranche())->setNom('Tranche Voisine')->setPourcentage(50)
            ->setPayableAt(new \DateTimeImmutable('-60 days'))
            ->setEcheanceAt(new \DateTimeImmutable('-10 days'));
        $trancheVoisine->setCotation($cotationVoisine);
        $trancheVoisine->setEntreprise($entreprise);
        $em->persist($trancheVoisine);

        $em->flush();
        $entrepriseId = $entreprise->getId();
        $inviteId = $invite->getId();
        $em->clear();

        return [
            'entreprise' => $this->em()->getRepository(Entreprise::class)->find($entrepriseId),
            'invite' => $this->em()->getRepository(Invite::class)->find($inviteId),
        ];
    }

    private function compter(): CompterEntitesTool
    {
        return static::getContainer()->get(CompterEntitesTool::class);
    }

    private function rechercher(): RechercherEntitesTool
    {
        return static::getContainer()->get(RechercherEntitesTool::class);
    }

    /**
     * Les critères que la RUBRIQUE applique réellement au premier chargement : le chip
     * demandé PLUS le périmètre portefeuille, produit par la fabrique dont se sert
     * ControllerUtilsTrait::getInitialSearchCriteria. Aucun critère n'est écrit à la main :
     * c'est ce qui rend ce test capable de détecter une divergence de périmètre.
     *
     * @return array<string, mixed>
     */
    private function criteresRubrique(string $shortName, Invite $invite, string $cleChip, string $valeurChip): array
    {
        $factory = static::getContainer()->get(PortefeuilleCritereFactory::class);

        return [$cleChip => $valeurChip] + $factory->pour($shortName, $invite);
    }

    /**
     * Pour CHAQUE chip de la rubrique Avenants : le compte affiché par la liste et le compte
     * annoncé par Ket doivent être identiques, et la liste de Ket doit restituer les mêmes
     * enregistrements dans le même ordre (tri par urgence).
     */
    public function testAvenantsChaqueChipCoincideAvecLAssistant(): void
    {
        ['entreprise' => $entreprise, 'invite' => $invite] = $this->seed();
        $scope = new AiScope($entreprise, $invite);

        foreach (array_keys(AvenantEcheanceScope::VALEURS) as $statut) {
            // Ce que la rubrique affiche : chip + périmètre portefeuille, comme au premier
            // chargement de la liste.
            $chip = $this->service()->search(
                Avenant::class,
                $this->criteresRubrique('Avenant', $invite, AvenantEcheanceScope::CRITERION_KEY, $statut),
                $entreprise,
            );
            $idsChip = array_map(static fn (Avenant $a) => $a->getId(), $chip['data']);

            // Ce que Ket répond à « combien d'avenants … ? ».
            $compte = $this->compter()->execute(['entite' => 'Avenant', 'echeance' => $statut], $scope);
            $this->assertSame(AiToolResult::STATUS_OK, $compte->status, "Chip {$statut}");
            $this->assertSame(
                (int) $chip['totalItems'],
                $compte->data['count'],
                "Chip « {$statut} » : le comptage de Ket doit égaler celui de la rubrique."
            );

            // Ce que Ket répond à « quels avenants … ? ».
            $liste = $this->rechercher()->execute(['entite' => 'Avenant', 'echeance' => $statut], $scope);
            $this->assertSame(AiToolResult::STATUS_OK, $liste->status);
            $this->assertSame(
                $idsChip,
                array_column($liste->data['items'], 'id'),
                "Chip « {$statut} » : mêmes enregistrements, même ordre d'urgence."
            );
        }
    }

    /**
     * Idem pour la rubrique Tranches (soldes dérivés, filtrés/triés en mémoire), mais la
     * rubrique porte QUATRE groupes de chips indépendants et cumulables. On balaie donc
     * chaque valeur de chaque axe SEULE, puis les paires qui remplacent les anciens statuts
     * composites — c'est là que la parité chip ⇔ Ket peut le plus facilement casser.
     */
    public function testTranchesChaqueChipCoincideAvecLAssistant(): void
    {
        ['entreprise' => $entreprise, 'invite' => $invite] = $this->seed();
        $scope = new AiScope($entreprise, $invite);

        // Chaque axe seul…
        $combinaisons = [];
        foreach (TranchePaiementScope::AXES as $cle => $axe) {
            foreach (array_keys($axe['valeurs']) as $valeur) {
                $combinaisons[$axe['nom'] . '=' . $valeur] = [$cle => $valeur];
            }
        }
        // …puis les compositions qui portent le sens métier.
        $combinaisons['commission exigible'] = [
            TranchePaiementScope::AXE_PRIME => TranchePaiementScope::PAYEE,
            TranchePaiementScope::AXE_COMMISSION => TranchePaiementScope::IMPAYEE,
        ];
        $combinaisons['primes en retard'] = [
            TranchePaiementScope::AXE_PRIME => TranchePaiementScope::IMPAYEE,
            TranchePaiementScope::AXE_ECHEANCE => TranchePaiementScope::ECHUE,
        ];
        $combinaisons['tout soldé'] = [
            TranchePaiementScope::AXE_PRIME => TranchePaiementScope::PAYEE,
            TranchePaiementScope::AXE_COMMISSION => TranchePaiementScope::PAYEE,
        ];

        $factory = static::getContainer()->get(PortefeuilleCritereFactory::class);
        $vusNonVides = 0;

        foreach ($combinaisons as $libelle => $axes) {
            // Ce que la rubrique affiche : les chips posés + le périmètre portefeuille.
            $chip = $this->service()->search(
                Tranche::class,
                $axes + $factory->pour('Tranche', $invite),
                $entreprise,
            );
            $idsChip = array_map(static fn (Tranche $t) => $t->getId(), $chip['data']);
            $vusNonVides += $idsChip === [] ? 0 : 1;

            // Ce que Ket répond, avec les MÊMES axes exprimés en noms courts.
            $args = ['entite' => 'Tranche', 'axes' => []];
            foreach ($axes as $cle => $valeur) {
                $args['axes'][TranchePaiementScope::AXES[$cle]['nom']] = $valeur;
            }

            $compte = $this->compter()->execute($args, $scope);
            $this->assertSame(AiToolResult::STATUS_OK, $compte->status, "Chips « {$libelle} »");
            $this->assertSame(
                (int) $chip['totalItems'],
                $compte->data['count'],
                "Chips « {$libelle} » : le comptage de Ket doit égaler celui de la rubrique."
            );

            $liste = $this->rechercher()->execute($args, $scope);
            $this->assertSame(
                $idsChip,
                array_column($liste->data['items'], 'id'),
                "Chips « {$libelle} » : mêmes tranches, même ordre d'urgence."
            );
        }

        // Un vert sur des ensembles tous vides ne prouverait rien : le semis doit
        // réellement alimenter plusieurs combinaisons.
        $this->assertGreaterThan(2, $vusNonVides, 'Le semis doit peupler plusieurs combinaisons d\'axes.');
    }

    /**
     * COMPLÉMENTARITÉ À L'ÉCRAN. Les deux valeurs d'un même axe partitionnent la rubrique :
     * disjointes, et leur réunion couvre toutes les tranches suivies (sauf le hors-champ
     * explicite de la rétro et de l'échéance). C'est ce qui rend impossible le retour d'un
     * filtre ambigu du type « impayées = prime OU commission ».
     */
    public function testLesDeuxValeursDUnAxePartitionnentLaRubrique(): void
    {
        ['entreprise' => $entreprise, 'invite' => $invite] = $this->seed();
        $factory = static::getContainer()->get(PortefeuilleCritereFactory::class);
        $perimetre = $factory->pour('Tranche', $invite);

        $ids = fn (array $axes): array => array_map(
            static fn (Tranche $t) => $t->getId(),
            $this->service()->search(Tranche::class, $axes + $perimetre, $entreprise)['data'],
        );

        // Référence : toutes les tranches suivies (aucun axe posé, hors « N/A »).
        $suivies = $ids([TranchePaiementScope::AXE_PRIME => TranchePaiementScope::PAYEE]);
        $suivies = array_merge($suivies, $ids([TranchePaiementScope::AXE_PRIME => TranchePaiementScope::IMPAYEE]));

        foreach ([TranchePaiementScope::AXE_PRIME, TranchePaiementScope::AXE_COMMISSION] as $axe) {
            $payees = $ids([$axe => TranchePaiementScope::PAYEE]);
            $impayees = $ids([$axe => TranchePaiementScope::IMPAYEE]);

            $this->assertSame([], array_intersect($payees, $impayees), "Axe {$axe} : valeurs DISJOINTES.");
            $this->assertEqualsCanonicalizing(
                $suivies,
                array_merge($payees, $impayees),
                "Axe {$axe} : la réunion des deux valeurs couvre toutes les tranches suivies."
            );
        }
    }

    /**
     * Idem pour la rubrique Propositions (Cotations) : le statut de souscription (« souscrite »
     * dès qu'un avenant existe, « en attente » sinon) est filtré en SQL (EXISTS / NOT EXISTS).
     * Les outils génériques de Ket doivent passer par le même critère synthétique et donc par
     * le même moteur, scope portefeuille inclus.
     */
    public function testCotationsChaqueChipCoincideAvecLAssistant(): void
    {
        ['entreprise' => $entreprise, 'invite' => $invite] = $this->seed();
        $scope = new AiScope($entreprise, $invite);

        foreach (array_keys(CotationSouscriptionScope::VALEURS) as $statut) {
            $chip = $this->service()->search(
                Cotation::class,
                $this->criteresRubrique('Cotation', $invite, CotationSouscriptionScope::CRITERION_KEY, $statut),
                $entreprise,
            );
            $idsChip = array_map(static fn (Cotation $c) => $c->getId(), $chip['data']);

            $compte = $this->compter()->execute(['entite' => 'Cotation', 'validation' => $statut], $scope);
            $this->assertSame(AiToolResult::STATUS_OK, $compte->status, "Chip {$statut}");
            $this->assertSame(
                (int) $chip['totalItems'],
                $compte->data['count'],
                "Chip « {$statut} » : le comptage de Ket doit égaler celui de la rubrique."
            );

            $liste = $this->rechercher()->execute(['entite' => 'Cotation', 'validation' => $statut], $scope);
            $this->assertSame(AiToolResult::STATUS_OK, $liste->status);
            $this->assertSame(
                $idsChip,
                array_column($liste->data['items'], 'id'),
                "Chip « {$statut} » : mêmes propositions, même ordre."
            );
        }

        // Preuve que chaque chip isole bien sa part : dans le portefeuille de l'invité
        // coexistent une cotation souscrite (avec avenants, sur « Piste Cohérence »), une
        // caduque (rivale non souscrite sur cette MÊME piste bound) et une en attente (non
        // souscrite sur « Piste En Cours », piste non bound). Les trois groupes sont disjoints.
        $souscrites = $this->compter()->execute(['entite' => 'Cotation', 'validation' => CotationSouscriptionScope::STATUT_SOUSCRITES], $scope);
        $enAttente = $this->compter()->execute(['entite' => 'Cotation', 'validation' => CotationSouscriptionScope::STATUT_EN_ATTENTE], $scope);
        $caduques = $this->compter()->execute(['entite' => 'Cotation', 'validation' => CotationSouscriptionScope::STATUT_CADUQUES], $scope);
        $this->assertSame(1, $souscrites->data['count'], 'La seule cotation à avenants du portefeuille.');
        $this->assertSame(1, $enAttente->data['count'], 'La seule cotation sans avenant sur une piste non bound (« Cotation Voisine » est hors périmètre).');
        $this->assertSame(1, $caduques->data['count'], 'La seule proposition concurrente perdante (même piste que la souscrite).');
    }

    /**
     * Idem pour la rubrique Pistes (opportunités) : le statut de transformation (« transformée »
     * dès qu'une cotation est souscrite, « en cours » sinon) est filtré en SQL (EXISTS / NOT
     * EXISTS sur les avenants des cotations de la piste). Les outils génériques de Ket doivent
     * passer par le même critère synthétique et donc par le même moteur, scope portefeuille inclus.
     */
    public function testPistesChaqueChipCoincideAvecLAssistant(): void
    {
        ['entreprise' => $entreprise, 'invite' => $invite] = $this->seed();
        $scope = new AiScope($entreprise, $invite);

        foreach (array_keys(PisteTransformationScope::VALEURS) as $statut) {
            $chip = $this->service()->search(
                Piste::class,
                $this->criteresRubrique('Piste', $invite, PisteTransformationScope::CRITERION_KEY, $statut),
                $entreprise,
            );
            $idsChip = array_map(static fn (Piste $p) => $p->getId(), $chip['data']);

            $compte = $this->compter()->execute(['entite' => 'Piste', 'transformation' => $statut], $scope);
            $this->assertSame(AiToolResult::STATUS_OK, $compte->status, "Chip {$statut}");
            $this->assertSame(
                (int) $chip['totalItems'],
                $compte->data['count'],
                "Chip « {$statut} » : le comptage de Ket doit égaler celui de la rubrique."
            );

            $liste = $this->rechercher()->execute(['entite' => 'Piste', 'transformation' => $statut], $scope);
            $this->assertSame(AiToolResult::STATUS_OK, $liste->status);
            $this->assertSame(
                $idsChip,
                array_column($liste->data['items'], 'id'),
                "Chip « {$statut} » : mêmes pistes, même ordre."
            );
        }

        // Preuve que chaque chip isole bien sa moitié : une piste transformée (cotation à
        // avenants) et une en cours (sans) coexistent dans le portefeuille de l'invité.
        $transformees = $this->compter()->execute(['entite' => 'Piste', 'transformation' => PisteTransformationScope::STATUT_TRANSFORMEES], $scope);
        $enCours = $this->compter()->execute(['entite' => 'Piste', 'transformation' => PisteTransformationScope::STATUT_EN_COURS], $scope);
        $this->assertSame(1, $transformees->data['count'], 'La seule piste transformée du portefeuille.');
        $this->assertSame(1, $enCours->data['count'], 'La seule piste en cours du portefeuille (« Piste Voisine » est hors périmètre).');
    }

    /**
     * Sans filtre de chip, la rubrique reste bornée au portefeuille de l'invité : l'outil
     * doit l'être aussi, et annoncer le périmètre appliqué.
     */
    public function testSansChipLePerimetrePortefeuilleSApplique(): void
    {
        ['entreprise' => $entreprise, 'invite' => $invite] = $this->seed();
        $scope = new AiScope($entreprise, $invite);

        // 5 : les 4 avenants des fenêtres d'échéance PLUS la police signalée non renouvelable.
        // Sans chip, aucune interception n'a lieu : le décompte est celui de la rubrique en
        // mode « Toutes », où une police écartée du pipeline reste bien visible.
        $compte = $this->compter()->execute(['entite' => 'Avenant'], $scope);
        $this->assertSame(5, $compte->data['count'], 'Les 5 avenants du portefeuille, pas les 6 de l\'entreprise.');
        $this->assertSame('Portefeuille Cohérence', $compte->data['perimetre'], 'Le périmètre appliqué est annoncé.');
        $this->assertArrayNotHasKey('filtre', $compte->data, 'Aucun filtre annoncé quand aucun n\'est demandé.');

        $compteTranches = $this->compter()->execute(['entite' => 'Tranche'], $scope);
        $this->assertSame(2, $compteTranches->data['count']);

        // Une valeur inconnue est ignorée (pas d'erreur, pas de filtre appliqué).
        $compteInvalide = $this->compter()->execute(['entite' => 'Avenant', 'echeance' => 'valeur-inconnue'], $scope);
        $this->assertSame(5, $compteInvalide->data['count']);

        // Le filtre d'une rubrique ne fuit jamais vers une autre entité.
        $compteCroise = $this->compter()->execute(['entite' => 'Client', 'echeance' => AvenantEcheanceScope::STATUT_ECHUS], $scope);
        $this->assertSame(1, $compteCroise->data['count'], 'Le filtre échéance ne s\'applique qu\'aux avenants.');
    }

    /**
     * RÉGRESSION HISTORIQUE (l'incident) : « combien d'avenants échus dans mon portefeuille ? »
     * — l'assistant annonçait le total de l'entreprise (6) quand la rubrique en affichait 5,
     * l'écart venant d'un avenant appartenant au portefeuille d'un autre gestionnaire.
     */
    public function testAvenantsEchusDUnAutrePortefeuilleNeSontPasComptes(): void
    {
        ['entreprise' => $entreprise, 'invite' => $invite] = $this->seed();
        $scope = new AiScope($entreprise, $invite);

        $dansMonPortefeuille = $this->compter()->execute(
            ['entite' => 'Avenant', 'echeance' => AvenantEcheanceScope::STATUT_ECHUS],
            $scope
        );
        $this->assertSame(1, $dansMonPortefeuille->data['count'], 'Seul l\'avenant échu du portefeuille de l\'invité.');
        $this->assertSame('Portefeuille Cohérence', $dansMonPortefeuille->data['perimetre']);

        $liste = $this->rechercher()->execute(
            ['entite' => 'Avenant', 'echeance' => AvenantEcheanceScope::STATUT_ECHUS],
            $scope
        );
        $this->assertNotContains(
            'Avenant Voisin',
            array_column($liste->data['items'], 'libelle'),
            'L\'avenant du portefeuille voisin ne doit jamais apparaître.'
        );

        // Élargissement EXPLICITE : on retrouve alors l'avenant du portefeuille voisin.
        $dansToutLEntreprise = $this->compter()->execute(
            [
                'entite' => 'Avenant',
                'echeance' => AvenantEcheanceScope::STATUT_ECHUS,
                'perimetre' => PortefeuilleScope::PERIMETRE_ENTREPRISE,
            ],
            $scope
        );
        $this->assertSame(2, $dansToutLEntreprise->data['count']);
        $this->assertSame(PortefeuilleScope::LIBELLE_ENTREPRISE, $dansToutLEntreprise->data['perimetre']);
    }

    /**
     * Le suivi des impayés (rubrique Tranches) obéit à la même règle : la tranche impayée
     * du portefeuille voisin n'est comptée que sur demande explicite.
     */
    public function testSuiviImpayesRespecteLePerimetrePortefeuille(): void
    {
        ['entreprise' => $entreprise, 'invite' => $invite] = $this->seed();
        $scope = new AiScope($entreprise, $invite);
        $outil = static::getContainer()->get(\App\Ai\Tool\SuiviImpayesTool::class);

        $mien = $outil->execute(['axes' => ['prime' => TranchePaiementScope::IMPAYEE]], $scope);
        $this->assertSame(AiToolResult::STATUS_OK, $mien->status);
        $this->assertSame('Portefeuille Cohérence', $mien->data['perimetre']);
        $this->assertNotContains(
            'Tranche Voisine',
            array_map(static fn (array $ligne) => $ligne['tranche'] ?? null, $mien->data['lignes']),
            'La tranche du portefeuille voisin est hors périmètre.'
        );

        $global = $outil->execute([
            'axes' => ['prime' => TranchePaiementScope::IMPAYEE],
            'perimetre' => PortefeuilleScope::PERIMETRE_ENTREPRISE,
        ], $scope);
        $this->assertSame(PortefeuilleScope::LIBELLE_ENTREPRISE, $global->data['perimetre']);
        $this->assertGreaterThan($mien->data['total'], $global->data['total']);
    }

    /**
     * Moteur simulé : une question en langage naturel exprimant une fenêtre d'échéance ou un
     * statut de paiement doit produire l'argument de filtre correspondant — sans quoi Ket
     * répondrait sur la rubrique entière alors que l'utilisateur voit un chip actif.
     */
    public function testDetectionLangageNaturel(): void
    {
        $entreprise = new Entreprise();
        $scope = new AiScope($entreprise, new Invite());

        $cas = [
            "combien d'avenants échoient dans les 30 prochains jours ?" => ['echeance', AvenantEcheanceScope::STATUT_30J],
            'combien d\'avenants sont échus ?' => ['echeance', AvenantEcheanceScope::STATUT_ECHUS],
            'combien d\'avenants entre 31 et 60 jours ?' => ['echeance', AvenantEcheanceScope::STATUT_31_60J],
            'combien d\'avenants au-delà de 60 jours ?' => ['echeance', AvenantEcheanceScope::STATUT_60_PLUS],
        ];

        foreach ($cas as $question => [$cle, $attendu]) {
            $args = $this->compter()->match($question, $scope);
            $this->assertIsArray($args, "Question non reconnue : {$question}");
            $this->assertSame($attendu, $args[$cle] ?? null, "Question : {$question}");
        }

        // Tranches : la détection rend une COMBINAISON d'axes, pas un statut unique. La
        // dette évoquée doit être celle que l'utilisateur a nommée — c'est précisément ce
        // qui manquait quand « impayées » désignait deux dettes à la fois.
        // NB : « prime … payée » est capté en amont par PaiementPrimeIntent, qui route vers
        // l'outil dédié paiements_prime — d'où la formulation « soldée » ici, qui interroge
        // bien la RUBRIQUE et non le signalement de règlement.
        $casAxes = [
            'combien de tranches dont la prime est impayée ?' => ['prime' => TranchePaiementScope::IMPAYEE],
            'combien de tranches dont la prime est soldée ?' => ['prime' => TranchePaiementScope::PAYEE],
            'combien de tranches dont la commission est impayée ?' => ['commission' => TranchePaiementScope::IMPAYEE],
            'combien de tranches avec une commission exigible ?' => [
                'prime' => TranchePaiementScope::PAYEE,
                'commission' => TranchePaiementScope::IMPAYEE,
            ],
        ];

        foreach ($casAxes as $question => $attendu) {
            $args = $this->compter()->match($question, $scope);
            $this->assertIsArray($args, "Question non reconnue : {$question}");
            $this->assertSame($attendu, $args['axes'] ?? null, "Question : {$question}");
        }

        // Une question sans fenêtre exprimée ne pose AUCUN filtre (comptage global).
        $args = $this->compter()->match('combien d\'avenants ?', $scope);
        $this->assertArrayNotHasKey('echeance', $args);

        $args = $this->compter()->match('combien de tranches ?', $scope);
        $this->assertArrayNotHasKey('axes', $args);
    }

    /**
     * La question EXACTE de l'incident. Deux pièges s'y cumulaient :
     *  - « portefeuille » y désigne un périmètre, pas la rubrique interrogée (le lexique
     *    retenait pourtant Portefeuille, plus haut dans la carte de permissions) ;
     *  - « dans mon portefeuille » ne doit PAS être lu comme une demande d'élargissement :
     *    le portefeuille est déjà le périmètre par défaut.
     */
    public function testQuestionDeLIncidentRouteVersLesAvenantsEchus(): void
    {
        $scope = new AiScope(new Entreprise(), new Invite());

        $args = $this->compter()->match(
            "J'ai combien d'avenants, dans mon portefeuille, qui ont échu déjà ?",
            $scope
        );

        $this->assertSame('Avenant', $args['entite'], 'La rubrique interrogée est Avenant, pas Portefeuille.');
        $this->assertSame(AvenantEcheanceScope::STATUT_ECHUS, $args['echeance']);
        $this->assertArrayNotHasKey('perimetre', $args, 'Le périmètre par défaut (portefeuille) suffit.');
    }

    /**
     * À l'inverse, une demande explicite d'élargissement doit être détectée — sans quoi
     * l'utilisateur ne pourrait plus obtenir le chiffre du cabinet entier.
     */
    public function testDetectionDeLElargissementExplicite(): void
    {
        $scope = new AiScope(new Entreprise(), new Invite());

        foreach ([
            "combien d'avenants échus dans toute l'entreprise ?",
            "combien d'avenants échus sur tous les portefeuilles ?",
        ] as $question) {
            $args = $this->compter()->match($question, $scope);
            $this->assertSame(
                PortefeuilleScope::PERIMETRE_ENTREPRISE,
                $args['perimetre'] ?? null,
                "Question : {$question}"
            );
        }
    }

    /**
     * LE GARDE-FOU DE L'INCIDENT « plus aucune police échue ».
     *
     * La vigie de Ket passe par DashboardDataProvider (DQL écrit à la main), les chips par
     * JSBDynamicSearchService : deux chemins, un seul ensemble attendu. Ils avaient
     * divergé sur quatre points à la fois — borne basse à « now » (les échues ne pouvaient
     * pas entrer), borne haute inclusive et horodatée, filtre renewalCondition surnuméraire,
     * INNER JOIN sur un assureur nullable. Résultat : la rubrique affichait cinq polices
     * échues et l'assistant annonçait qu'il n'en restait aucune.
     *
     * Ce test confronte les deux chemins sur le MÊME jeu de données, en incluant tous les
     * SORTS de police (scellé / amorcé / sans suite / résilié), et sans écrire aucun total
     * à la main : c'est l'égalité des deux chemins qui est vérifiée, pas un nombre.
     */
    public function testVigieDeKetCoincideAvecLesChipsEchusEtSous30j(): void
    {
        ['entreprise' => $entreprise, 'invite' => $invite] = $this->seed();
        $this->ajouterCasDeSuccession($entreprise, $invite);
        $scope = new AiScope($entreprise, $invite);

        // Ce que l'utilisateur VOIT : les deux chips que la vigie couvre, à horizon 30.
        $idsChips = [];
        foreach ([AvenantEcheanceScope::STATUT_ECHUS, AvenantEcheanceScope::STATUT_30J] as $statut) {
            $chip = $this->service()->search(
                Avenant::class,
                $this->criteresRubrique('Avenant', $invite, AvenantEcheanceScope::CRITERION_KEY, $statut),
                $entreprise,
            );
            foreach ($chip['data'] as $avenant) {
                $idsChips[] = $avenant->getId();
            }
        }

        $volet = static::getContainer()->get(VigieEcheancesTool::class)
            ->execute(['volet' => 'renouvellements', 'horizonJours' => 30], $scope)
            ->data['volets']['renouvellements'];

        $idsVigie = array_merge(
            array_column($volet['echues']['lignes'], 'id'),
            array_column($volet['aVenir']['lignes'], 'id'),
        );

        // Le jeu de données doit être PARLANT : un test vert sur deux ensembles vides
        // ne prouverait rien.
        $this->assertGreaterThan(2, count($idsChips), 'Le jeu de données doit contenir plusieurs polices.');
        $this->assertSame(
            $idsChips,
            $idsVigie,
            'La vigie de Ket et les chips « Échus » + « Sous 30 jours » doivent désigner '
            . 'les MÊMES polices, dans le même ordre d\'urgence.'
        );
        $this->assertSame(
            count($idsChips),
            $volet['echues']['total'] + $volet['aVenir']['total'],
            'Les totaux de la vigie doivent égaler ceux des chips.'
        );
        $this->assertGreaterThan(0, $volet['echues']['total'], 'Les polices ÉCHUES doivent être VUES par la vigie.');

        // Les polices SIGNALÉES non renouvelables ne sont NI dans les chips d'échéance, NI
        // comptées dans le travail à faire — mais la vigie les ANNONCE tout de même, avec leur
        // motif. Les taire ferait dire à Ket « il ne reste plus rien » là où l'utilisateur voit
        // un chip et un onglet pleins.
        $this->assertArrayHasKey('nonRenouvelables', $volet, 'La vigie doit annoncer le groupe des décisions.');
        $this->assertGreaterThan(0, $volet['nonRenouvelables']['total']);
        $this->assertStringContainsString(
            'vendu le véhicule',
            (string) ($volet['nonRenouvelables']['lignes'][0]['motif'] ?? ''),
            'Le motif accompagne la ligne : c’est lui qui explique la décision.'
        );
        foreach (array_column($volet['nonRenouvelables']['lignes'], 'id') as $idMarque) {
            $this->assertNotContains($idMarque, $idsVigie, 'Une police signalée n’est pas du travail à faire.');
        }
    }

    /**
     * TRIPLE COHÉRENCE DU CINQUIÈME GROUPE : le chip de la rubrique, l'outil de Ket et
     * l'onglet du tableau de bord doivent désigner les MÊMES polices.
     *
     * Les trois lisent la même condition (Avenant::$nonRenouvelable) mais par trois chemins
     * distincts — interception SQL du moteur de recherche, outil générique, requête DQL dédiée
     * du tableau de bord. Rien n'empêcherait l'un des trois de dériver : c'est ce test qui
     * l'interdit, et il ne compare que les chemins entre eux, sans écrire aucun total.
     */
    public function testLeGroupeNonRenouvelablesEstAligneChipKetEtTableauDeBord(): void
    {
        ['entreprise' => $entreprise, 'invite' => $invite] = $this->seed();
        $scope = new AiScope($entreprise, $invite);

        // 1. Ce que la rubrique affiche sous le chip « Non renouvelables ».
        $chip = $this->service()->search(
            Avenant::class,
            $this->criteresRubrique('Avenant', $invite, AvenantEcheanceScope::CRITERION_KEY, AvenantEcheanceScope::STATUT_NON_RENOUVELABLES),
            $entreprise,
        );
        $idsChip = array_map(static fn (Avenant $a) => $a->getId(), $chip['data']);
        $this->assertNotEmpty($idsChip, 'Le jeu de données doit contenir au moins une police signalée.');

        // 2. Ce que Ket répond à « liste les polices non renouvelables ».
        $liste = $this->rechercher()->execute(
            ['entite' => 'Avenant', 'echeance' => AvenantEcheanceScope::STATUT_NON_RENOUVELABLES],
            $scope,
        );
        $this->assertSame($idsChip, array_column($liste->data['items'], 'id'), 'Ket doit voir le même groupe.');

        $compte = $this->compter()->execute(
            ['entite' => 'Avenant', 'echeance' => AvenantEcheanceScope::STATUT_NON_RENOUVELABLES],
            $scope,
        );
        $this->assertSame(count($idsChip), $compte->data['count']);

        // 3. Ce que l'onglet « Non renouv. » du tableau de bord affiche, au même périmètre.
        $dashboard = array_map(
            static fn (Avenant $a) => $a->getId(),
            static::getContainer()->get(DashboardDataProvider::class)
                ->getAvenantsNonRenouvelables($entreprise, $invite),
        );
        $this->assertSame($idsChip, $dashboard, 'Le tableau de bord doit désigner le même groupe que le chip.');

        // Et la police y est bien parce qu'elle est MARQUÉE, pas parce qu'elle est échue :
        // le groupe ne borne aucune date.
        $this->assertTrue(
            $this->em()->getRepository(Avenant::class)->find($idsChip[0])->isNonRenouvelable(),
        );
    }

    /**
     * Les quatre SORTS possibles d'une police échue, ajoutés au portefeuille de l'invité :
     * scellée par un avenant successeur (sort acquis → sort du pipeline), amorcée sans
     * successeur (RENEWING → y reste, l'action est due), sans aucune suite (piège NULL du
     * NOT EXISTS), résiliée (décision de fin → sort du pipeline). C'est l'état réel des
     * polices de l'incident : leurs pistes de renouvellement existaient SANS avenant issu.
     */
    private function ajouterCasDeSuccession(Entreprise $entreprise, Invite $invite): void
    {
        $em = $this->em();
        $portefeuille = $em->getRepository(Portefeuille::class)
            ->findOneBy(['gestionnaire' => $invite, 'nom' => 'Portefeuille Cohérence']);
        $echu = new \DateTimeImmutable('-30 days');

        foreach ([
            ['SCELLEE', Piste::AVENANT_RENOUVELLEMENT, true],
            ['AMORCEE', Piste::AVENANT_RENOUVELLEMENT, false],
            ['RESILIEE', Piste::AVENANT_RESILIATION, false],
        ] as [$ref, $typeAvenant, $avecAvenantIssu]) {
            $client = (new Client())->setNom('Client ' . $ref)->setExonere(false);
            $client->setEntreprise($entreprise);
            $portefeuille->addClient($client);
            $em->persist($client);

            $piste = (new Piste())->setNom('Piste ' . $ref)->setTypeAvenant(Piste::AVENANT_SOUSCRIPTION)
                ->setDescriptionDuRisque('Risque')->setExercice(2026)->setClient($client);
            $piste->setEntreprise($entreprise)->setInvite($invite);
            $em->persist($piste);

            $cotation = (new Cotation())->setNom('Cotation ' . $ref)->setDuree(365);
            $cotation->setPiste($piste);
            $cotation->setEntreprise($entreprise);
            $em->persist($cotation);

            $base = (new Avenant())->setCotation($cotation)->setReferencePolice('POL-' . $ref)->setNumero('0')
                ->setDescription('Avenant ' . $ref)
                ->setStartingAt($echu->modify('-365 days'))->setEndingAt($echu);
            $base->setEntreprise($entreprise)->setInvite($invite);
            $em->persist($base);

            // Opportunité dérivée : les DEUX sens du double lien, comme en production.
            $derivee = (new Piste())->setNom('Mouvement ' . $ref)->setTypeAvenant($typeAvenant)
                ->setDescriptionDuRisque('Risque')->setExercice(2026)->setClient($client);
            $derivee->setEntreprise($entreprise)->setInvite($invite);
            $derivee->setAvenantDeBase($base);
            $em->persist($derivee);
            $base->setPisteDeRenouvellement($derivee);

            if ($avecAvenantIssu) {
                $cotationSuite = (new Cotation())->setNom('Cotation suite ' . $ref)->setDuree(365);
                $cotationSuite->setPiste($derivee);
                $cotationSuite->setEntreprise($entreprise);
                $em->persist($cotationSuite);

                $successeur = (new Avenant())->setCotation($cotationSuite)
                    ->setReferencePolice('POL-' . $ref)->setNumero('1')->setDescription('Successeur ' . $ref)
                    ->setStartingAt(new \DateTimeImmutable('today'))
                    ->setEndingAt(new \DateTimeImmutable('+1 year'));
                $successeur->setEntreprise($entreprise)->setInvite($invite);
                $em->persist($successeur);
            }
        }

        $em->flush();
        $em->clear();
    }
}
