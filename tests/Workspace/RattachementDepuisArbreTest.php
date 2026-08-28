<?php

namespace App\Tests\Workspace;

use App\Entity\Avenant;
use App\Entity\Client;
use App\Entity\ConditionPartage;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Piste;
use App\Entity\ReversementRetroAgent;
use App\Entity\Risque;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * RATTACHER DEPUIS L'ARBRE — et n'écrire que sur l'affaire.
 *
 * On ordonne le geste depuis un avenant, une tranche ou une proposition, parce que c'est de
 * là qu'on travaille. Mais la condition de partage s'écrit sur la PISTE, comme elle l'a
 * toujours fait : c'est ce que ce test surveille avant tout, car une écriture au mauvais
 * endroit ne se verrait qu'au moment où l'agent réclamerait sa part.
 *
 * Trois autres propriétés en dépendent :
 *   — le LOT est tout ou rien, et il dédoublonne les affaires ;
 *   — une affaire déjà prise refuse un second agent, en le disant ;
 *   — un versement SCELLE le rattachement : plus de détachement, donc plus de changement.
 */
class RattachementDepuisArbreTest extends WebTestCase
{
    private const ENT = 'PHPUnit-Arbre SARL';
    private const OWNER = 'phpunit-arbre-owner@test.local';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        parent::tearDown();
    }

    private function cleanUp(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email = :e', ['e' => self::OWNER]);
        $conn->executeStatement(
            'DELETE pcp FROM piste_condition_partage pcp JOIN piste p ON pcp.piste_id = p.id
             JOIN entreprise e ON p.entreprise_id = e.id WHERE e.nom = :nom',
            ['nom' => self::ENT],
        );
        foreach ([
            'reversement_retro_agent', 'condition_partage', 'avenant', 'cotation', 'piste',
            'client', 'risque', 'partenaire',
            'roles_en_production', 'roles_en_administration', 'roles_en_finance', 'invite',
        ] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :nom",
                ['nom' => self::ENT],
            );
        }
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :nom', ['nom' => self::ENT]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :email', ['email' => self::OWNER]);
        $this->em->clear();
    }

    /**
     * Deux affaires, et pour la première DEUX avenants : c'est ce qui permet d'éprouver le
     * dédoublonnage — deux lignes d'une même affaire ne font qu'un rattachement.
     *
     * @return array{agent: Invite, condition: ConditionPartage, autre: ConditionPartage, pisteA: Piste, pisteB: Piste, avenantsA: array<int, Avenant>, avenantB: Avenant}
     */
    private function semer(): array
    {
        $owner = (new Utilisateur())->setEmail(self::OWNER)->setNom('PHPUnit')->setVerified(true);
        $owner->setPassword('x');
        $owner->setPaidTokens(1_000_000);
        $this->em->persist($owner);

        $ent = (new Entreprise())->setNom(self::ENT)->setLicence('LIC')->setAdresse('1 rue')
            ->setTelephone('+243000')->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($owner);
        $this->em->persist($ent);
        $owner->setConnectedTo($ent);

        $proprietaire = (new Invite())->setNom('Owner')->setProprietaire(true);
        $proprietaire->setUtilisateur($owner)->setEntreprise($ent);
        $this->em->persist($proprietaire);

        $agent = (new Invite())->setNom('Alice')->setProprietaire(false);
        $agent->setEntreprise($ent);
        $this->em->persist($agent);

        $risque = (new Risque())->setCode('ARB')->setNomComplet('Risque arbre')
            ->setBranche(Risque::BRANCHE_IARD_OU_NON_VIE)->setImposable(true);
        $risque->setEntreprise($ent);
        $this->em->persist($risque);

        $faireCondition = function (string $nom, Invite $beneficiaire) use ($ent): ConditionPartage {
            $c = (new ConditionPartage())->setNom($nom)
                ->setFormule(ConditionPartage::FORMULE_NE_SAPPLIQUE_PAS_SEUIL)->setSeuil(0.0)->setTaux(12.0)
                ->setCritereRisque(ConditionPartage::CRITERE_PAS_RISQUES_CIBLES)->setAgent($beneficiaire);
            $c->setEntreprise($ent);
            $this->em->persist($c);

            return $c;
        };
        $condition = $faireCondition('Prime Alice', $agent);

        $bruno = (new Invite())->setNom('Bruno')->setProprietaire(false);
        $bruno->setEntreprise($ent);
        $this->em->persist($bruno);
        $autre = $faireCondition('Prime Bruno', $bruno);

        // UNE CONDITION DE PARTENAIRE. La fixture n'en avait aucune : le picker ne
        // proposait que des agents, et rien n'aurait signalé qu'il continue de le faire.
        $sunu = (new \App\Entity\Partenaire())->setNom('SUNU Courtage')->setPart(20.0);
        $sunu->setEntreprise($ent);
        $this->em->persist($sunu);

        $conditionSunu = (new ConditionPartage())->setNom('Accord SUNU 20%')
            ->setFormule(ConditionPartage::FORMULE_NE_SAPPLIQUE_PAS_SEUIL)->setSeuil(0.0)->setTaux(20.0)
            ->setCritereRisque(ConditionPartage::CRITERE_PAS_RISQUES_CIBLES)->setPartenaire($sunu);
        $conditionSunu->setEntreprise($ent);
        $this->em->persist($conditionSunu);

        $faireAffaire = function (string $nom) use ($ent, $proprietaire, $risque): array {
            $client = (new Client())->setNom('Client ' . $nom)->setExonere(false);
            $client->setEntreprise($ent);
            $this->em->persist($client);

            $piste = (new Piste())->setNom($nom)->setTypeAvenant(Piste::AVENANT_SOUSCRIPTION)
                ->setDescriptionDuRisque('x')->setExercice((int) date('Y'))
                ->setClient($client)->setRisque($risque);
            $piste->setEntreprise($ent)->setInvite($proprietaire);
            $this->em->persist($piste);

            $cotation = (new Cotation())->setNom('Cotation ' . $nom)->setDuree(365);
            $cotation->setPiste($piste)->setEntreprise($ent);
            $this->em->persist($cotation);

            return [$piste, $cotation];
        };

        [$pisteA, $cotationA] = $faireAffaire('Affaire A');
        [$pisteB, $cotationB] = $faireAffaire('Affaire B');

        $faireAvenant = function (Cotation $cotation, string $ref) use ($ent, $proprietaire): Avenant {
            $a = (new Avenant())->setReferencePolice($ref)->setNumero('0')->setDescription('Police ' . $ref)
                ->setStartingAt(new \DateTimeImmutable('-30 days'))
                ->setEndingAt(new \DateTimeImmutable('+335 days'));
            $a->setEntreprise($ent)->setInvite($proprietaire);
            $cotation->addAvenant($a);
            $this->em->persist($a);

            return $a;
        };

        $avenantsA = [$faireAvenant($cotationA, 'POL-A-1'), $faireAvenant($cotationA, 'POL-A-2')];
        $avenantB = $faireAvenant($cotationB, 'POL-B-1');

        $this->em->flush();
        $this->client->loginUser($owner);

        // La cotation est rendue explicitement : c'est `setPiste` qui porte la relation,
        // donc la collection INVERSE de la piste n'est pas peuplée en mémoire et
        // `getCotations()->first()` rendrait false tant qu'on n'a pas rechargé.
        return compact('agent', 'condition', 'autre', 'pisteA', 'pisteB', 'avenantsA', 'avenantB')
            + ['cotationB' => $cotationB];
    }

    /**
     * Détacher une condition d'un lot d'affaires — même forme que rattacher().
     *
     * Les deux gestes partagent le picker, donc la même charge utile : c'est ce qui permet
     * au contrôleur Stimulus de servir les deux sans une ligne de plus.
     */
    private function detacher(array $ids, int $conditionId, string $entite = 'avenant'): array
    {
        $this->client->request(
            'POST',
            '/admin/partage/' . $entite . '/detacher',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['ids' => $ids, 'conditionId' => $conditionId]),
        );

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?: [];
    }

    private function rattacher(array $ids, int $conditionId, string $entite = 'avenant'): array
    {
        $this->client->request(
            'POST',
            '/admin/partage/' . $entite . '/rattacher',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['ids' => $ids, 'conditionId' => $conditionId]),
        );

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    /** @return ConditionPartage[] */
    private function conditionsDe(Piste $piste): array
    {
        $this->em->clear();
        $frais = $this->em->getRepository(Piste::class)->find($piste->getId());

        return array_merge(
            $frais->getConditionsPartageAgent()->toArray(),
            $frais->getConditionsPartageExceptionnelles()->toArray(),
        );
    }

    // ===================== 1. On écrit sur l'affaire =====================

    /**
     * LE CŒUR DU LOT : ordonné depuis un AVENANT, écrit sur la PISTE.
     *
     * Si cette propriété tombait, la condition finirait sur un objet qui ne la porte pas —
     * le décompte l'ignorerait, et l'agent ne serait jamais payé.
     */
    public function testOrdonneDepuisUnAvenantMaisEcritSurLaPiste(): void
    {
        $s = $this->semer();

        $reponse = $this->rattacher([$s['avenantsA'][0]->getId()], $s['condition']->getId());

        self::assertResponseIsSuccessful();
        self::assertSame(1, $reponse['affaires']);
        self::assertStringContainsString('Alice', $reponse['message']);

        $conditions = $this->conditionsDe($s['pisteA']);
        self::assertCount(1, $conditions, 'La PISTE doit porter la condition.');
        self::assertSame('Prime Alice', $conditions[0]->getNom());
    }

    /** Le même geste depuis une PROPOSITION mène à la même affaire. */
    public function testLeMemeGesteDepuisUneCotationMeneALaMemeAffaire(): void
    {
        $s = $this->semer();
        $cotationId = $s['cotationB']->getId();

        $this->rattacher([$cotationId], $s['condition']->getId(), 'cotation');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->conditionsDe($s['pisteB']));
    }

    // ===================== 2. Le lot =====================

    /**
     * DEUX LIGNES D'UNE MÊME AFFAIRE NE FONT QU'UN RATTACHEMENT.
     *
     * Sans dédoublonnage, le compte annoncé (« 3 affaires ») serait faux et l'on écrirait
     * deux fois la même chose.
     */
    public function testLeLotDedoublonneLesAffaires(): void
    {
        $s = $this->semer();

        $reponse = $this->rattacher(
            [$s['avenantsA'][0]->getId(), $s['avenantsA'][1]->getId(), $s['avenantB']->getId()],
            $s['condition']->getId(),
        );

        self::assertResponseIsSuccessful();
        self::assertSame(2, $reponse['affaires'], 'Trois lignes, mais DEUX affaires.');
        self::assertCount(1, $this->conditionsDe($s['pisteA']));
        self::assertCount(1, $this->conditionsDe($s['pisteB']));
    }

    /**
     * TOUT OU RIEN : une seule affaire prise fait tomber le lot entier, et elle est nommée.
     *
     * Appliquer le reste serait pire qu'un refus — l'utilisateur croirait avoir tout
     * couvert, et l'affaire oubliée ne se signalerait jamais.
     */
    public function testUnLotMixteEstRefuseEtNEcritRien(): void
    {
        $s = $this->semer();
        $this->rattacher([$s['avenantsA'][0]->getId()], $s['condition']->getId());
        self::assertResponseIsSuccessful();

        $reponse = $this->rattacher(
            [$s['avenantsA'][0]->getId(), $s['avenantB']->getId()],
            $s['autre']->getId(),
        );

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Affaire A', $reponse['message']);
        self::assertStringContainsString('Rien n\'a été rattaché', $reponse['message']);

        // L'affaire libre du lot n'a RIEN reçu : c'est tout l'intérêt du « tout ou rien ».
        self::assertCount(0, $this->conditionsDe($s['pisteB']));
    }

    /** Une affaire déjà prise refuse un second agent, et le refus dit quoi faire. */
    public function testUneAffaireDejaPriseRefuseUnSecondAgent(): void
    {
        $s = $this->semer();
        $this->rattacher([$s['avenantsA'][0]->getId()], $s['condition']->getId());

        $reponse = $this->rattacher([$s['avenantsA'][1]->getId()], $s['autre']->getId());

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Alice', $reponse['message']);
        self::assertStringContainsString('détachez', mb_strtolower($reponse['message'], 'UTF-8'));
        self::assertCount(1, $this->conditionsDe($s['pisteA']), 'Toujours une seule condition.');
    }

    // ===================== 3. Le détachement, et le scellement =====================

    /** Tant que rien n'est versé, on détache — et l'affaire redevient celle du cabinet. */
    public function testLeDetachementRendLAffaireAuCabinet(): void
    {
        $s = $this->semer();
        $this->rattacher([$s['avenantsA'][0]->getId()], $s['condition']->getId());

        // LE DÉTACHEMENT NOMME SA CONDITION. C'était un DELETE sur l'affaire, ce qui
        // suffisait tant qu'elle n'en portait qu'une ; depuis qu'un apporteur externe et
        // un agent interne y coexistent, il faut dire LAQUELLE on retire — et le geste
        // passe donc par le même picker que le rattachement.
        $reponse = $this->detacher([$s['avenantsA'][0]->getId()], $s['condition']->getId());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('ne revient plus', $reponse['message']);
        self::assertCount(0, $this->conditionsDe($s['pisteA']));
    }

    /**
     * UN VERSEMENT SCELLE L'AFFAIRE : plus de détachement, donc plus de changement d'agent.
     *
     * C'est la règle la plus coûteuse à découvrir sur le tard — et le refus doit dire
     * combien a déjà été reçu, sans quoi « impossible » laisse chercher.
     */
    public function testUnVersementScelleLAffaire(): void
    {
        $s = $this->semer();
        $this->rattacher([$s['avenantsA'][0]->getId()], $s['condition']->getId());

        $this->em->clear();
        $agent = $this->em->getRepository(Invite::class)->find($s['agent']->getId());
        $avenant = $this->em->getRepository(Avenant::class)->find($s['avenantsA'][0]->getId());

        $reversement = (new ReversementRetroAgent())
            ->setAgent($agent)->setAvenant($avenant)->setMontant(154.19)
            ->setPaidAt(new \DateTimeImmutable('-1 day'))->setReference('VIR-ARB-1');
        $reversement->setEntreprise($avenant->getEntreprise())->setInvite($avenant->getInvite());
        $this->em->persist($reversement);
        $this->em->flush();

        // 1. Le détachement est refusé, en chiffres.
        $refusReponse = $this->detacher([$s['avenantsA'][0]->getId()], $s['condition']->getId());
        self::assertResponseStatusCodeSame(422);
        $refus = $refusReponse['message'];
        self::assertStringContainsString('154,19', $refus);
        self::assertStringContainsString('remplacé par un autre agent', $refus);

        // 2. Et le changement d'agent est fermé du même coup, puisqu'il passe par là.
        $reponse = $this->rattacher([$s['avenantsA'][0]->getId()], $s['autre']->getId());
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Alice', $reponse['message']);

        self::assertCount(1, $this->conditionsDe($s['pisteA']), 'L\'affaire reste acquise à Alice.');
    }

    // ===================== 4. Le picker =====================

    /** Le picker se rend, nomme les affaires visées et ne propose que des conditions d'AGENT. */
    public function testLePickerNommeLesAffairesEtProposeLesDeuxFamilles(): void
    {
        $s = $this->semer();

        $this->client->request('GET', sprintf(
            '/admin/partage/avenant/conditions-picker?ids=%d,%d',
            $s['avenantsA'][0]->getId(),
            $s['avenantB']->getId(),
        ));

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();

        // Fragment HTML, pas une enveloppe JSON : l'ouvreur lit du TEXTE.
        self::assertStringStartsWith('<div', ltrim($html));
        self::assertStringContainsString('data-controller="partage-conditions-picker"', $html);

        // Les affaires visées sont NOMMÉES : on a sélectionné des avenants, pas des affaires.
        self::assertStringContainsString('Affaire A', $html);
        self::assertStringContainsString('Affaire B', $html);
        self::assertStringContainsString('2 affaires concernées', $html);

        // Et les implications sont lisibles AVANT le clic, puisque le clic vaut accord.
        $minuscules = mb_strtolower($html, 'UTF-8');
        self::assertStringContainsString('apporteur externe', $minuscules);
        self::assertStringContainsString('agent interne', $minuscules);
        self::assertStringContainsString('Prime Alice', $html);

        // LES DEUX FAMILLES SONT PROPOSÉES. Ce test exigeait l'inverse — « ne propose que
        // des agents » — et il avait alors raison : le champ de rattachement filtrait
        // `agent IS NOT NULL`, ce qui fermait le geste aux partenaires sur TOUS les
        // chemins à la fois, l'écriture d'un ManyToMany passant toujours par le FormType.
        self::assertStringContainsString('Accord SUNU', $html, 'Une condition de partenaire doit être proposée.');
    }

    /** Un lot déjà pris montre son motif dans le picker, avant qu'on choisisse. */
    public function testLePickerMontreLeRefusAvantLeClic(): void
    {
        $s = $this->semer();
        $this->rattacher([$s['avenantsA'][0]->getId()], $s['condition']->getId());

        $this->client->request('GET', '/admin/partage/avenant/conditions-picker?ids=' . $s['avenantsA'][0]->getId());

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Affaire A', $html);

        // CE QUE L'AFFAIRE PORTE DÉJÀ, à la place du refus calculé d'avance.
        //
        // Le picker annonçait le refus avant le clic. Ce n'est plus possible : le refus
        // dépend de la FAMILLE de la condition choisie, et une affaire déjà prise par un
        // agent reste libre pour un apporteur. Montrer l'occupation vaut mieux que de
        // deviner — l'utilisateur voit le conflit au lieu de le subir.
        self::assertStringContainsString('Effort : Alice', $html);
    }

    /**
     * LE MÊME PICKER, EN MODE DÉTACHER, ne propose QUE ce qui est rattaché.
     *
     * Le détachement était un appel direct : cela ne suffit plus depuis qu'une affaire
     * peut porter deux conditions. Offrir la liste entière ferait choisir une condition
     * qui n'y est pas — un geste sans effet, et rien pour l'expliquer.
     */
    public function testLePickerDonneAChaqueConditionSonPropreVerbe(): void
    {
        $s = $this->semer();
        $this->rattacher([$s['avenantsA'][0]->getId()], $s['condition']->getId());

        $this->client->request(
            'GET',
            '/admin/partage/avenant/conditions-picker?ids=' . $s['avenantsA'][0]->getId(),
        );

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();

        // LES DEUX CONDITIONS SONT LÀ, mais elles ne proposent pas le même geste.
        //
        // Ce test exigeait l'inverse — le picker s'ouvrait en mode « détacher » et ne
        // montrait que ce qui était rattaché. Deux vues pour une même liste, et un
        // paramètre d'URL pour les distinguer : dès lors que chaque ligne porte son verbe,
        // dire au picker ce qu'on est venu faire devenait redondant, et permettait de le
        // contredire.
        self::assertStringContainsString('Prime Alice', $html, 'La condition rattachée est là…');
        self::assertStringContainsString('Prime Bruno', $html, '…et les autres aussi.');

        // Celle qui est posée ne se rattache pas une seconde fois : elle se détache.
        self::assertStringContainsString('Déjà rattachée', $html);
        self::assertStringContainsString('Détacher', $html);
        self::assertStringContainsString('Rattacher ici', $html);

        // Et chaque bouton porte SA route : c'est ce qui permet un picker unique.
        self::assertStringContainsString('data-action-url="/admin/partage/avenant/detacher"', $html);
        self::assertStringContainsString('data-action-url="/admin/partage/avenant/rattacher"', $html);
    }
}
