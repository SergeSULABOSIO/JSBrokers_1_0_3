<?php

namespace App\Tests\Echange;

use App\Echange\Canevas\CanevasDEchange;
use App\Entity\EchangeImportRun;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\RolesEnAdministration;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * L'ÉCRAN DE LA RUBRIQUE, RENDU POUR DE VRAI.
 *
 * Les autres tests de ce dossier prouvent que le serveur calcule juste. Celui-ci prouve
 * que l'écran s'affiche — ce qui n'est pas la même chose, et ce qui manquait : une route
 * nommée à côté (`admin_echange_gabarit` au lieu de `admin.echange.gabarit`) passe toutes
 * les vérifications de service et casse la page entière au premier affichage.
 *
 * Il vérifie aussi que les trois gestes ajoutés sont bien LÀ, atteignables, et pas
 * seulement implémentés quelque part.
 */
class EcranPerimetreTest extends WebTestCase
{
    private const OWNER_EMAIL = 'phpunit-echange-ecran@test.local';
    private const ENT = 'PHPUnit Écran SARL';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->nettoyer();
    }

    protected function tearDown(): void
    {
        $this->nettoyer();
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Le groupement par module
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * ⚠ AUTANT DE GROUPES QUE DE MODULES, ET TOUTES LES DONNÉES DEDANS.
     *
     * Le regroupement n'a d'intérêt que s'il est EXHAUSTIF : une donnée oubliée par les
     * groupes serait invisible à l'écran alors qu'elle sortirait dans le fichier — un
     * export qui contient plus que ce qu'on a coché.
     */
    public function testLExportPresenteLesDonneesGroupeesParModule(): void
    {
        [$entreprise] = $this->fixture();

        $crawler = $this->client->request('GET', sprintf('/admin/echange/workspace/%d?onglet=exporter', $entreprise->getId()));
        self::assertResponseIsSuccessful();

        $ressources = static::getContainer()->get(CanevasDEchange::class)->toutes();
        $modules = array_unique(array_map(static fn ($r) => $r->module, $ressources));

        self::assertCount(
            \count($modules),
            $crawler->filter('details.ech-groupe'),
            'Il doit y avoir exactement un groupe repliable par module.',
        );

        // Chaque donnée du périmètre a sa case, dans un groupe.
        foreach ($ressources as $code => $ressource) {
            self::assertCount(
                1,
                $crawler->filter(sprintf('input[data-echange-code-param="%s"]', $code)),
                sprintf('La donnée « %s » n%sapparaît dans aucun groupe.', $ressource->libelle, "'"),
            );
        }
    }

    /**
     * La case d'un module et les cases de ses lignes se désignent par le MÊME nom de
     * module. Sans cela, cocher l'en-tête ne toucherait rien : le geste de confort
     * échouerait en silence, ce qui est pire que de ne pas l'offrir.
     */
    public function testChaqueEnTeteDeModuleDesigneDesLignes(): void
    {
        [$entreprise] = $this->fixture();

        $crawler = $this->client->request('GET', sprintf('/admin/echange/workspace/%d?onglet=exporter', $entreprise->getId()));
        self::assertResponseIsSuccessful();

        $enTetes = $crawler->filter('button.jsb-preset-chip[data-echange-target="module"]');
        self::assertGreaterThan(0, $enTetes->count());

        foreach ($enTetes as $noeud) {
            $module = $noeud->getAttribute('data-echange-module-param');
            self::assertNotSame('', (string) $module);

            $lignes = $crawler->filter(sprintf(
                'input[data-echange-target="donnee"][data-echange-module-param="%s"]',
                $module,
            ));
            self::assertGreaterThan(
                0,
                $lignes->count(),
                sprintf('Le groupe « %s » ne commande aucune ligne.', $module),
            );
        }
    }

    /**
     * ⚠ LES CHIPS SONT CEUX DES LISTES, PAS DES SOSIES.
     *
     * Un gabarit qui ressemble au modèle sans en être un se met à en diverger dès la
     * première retouche de la charte, et l'écran finit par avoir sa propre grammaire.
     * On vérifie donc la structure canonique : barre, pilule, titre purement textuel.
     */
    public function testLesFamillesSuiventLeGabaritDeChipsDesListes(): void
    {
        [$entreprise] = $this->fixture();

        $crawler = $this->client->request('GET', sprintf('/admin/echange/workspace/%d?onglet=exporter', $entreprise->getId()));
        self::assertResponseIsSuccessful();

        $groupe = $crawler->filter('.jsb-preset-filters-bar .jsb-preset-filters.jsb-control-pill[aria-label="Familles de données"]');
        self::assertCount(1, $groupe, 'La barre de familles doit suivre le gabarit des listes.');
        self::assertSame('familles', trim($groupe->filter('.jsb-preset-filters__titre')->text()));

        // Trois états possibles, et aucun autre : un chip qui n'annonce rien laisserait
        // un lecteur d'écran muet sur ce qui va sortir du cabinet.
        foreach ($groupe->filter('button.jsb-preset-chip[data-echange-target="module"]') as $chip) {
            self::assertContains(
                $chip->getAttribute('aria-pressed'),
                ['true', 'false', 'mixed'],
                'Un chip de famille doit annoncer son état.',
            );
            self::assertNotSame('', trim($chip->textContent));
        }
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Les trois gestes
    // ─────────────────────────────────────────────────────────────────────────────

    /** Le gabarit vierge s'atteint depuis l'onglet Exporter, et il est dit gratuit. */
    public function testLeGabaritEstProposeDansLOngletExporter(): void
    {
        [$entreprise] = $this->fixture();

        $crawler = $this->client->request('GET', sprintf('/admin/echange/workspace/%d?onglet=exporter', $entreprise->getId()));
        self::assertResponseIsSuccessful();

        $lien = $crawler->filter(sprintf('a[href="/admin/echange/gabarit/%d"]', $entreprise->getId()));
        self::assertCount(1, $lien, 'Le lien vers le gabarit vierge doit être présent.');

        // ⚠ Le mot « gratuit » n'est pas décoratif : à côté d'un export facturé, un geste
        // dont on ne dit pas le prix est un geste qu'on n'ose pas faire.
        self::assertStringContainsString('Gratuit', $crawler->filter('.ech-actions')->text());
    }

    /** Le choix de ce qu'on reprend est offert AVANT le dépôt, pas après le contrôle. */
    public function testLOngletImporterOffreLeChoixDesDonnees(): void
    {
        [$entreprise] = $this->fixture();

        $crawler = $this->client->request('GET', sprintf('/admin/echange/workspace/%d?onglet=importer', $entreprise->getId()));
        self::assertResponseIsSuccessful();

        self::assertCount(1, $crawler->filter('details.ech-perimetre-import'));
        self::assertGreaterThan(
            0,
            $crawler->filter('details.ech-perimetre-import button.jsb-preset-chip[data-echange-target="module"]')->count(),
        );

        // Le bloc précède le dépôt dans le document : l'ordre de lecture EST l'ordre du
        // geste, et un lecteur d'écran n'a pas d'autre indice.
        // ⚠ On cherche les BALISES, pas les noms de classes : la feuille de style, posée
        // en tête du composant, contient les deux sélecteurs et fausserait la comparaison.
        $html = (string) $this->client->getResponse()->getContent();
        self::assertLessThan(
            strpos($html, '<div class="ech-depot">'),
            strpos($html, '<details class="ech-perimetre-import">'),
            'Le choix des données doit précéder le dépôt du fichier.',
        );
    }

    /**
     * Le fichier annoté n'est proposé QUE lorsqu'il y a quelque chose à corriger — et il
     * l'est même quand le contrôle est confirmable : un avertissement mérite d'être vu en
     * place avant qu'on ne l'accepte.
     */
    public function testLeFichierAnnoteEstProposeQuandLeRapportPorteDesAnomalies(): void
    {
        [$entreprise, $invite] = $this->fixture();

        $run = $this->controleEnAttente($entreprise, $invite, [
            'anomalies' => [
                ['gravite' => 'AVERTISSEMENT', 'feuille' => 'Clients', 'ligne' => 47, 'colonne' => 'M', 'message' => 'Valeur inattendue.'],
            ],
        ]);

        $crawler = $this->client->request('GET', sprintf('/admin/echange/workspace/%d?onglet=importer', $entreprise->getId()));
        self::assertResponseIsSuccessful();

        self::assertCount(
            1,
            $crawler->filter(sprintf('a[href="/admin/echange/importer/%d/%d/anomalies"]', $entreprise->getId(), $run->getId())),
            'Le fichier annoté doit être téléchargeable dès qu une anomalie est signalée.',
        );
    }

    /** Sans anomalie, pas de bouton : proposer de corriger un fichier juste est du bruit. */
    public function testAucunFichierAnnoteQuandLeRapportEstVierge(): void
    {
        [$entreprise, $invite] = $this->fixture();

        $run = $this->controleEnAttente($entreprise, $invite, ['anomalies' => []]);

        $crawler = $this->client->request('GET', sprintf('/admin/echange/workspace/%d?onglet=importer', $entreprise->getId()));
        self::assertResponseIsSuccessful();

        self::assertCount(
            0,
            $crawler->filter(sprintf('a[href="/admin/echange/importer/%d/%d/anomalies"]', $entreprise->getId(), $run->getId())),
        );
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Fixtures
    // ─────────────────────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $rapport */
    private function controleEnAttente(Entreprise $entreprise, Invite $invite, array $rapport): EchangeImportRun
    {
        $em = $this->em();

        $run = new EchangeImportRun();
        $run->setNomFichier('depot.xlsx');
        $run->setStatut(EchangeImportRun::STATUT_EN_ATTENTE_CONFIRMATION);
        $run->setExpireLe(new \DateTimeImmutable('+1 hour'));
        $run->setRapport($rapport);
        $run->setEntreprise($entreprise);
        $run->setInvite($invite);
        $em->persist($run);
        $em->flush();

        return $run;
    }

    /** @return array{0: Entreprise, 1: Invite} */
    private function fixture(): array
    {
        $em = $this->em();

        $owner = (new Utilisateur())->setEmail(self::OWNER_EMAIL)->setNom('Ecran')->setVerified(true)->setPassword('x');
        $owner->setPaidTokens(500000);
        $em->persist($owner);

        $entreprise = (new Entreprise())->setNom(self::ENT)->setLicence('LIC')->setAdresse('1 rue')
            ->setTelephone('+2430000')->setRccm('R')->setIdnat('I')->setNumimpot('N');
        $entreprise->setUtilisateur($owner);
        $em->persist($entreprise);
        $owner->setConnectedTo($entreprise);
        $em->flush();

        $proprietaire = (new Invite())->setNom('Le Patron')->setEmail(self::OWNER_EMAIL);
        $proprietaire->setProprietaire(true);
        $proprietaire->setEntreprise($entreprise);
        $proprietaire->setUtilisateur($owner);
        $em->persist($proprietaire);

        $admin = (new RolesEnAdministration())->setNom('Admin');
        $admin->setAccessEchange([Invite::ACCESS_LECTURE, Invite::ACCESS_ECRITURE]);
        $admin->setEntreprise($entreprise);
        $admin->setInvite($proprietaire);
        $em->persist($admin);
        $proprietaire->addRolesEnAdministration($admin);

        $em->flush();

        $this->client->loginUser($owner);

        return [$entreprise, $proprietaire];
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /** Purge dérivée du schéma — cf. ExportJsbxTest, même raison. */
    private function nettoyer(): void
    {
        $cnx = $this->em()->getConnection();
        $cnx->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['echange_import_run', 'echange_occurrence', 'token_consumption', 'roles_en_administration', 'invite', 'entreprise', 'utilisateur'] as $table) {
            $cnx->executeStatement(sprintf('DELETE FROM `%s` WHERE 1', $table));
        }
        $cnx->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
    }
}
