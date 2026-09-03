<?php

namespace App\Tests\Echange;

use App\Echange\Canevas\CanevasDEchange;
use App\Echange\Classeur\EcrivainJsbx;
use App\Echange\Service\Anomalie;
use App\Echange\Service\ExportateurJsbx;
use App\Echange\Service\ImportateurJsbx;
use App\Entity\Client;
use App\Entity\EchangeImportRun;
use App\Entity\Entreprise;
use App\Entity\Groupe;
use App\Entity\Invite;
use App\Entity\RolesEnAdministration;
use App\Entity\RolesEnProduction;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * LE CONTRÔLE À BLANC : passes 1 et 2.
 *
 * Ce qui est vérifié ici tient en une phrase : le contrôle doit être GRATUIT, ne RIEN
 * écrire, et dire exactement ce qui se passerait — y compris quand la réponse est
 * « rien, et voici pourquoi ».
 *
 * Les cas de refus comptent autant que les cas nominaux. Un import qui échoue mal —
 * en silence, ou sans dire où — coûte plus cher qu'un import qui n'existe pas : il
 * fait perdre le travail hors ligne ET la confiance dans l'outil.
 */
class ControleImportTest extends WebTestCase
{
    private const OWNER_EMAIL = 'phpunit-echange-ctrl@test.local';
    private const ENT = 'PHPUnit Contrôle SARL';

    /** @var string[] fichiers temporaires à effacer */
    private array $temporaires = [];

    private KernelBrowser $client;

