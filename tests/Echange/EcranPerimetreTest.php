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
     * ⚠ L'EXPORT ARRIVE AVEC TOUTES SES COLONNES.
     *
     * L'état a une maille fixe : ce qu'on y choisit, ce sont les colonnes, et le défaut
     * est de tout emporter — à charge de retirer ce dont on n'a pas besoin. L'inverse
     * aurait livré un fichier amputé à qui n'a rien demandé.
     */
    public function testLExportArriveAvecToutesSesColonnes(): void
    {
        [$entreprise] = $this->fixture();

        $crawler = $this->client->request('GET', sprintf('/admin/echange/workspace/%d?onglet=exporter', $entreprise->getId()));
        self::assertResponseIsSuccessful();

        $cases = $crawler->filter('input[data-echange-target="donnee"]');
        self::assertGreaterThan(0, $cases->count());

        foreach ($cases as $case) {
            self::assertTrue($case->hasAttribute('checked'), 'Toute colonne part cochée.');
        }

        // ⚠ ET LA COLONNE D'IDENTITÉ NE SE DÉCOCHE PAS : elle rattache la ligne à sa
        // tranche. Un état dont aucune ligne ne se rattache n'est plus un état.
        $identite = $crawler->filter('input[data-echange-code-param="id"]');
        self::assertCount(1, $identite);
        self::assertTrue($identite->getNode(0)->hasAttribute('disabled'));
    }

    /**
     * ⚠ NI GABARIT NI FAMILLES DE DONNÉES DANS L'ONGLET EXPORTER.
     *
     * C'est la régression que ce chantier corrige : le volet y proposait des familles qui
     * ne commandaient plus rien de l'export, et le gabarit — un outil d'IMPORT — trônait à
     * côté du bouton d'export.
     */
    public function testLOngletExporterNaPlusNiGabaritNiFamillesDeDonnees(): void
    {
        [$entreprise] = $this->fixture();

        $crawler = $this->client->request('GET', sprintf('/admin/echange/workspace/%d?onglet=exporter', $entreprise->getId()));
        self::assertResponseIsSuccessful();

        self::assertCount(0, $crawler->filter('a[data-echange-target="lienGabarit"]'));
        foreach ($crawler->filter('input[data-echange-target="donnee"]') as $case) {
            self::assertSame(
                '',
                $case->getAttribute('data-echange-dependances-param'),
                "Une colonne n'appelle aucune autre colonne : l'attribut doit rester vide.",
            );
        }

        // Le volet nomme ce qu'il gouverne vraiment.
        $titre = $crawler->filter('details.ech-perimetre-volet summary span')->first()->text();
        self::assertStringContainsString('colonnes', $titre);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Les trois gestes
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * ⚠ LE GABARIT VIT DANS LES DEUX ONGLETS, ET SON LIEN SUIT LES CASES.
     *
     * C'est un outil d'IMPORT : on le remplit pour le déposer. Ne l'offrir que dans
     * « Exporter » obligeait à passer par un écran qui commande autre chose. Et son lien
     * doit porter la cible que le contrôleur réécrit, sinon il rendrait les quarante-deux
     * feuilles quoi qu'on ait coché — c'est-à-dire le contraire de ce qu'il promet.
     *
     * @dataProvider onglets
     */
    public function testLeGabaritEstProposeAuDepot(string $onglet): void
    {
        [$entreprise] = $this->fixture();

        $crawler = $this->client->request('GET', sprintf('/admin/echange/workspace/%d?onglet=%s', $entreprise->getId(), $onglet));
        self::assertResponseIsSuccessful();

        // ⚠ LE LIEN DU CLASSEUR DE REPRISE NE SUIT PLUS AUCUNE CASE, et il n'a donc plus
        // de cible Stimulus. Ce n'est pas un oubli : ce classeur a une MAILLE FIXE — une
        // ligne par échéance —, si bien qu'un « ?donnees=Client,Cotation » y serait sans
        // objet. Le contrôleur l'écrivait pourtant, et annonçait « gabarit des 3 données
        // retenues » pour un fichier complet : le libellé décrivait un fichier qu'on ne
        // recevait pas. Le pilotage a rejoint le lien auquel il s'applique, celui du
        // classeur NORMALISÉ, dans le volet des familles.
        $lien = $crawler->filter(sprintf('a[href="/admin/echange/gabarit/%d"]', $entreprise->getId()));
        self::assertCount(1, $lien, sprintf('Le classeur de reprise doit être atteignable depuis « %s ».', $onglet));
        self::assertStringContainsString('reprise', $lien->text(), 'Le libellé doit dire ce qu\'on obtient.');

        // ⚠ Le mot « gratuit » n'est pas décoratif : à côté d'un export facturé, un geste
        // dont on ne dit pas le prix est un geste qu'on n'ose pas faire.
        self::assertStringContainsString('Gratuit', $crawler->filter('.ech-gabarit')->text());
    }

    /**
     * ⚠ LE PARAMÈTRE « format=normalise » NE ROUVRE PAS LA PORTE.
     *
     * La route servait deux formats. Le second — un classeur à une feuille par donnée —
     * rétablissait à lui seul la confusion que la reprise avait éliminée : deux fichiers
     * pour un même geste, dont un que l'utilisateur ne savait pas nommer. Il a été retiré
     * de l'écran ET de la route.
     *
     * Retirer un bouton en laissant l'adresse répondre, ce serait garder une capacité que
     * plus rien ne montre — le genre de chemin qu'on redécouvre trois ans plus tard sans
     * savoir s'il sert. Le paramètre est donc INERTE, et c'est ce que ce test garde.
     */
    public function testLeParametreDeFormatNeRouvrePasLAncienGabarit(): void
    {
        [$entreprise] = $this->fixture();

        foreach (['', '?format=normalise', '?format=normalise&donnees=Client'] as $suffixe) {
            $this->client->request('GET', sprintf('/admin/echange/gabarit/%d%s', $entreprise->getId(), $suffixe));
            self::assertResponseIsSuccessful();

            $entete = (string) $this->client->getResponse()->headers->get('Content-Disposition');
            self::assertStringContainsString('reprise', $entete, $suffixe);
            self::assertStringNotContainsString('gabarit', $entete, $suffixe);
        }
    }

    /**
     * ⚠ LE CLASSEUR DE REPRISE PRÉCÈDE LE DÉPÔT — on le remplit pour le déposer.
     *
     * Ce test gardait aussi la place du gabarit NORMALISÉ, relégué dans le volet des
     * familles. Ce volet a disparu avec lui : l'onglet ne propose plus qu'un fichier, et
     * c'est tout l'objet du retrait.
     *
     * @dataProvider onglets
     */
    public function testLeGabaritPrecedeLeDepot(string $onglet): void
    {
        [$entreprise] = $this->fixture();

        $crawler = $this->client->request('GET', sprintf('/admin/echange/workspace/%d?onglet=%s', $entreprise->getId(), $onglet));
        self::assertResponseIsSuccessful();

        $html = (string) $this->client->getResponse()->getContent();

        // ⚠ PLUS AUCUN CHEMIN VERS L'ANCIEN FORMAT, dans aucun onglet.
        self::assertStringNotContainsString('format=normalise', $html);
        self::assertCount(0, $crawler->filter('details.ech-perimetre-volet'));

        if ($onglet === 'importer') {
            self::assertLessThan(
                strpos($html, '<div class="ech-depot">'),
                strpos($html, sprintf('href="/admin/echange/gabarit/%d"', $entreprise->getId())),
                'Le classeur de reprise doit précéder le dépôt : on le remplit pour le déposer.',
            );
        }
    }

    /**
     * ⚠ LE GESTE D'ABORD, LE RÉGLAGE EN DESSOUS.
     *
     * « Générer l'export » ouvre le panneau ; le choix des colonnes ferme la marche,
     * replié. La plupart des exports ne le touchent pas — on veut tout — et le laisser
     * en travers du chemin, c'était trois écrans à franchir avant d'atteindre le bouton
     * qu'on venait chercher.
     *
     * ⚠ Le gabarit ne figure plus à cette barre : voir
     * testLOngletExporterNaPlusNiGabaritNiFamillesDeDonnees.
     */
    public function testLeBoutonPrecedeLeReglageDesColonnes(): void
    {
        [$entreprise] = $this->fixture();

        $crawler = $this->client->request('GET', sprintf('/admin/echange/workspace/%d?onglet=exporter', $entreprise->getId()));
        self::assertResponseIsSuccessful();

        $barre = $crawler->filter('.ech-actions.ech-actions--tete');
        self::assertCount(1, $barre);
        self::assertCount(1, $barre->filter('[data-echange-target="boutonExport"]'));

        $html = (string) $this->client->getResponse()->getContent();
        self::assertLessThan(
            strpos($html, '<details class="ech-perimetre-volet">'),
            strpos($html, 'data-echange-target="boutonExport"'),
            'Le réglage des colonnes vient après le bouton, jamais avant.',
        );
    }

    /**
     * LES ONGLETS QUI PROPOSENT LE CLASSEUR DE REPRISE.
     *
     * ⚠ UN SEUL, ET C'EST VOULU. Le gabarit a quitté l'onglet Exporter : on n'y vient pas
     * pour préparer une saisie, on y vient pour obtenir ses données. Il est resté du côté
     * du dépôt, là où on le remplit pour le déposer — et
     * `testLOngletExporterNaPlusNiGabaritNiFamillesDeDonnees` garde cette porte fermée.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function onglets(): iterable
    {
        yield 'importer' => ['importer'];
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

    /**
     * Purge CIBLÉE sur le cabinet de ce test.
     *
     * ⚠ ELLE EFFAÇAIT TOUTE LA BASE DE TEST — `DELETE FROM entreprise WHERE 1`, et de
     * même pour les invités et les utilisateurs. La base d'essais est PARTAGÉE : plusieurs
     * fichiers y laissent des fixtures dont d'autres se servent, et `tests/Echange`
     * s'exécute avant `tests/Workspace`. Un test parfaitement juste tombait donc en suite
     * complète et passait seul, pour une raison sans aucun rapport avec ce qu'il vérifie.
     *
     * Une suite ne doit détruire QUE ce qu'elle a créé. Enfants avant parents.
     */
    private function nettoyer(): void
    {
        $cnx = $this->em()->getConnection();
        $noms = [self::ENT];

        foreach (['echange_import_run', 'echange_occurrence', 'token_consumption', 'roles_en_administration', 'invite'] as $table) {
            $cnx->executeStatement(
                sprintf('DELETE t FROM `%s` t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom IN (:noms)', $table),
                ['noms' => $noms],
                ['noms' => \Doctrine\DBAL\ArrayParameterType::STRING],
            );
        }

        // Dénoue la clé étrangère utilisateur.connected_to_id avant de retirer le cabinet.
        $cnx->executeStatement(
            'UPDATE utilisateur SET connected_to_id = NULL WHERE email = :email',
            ['email' => self::OWNER_EMAIL],
        );
        $cnx->executeStatement(
            'DELETE FROM entreprise WHERE nom IN (:noms)',
            ['noms' => $noms],
            ['noms' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );
        $cnx->executeStatement('DELETE FROM utilisateur WHERE email = :email', ['email' => self::OWNER_EMAIL]);
    }
}
