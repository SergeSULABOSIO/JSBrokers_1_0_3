<?php

namespace App\Tests\Workspace;

use App\Entity\Assureur;
use App\Entity\Client;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Piste;
use App\Entity\RolesEnAdministration;
use App\Entity\RolesEnFinance;
use App\Entity\RolesEnMarketing;
use App\Entity\RolesEnProduction;
use App\Entity\RolesEnSinistre;
use App\Entity\Tache;
use App\Entity\Utilisateur;
use App\Service\Workspace\DecisionDeCreation;
use App\Service\Workspace\ParentObligatoire;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * « AJOUTER » AFFICHÉ ⇔ LE SERVEUR ACCEPTE LA CRÉATION (Serge, 2026-10-30).
 *
 * Le test lit les écrans TELS QUE LE SERVEUR LES CONSTRUIT — le canevas que reçoivent la barre
 * d'outils et le menu contextuel (`parametres.ajout`), le bouton de l'état vide — puis poste la
 * création comme CE point d'entrée l'enverrait : sans parent depuis une liste principale, avec
 * le parent de l'onglet depuis un onglet contextuel. Le verdict du serveur ne retient que ses
 * PORTES (403, refus d'une décision de création) : une erreur de champ n'est pas un refus de
 * créer, c'est un formulaire à compléter.
 *
 * Deux lecteurs : le propriétaire (tous les droits) et un collaborateur qui lit tout mais
 * n'écrit que clients, pistes, propositions et tranches.
 */
class CreationPariteTest extends WebTestCase
{
    private const OWNER = 'phpunit-creation-parite-owner@test.local';
    private const GUEST = 'phpunit-creation-parite-guest@test.local';
    private const CABINET = 'PHPUnit Creation Parite SARL';

    /** Ce que le collaborateur peut écrire (nom court => niveaux). */
    private const ECRIT = ['Client', 'Piste', 'Cotation', 'Tranche'];

    private KernelBrowser $client;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
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
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email IN (?, ?)', [self::OWNER, self::GUEST]);
        $id = $conn->fetchOne('SELECT id FROM entreprise WHERE nom = ?', [self::CABINET]);
        if ($id !== false) {
            $tables = $conn->fetchFirstColumn(
                "SELECT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'entreprise_id' AND TABLE_NAME <> 'entreprise'"
            );
            $conn->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
            try {
                foreach ($tables as $table) {
                    $conn->executeStatement("DELETE FROM `$table` WHERE entreprise_id = ?", [$id]);
                }
                $conn->executeStatement('DELETE FROM entreprise WHERE id = ?', [$id]);
            } finally {
                $conn->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
            }
        }
        $conn->executeStatement('DELETE FROM utilisateur WHERE email IN (?, ?)', [self::OWNER, self::GUEST]);
        $this->em()->clear();
    }

    /** @return array<string, int> */
    private function semer(): array
    {
        $em = $this->em();
        $owner = (new Utilisateur())->setEmail(self::OWNER)->setNom('Proprio')->setVerified(true)->setPassword('x');
        $owner->setPaidTokens(1_000_000);
        $guest = (new Utilisateur())->setEmail(self::GUEST)->setNom('Collaborateur')->setVerified(true)->setPassword('x');
        $em->persist($owner);
        $em->persist($guest);
        $cabinet = (new Entreprise())->setNom(self::CABINET)->setLicence('L')->setAdresse('1 rue')
            ->setTelephone('+243000000096')->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($owner);
        $em->persist($cabinet);
        $owner->setConnectedTo($cabinet);
        $guest->setConnectedTo($cabinet);
        $proprio = (new Invite())->setNom('Proprio')->setUtilisateur($owner)->setEntreprise($cabinet)->setProprietaire(true);
        $collaborateur = (new Invite())->setNom('Collaborateur')->setUtilisateur($guest)->setEntreprise($cabinet)->setProprietaire(false);
        $em->persist($proprio);
        $em->persist($collaborateur);

        // LIT TOUT, n'écrit que ECRIT : chaque `setAccess*` de chaque famille de rôle.
        $ecrit = array_map(static fn (string $e): string => 'setAccess' . $e, self::ECRIT);
        foreach ([RolesEnFinance::class, RolesEnMarketing::class, RolesEnProduction::class, RolesEnSinistre::class, RolesEnAdministration::class] as $classe) {
            $role = (new $classe())->setNom('Parité');
            foreach (get_class_methods($role) as $methode) {
                if (str_starts_with($methode, 'setAccess')) {
                    $role->$methode(in_array($methode, $ecrit, true)
                        ? [Invite::ACCESS_LECTURE, Invite::ACCESS_ECRITURE, Invite::ACCESS_MODIFICATION]
                        : [Invite::ACCESS_LECTURE]);
                }
            }
            $role->setEntreprise($cabinet);
            $collaborateur->{'add' . substr(strrchr($classe, '\\'), 1)}($role);
            $em->persist($role);
        }

        $assureur = (new Assureur())->setNom('Assureur parité');
        $assureur->setEntreprise($cabinet);
        $em->persist($assureur);
        $client = (new Client())->setNom('Client parité')->setExonere(false);
        $client->setEntreprise($cabinet);
        $em->persist($client);
        $piste = (new Piste())->setNom('Piste parité')->setTypeAvenant(Piste::AVENANT_SOUSCRIPTION)->setDescriptionDuRisque('R')
            ->setExercice(2026)->setClient($client);
        $piste->setEntreprise($cabinet)->setInvite($proprio);
        $em->persist($piste);
        $cotation = (new Cotation())->setNom('Proposition parité')->setDuree(12)->setAssureur($assureur);
        $cotation->setPiste($piste)->setEntreprise($cabinet)->setInvite($proprio);
        $em->persist($cotation);
        // Une prime chiffrée : sans elle, l'onglet « Avenants » dit à raison qu'une souscription
        // sans prime n'a pas de sens (`ajoutImpossible`) — ce test-ci juge la règle du parent.
        $prime = (new \App\Entity\ChargementPourPrime())->setNom('Prime nette')->setMontantFlatExceptionel(1000.0);
        $prime->setCotation($cotation)->setEntreprise($cabinet);
        $em->persist($prime);
        $tache = (new Tache())->setDescription('Tâche parité')->setToBeEndedAt(new \DateTimeImmutable('+7 days'))->setClosed(false);
        $tache->setEntreprise($cabinet)->setInvite($proprio)->setExecutor($proprio);
        $em->persist($tache);
        $em->flush();

        $s = [
            'owner' => (int) $owner->getId(), 'guest' => (int) $guest->getId(), 'entreprise' => (int) $cabinet->getId(),
            'proprio' => (int) $proprio->getId(), 'collaborateur' => (int) $collaborateur->getId(),
            'assureur' => (int) $assureur->getId(), 'piste' => (int) $piste->getId(),
            'cotation' => (int) $cotation->getId(), 'tache' => (int) $tache->getId(),
        ];
        $em->clear();

        return $s;
    }

    /** Les rubriques du menu qui ouvrent une liste d'entité : nom court => route. */
    private static function rubriques(): array
    {
        $rubriques = [];
        $menu = Yaml::parseFile(__DIR__ . '/../../config/packages/menu.yaml');
        $parcourir = static function ($noeud) use (&$parcourir, &$rubriques): void {
            if (!is_array($noeud)) {
                return;
            }
            if (isset($noeud['entity_name'], $noeud['composant_twig']) && str_starts_with((string) $noeud['entity_name'], 'App\\Entity\\')
                && str_starts_with((string) $noeud['composant_twig'], '_view_manager')) {
                $court = substr((string) $noeud['entity_name'], strlen('App\\Entity\\'));
                $rubriques[$court] = strtolower($court);
            }
            foreach ($noeud as $enfant) {
                $parcourir($enfant);
            }
        };
        $parcourir($menu);
        ksort($rubriques);

        return $rubriques;
    }

    /** La décision posée dans le canevas de la PREMIÈRE liste rendue (celle de l'écran demandé). */
    private function decisionAffichee(string $html): ?array
    {
        if (!preg_match("/data-list-manager-entity-form-canvas-value='([^']*)'/", $html, $m)) {
            return null;
        }
        $canevas = json_decode(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5), true);

        return $canevas['parametres']['ajout'] ?? ['offert' => false, 'motif' => '(aucune décision dans le canevas)'];
    }

    /** Les motifs par lesquels le serveur FERME LA PORTE d'une création. */
    private static function motifsDeRefus(): array
    {
        return array_merge(
            [DecisionDeCreation::HORS_PERIMETRE],
            array_values(DecisionDeCreation::ECRIVAIN_DEDIE),
            array_values(DecisionDeCreation::UNIQUE_PAR_CABINET),
            array_column(ParentObligatoire::PARENTS, 'refus'),
        );
    }

    /** Le serveur ferme-t-il la porte de cette création ? (une erreur de champ n'en est pas une) */
    private function porteFermee(string $route, array $corps): bool
    {
        $this->client->request('POST', "/admin/$route/api/submit", $corps);
        $code = $this->client->getResponse()->getStatusCode();
        $message = (string) (json_decode((string) $this->client->getResponse()->getContent(), true)['message'] ?? '');
        self::assertLessThan(500, $code, "POST /admin/$route/api/submit : " . substr((string) $this->client->getResponse()->getContent(), 0, 300));

        return $code === 403 || ($code === 422 && in_array($message, self::motifsDeRefus(), true));
    }

    private function connecter(int $utilisateur): void
    {
        $this->client->loginUser($this->em()->find(Utilisateur::class, $utilisateur));
    }

    public function testChaqueListePrincipaleNOffreAjouterQueSiLeServeurAccepte(): void
    {
        $s = $this->semer();
        $ecarts = [];
        $jugees = ['propriétaire' => 0, 'collaborateur' => 0];
        foreach (['propriétaire' => [$s['owner'], $s['proprio']], 'collaborateur' => [$s['guest'], $s['collaborateur']]] as $qui => [$utilisateur, $invite]) {
            $this->connecter($utilisateur);
            foreach (self::rubriques() as $court => $route) {
                // L'URL par le routeur : toutes les rubriques n'ont pas le même gabarit (Avenants).
                $this->client->request('GET', static::getContainer()->get('router')->generate("admin.$route.index", ['idInvite' => $invite, 'idEntreprise' => $s['entreprise']]));
                $html = (string) $this->client->getResponse()->getContent();
                if ($this->client->getResponse()->getStatusCode() !== 200 || str_contains($html, 'jsb-access-denied')) {
                    // Le propriétaire lit tout : une rubrique illisible pour lui est une panne.
                    self::assertNotSame('propriétaire', $qui, "$court : rubrique illisible pour le propriétaire (" . $this->client->getResponse()->getStatusCode() . ').');
                    continue; // rubrique hors de portée en lecture : aucun bouton à juger
                }
                $decision = $this->decisionAffichee($html);
                self::assertNotNull($decision, "$qui · $court : la liste principale porte sa décision (`parametres.ajout`).");
                ++$jugees[$qui];
                $etatVide = str_contains($html, 'list-manager#requestAddItem');
                $fermee = $this->porteFermee($route, []);
                if ($decision['offert'] === $fermee) {
                    $ecarts[] = sprintf('%s · %s : bouton %s, serveur %s', $qui, $court, $decision['offert'] ? 'affiché' : 'absent', $fermee ? 'refuse' : 'accepte');
                }
                if ($etatVide && !$decision['offert']) {
                    $ecarts[] = "$qui · $court : l'état vide offre « Ajouter » contre la décision";
                }
            }
        }

        self::assertSame([], $ecarts);
        // Le test ne passe pas « vide » : le propriétaire lit toutes les rubriques du menu.
        self::assertSame(count(self::rubriques()), $jugees['propriétaire'], 'Chaque rubrique du menu a été jugée.');
        self::assertGreaterThan(10, $jugees['collaborateur']);
    }

    /**
     * Un onglet de RATTACHEMENT (lien multiple : les partenaires associés d'un client) n'offre pas
     * la création — la fiche y naîtrait détachée —, ni par sa barre, ni par son état vide. Et
     * l'onglet d'une famille de rôles déjà pourvue dit la raison du parent.
     */
    public function testUnOngletDeRattachementEtUnRoleDejaPourvuNOffrentRien(): void
    {
        $s = $this->semer();
        $this->connecter($s['owner']);
        $client = (int) $this->em()->getConnection()->fetchOne('SELECT id FROM client WHERE entreprise_id = ?', [$s['entreprise']]);

        $this->client->request('GET', "/admin/client/api/$client/partenaires/generic");
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertSame(['offert' => false, 'motif' => DecisionDeCreation::RATTACHEMENT], $this->decisionAffichee($html));
        self::assertStringNotContainsString('list-manager#requestAddItem', $html, 'Son état vide n’offre rien non plus.');

        // Le collaborateur a déjà son rôle Finance (semé) : l'onglet de sa fiche le dit.
        $this->client->request('GET', "/admin/invite/api/{$s['collaborateur']}/rolesEnFinance/generic");
        self::assertResponseIsSuccessful();
        $decision = $this->decisionAffichee((string) $this->client->getResponse()->getContent());
        self::assertFalse($decision['offert']);
        self::assertStringContainsString('déjà un rôle Finance', (string) $decision['motif']);
        self::assertSame(422, $this->posterLaCreation('rolesenfinance', ['invite' => (string) $s['collaborateur'], 'nom' => 'Second']));
    }

    private function posterLaCreation(string $route, array $corps): int
    {
        $this->client->request('POST', "/admin/$route/api/submit", $corps);

        return $this->client->getResponse()->getStatusCode();
    }

    public function testChaqueOngletContextuelNOffreAjouterQueSiLeServeurAccepte(): void
    {
        $s = $this->semer();
        // [onglet du parent, enfant, champ que le dialogue poste, offert attendu pour le propriétaire]
        $onglets = [
            ["/admin/piste/api/{$s['piste']}/cotations/generic", 'cotation', 'piste', $s['piste'], true],
            ["/admin/cotation/api/{$s['cotation']}/tranches/generic", 'tranche', 'cotation', $s['cotation'], true],
            ["/admin/cotation/api/{$s['cotation']}/avenants/generic", 'avenant', 'cotation', $s['cotation'], true],
            ["/admin/cotation/api/{$s['cotation']}/revenus/generic", 'revenupourcourtier', 'cotation', $s['cotation'], true],
            ["/admin/tache/api/{$s['tache']}/feedbacks/generic", 'feedback', 'tache', $s['tache'], true],
            // Rattaché par un AUTRE champ : une proposition naît de sa piste, pas de son assureur.
            ["/admin/assureur/api/{$s['assureur']}/cotations/generic", 'cotation', 'assureur', $s['assureur'], false],
        ];
        $ecarts = [];
        foreach (['propriétaire' => $s['owner'], 'collaborateur' => $s['guest']] as $qui => $utilisateur) {
            $this->connecter($utilisateur);
            foreach ($onglets as [$url, $route, $champ, $id, $attenduProprio]) {
                $this->client->request('GET', $url);
                if ($this->client->getResponse()->getStatusCode() !== 200) {
                    continue;
                }
                $html = (string) $this->client->getResponse()->getContent();
                $decision = $this->decisionAffichee($html);
                self::assertNotNull($decision, "$url : la liste contextuelle porte sa décision.");
                if ($qui === 'propriétaire') {
                    self::assertSame($attenduProprio, $decision['offert'], "$url : " . ($decision['motif'] ?? ''));
                }
                $fermee = $this->porteFermee($route, [$champ => (string) $id]);
                if ($decision['offert'] === $fermee) {
                    $ecarts[] = sprintf('%s · %s : bouton %s, serveur %s', $qui, $url, $decision['offert'] ? 'affiché' : 'absent', $fermee ? 'refuse' : 'accepte');
                }
                if (str_contains($html, 'list-manager#requestAddItem') && !$decision['offert']) {
                    $ecarts[] = "$qui · $url : l'état vide offre « Ajouter » contre la décision";
                }
            }
        }

        self::assertSame([], $ecarts);
    }

    public function testLesParametresDeCongeNeSeCreentQuUneFois(): void
    {
        $s = $this->semer();
        $this->connecter($s['owner']);
        // Tant qu'il n'existe pas, la décision l'offre (guide de démarrage)…
        $decision = static::getContainer()->get(DecisionDeCreation::class)
            ->pour(\App\Entity\ParametresConge::class, $this->em()->find(Entreprise::class, $s['entreprise']));
        self::assertTrue($decision['offert'], 'Tant que le cabinet n’a pas ses réglages, on peut les créer.');

        // … mais ouvrir la rubrique les MATÉRIALISE (ParametresCongeController::index) : sa liste
        // n'offre donc jamais « Ajouter », et le serveur refuse un second jeu.
        $this->client->request('GET', "/admin/parametresconge/index/{$s['proprio']}/{$s['entreprise']}");
        self::assertFalse($this->decisionAffichee((string) $this->client->getResponse()->getContent())['offert'] ?? null);
        self::assertTrue($this->porteFermee('parametresconge', []), 'Le serveur refuse un second jeu.');
        self::assertSame(DecisionDeCreation::UNIQUE_PAR_CABINET[\App\Entity\ParametresConge::class], json_decode((string) $this->client->getResponse()->getContent(), true)['message'] ?? null);
    }

    /** Ket n'ouvre pas un formulaire de création que l'enregistrement refuserait (dialog-context). */
    public function testKetNOuvrePasUneCreationQueLeServeurRefuserait(): void
    {
        $s = $this->semer();
        $this->connecter($s['owner']);

        $this->client->request('GET', "/admin/assistant-ia/api/dialog-context/{$s['entreprise']}?entite=Tranche&mode=creation");
        self::assertResponseStatusCodeSame(422);
        self::assertSame(ParentObligatoire::PARENTS[\App\Entity\Tranche::class]['refus'], json_decode((string) $this->client->getResponse()->getContent(), true)['message'] ?? null);

        $this->client->request('GET', "/admin/assistant-ia/api/dialog-context/{$s['entreprise']}?entite=Client&mode=creation");
        self::assertResponseIsSuccessful();
    }

    public function testUnReversementNeNaitPasDuFormulaireGenerique(): void
    {
        $s = $this->semer();
        $this->connecter($s['owner']);

        self::assertTrue($this->porteFermee('reversementretroagent', []));
        self::assertSame(DecisionDeCreation::ECRIVAIN_DEDIE[\App\Entity\ReversementRetroAgent::class], json_decode((string) $this->client->getResponse()->getContent(), true)['message'] ?? null);
    }
}