    /**
     * ⚠ UN UTILISATEUR CONNECTÉ EST NÉCESSAIRE, et ce n'est pas une commodité de test.
     *
     * Les champs de relation des formulaires (« Groupe », « Portefeuille »…) filtrent
     * leurs choix sur le CABINET ACTIF de l'utilisateur — c'est ce qui empêche de
     * rattacher un client au portefeuille du cabinet voisin. Sans session, la liste des
     * choix est vide et le formulaire refuse toute valeur avec « le choix sélectionné
     * est invalide », quel que soit le soin mis à résoudre le renvoi en amont.
     *
     * L'import hérite donc de cette contrainte : il ne peut s'exécuter que dans le
     * contexte d'un utilisateur, exactement comme une saisie à l'écran. C'est le cas en
     * production (requête HTTP authentifiée) comme depuis l'assistant.
     */
    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->nettoyer();
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaires as $chemin) {
            @unlink($chemin);
        }
        $this->temporaires = [];
        $this->nettoyer();
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Passe 1 — structure
    // ─────────────────────────────────────────────────────────────────────────────

    /** Un fichier qui n'est pas un classeur s'arrête net, sans rien coûter. */
    public function testUnFichierIllisibleEstRefuseImmediatement(): void
    {
        [$entreprise, $proprietaire] = $this->fixture();

        $chemin = $this->fichierTemporaire('ceci n\'est pas un classeur');
        $run = $this->importateur()->controler($chemin, 'faux.xlsx', $entreprise, $proprietaire);

        self::assertSame(EchangeImportRun::STATUT_ECHEC, $run->getStatut());
        self::assertFalse($run->getRapport()['confirmable']);
        self::assertSame(Anomalie::FICHIER_ILLISIBLE, $run->getRapport()['anomalies'][0]['code']);
    }

    /** Un classeur ordinaire, sans feuille d'identité, n'est pas un fichier d'échange. */
    public function testUnClasseurSansManifesteEstRefuse(): void
    {
        [$entreprise, $proprietaire] = $this->fixture();

        $classeur = new Spreadsheet();
        $classeur->getActiveSheet()->setTitle('Feuille1')->setCellValue('A1', 'Bonjour');
        $chemin = $this->ecrire($classeur);

        $run = $this->importateur()->controler($chemin, 'quelconque.xlsx', $entreprise, $proprietaire);

        self::assertSame(EchangeImportRun::STATUT_ECHEC, $run->getStatut());
        self::assertSame(Anomalie::MANIFESTE_ABSENT, $this->premierCode($run));
    }

    /** Un fichier d'un AUTRE cabinet est bloqué, jusqu'à confirmation explicite. */
    public function testUnFichierDUnAutreCabinetExigeUneConfirmation(): void
    {
        [$entreprise, $proprietaire] = $this->fixture();
        $this->creerClient($entreprise, $proprietaire, 'ACME Autre');

        $chemin = $this->exporter($entreprise, $proprietaire, ['Client']);
        $this->truquerManifeste($chemin, 'uid_cabinet', '999999');

        $run = $this->importateur()->controler($chemin, 'ailleurs.xlsx', $entreprise, $proprietaire);
        self::assertSame(EchangeImportRun::STATUT_ECHEC, $run->getStatut());
        self::assertSame(Anomalie::AUTRE_CABINET, $this->premierCode($run));

        // Confirmé explicitement, il passe — en laissant une trace de la reprise.
        $run = $this->importateur()->controler($chemin, 'ailleurs.xlsx', $entreprise, $proprietaire, false, true);
        self::assertSame(EchangeImportRun::STATUT_EN_ATTENTE_CONFIRMATION, $run->getStatut());
        self::assertContains(
            Anomalie::AUTRE_CABINET,
            array_column($run->getRapport()['anomalies'], 'code'),
            'La reprise assumée doit rester tracée dans le rapport.',
        );
    }

    /**
     * Supprimer une colonne technique rend le fichier menteur. Le rapport doit NOMMER
     * la colonne manquante : « la structure a changé » n'aiderait personne à réparer.
     */
    public function testUneColonneTechniqueSupprimeeEstNommee(): void
    {
        [$entreprise, $proprietaire] = $this->fixture();
        $this->creerClient($entreprise, $proprietaire, 'ACME Structure');

        $chemin = $this->exporter($entreprise, $proprietaire, ['Client']);
        $this->supprimerColonne($chemin, 'Client', CanevasDEchange::COL_UID);

        $run = $this->importateur()->controler($chemin, 'ampute.xlsx', $entreprise, $proprietaire);

        self::assertSame(EchangeImportRun::STATUT_ECHEC, $run->getStatut());
        $anomalie = $this->anomalieDeCode($run, Anomalie::STRUCTURE_ALTEREE);
        self::assertNotNull($anomalie, 'La structure altérée doit être signalée.');
        self::assertStringContainsString(CanevasDEchange::COL_UID, $anomalie['message'], 'La colonne manquante doit être nommée.');
    }

    /** Une feuille inconnue est ignorée sans bloquer, et mentionnée. */
    public function testUneFeuilleInconnueEstIgnoreeEtMentionnee(): void
    {
        [$entreprise, $proprietaire] = $this->fixture();
        $this->creerClient($entreprise, $proprietaire, 'ACME Brouillon');

        $chemin = $this->exporter($entreprise, $proprietaire, ['Client']);
        $classeur = IOFactory::load($chemin);
        $classeur->createSheet()->setTitle('Mon brouillon')->setCellValue('A1', 'notes perso');
        (new Xlsx($classeur))->save($chemin);

        $run = $this->importateur()->controler($chemin, 'brouillon.xlsx', $entreprise, $proprietaire);

        self::assertSame(EchangeImportRun::STATUT_EN_ATTENTE_CONFIRMATION, $run->getStatut());
        $anomalie = $this->anomalieDeCode($run, Anomalie::FEUILLE_INCONNUE);
        self::assertNotNull($anomalie);
        self::assertSame(Anomalie::AVERTISSEMENT, $anomalie['gravite'], 'Un brouillon ne doit pas bloquer un import.');
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Passe 2 — contrôle à blanc
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * L'ALLER-RETOUR À VIDE, le cas le plus important de tous : exporter, réimporter
     * sans rien toucher, et n'avoir RIEN à écrire. Si ce test tombe, le format ment
     * quelque part sur ce qu'il contient.
     */
    public function testUnAllerRetourSansModificationNeProposeAucuneEcriture(): void
    {
        [$entreprise, $proprietaire] = $this->fixture();
        $this->creerClient($entreprise, $proprietaire, 'ACME Idempotente');
        $this->creerClient($entreprise, $proprietaire, 'Deuxième Client');

        $chemin = $this->exporter($entreprise, $proprietaire, ['Client']);
        $run = $this->importateur()->controler($chemin, 'aller-retour.xlsx', $entreprise, $proprietaire);

        $rapport = $run->getRapport();
        self::assertTrue($rapport['confirmable'], 'Un aller-retour à vide ne doit produire aucune erreur.');
        self::assertSame(0, $rapport['creations'], 'Aucune ligne ne doit être créée.');
        self::assertSame(0, $rapport['suppressions'], 'Aucune ligne ne doit être supprimée.');
        // Les lignes existantes sont bien vues comme des mises à jour (identifiant
        // rempli), ce qui est le comportement voulu : elles réécriront les mêmes valeurs.
        self::assertSame(2, $rapport['lignes_lues']);
    }

    /** Le contrôle n'écrit RIEN : c'est ce qui le rend gratuit et rejouable. */
    public function testLeControleNecritRienEnBase(): void
    {
        [$entreprise, $proprietaire] = $this->fixture();
        $this->creerClient($entreprise, $proprietaire, 'ACME Témoin');

        $chemin = $this->exporter($entreprise, $proprietaire, ['Client']);
        $this->ajouterLigne($chemin, 'Client', ['nom' => 'Client Fantôme']);

        $avant = $this->compterClients($entreprise);
        $run = $this->importateur()->controler($chemin, 'sans-ecriture.xlsx', $entreprise, $proprietaire);

        self::assertSame(1, $run->getRapport()['creations'], 'La création est ANNONCÉE…');
        self::assertSame($avant, $this->compterClients($entreprise), '…mais rien n\'est écrit tant que rien n\'est confirmé.');
    }

    /** Une ligne ajoutée sans identifiant devient une création. */
    public function testUneLigneSansIdentifiantDevientUneCreation(): void
    {
        [$entreprise, $proprietaire] = $this->fixture();
        $chemin = $this->exporter($entreprise, $proprietaire, ['Client']);
        $this->ajouterLigne($chemin, 'Client', ['nom' => 'Nouveau Client']);

        $rapport = $this->importateur()->controler($chemin, 'creation.xlsx', $entreprise, $proprietaire)->getRapport();

        self::assertTrue($rapport['confirmable']);
        self::assertSame(1, $rapport['creations']);
    }

    /** Une suppression ne se déduit JAMAIS : elle doit être écrite noir sur blanc. */
    public function testUneSuppressionNeSeDeduitJamais(): void
    {
        [$entreprise, $proprietaire] = $this->fixture();
        $client = $this->creerClient($entreprise, $proprietaire, 'ACME À Garder');

        $chemin = $this->exporter($entreprise, $proprietaire, ['Client']);

        // Aucune action écrite : la ligne existante est une mise à jour, pas une
        // suppression — même si l'utilisateur a vidé toutes ses autres colonnes.
        $rapport = $this->importateur()->controler($chemin, 'sans-action.xlsx', $entreprise, $proprietaire)->getRapport();
        self::assertSame(0, $rapport['suppressions']);

        // Écrite explicitement, elle est bien comprise.
        $this->ecrireCellule($chemin, 'Client', CanevasDEchange::COL_ACTION, 3, CanevasDEchange::ACTION_SUPPRIMER);
        $rapport = $this->importateur()->controler($chemin, 'avec-action.xlsx', $entreprise, $proprietaire)->getRapport();
        self::assertSame(1, $rapport['suppressions']);
    }

    /** Une action inconnue est refusée, et le rapport dit où et quoi écrire. */
    public function testUneActionInconnueEstRefuseeEtSituee(): void
    {
        [$entreprise, $proprietaire] = $this->fixture();
        $this->creerClient($entreprise, $proprietaire, 'ACME Action');

        $chemin = $this->exporter($entreprise, $proprietaire, ['Client']);
        $this->ecrireCellule($chemin, 'Client', CanevasDEchange::COL_ACTION, 3, 'EFFACER');

        $run = $this->importateur()->controler($chemin, 'action.xlsx', $entreprise, $proprietaire);
        $anomalie = $this->anomalieDeCode($run, Anomalie::ACTION_INVALIDE);

        self::assertNotNull($anomalie);
        self::assertSame(3, $anomalie['ligne'], 'L\'anomalie doit situer la ligne.');
        self::assertNotNull($anomalie['colonne'], 'Et la colonne.');
        self::assertStringContainsString(CanevasDEchange::ACTION_SUPPRIMER, $anomalie['message'], 'Le message doit rappeler les valeurs acceptées.');
    }

    /**
     * LA CASCADE PAR REPÈRE LOCAL : un groupe nouveau et un client qui le désigne, dans
     * le même fichier. Sans ce niveau de résolution, on ne pourrait créer que des
     * lignes sans lien.
     */
    public function testUnRepereLocalRelieDeuxLignesNouvelles(): void
    {
        [$entreprise, $proprietaire] = $this->fixture();

        $chemin = $this->exporter($entreprise, $proprietaire, ['Groupe', 'Client']);
        $this->ajouterLigne($chemin, 'Groupe', ['nom' => 'Groupe Neuf', 'description' => 'Créé par import'], 'G1');
        $this->ajouterLigne($chemin, 'Client', ['nom' => 'Client Rattaché', 'groupe' => 'G1']);

        $run = $this->importateur()->controler($chemin, 'cascade.xlsx', $entreprise, $proprietaire);
        $rapport = $run->getRapport();

        self::assertTrue($rapport['confirmable'], 'Un renvoi vers un repère du même fichier doit être accepté : '
            . json_encode(array_column($rapport['anomalies'], 'message'), JSON_UNESCAPED_UNICODE));
        self::assertSame(2, $rapport['creations']);
    }

    /** Un renvoi qui ne désigne rien est une erreur BLOQUANTE, jamais un silence. */
    public function testUnRenvoiIrresoluEstUneErreurBloquante(): void
    {
        [$entreprise, $proprietaire] = $this->fixture();

        $chemin = $this->exporter($entreprise, $proprietaire, ['Groupe', 'Client']);
        $this->ajouterLigne($chemin, 'Client', ['nom' => 'Client Orphelin', 'groupe' => 'Groupe Qui N Existe Pas']);

        $run = $this->importateur()->controler($chemin, 'orphelin.xlsx', $entreprise, $proprietaire);

        self::assertFalse($run->getRapport()['confirmable']);
        $anomalie = $this->anomalieDeCode($run, Anomalie::RENVOI_IRRESOLU);
        self::assertNotNull($anomalie, 'Un renvoi introuvable doit bloquer.');
        self::assertNotNull($anomalie['ligne'], 'Et être situé.');
    }

    /** Un renvoi par NOM d'une ligne existante est accepté : c'est ce qu'un humain tape. */
    public function testUnRenvoiParNomEstAccepte(): void
    {
        [$entreprise, $proprietaire] = $this->fixture();
        $this->creerGroupe($entreprise, $proprietaire, 'Groupe Existant');

        $chemin = $this->exporter($entreprise, $proprietaire, ['Groupe', 'Client']);
        $this->ajouterLigne($chemin, 'Client', ['nom' => 'Client Par Nom', 'groupe' => 'groupe existant']);

        $rapport = $this->importateur()->controler($chemin, 'par-nom.xlsx', $entreprise, $proprietaire)->getRapport();

        self::assertTrue($rapport['confirmable'], 'La casse ne doit pas empêcher la reconnaissance : '
            . json_encode(array_column($rapport['anomalies'], 'message'), JSON_UNESCAPED_UNICODE));
        self::assertSame(1, $rapport['creations']);
    }

    /** Deux lignes de même nom ne se départagent pas : deviner serait rattacher au hasard. */
    public function testUnRenvoiAmbiguEstRefuse(): void
    {
        [$entreprise, $proprietaire] = $this->fixture();
        $this->creerGroupe($entreprise, $proprietaire, 'Doublon');
        $this->creerGroupe($entreprise, $proprietaire, 'Doublon');

        $chemin = $this->exporter($entreprise, $proprietaire, ['Groupe', 'Client']);
        $this->ajouterLigne($chemin, 'Client', ['nom' => 'Client Ambigu', 'groupe' => 'Doublon']);

        $run = $this->importateur()->controler($chemin, 'ambigu.xlsx', $entreprise, $proprietaire);

        self::assertFalse($run->getRapport()['confirmable']);
        self::assertNotNull($this->anomalieDeCode($run, Anomalie::RENVOI_AMBIGU));
    }

    /** Un identifiant d'un autre type de donnée est refusé, avec le motif exact. */
    public function testUnIdentifiantDuMauvaisTypeEstRefuse(): void
    {
        [$entreprise, $proprietaire] = $this->fixture();
        $this->creerClient($entreprise, $proprietaire, 'ACME Type');

        $chemin = $this->exporter($entreprise, $proprietaire, ['Client']);
        $this->ecrireCellule($chemin, 'Client', CanevasDEchange::COL_UID, 3, 'Avenant:42');

        $run = $this->importateur()->controler($chemin, 'mauvais-type.xlsx', $entreprise, $proprietaire);

        self::assertFalse($run->getRapport()['confirmable']);
        self::assertNotNull($this->anomalieDeCode($run, Anomalie::UID_INVALIDE));
    }

    /**
     * Une feuille qu'on n'a pas le droit d'écrire est SIGNALÉE, pas ignorée : sans
     * cela, l'utilisateur croirait ses modifications enregistrées.
     */
    public function testUneFeuilleHorsDroitDEcritureEstSignalee(): void
    {
        [$entreprise, $proprietaire, $lecteurSeul] = $this->fixture();
        $this->creerClient($entreprise, $proprietaire, 'ACME Lecture');

        $chemin = $this->exporter($entreprise, $proprietaire, ['Client']);
        $run = $this->importateur()->controler($chemin, 'lecture-seule.xlsx', $entreprise, $lecteurSeul);

        self::assertFalse($run->getRapport()['confirmable']);
        $anomalie = $this->anomalieDeCode($run, Anomalie::DROIT_INSUFFISANT);
        self::assertNotNull($anomalie, 'Un import sans droit d\'écriture doit être refusé, et dit.');
    }

    /**
     * Une date illisible est refusée EN NOMMANT LA COLONNE, jamais devinée.
     *
     * On ne fige pas l'entité visée : la ressource porteuse d'une date modifiable est
     * cherchée dans le canevas. Un test qui vise « les clients » se met en sommeil le
     * jour où les clients n'ont plus de date — et un test endormi ne protège rien.
     */
    public function testUneDateIllisibleEstRefuseeEtNommee(): void
    {
        [$entreprise, $proprietaire] = $this->fixture();

        $cible = null;
        foreach ($this->canevas()->ressourcesEcrivables($proprietaire) as $ressource) {
            foreach ($ressource->colonnes as $colonne) {
                if ($colonne->type === 'date' && $colonne->estModifiable() && !$colonne->obligatoire) {
                    $cible = [$ressource, $colonne];
                    break 2;
                }
            }
        }
        self::assertNotNull($cible, 'Le périmètre doit comporter au moins une date modifiable.');
        [$ressource, $colonne] = $cible;

        $chemin = $this->exporter($entreprise, $proprietaire, [$ressource->code]);
        $this->ajouterLigne($chemin, $ressource->code, [$colonne->code => 'la semaine prochaine']);

        $run = $this->importateur()->controler($chemin, 'date.xlsx', $entreprise, $proprietaire);

        self::assertFalse($run->getRapport()['confirmable'], 'Une date illisible doit bloquer.');
        $anomalie = $this->anomalieDeCode($run, Anomalie::VALEUR_INVALIDE);
        self::assertNotNull($anomalie, 'Elle doit être signalée comme valeur invalide.');
        self::assertStringContainsString($colonne->libelle, $anomalie['message'], 'Le champ fautif doit être nommé.');
        self::assertNotNull($anomalie['ligne'], 'Et la ligne située.');
    }

    /** Le rapport situe TOUTE anomalie de ligne : feuille, ligne, et colonne si connue. */
    public function testChaqueAnomalieDeLigneEstSituee(): void
    {
        [$entreprise, $proprietaire] = $this->fixture();

        $chemin = $this->exporter($entreprise, $proprietaire, ['Groupe', 'Client']);
        $this->ajouterLigne($chemin, 'Client', ['nom' => 'Client Perdu', 'groupe' => 'Inexistant']);

        $run = $this->importateur()->controler($chemin, 'situe.xlsx', $entreprise, $proprietaire);

        foreach ($run->getRapport()['anomalies'] as $anomalie) {
            if ($anomalie['code'] === Anomalie::RENVOI_IRRESOLU) {
                self::assertNotNull($anomalie['feuille']);
                self::assertNotNull($anomalie['ligne']);
                self::assertNotNull($anomalie['colonne']);
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Outillage
    // ─────────────────────────────────────────────────────────────────────────────

    private function importateur(): ImportateurJsbx
    {
        return static::getContainer()->get(ImportateurJsbx::class);
    }

    private function canevas(): CanevasDEchange
    {
        return static::getContainer()->get(CanevasDEchange::class);
    }

    /** Exporte réellement, et rend le chemin du fichier produit. */
    private function exporter(Entreprise $entreprise, Invite $invite, array $codes): string
    {
        $reponse = static::getContainer()->get(ExportateurJsbx::class)
            ->exporter($entreprise, $invite, $entreprise->getUtilisateur(), $codes, uniqid('t', true));

        ob_start();
        $reponse->sendContent();

        return $this->fichierTemporaire((string) ob_get_clean());
    }

    private function fichierTemporaire(string $contenu): string
    {
        $chemin = tempnam(sys_get_temp_dir(), 'jsbx_test_') . '.xlsx';
        file_put_contents($chemin, $contenu);
        $this->temporaires[] = $chemin;

        return $chemin;
    }

    private function ecrire(Spreadsheet $classeur): string
    {
        $chemin = tempnam(sys_get_temp_dir(), 'jsbx_test_') . '.xlsx';
        (new Xlsx($classeur))->save($chemin);
        $this->temporaires[] = $chemin;

        return $chemin;
    }

    /** Ajoute une ligne de données à la fin d'une feuille, par CODE de colonne. */
    private function ajouterLigne(string $chemin, string $codeRessource, array $valeurs, ?string $repere = null): void
    {
        $classeur = IOFactory::load($chemin);
        $ressource = $this->canevas()->ressource($codeRessource);
        $feuille = $classeur->getSheetByName($ressource->feuille);

        $lettres = $this->lettresParCode($feuille);
        $numero = max(3, $feuille->getHighestDataRow() + 1);

        if ($repere !== null) {
            $feuille->setCellValue($lettres[CanevasDEchange::COL_REF] . $numero, $repere);
        }
        foreach ($valeurs as $code => $valeur) {
            if (isset($lettres[$code])) {
                $feuille->setCellValue($lettres[$code] . $numero, $valeur);
            }
        }

        (new Xlsx($classeur))->save($chemin);
    }

    private function ecrireCellule(string $chemin, string $codeRessource, string $codeColonne, int $numero, string $valeur): void
    {
        $classeur = IOFactory::load($chemin);
        $ressource = $this->canevas()->ressource($codeRessource);
        $feuille = $classeur->getSheetByName($ressource->feuille);

        $lettres = $this->lettresParCode($feuille);
        $feuille->setCellValue($lettres[$codeColonne] . $numero, $valeur);

        (new Xlsx($classeur))->save($chemin);
    }

    /** Supprime physiquement une colonne — le geste qui rend le fichier menteur. */
    private function supprimerColonne(string $chemin, string $codeRessource, string $codeColonne): void
    {
        $classeur = IOFactory::load($chemin);
        $ressource = $this->canevas()->ressource($codeRessource);
        $feuille = $classeur->getSheetByName($ressource->feuille);

        $lettres = $this->lettresParCode($feuille);
        $feuille->removeColumn($lettres[$codeColonne]);

        (new Xlsx($classeur))->save($chemin);
    }

    private function truquerManifeste(string $chemin, string $cle, string $valeur): void
    {
        $classeur = IOFactory::load($chemin);
        $feuille = $classeur->getSheetByName(EcrivainJsbx::FEUILLE_MANIFESTE);

        for ($i = 1; $i <= $feuille->getHighestDataRow(); ++$i) {
            if (trim((string) $feuille->getCell('A' . $i)->getValue()) === $cle) {
                $feuille->setCellValue('C' . $i, $valeur);
                break;
            }
        }

        (new Xlsx($classeur))->save($chemin);
    }

    /** @return array<string, string> code technique => lettre de colonne */
    private function lettresParCode($feuille): array
    {
        $lettres = [];
        $derniere = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($feuille->getHighestDataColumn());
        for ($i = 1; $i <= $derniere; ++$i) {
            $lettre = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i);
            $code = trim((string) $feuille->getCell($lettre . '2')->getValue());
            if ($code !== '') {
                $lettres[$code] = $lettre;
            }
        }

        return $lettres;
    }

    private function premierCode(EchangeImportRun $run): string
    {
        return (string) ($run->getRapport()['anomalies'][0]['code'] ?? '');
    }

    /** @return array<string, mixed>|null */
    private function anomalieDeCode(EchangeImportRun $run, string $code): ?array
    {
        foreach ($run->getRapport()['anomalies'] ?? [] as $anomalie) {
            if (($anomalie['code'] ?? '') === $code) {
                return $anomalie;
            }
        }

        return null;
    }

    private function compterClients(Entreprise $entreprise): int
    {
        return (int) $this->em()->createQueryBuilder()
            ->select('COUNT(c.id)')->from(Client::class, 'c')
            ->andWhere('c.entreprise = :e')->setParameter('e', $entreprise)
            ->getQuery()->getSingleScalarResult();
    }

    private function creerClient(Entreprise $entreprise, Invite $invite, string $nom): Client
    {
        $client = (new Client())->setNom($nom);
        $client->setEntreprise($entreprise);
        $client->setInvite($invite);
        $this->em()->persist($client);
        $this->em()->flush();

        return $client;
    }

    private function creerGroupe(Entreprise $entreprise, Invite $invite, string $nom): Groupe
    {
        $groupe = (new Groupe())->setNom($nom)->setDescription('Groupe de test');
        $groupe->setEntreprise($entreprise);
        $groupe->setInvite($invite);
        $this->em()->persist($groupe);
        $this->em()->flush();

        return $groupe;
    }

    /**
     * Cabinet, propriétaire, et un invité en LECTURE SEULE sur les clients (il a la
     * porte de la rubrique, pas le droit d'écrire les données).
     *
     * @return array{0: Entreprise, 1: Invite, 2: Invite}
     */
    private function fixture(): array
    {
        $em = $this->em();

        $owner = (new Utilisateur())->setEmail(self::OWNER_EMAIL)->setNom('Ctrl')->setVerified(true)->setPassword('x');
        $owner->setPaidTokens(100000);
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

        $lecteur = (new Invite())->setNom('Lecteur')->setEmail('phpunit-echange-lecteur@test.local');
        $lecteur->setProprietaire(false);
        $lecteur->setEntreprise($entreprise);
        $em->persist($lecteur);

        $admin = (new RolesEnAdministration())->setNom('Admin lecteur');
        $admin->setAccessEchange([Invite::ACCESS_LECTURE, Invite::ACCESS_ECRITURE]);
        $admin->setEntreprise($entreprise);
        $admin->setInvite($lecteur);
        $em->persist($admin);
        $lecteur->addRolesEnAdministration($admin);

        // Lecture seule sur les clients : il peut ouvrir la rubrique et exporter, mais
        // pas écrire une seule ligne de données.
        $prod = (new RolesEnProduction())->setNom('Prod lecteur');
        $prod->setAccessClient([Invite::ACCESS_LECTURE]);
        $prod->setEntreprise($entreprise);
        $prod->setInvite($lecteur);
        $em->persist($prod);
        $lecteur->addRolesEnProduction($prod);

        $em->flush();

        // Session ouverte sur ce cabinet : c'est elle qui peuple les listes de choix
        // des formulaires, donc qui rend les renvois acceptables.
        $this->client->loginUser($owner);

        return [$entreprise, $proprietaire, $lecteur];
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /** Purge dérivée du schéma — cf. ExportJsbxTest, même raison. */
    private function nettoyer(): void
    {
        $cnx = $this->em()->getConnection();
        $ids = $cnx->fetchFirstColumn('SELECT id FROM entreprise WHERE nom = ?', [self::ENT]);

        $cnx->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        try {
            if ($ids !== []) {
                $enfants = $cnx->fetchAllAssociative(
                    'SELECT DISTINCT TABLE_NAME, COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
                     WHERE REFERENCED_TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = ?',
                    ['entreprise'],
                );
                foreach ($ids as $id) {
                    foreach ($enfants as $enfant) {
                        $sql = $enfant['TABLE_NAME'] === 'utilisateur'
                            ? sprintf('UPDATE `%s` SET `%s` = NULL WHERE `%s` = ?', $enfant['TABLE_NAME'], $enfant['COLUMN_NAME'], $enfant['COLUMN_NAME'])
                            : sprintf('DELETE FROM `%s` WHERE `%s` = ?', $enfant['TABLE_NAME'], $enfant['COLUMN_NAME']);
                        $cnx->executeStatement($sql, [$id]);
                    }
                    $cnx->executeStatement('DELETE FROM entreprise WHERE id = ?', [$id]);
                }
            }
            foreach ([self::OWNER_EMAIL, 'phpunit-echange-lecteur@test.local'] as $email) {
                $cnx->executeStatement('DELETE FROM utilisateur WHERE email = ?', [$email]);
            }
        } finally {
            $cnx->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        }

        $this->em()->clear();
    }
}
