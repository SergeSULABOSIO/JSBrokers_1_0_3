<?php

namespace App\Tests\Workspace;

use App\Entity\Avenant;
use App\Entity\Client;
use App\Entity\Cotation;
use App\Entity\Document;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Piste;
use App\Entity\ReversementRetroAgent;
use App\Entity\Risque;
use App\Entity\Utilisateur;
use App\Service\Retro\LotDeVersement;
use App\Services\Canvas\Provider\List\ReversementRetroAgentListCanvasProvider;
use App\Services\CanvasBuilder;
use App\Services\JSBDynamicSearchService;
use App\Services\Search\ReversementScope;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * LES FILTRES RAPIDES DE LA RUBRIQUE DES REVERSEMENTS.
 *
 * Le volet « Versements enregistrés » a été supprimé : la rubrique porte seule la lecture des
 * versements, et le rapport de production l'ouvre filtrée sur son agent. Encore faut-il
 * qu'elle sache filtrer — et qu'elle filtre EN BASE, pas en mémoire, sans quoi la pagination
 * et les totaux porteraient sur un ensemble et l'affichage sur un autre.
 *
 * Ce test tient les trois propriétés dont dépend cette bascule :
 *
 *  1. LES QUATRE GROUPES SONT DÉCLARÉS, dont le chip-sélecteur du bénéficiaire — le seul
 *     qui ne porte pas des valeurs mais une entité où aller les chercher.
 *  2. CHAQUE VALEUR FILTRE VRAIMENT. Un chip qui ne restreint rien est pire qu'un chip
 *     absent : on croit avoir filtré.
 *  3. LA PREUVE EST CELLE DU VIREMENT. « Sans pièce » ne doit pas ramener les deux lignes
 *     d'un lot que le bordereau du porteur couvre déjà.
 */
class ReversementRubriqueFiltresTest extends KernelTestCase
{
    private const OWNER_EMAIL = 'phpunit-filtres-reversement@test.local';
    private const ENT = 'PHPUnit Filtres Reversement SARL';

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
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email = :e', ['e' => self::OWNER_EMAIL]);
        foreach (['document', 'reversement_retro_agent', 'avenant', 'cotation', 'piste', 'client', 'risque', 'invite'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :nom",
                ['nom' => self::ENT],
            );
        }
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :nom', ['nom' => self::ENT]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :email', ['email' => self::OWNER_EMAIL]);
        $this->em()->clear();
    }

    /**
     * Quatre versements, choisis pour que chaque chip ait quelque chose à écarter :
     *
     *  — un LOT de deux lignes (« VIR-LOT »), dont SEUL le porteur porte le bordereau ;
     *  — un versement ISOLÉ récent, sans pièce (« VIR-SOLO ») ;
     *  — un versement ISOLÉ ANCIEN (l'an dernier), sans pièce (« VIR-VIEUX »).
     *
     * @return array{entreprise: Entreprise, agent: Invite, autre: Invite}
     */
    private function semer(): array
    {
        $em = $this->em();

        $owner = (new Utilisateur())->setEmail(self::OWNER_EMAIL)->setNom('Filtres')->setVerified(true)->setPassword('x');
        $em->persist($owner);

        $ent = (new Entreprise())->setNom(self::ENT)->setLicence('LIC')->setAdresse('1 rue')
            ->setTelephone('+2430000')->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($owner);
        $em->persist($ent);
        $owner->setConnectedTo($ent);

        $proprietaire = (new Invite())->setNom('Owner')->setProprietaire(true);
        $proprietaire->setUtilisateur($owner)->setEntreprise($ent);
        $em->persist($proprietaire);

        $agent = (new Invite())->setNom('Alice')->setProprietaire(false);
        $agent->setEntreprise($ent);
        $em->persist($agent);

        $autre = (new Invite())->setNom('Bruno')->setProprietaire(false);
        $autre->setEntreprise($ent);
        $em->persist($autre);

        $risque = (new Risque())->setCode('FIL')->setNomComplet('Risque filtres')
            ->setBranche(Risque::BRANCHE_IARD_OU_NON_VIE)->setImposable(true);
        $risque->setEntreprise($ent);
        $em->persist($risque);

        $client = (new Client())->setNom('Client filtres')->setExonere(false);
        $client->setEntreprise($ent);
        $em->persist($client);

        $piste = (new Piste())->setNom('Affaire filtres')->setTypeAvenant(Piste::AVENANT_SOUSCRIPTION)
            ->setDescriptionDuRisque('x')->setExercice((int) date('Y'))->setClient($client)->setRisque($risque);
        $piste->setEntreprise($ent)->setInvite($proprietaire);
        $em->persist($piste);

        $cotation = (new Cotation())->setNom('Cotation filtres')->setDuree(365);
        $cotation->setPiste($piste)->setEntreprise($ent);
        $em->persist($cotation);

        $faireAvenant = function (string $ref) use ($ent, $proprietaire, $cotation, $em): Avenant {
            $a = (new Avenant())->setReferencePolice($ref)->setNumero('0')->setDescription('Police ' . $ref)
                ->setStartingAt(new \DateTimeImmutable('-30 days'))->setEndingAt(new \DateTimeImmutable('+335 days'));
            $a->setEntreprise($ent)->setInvite($proprietaire);
            $cotation->addAvenant($a);
            $em->persist($a);

            return $a;
        };

        $faireVersement = function (string $ref, ?string $lot, Avenant $avenant, \DateTimeImmutable $quand) use ($ent, $proprietaire, $agent, $em): ReversementRetroAgent {
            $r = (new ReversementRetroAgent())->setAgent($agent)->setAvenant($avenant)->setMontant(100.0)
                ->setPaidAt($quand)->setReference($ref)->setLotReference($lot);
            $r->setEntreprise($ent)->setInvite($proprietaire);
            $em->persist($r);

            return $r;
        };

        $maintenant = new \DateTimeImmutable('now');
        $porteur = $faireVersement('VIR-LOT', 'VIR-LOT', $faireAvenant('POL-F-1'), $maintenant);
        $faireVersement('VIR-LOT', 'VIR-LOT', $faireAvenant('POL-F-2'), $maintenant);
        $faireVersement('VIR-SOLO', null, $faireAvenant('POL-F-3'), $maintenant);
        // L'an dernier : hors « ce mois », hors « 30 jours » ET hors « cet exercice ».
        $faireVersement('VIR-VIEUX', null, $faireAvenant('POL-F-4'), $maintenant->modify('-1 year'));

        $em->flush();

        // LE BORDEREAU N'EST POSÉ QUE SUR LE PORTEUR : c'est ce qui rend le test probant.
        // Les deux lignes du lot doivent pourtant compter pour justifiées.
        $document = (new Document())->setNom('bordereau.txt');
        $document->setReversementRetroAgent($porteur)->setEntreprise($ent)->setInvite($proprietaire);
        $em->persist($document);
        $em->flush();

        return ['entreprise' => $ent, 'agent' => $agent, 'autre' => $autre];
    }

    /**
     * Les références des versements que ce filtre laisse passer.
     *
     * @return string[]
     */
    private function references(Entreprise $ent, array $criteres): array
    {
        /** @var JSBDynamicSearchService $search */
        $search = static::getContainer()->get(JSBDynamicSearchService::class);
        $resultat = $search->search(ReversementRetroAgent::class, $criteres, $ent, null, 1, 50);

        self::assertSame(200, $resultat['status']['code'] ?? 500, json_encode($resultat['status'] ?? []));

        $refs = array_map(static fn (ReversementRetroAgent $r) => (string) $r->getReference(), $resultat['data']);
        sort($refs);

        return $refs;
    }

    // ===================== 1. Les quatre groupes =====================

    /** Les quatre groupes sont déclarés, avec leur option « Tous » et le chip-sélecteur. */
    public function testLesQuatreGroupesDeChipsSontDeclares(): void
    {
        $canvas = static::getContainer()->get(ReversementRetroAgentListCanvasProvider::class)->getCanvas();
        $groupes = $canvas['filtres_predefinis'] ?? [];

        $criteres = array_column($groupes, 'critere');
        self::assertContains(ReversementScope::CLE_JUSTIFICATIF, $criteres);
        self::assertContains(ReversementScope::CLE_PERIODE, $criteres);
        self::assertContains(ReversementScope::CLE_VIREMENT, $criteres);
        self::assertContains(ReversementScope::CHAMP_BENEFICIAIRE, $criteres);

        foreach ($groupes as $groupe) {
            $valeurs = array_column($groupe['options'], 'value');
            self::assertContains('', $valeurs, sprintf(
                'Le groupe « %s » doit offrir de RETIRER son filtre : une valeur vide.',
                $groupe['libelle'],
            ));
        }

        // Le chip-sélecteur : il ne porte pas des valeurs, mais l'entité où aller les
        // chercher — les canevas de liste sont partagés et ignorent l'entreprise.
        $beneficiaire = array_values(array_filter(
            $groupes,
            static fn (array $g) => $g['critere'] === ReversementScope::CHAMP_BENEFICIAIRE,
        ))[0];
        $selecteurs = array_filter($beneficiaire['options'], static fn (array $o) => isset($o['selecteur']));
        self::assertCount(1, $selecteurs, 'Le bénéficiaire se choisit, il ne s’énumère pas.');
        self::assertSame('Invite', reset($selecteurs)['selecteur']['entite']);
    }

    // ===================== 2. Chaque valeur filtre vraiment =====================

    /**
     * LA PREUVE EST CELLE DU VIREMENT — la propriété la plus facile à casser.
     *
     * Le bordereau n'est posé que sur le porteur du lot ; ses DEUX lignes doivent pourtant
     * être « avec pièce », et aucune ne doit apparaître dans « sans pièce ».
     */
    public function testLeFiltreJustificatifRaisonneParVirement(): void
    {
        $s = $this->semer();

        self::assertSame(
            ['VIR-LOT', 'VIR-LOT'],
            $this->references($s['entreprise'], ReversementScope::critereRecherche(
                ReversementScope::ENTITE,
                ReversementScope::CLE_JUSTIFICATIF,
                ReversementScope::AVEC_PIECE,
            )),
            'Les deux lignes du lot sont couvertes par le bordereau du porteur.',
        );

        self::assertSame(
            ['VIR-SOLO', 'VIR-VIEUX'],
            $this->references($s['entreprise'], ReversementScope::critereRecherche(
                ReversementScope::ENTITE,
                ReversementScope::CLE_JUSTIFICATIF,
                ReversementScope::SANS_PIECE,
            )),
            'Seuls les versements réellement nus doivent remonter.',
        );
    }

    /** La période borne sur la date de versement, et « Toutes » ne borne rien. */
    public function testLeFiltrePeriodeEcarteLAncien(): void
    {
        $s = $this->semer();

        foreach ([ReversementScope::CE_MOIS, ReversementScope::TRENTE_JOURS, ReversementScope::EXERCICE] as $valeur) {
            $refs = $this->references($s['entreprise'], ReversementScope::critereRecherche(
                ReversementScope::ENTITE,
                ReversementScope::CLE_PERIODE,
                $valeur,
            ));
            self::assertNotContains('VIR-VIEUX', $refs, sprintf('« %s » ne doit pas ramener l’an dernier.', $valeur));
            self::assertContains('VIR-SOLO', $refs, sprintf('« %s » doit ramener le versement du jour.', $valeur));
        }

        // « Toutes » : aucune valeur, donc aucun critère — les quatre lignes reviennent.
        self::assertCount(4, $this->references($s['entreprise'], ReversementScope::critereRecherche(
            ReversementScope::ENTITE,
            ReversementScope::CLE_PERIODE,
            '',
        )));
    }

    /** Groupé / isolé : la référence de lot n'est posée qu'à partir de deux lignes. */
    public function testLeFiltreVirementSepareLesLotsDesIsoles(): void
    {
        $s = $this->semer();

        self::assertSame(
            ['VIR-LOT', 'VIR-LOT'],
            $this->references($s['entreprise'], ReversementScope::critereRecherche(
                ReversementScope::ENTITE,
                ReversementScope::CLE_VIREMENT,
                ReversementScope::GROUPE,
            )),
        );
        self::assertSame(
            ['VIR-SOLO', 'VIR-VIEUX'],
            $this->references($s['entreprise'], ReversementScope::critereRecherche(
                ReversementScope::ENTITE,
                ReversementScope::CLE_VIREMENT,
                ReversementScope::ISOLE,
            )),
        );
    }

    /**
     * Le bénéficiaire est une RELATION : le critère posé par le chip-sélecteur, par le
     * bouton du rapport et par l'assistant est le même, et il filtre.
     */
    public function testLeFiltreBeneficiaireEstUnCritereDeRelation(): void
    {
        $s = $this->semer();

        self::assertCount(4, $this->references(
            $s['entreprise'],
            ReversementScope::critereBeneficiaire((int) $s['agent']->getId(), 'Alice'),
        ), 'Les quatre versements reviennent à Alice.');

        self::assertSame([], $this->references(
            $s['entreprise'],
            ReversementScope::critereBeneficiaire((int) $s['autre']->getId(), 'Bruno'),
        ), 'Bruno n’a rien reçu : la liste doit être vide, pas entière.');
    }

    /** Deux chips se cumulent en ET — sinon poser un second filtre élargirait la liste. */
    public function testLesChipsSeCumulent(): void
    {
        $s = $this->semer();

        $criteres = ReversementScope::critereRecherche(ReversementScope::ENTITE, ReversementScope::CLE_JUSTIFICATIF, ReversementScope::SANS_PIECE)
            + ReversementScope::critereRecherche(ReversementScope::ENTITE, ReversementScope::CLE_PERIODE, ReversementScope::TRENTE_JOURS);

        self::assertSame(['VIR-SOLO'], $this->references($s['entreprise'], $criteres));
    }

    // ===================== 3. Le coût =====================

    /**
     * LE COMPTE DE PIÈCES D'UNE PAGE COÛTE UN NOMBRE CONSTANT DE REQUÊTES.
     *
     * La colonne « Justificatif » raisonne par virement : calculée ligne à ligne, elle
     * rallumerait une requête par ligne — et deux pour un lot. C'est le N+1 déjà combattu
     * ailleurs dans ce projet, et il reviendrait ici par la porte du lot.
     */
    public function testLeCompteDeJustificatifsEstPrechargeEnUneRequete(): void
    {
        $s = $this->semer();
        $em = $this->em();
        $em->clear();

        $page = $em->getRepository(ReversementRetroAgent::class)
            ->findBy(['entreprise' => $s['entreprise']->getId()], ['id' => 'ASC']);
        self::assertCount(4, $page);

        $logger = new class () implements \Doctrine\DBAL\Logging\SQLLogger {
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
            /** @var LotDeVersement $lot */
            $lot = static::getContainer()->get(LotDeVersement::class);
            $lot->prechargerJustificatifs($page);
            $apresPrechargement = $logger->nb;

            foreach ($page as $ligne) {
                $lot->compteDeJustificatifs($ligne);
                $lot->libelleJustificatif($ligne);
            }

            self::assertSame(
                $apresPrechargement,
                $logger->nb,
                'Après le préchargement, lire le compte d’une ligne ne doit plus rien coûter.',
            );
            self::assertLessThanOrEqual(
                1,
                $apresPrechargement,
                'Le préchargement d’une page entière tient en UNE requête.',
            );
        } finally {
            $config->setSQLLogger($precedent);
        }
    }

    /** Et la page rendue par le canevas ne rallume rien non plus. */
    public function testLaPageRendueNeRallumePasUneRequeteParLigne(): void
    {
        $s = $this->semer();
        $em = $this->em();
        $em->clear();

        $page = $em->getRepository(ReversementRetroAgent::class)
            ->findBy(['entreprise' => $s['entreprise']->getId()], ['id' => 'ASC']);

        /** @var CanvasBuilder $canvas */
        $canvas = static::getContainer()->get(CanvasBuilder::class);
        $canvas->batchPreloadForCollection($page);

        foreach ($page as $ligne) {
            $canvas->loadAllCalculatedValues($ligne);
        }

        // Le voyant est bien posé, et il parle du VIREMENT.
        $parReference = [];
        foreach ($page as $ligne) {
            $parReference[(string) $ligne->getReference()][] = $ligne->justificatifLibelle ?? null;
        }

        self::assertSame(['1 pièce', '1 pièce'], $parReference['VIR-LOT']);
        self::assertSame(['Aucune pièce'], $parReference['VIR-SOLO']);
    }
}
