<?php

namespace App\Tests\Echange;

use App\Ai\Mutation\MutationOperation;
use App\Echange\Classeur\LigneLue;
use App\Echange\Etat\CatalogueDesColonnes;
use App\Echange\Reprise\ReconstitueurDeTranche;
use App\Echange\Service\Anomalie;
use App\Entity\Assureur;
use App\Entity\Avenant;
use App\Entity\Chargement;
use App\Entity\Client;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Note;
use App\Entity\Piste;
use App\Entity\Risque;
use App\Entity\Tranche;
use App\Entity\TypeRevenu;
use App\Entity\Utilisateur;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * LA RECONSTITUTION : une ligne plate redevient une affaire.
 *
 * ── CE QUE CES TESTS GARDENT ────────────────────────────────────────────────────────
 * La feuille `DONNEES` décrit une TRANCHE par ligne, mais chaque ligne porte toute son
 * ascendance — client, risque, assureur, police, composition de la prime. Une police à
 * quatre échéances occupe donc quatre lignes qui répètent les quatre premiers niveaux.
 *
 * ⚠ LA FAUTE À CRAINDRE N'EST PAS UNE ERREUR, C'EST UN DOUBLON. Quatre lignes qui
 * créeraient quatre polices ne casseraient rien : le portefeuille doublerait de volume,
 * les primes se compteraient plusieurs fois, et l'on ne s'en apercevrait qu'aux totaux —
 * bien après avoir confirmé l'import. La CONVERGENCE est donc le premier test de ce
 * fichier, et le plus important.
 */
final class RepriseTest extends KernelTestCase
{
    private const ENT = 'PHPUnit Reprise SARL';
    private const OWNER_EMAIL = 'reprise-phpunit@example.test';

    protected function setUp(): void
    {
        self::bootKernel();
        $this->nettoyer();
    }

    protected function tearDown(): void
    {
        $this->nettoyer();
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // La convergence
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * ⚠ DEUX ÉCHÉANCES D'UNE MÊME POLICE FONT UNE POLICE — ET DEUX ÉCHÉANCES.
     *
     * C'est le test qui tient tout le reste. Le repère de chaque niveau est dérivé du
     * CONTENU de la ligne (la référence de police), et non de son rang : la seconde ligne
     * retrouve donc le repère de la première et s'y renvoie, au lieu de recréer.
     */
    public function testDeuxEcheancesDUneMemePoliceNeFontQuUnePolice(): void
    {
        $entreprise = $this->cabinet();
        $colonnes = $this->colonnes();
        $reconstitueur = $this->reconstitueur();

        $operations = [];
        foreach ([1, 2] as $rang) {
            $anomalies = [];
            $operations = array_merge($operations, $reconstitueur->pour(
                $this->ligne([
                    'policeReference' => 'POL/2026/001',
                    'policeDateEffet' => '01/01/2026',
                    'policeEcheance' => '31/12/2026',
                    'trancheNom' => 'Échéance ' . $rang,
                    'tranchePart' => 50,
                    'assure' => 'KIN AVIA',
                    'risque' => 'RC Aviation',
                    'assureur' => 'SFA CONGO',
                ], $rang + 1),
                $colonnes,
                $entreprise,
                $anomalies,
            ));

            self::assertSame([], $this->erreurs($anomalies), 'Aucune erreur attendue sur ces lignes.');
        }

        $comptes = $this->comptesParEntite($operations);

        self::assertSame(1, $comptes['Client'] ?? 0, 'Un seul client pour les deux lignes.');
        self::assertSame(1, $comptes['Risque'] ?? 0);
        self::assertSame(1, $comptes['Assureur'] ?? 0);
        self::assertSame(1, $comptes['Piste'] ?? 0, 'Une seule opportunité.');
        self::assertSame(1, $comptes['Cotation'] ?? 0, 'Une seule proposition.');
        self::assertSame(1, $comptes['Avenant'] ?? 0, 'UNE seule police : c\'est tout l\'enjeu.');
        self::assertSame(2, $comptes['Tranche'] ?? 0, 'Mais DEUX échéances.');
    }

    /**
     * ⚠ UNE POLICE ET SON AVENANT N° 2 SONT DEUX ACTES, PAS UN DOUBLON.
     *
     * Ils partagent la référence : les confondre écraserait l'un par l'autre, et la
     * police porterait les dates de son avenant. Le numéro fait donc partie de la clé.
     */
    public function testUnAvenantNumeroteEstUnActeDistinct(): void
    {
        $entreprise = $this->cabinet();
        $colonnes = $this->colonnes();
        $reconstitueur = $this->reconstitueur();

        $operations = [];
        foreach ([['', 'Prime unique'], ['2', 'Prime de l\'avenant']] as [$numero, $nom]) {
            $anomalies = [];
            $operations = array_merge($operations, $reconstitueur->pour(
                $this->ligne([
                    'policeReference' => 'POL/2026/007',
                    'policeDateEffet' => '01/01/2026',
                    'policeEcheance' => '31/12/2026',
                    'risque' => 'RC Aviation',
                    'policeNumeroAvenant' => $numero,
                    'trancheNom' => $nom,
                    'assure' => 'KIN AVIA',
                    'assureur' => 'SFA CONGO',
                ], 2),
                $colonnes,
                $entreprise,
                $anomalies,
            ));
        }

        $comptes = $this->comptesParEntite($operations);

        self::assertSame(2, $comptes['Avenant'] ?? 0, 'Deux actes : la police et son avenant.');
        self::assertSame(1, $comptes['Cotation'] ?? 0, 'Mais une seule proposition les porte.');
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Ce qui ne se devine jamais
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * ⚠ UNE LIGNE SANS RÉFÉRENCE DE POLICE NI IDENTIFIANT EST REFUSÉE, ET LE MOTIF LE DIT.
     *
     * Aucune combinaison de client, risque, assureur et dates ne départage deux polices
     * avec certitude : deux affaires du même client chez le même assureur sur le même
     * risque et au même jour sont possibles. Les fusionner perdrait une affaire, les
     * séparer à tort en dupliquerait une — les deux erreurs sont graves, donc on refuse.
     */
    public function testUneLigneSansCleEstRefuseeAvecSonMotif(): void
    {
        $entreprise = $this->cabinet();
        $anomalies = [];

        $operations = $this->reconstitueur()->pour(
            $this->ligne(['assure' => 'KIN AVIA', 'trancheNom' => 'Échéance orpheline'], 9),
            $this->colonnes(),
            $entreprise,
            $anomalies,
        );

        self::assertSame([], $operations, 'Rien ne doit être écrit sur une ligne qu\'on ne sait pas rattacher.');
        $erreurs = $this->erreurs($anomalies);
        self::assertCount(1, $erreurs);
        self::assertStringContainsString('référence de police', $erreurs[0]->message);
        self::assertSame(9, $erreurs[0]->ligne, 'Le rapport doit dire OÙ, sinon il faut relire tout le fichier.');
    }

    /**
     * ⚠ UNE SUPPRESSION NE PORTE QUE SUR L'ÉCHÉANCE, jamais sur son ascendance.
     *
     * Supprimer la police, la proposition et le client parce qu'on a effacé une échéance
     * serait une catastrophe silencieuse : les autres échéances de la même police
     * disparaîtraient avec elle.
     */
    public function testUneSuppressionNeTouchePasALAscendance(): void
    {
        $entreprise = $this->cabinet();
        $anomalies = [];

        $operations = $this->reconstitueur()->pour(
            $this->ligne([
                'id' => 4242,
                '_action' => 'SUPPRIMER',
                'policeReference' => 'POL/2026/001',
                'policeDateEffet' => '01/01/2026',
                'policeEcheance' => '31/12/2026',
                'risque' => 'RC Aviation',
                'assure' => 'KIN AVIA',
            ], 3),
            $this->colonnes(),
            $entreprise,
            $anomalies,
        );

        self::assertCount(1, $operations, 'Une suppression, et une seule.');
        self::assertSame('Tranche', $operations[0]->entityShortName);
        self::assertSame(MutationOperation::OP_DELETE, $operations[0]->op);
        self::assertSame(4242, $operations[0]->targetId);
    }

    /** Une suppression sans identifiant ne peut désigner personne : elle est refusée. */
    public function testUneSuppressionSansIdentifiantEstRefusee(): void
    {
        $entreprise = $this->cabinet();
        $anomalies = [];

        $operations = $this->reconstitueur()->pour(
            $this->ligne(['_action' => 'SUPPRIMER', 'policeReference' => 'POL/2026/001'], 3),
            $this->colonnes(),
            $entreprise,
            $anomalies,
        );

        self::assertSame([], $operations);
        self::assertCount(1, $this->erreurs($anomalies));
    }

    /**
     * ⚠ UNE LIGNE QUI PORTE SON IDENTIFIANT NE REFAIT PAS SON ASCENDANCE.
     *
     * Un export réimporté tel quel ne doit toucher que les échéances : réécrire à chaque
     * fois le client, la proposition et la police produirait des modifications que
     * personne n'a demandées, et le journal annoncerait cinq écritures pour une.
     */
    public function testUneLigneIdentifieeNeTouchePasALAscendance(): void
    {
        $entreprise = $this->cabinet();
        $anomalies = [];

        $operations = $this->reconstitueur()->pour(
            $this->ligne([
                'id' => 77,
                'policeReference' => 'POL/2026/001',
                'policeDateEffet' => '01/01/2026',
                'policeEcheance' => '31/12/2026',
                'risque' => 'RC Aviation',
                'trancheNom' => 'Échéance corrigée',
                'assure' => 'KIN AVIA',
                'assureur' => 'SFA CONGO',
            ], 2),
            $this->colonnes(),
            $entreprise,
            $anomalies,
        );

        self::assertCount(1, $operations);
        self::assertSame('Tranche', $operations[0]->entityShortName);
        self::assertSame(MutationOperation::OP_EDIT, $operations[0]->op);
        self::assertSame(77, $operations[0]->targetId);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // La convergence CONTRE LA BASE — un second dépôt n'empile pas
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * ⚠ UNE POLICE DÉJÀ REPRISE N'EST PAS RECRÉÉE PAR UN SECOND DÉPÔT.
     *
     * C'est le pendant du premier test de ce fichier, et il manquait. Le registre de la
     * reconstitution fait converger les lignes d'un MÊME fichier ; il vit en mémoire et
     * meurt avec la passe. Un portefeuille se reprend pourtant en plusieurs fois — parce
     * qu'on l'a découpé, parce qu'on corrige une ligne et qu'on redépose, parce qu'on le
     * complète six mois plus tard. Chacun de ces gestes recréait l'opportunité, la
     * proposition et la police.
     *
     * Rien ne cassait : le portefeuille doublait de volume, les primes se comptaient deux
     * fois, et cela ne se voyait qu'aux totaux.
     */
    public function testUnePoliceDejaEnBaseNestPasRecreee(): void
    {
        $entreprise = $this->cabinet();
        $this->policeEnBase($entreprise, 'POL/2026/001', 'Échéance 1');

        $anomalies = [];
        $operations = $this->reconstitueur()->pour(
            $this->ligne([
                'policeReference' => 'POL/2026/001',
                'policeDateEffet' => '01/01/2026',
                'policeEcheance' => '31/12/2026',
                'trancheNom' => 'Échéance 2',
                'tranchePayableAt' => '2026-06-30',
                'assure' => 'KIN AVIA',
                'risque' => 'RC Aviation',
                'assureur' => 'SFA CONGO',
            ], 2),
            $this->colonnes(),
            $entreprise,
            $anomalies,
        );

        self::assertSame([], $this->erreurs($anomalies));

        $comptes = $this->comptesParEntite($operations);

        self::assertArrayNotHasKey('Piste', $comptes, 'L\'opportunité existe : rien à créer.');
        self::assertArrayNotHasKey('Cotation', $comptes, 'La proposition existe : rien à créer.');
        self::assertArrayNotHasKey('Avenant', $comptes, 'LA POLICE EXISTE : c\'est tout l\'enjeu.');
        self::assertArrayNotHasKey('Client', $comptes, 'Le client est reconnu par son nom.');
        self::assertSame(1, $comptes['Tranche'] ?? 0, 'Seule l\'échéance nouvelle est écrite.');
    }

    /**
     * ⚠ REDÉPOSER LE MÊME FICHIER NE DOIT RIEN AJOUTER DU TOUT.
     *
     * C'est le geste le plus banal de la reprise : on corrige une ligne refusée, et l'on
     * redépose le classeur entier. Les échéances déjà écrites doivent être RETROUVÉES et
     * mises à jour, jamais empilées — sans quoi la police porterait deux fois chacune de
     * ses échéances, et la prime attendue doublerait.
     *
     * ⚠ LE REPÈRE LOCAL NE POUVAIT PAS S'EN CHARGER : il porte le numéro de ligne, ce qui
     * le rend unique DANS le fichier — son seul rôle — et muet sur le portefeuille.
     */
    public function testUneEcheanceDejaEnBaseEstMiseAJourEtNonAjoutee(): void
    {
        $entreprise = $this->cabinet();
        $idTranche = $this->policeEnBase($entreprise, 'POL/2026/001', 'Échéance 1');

        $anomalies = [];
        $operations = $this->reconstitueur()->pour(
            $this->ligne([
                'policeReference' => 'POL/2026/001',
                'policeDateEffet' => '01/01/2026',
                'policeEcheance' => '31/12/2026',
                'risque' => 'RC Aviation',
                'trancheNom' => 'Échéance 1',
                'tranchePayableAt' => '2026-01-15',
                'assure' => 'KIN AVIA',
                'assureur' => 'SFA CONGO',
            ], 2),
            $this->colonnes(),
            $entreprise,
            $anomalies,
        );

        self::assertSame([], $this->erreurs($anomalies));
        self::assertCount(1, $operations, 'Une seule écriture : la mise à jour de l\'échéance.');
        self::assertSame('Tranche', $operations[0]->entityShortName);
        self::assertSame(MutationOperation::OP_EDIT, $operations[0]->op);
        self::assertSame($idTranche, $operations[0]->targetId);
    }

    /**
     * ⚠ UN SOLDE D'OUVERTURE NE SE REJOUE PAS SUR UNE ÉCHÉANCE RETROUVÉE.
     *
     * La règle existait déjà pour une ligne portant son identifiant. Elle vaut désormais
     * pour toute échéance reconnue par ses signes : relire « prime encaissée » à chaque
     * dépôt ajouterait un règlement de plus à chaque fois. La prime paraîtrait encaissée
     * deux fois, le solde du client tomberait à zéro, et rien ne le signalerait.
     */
    public function testUnSoldeDOuvertureNestPasRejoueSurUneEcheanceRetrouvee(): void
    {
        $entreprise = $this->cabinet();
        $this->policeEnBase($entreprise, 'POL/2026/001', 'Échéance 1');

        $anomalies = [];
        $operations = $this->reconstitueur()->pour(
            $this->ligne([
                'policeReference' => 'POL/2026/001',
                'policeDateEffet' => '01/01/2026',
                'policeEcheance' => '31/12/2026',
                'risque' => 'RC Aviation',
                'trancheNom' => 'Échéance 1',
                'tranchePayableAt' => '2026-01-15',
                'ouverturePrimeEncaissee' => 250000,
                'ouverturePrimeLe' => '2026-02-01',
                'assure' => 'KIN AVIA',
                'assureur' => 'SFA CONGO',
            ], 2),
            $this->colonnes(),
            $entreprise,
            $anomalies,
        );

        self::assertCount(1, $operations);
        self::assertSame([], $operations[0]->collections, 'Aucun règlement d\'ouverture rejoué.');
    }

    /**
     * ⚠ DEUX POLICES DE MÊME RÉFÉRENCE EN BASE : ON REFUSE, ON NE CHOISIT PAS.
     *
     * C'est l'héritage des imports d'avant cette correction. Rattacher une échéance à
     * l'une des deux, ce serait se tromper une fois sur deux en silence ; en créer une
     * troisième aggraverait le doublon. Le refus nomme la référence et dit quoi faire.
     */
    public function testUneReferenceEnDoubleDansLeCabinetEstRefusee(): void
    {
        $entreprise = $this->cabinet();
        $this->policeEnBase($entreprise, 'POL/2026/001', 'Échéance 1');
        $this->policeEnBase($entreprise, 'POL/2026/001', 'Échéance 1 bis');

        $anomalies = [];
        $operations = $this->reconstitueur()->pour(
            $this->ligne([
                'policeReference' => 'POL/2026/001',
                'policeDateEffet' => '01/01/2026',
                'policeEcheance' => '31/12/2026',
                'risque' => 'RC Aviation',
                'trancheNom' => 'Échéance 2',
                'assure' => 'KIN AVIA',
                'assureur' => 'SFA CONGO',
            ], 2),
            $this->colonnes(),
            $entreprise,
            $anomalies,
        );

        self::assertSame([], $operations, 'Rien n\'est écrit tant que le doublon n\'est pas levé.');

        $erreurs = $this->erreurs($anomalies);
        self::assertCount(1, $erreurs);
        self::assertStringContainsString('POL/2026/001', $erreurs[0]->message);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // La prime, et ce qui la produit
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * ⚠ LA PRIME EST ÉCRITE EN COLLECTION DE LA PROPOSITION, depuis UNE COLONNE PAR
     * CHARGEMENT.
     *
     * `ChargementPourPrime` n'a pas de feuille dans le format normalisé — elle est absente
     * du périmètre d'échange —, si bien qu'une reprise par ce format rend des propositions
     * SANS PRIME, sans que rien ne le signale. Elle est donc portée en collection
     * imbriquée, ce que le circuit d'écriture sait déjà faire.
     *
     * ⚠ ET LE PRORATA EST REMONTÉ. La colonne porte la part de l'ÉCHÉANCE — sans quoi elle
     * ne se totaliserait pas juste ; la cotation, elle, porte le tout. À 50 %, une colonne
     * à 5 000 vaut donc 10 000 sur la proposition.
     */
    public function testLaPrimeEstEcriteEnCollectionDeLaProposition(): void
    {
        $entreprise = $this->cabinet();
        $anomalies = [];

        $operations = $this->reconstitueur()->pour(
            $this->ligne([
                'policeReference' => 'POL/2026/010',
                'policeDateEffet' => '01/01/2026',
                'policeEcheance' => '31/12/2026',
                'risque' => 'RC Aviation',
                'assure' => 'KIN AVIA',
                'assureur' => 'SFA CONGO',
                'trancheNom' => 'Une échéance sur deux',
                'tranchePart' => 50,
                $this->colonneDeChargement(Chargement::FONCTION_PRIME_NETTE) => 5000,
                $this->colonneDeChargement(Chargement::FONCTION_FRAIS_ADMIN) => 250,
                'commissionRevenus' => 'Commission Ordinaire ; Honoraire de gestion = 12%',
            ], 2),
            $this->colonnes(),
            $entreprise,
            $anomalies,
        );

        self::assertSame([], $this->erreurs($anomalies));

        $cotation = $this->operation($operations, 'Cotation');
        self::assertNotNull($cotation, 'La proposition doit être créée.');
        self::assertCount(2, $cotation->collections['chargements'] ?? [], 'Deux chargements.');
        self::assertCount(2, $cotation->collections['revenus'] ?? [], 'Deux revenus.');

        $montants = [];
        foreach ($cotation->collections['chargements'] as $chargement) {
            $montants[$chargement->fields['nom']] = $chargement->fields['montantFlatExceptionel'] ?? null;
        }

        // ⚠ LE DOUBLE DE CE QUE LA LIGNE PORTE : la part est de 50 %.
        self::assertEqualsWithDelta(10000.0, $montants['Prime nette'], 0.01);
        self::assertEqualsWithDelta(500.0, $montants['Frais accessoires'], 0.01);

        // ⚠ UN TAUX NE S'ÉCRIT QUE S'IL DÉROGE. « Commission Ordinaire » sans valeur laisse
        // la cascade résoudre le taux à la lecture — un type marqué « pourcentage du
        // risque » va chercher celui du risque. L'écrire le FIGERAIT, et la commission
        // cesserait de suivre le risque le jour où son taux change.
        $revenus = [];
        foreach ($cotation->collections['revenus'] as $revenu) {
            $revenus[$revenu->fields['nom']] = $revenu->fields;
        }
        self::assertArrayNotHasKey('tauxExceptionel', $revenus['Commission Ordinaire']);
        self::assertSame(12.0, $revenus['Honoraire de gestion']['tauxExceptionel']);
    }

    /**
     * ⚠ SANS PART, LA LIGNE VAUT POUR LA TOTALITÉ — et surtout, on ne divise pas par zéro.
     *
     * Une échéance sans part est une échéance unique : le montant lu EST celui de la
     * cotation. Supposer autre chose donnerait une prime inventée, ou une erreur fatale.
     */
    public function testSansPartLeMontantEstCeluiDeLaCotation(): void
    {
        $entreprise = $this->cabinet();
        $anomalies = [];

        $operations = $this->reconstitueur()->pour(
            $this->ligne([
                'policeReference' => 'POL/2026/014',
                'policeDateEffet' => '01/01/2026',
                'policeEcheance' => '31/12/2026',
                'risque' => 'RC Aviation',
                'assure' => 'KIN AVIA',
                'assureur' => 'SFA CONGO',
                $this->colonneDeChargement(Chargement::FONCTION_PRIME_NETTE) => 7000,
            ], 2),
            $this->colonnes(),
            $entreprise,
            $anomalies,
        );

        $cotation = $this->operation($operations, 'Cotation');
        self::assertNotNull($cotation);
        self::assertEqualsWithDelta(
            7000.0,
            $cotation->collections['chargements'][0]->fields['montantFlatExceptionel'],
            0.01,
        );
    }

    /**
     * ⚠ UNE FONCTION QUE LE CABINET NE POURVOIT PAS EST UN REFUS QUI DIT QUOI CRÉER.
     *
     * La colonne « Prime · Fronting » existe partout — elle vient du modèle et non du
     * catalogue —, mais le montant qu'on y écrit doit se rattacher à un TYPE du cabinet :
     * c'est lui qui donne au chargement sa place dans le calcul de la prime. Fabriquer ce
     * type à la volée donnerait une configuration muette, que personne n'a choisie.
     *
     * ⚠ ET LE REFUS NOMME LA FONCTION, pas un code de colonne. « Aucun type de chargement
     * “Fronting” » se corrige ; « colonne chargement_fronting inconnue » ne se corrige pas.
     */
    public function testUneFonctionNonPourvueEstRefuseeEtNonCreee(): void
    {
        $entreprise = $this->cabinet();
        $anomalies = [];

        // Le cabinet de test porte « Prime nette » et « Frais accessoires » ; le FRONTING,
        // non. La colonne, elle, est là — c'est tout l'intérêt des quatre colonnes fixes.
        $operations = $this->reconstitueur()->pour(
            $this->ligne([
                'policeReference' => 'POL/2026/011',
                'policeDateEffet' => '01/01/2026',
                'policeEcheance' => '31/12/2026',
                'risque' => 'RC Aviation',
                'assure' => 'KIN AVIA',
                'assureur' => 'SFA CONGO',
                $this->colonneDeChargement(Chargement::FONCTION_FRONTING) => 999,
            ], 2),
            $this->colonnes(),
            $entreprise,
            $anomalies,
        );

        $erreurs = $this->erreurs($anomalies);
        self::assertCount(1, $erreurs);
        self::assertStringContainsString('Fronting', $erreurs[0]->message, 'La fonction manquante doit être nommée.');

        // ⚠ ET RIEN N'EST FABRIQUÉ : aucun type inventé, aucun chargement orphelin.
        $cotation = $this->operation($operations, 'Cotation');
        self::assertSame([], $cotation?->collections['chargements'] ?? []);
    }

    /**
     * ⚠ UN POSTE DE CATALOGUE NE PEUT PLUS EXISTER EN DOUBLE — la base le refuse.
     *
     * Ce test en remplace un autre, et le remplacement dit ce qui a changé. Le catalogue
     * réel portait « Commission Ordinaire » SIX fois et « Prime nette » six fois,
     * séquelles d'un semis rejoué : la reprise retenait alors le premier et le DISAIT,
     * plutôt que de bloquer tout le portefeuille pour un choix sans conséquence.
     *
     * C'était soigner le symptôme. Le semis est devenu idempotent, les doublons ont été
     * fusionnés en base, et un index UNIQUE (entreprise, nom) interdit désormais la
     * rechute — parce qu'une règle qui ne vit que dans du PHP est une règle qu'un import
     * ou un script de reprise contourne sans le savoir.
     *
     * ⚠ ET L'INDEX EST INSENSIBLE À LA CASSE, par la collation de la colonne. C'est voulu :
     * « Écart » et « écart » désignent le même poste, et deux lignes qui ne se distinguent
     * que par une majuscule sont un doublon pour tout le monde sauf pour la machine.
     */
    public function testUnPosteDeCatalogueNePeutPasExisterEnDouble(): void
    {
        $entreprise = $this->cabinet();
        $em = $this->em();

        $doublon = (new TypeRevenu())
            ->setNom('Commission Ordinaire')
            ->setShared(false)
            ->setMultipayments(true)
            ->setRedevable(TypeRevenu::REDEVABLE_ASSUREUR);
        $doublon->setEntreprise($entreprise);
        $em->persist($doublon);

        $this->expectException(UniqueConstraintViolationException::class);
        $em->flush();
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Les soldes d'ouverture
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * ⚠ CE TEST EST LE PLUS IMPORTANT DE LA SECTION : les ouvertures ne se rejouent PAS.
     *
     * Un solde d'ouverture décrit une situation de DÉPART. Le relire sur une échéance qui
     * existe déjà ajouterait un second règlement à chaque dépôt du même fichier : les
     * encaissements doubleraient à chaque aller-retour — sans erreur, sans avertissement,
     * et l'on ne s'en apercevrait qu'en constatant une prime réglée deux fois.
     */
    public function testLesSoldesDOuvertureNeSontPasRejouesSurUneEcheanceExistante(): void
    {
        $entreprise = $this->cabinet();
        $anomalies = [];

        $operations = $this->reconstitueur()->pour(
            $this->ligne([
                'id' => 77,
                'policeReference' => 'POL/2026/020',
                'policeDateEffet' => '01/01/2026',
                'policeEcheance' => '31/12/2026',
                'risque' => 'RC Aviation',
                'assure' => 'KIN AVIA',
                'assureur' => 'SFA CONGO',
                'trancheNom' => 'Prime unique',
                'commissionRevenus' => 'Commission Ordinaire',
                'ouverturePrimeEncaissee' => 8000,
                'ouvertureCommissionEncaissee' => 1200,
                'ouvertureRetroReversee' => 300,
            ], 2),
            $this->colonnes(),
            $entreprise,
            $anomalies,
        );

        self::assertCount(1, $operations, 'Une échéance identifiée ne produit QUE sa mise à jour.');
        self::assertSame('Tranche', $operations[0]->entityShortName);
        self::assertSame(MutationOperation::OP_EDIT, $operations[0]->op);
        self::assertSame([], $operations[0]->collections, 'Aucune écriture d\'ouverture imbriquée.');
    }

    /**
     * LA PRIME DÉJÀ RÉGLÉE devient UN règlement, imbriqué sous l'échéance.
     *
     * ⚠ UNE SEULE ÉCRITURE POUR UN SOLDE, ET C'EST ASSUMÉ. Un solde de 8 000 devient un
     * versement de 8 000, non les trois qui l'ont composé : une ligne plate ne peut pas
     * porter un journal. On reprend une situation, pas une comptabilité.
     */
    public function testLaPrimeDejaRegleeDevientUnReglementDeLEcheance(): void
    {
        $entreprise = $this->cabinet();
        $anomalies = [];

        $operations = $this->reconstitueur()->pour(
            $this->ligne([
                'policeReference' => 'POL/2026/021',
                'risque' => 'RC Aviation',
                'policeDateEffet' => '2026-01-31',
                'policeEcheance' => '31/12/2026',
                'assure' => 'KIN AVIA',
                'assureur' => 'SFA CONGO',
                'trancheNom' => 'Prime unique',
                'ouverturePrimeEncaissee' => 8000,
            ], 2),
            $this->colonnes(),
            $entreprise,
            $anomalies,
        );

        self::assertSame([], $this->erreurs($anomalies));

        $tranche = $this->operation($operations, 'Tranche');
        self::assertNotNull($tranche);

        $reglements = $tranche->collections['paiementsPrime'] ?? [];
        self::assertCount(1, $reglements, 'Un solde, un règlement.');
        self::assertSame(8000.0, $reglements[0]->fields['montant']);

        // ⚠ LA DATE SE REPLIE SUR CELLE DE LA POLICE. Un règlement sans date ne se
        // rattache à aucun exercice : il disparaîtrait des états par période.
        //
        // ⚠ ET LE FORMAT PORTE L'HEURE, ce qui n'est pas un détail : toutes les
        // propriétés temporelles visées sont des `datetime_immutable`, dont le widget
        // attend « aaaa-mm-jjThh:mm ». Avoir écrit « aaaa-mm-jj » a fait rejeter les
        // soixante-dix-neuf lignes d'un export réimporté, sur « Veuillez saisir une date
        // et une heure valides » — une erreur qui accuse la saisie alors que la faute
        // était dans la conversion. Ce test est le verrou de ce format.
        self::assertSame('2026-01-31T00:00', $reglements[0]->fields['paidAt']);

        // ⚠ ET IL PORTE SA PROVENANCE. Sans cette référence, un règlement de reprise
        // ressemble trait pour trait à un encaissement réel : impossible, six mois plus
        // tard, de distinguer ce que le cabinet a reçu de ce qu'on a déclaré.
        self::assertSame('REPRISE', $reglements[0]->fields['reference']);
    }

    /**
     * ⚠ LA COMMISSION DÉJÀ ENCAISSÉE EST REPRISE — note, article et règlement.
     *
     * ── CE TEST A ÉTÉ RETOURNÉ, ET C'EST TOUT SON INTÉRÊT ─────────────────────────
     * Il gardait le contraire : « aucune note ne doit être posée ». La reprise renonçait,
     * parce que `Note::$validated` et `Note::$signature` sont NON NULLES en base et
     * ABSENTES de `NoteType` — le contrôle à blanc les réclamait sans qu'aucun champ ne
     * permette de les fournir. Cinquante refus sur un portefeuille réel, un par échéance.
     *
     * On en avait conclu qu'une note de reprise posait une question métier. Elle n'en pose
     * aucune : l'écran lui-même y met `false` et l'horodatage du moment. Ces valeurs ont
     * rejoint `ValeursDeNaissance`, appliquée par le circuit d'écriture commun — et le
     * dernier travail manuel de la reprise a disparu avec elles.
     *
     * ⚠ LES TROIS PIÈCES SONT INDISSOCIABLES : sans l'article la note ne vaut rien, sans
     * le règlement rien n'est encaissé, et sans le lien vers le revenu
     * `getArticleMontant()` rend zéro.
     */
    public function testLaCommissionEncaisseeDevientUneNoteSoldee(): void
    {
        $entreprise = $this->cabinet();
        $anomalies = [];

        $operations = $this->reconstitueur()->pour(
            $this->ligne([
                'policeReference' => 'POL/2026/022',
                'policeDateEffet' => '01/01/2026',
                'policeEcheance' => '31/12/2026',
                'risque' => 'RC Aviation',
                'assure' => 'KIN AVIA',
                'assureur' => 'SFA CONGO',
                'trancheNom' => 'Prime unique',
                'commissionRevenus' => 'Commission Ordinaire',
                'ouvertureCommissionEncaissee' => 1200,
                'ouvertureCommissionLe' => '15/01/2026',
            ], 2),
            $this->colonnes(),
            $entreprise,
            $anomalies,
        );

        self::assertSame([], $this->erreurs($anomalies), 'La reprise ne réclame plus rien.');

        $note = $this->operation($operations, 'Note');
        self::assertNotNull($note, 'La note doit être posée : ' . $this->messages($anomalies));

        // Une commission est un DÉBIT, adressé à qui la doit — ici l'assureur, qui
        // précompte. Le calcul n'accepte que les notes adressées au client ou à l'assureur.
        self::assertSame(Note::TYPE_NOTE_DE_DEBIT, $note->fields['type']);
        self::assertSame(Note::TO_ASSUREUR, $note->fields['addressedTo']);
        self::assertArrayHasKey('assureur', $note->fields);

        // ⚠ L'ARTICLE PORTE LES DEUX LIENS. Sans `revenuFacture`, `getArticleMontant()`
        // rend zéro et la note ne compte rien — une coquille que l'écran afficherait sans
        // jamais l'additionner.
        self::assertCount(1, $note->collections['articles'] ?? [], 'UNE note, UN article.');
        $article = $note->collections['articles'][0];
        self::assertArrayHasKey('tranche', $article->fields);
        self::assertArrayHasKey('revenuFacture', $article->fields);

        // C'est le RÈGLEMENT qui porte le montant encaissé : le calcul en tire la
        // proportion payée de la note, donc exactement ce qui a été versé.
        self::assertCount(1, $note->collections['paiements'] ?? [], 'UNE note, UN règlement.');
        self::assertSame(1200.0, $note->collections['paiements'][0]->fields['montant']);
    }

    /**
     * ⚠ SANS COMMISSION NOMMÉE, ON LE DIT PLUTÔT QUE D'ÉCRIRE UNE COQUILLE.
     *
     * L'article d'une note tire son montant du revenu qu'il facture. Une ligne qui encaisse
     * une commission sans dire LAQUELLE produirait une note à zéro : elle s'afficherait au
     * portefeuille sans jamais entrer dans aucun total. Le refus nomme la colonne à
     * remplir — c'est le seul cas où la commission reste à la charge de l'utilisateur.
     */
    public function testUneCommissionEncaisseeSansRevenuNommeEstSignalee(): void
    {
        $entreprise = $this->cabinet();
        $anomalies = [];

        $operations = $this->reconstitueur()->pour(
            $this->ligne([
                'policeReference' => 'POL/2026/023',
                'policeDateEffet' => '01/01/2026',
                'policeEcheance' => '31/12/2026',
                'risque' => 'RC Aviation',
                'assure' => 'KIN AVIA',
                'assureur' => 'SFA CONGO',
                'trancheNom' => 'Prime unique',
                'ouvertureCommissionEncaissee' => 1200,
            ], 2),
            $this->colonnes(),
            $entreprise,
            $anomalies,
        );

        self::assertNull($this->operation($operations, 'Note'), 'Une note sans revenu ne compterait rien.');

        // ⚠ UN AVERTISSEMENT, ET NON UNE ERREUR : le reste de la ligne doit passer.
        self::assertSame([], $this->erreurs($anomalies));
        self::assertStringContainsString('1 200,00', $this->messages($anomalies), 'Le montant laissé de côté est nommé.');
        self::assertStringContainsString('Commission · Revenus', $this->messages($anomalies), 'Et la colonne à remplir.');

        self::assertNotNull($this->operation($operations, 'Tranche'), 'L\'échéance, elle, est reprise.');
    }

    /**
     * ⚠ UN REVERSEMENT SANS BÉNÉFICIAIRE N'A PAS DE SENS.
     *
     * `ReversementRetroAgent` porte un agent OU un partenaire : sans l'un des deux, la
     * ligne serait une somme versée à personne.
     */
    public function testUneRetroReverseeSansIntermediaireEstRefusee(): void
    {
        $entreprise = $this->cabinet();
        $anomalies = [];

        $operations = $this->reconstitueur()->pour(
            $this->ligne([
                'policeReference' => 'POL/2026/024',
                'policeDateEffet' => '01/01/2026',
                'policeEcheance' => '31/12/2026',
                'risque' => 'RC Aviation',
                'assure' => 'KIN AVIA',
                'assureur' => 'SFA CONGO',
                'trancheNom' => 'Prime unique',
                'ouvertureRetroReversee' => 300,
            ], 2),
            $this->colonnes(),
            $entreprise,
            $anomalies,
        );

        self::assertNull($this->operation($operations, 'ReversementRetroAgent'));
        $erreurs = $this->erreurs($anomalies);
        self::assertCount(1, $erreurs);
        // Le reproche doit dire CE QUI MANQUE — l'intermédiaire — et non « bénéficiaire
        // absent », mot qui n'apparaît nulle part dans le classeur.
        self::assertStringContainsString('intermédiaire', $erreurs[0]->message);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Les colonnes de résultat
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * ⚠ CE QUI SE CALCULE NE SE RELIT JAMAIS.
     *
     * Un courtier qui corrige « Prime · Solde » dans son fichier croira l'avoir corrigée.
     * Relire cette valeur écrirait en base un chiffre que l'application recalcule aussitôt
     * — au mieux inutile, au pire écrasant une soustraction juste par une fausse.
     */
    public function testLesColonnesDeResultatNeSontJamaisEcrites(): void
    {
        $entreprise = $this->cabinet();
        $anomalies = [];

        $operations = $this->reconstitueur()->pour(
            $this->ligne([
                'policeReference' => 'POL/2026/013',
                'policeDateEffet' => '01/01/2026',
                'policeEcheance' => '31/12/2026',
                'risque' => 'RC Aviation',
                'assure' => 'KIN AVIA',
                'assureur' => 'SFA CONGO',
                'trancheNom' => 'Prime unique',
                // Des résultats, tous faux, tous censés être ignorés.
                'primeSolde' => 999999,
                'commissionEncaissee' => 888888,
                'reserve' => 777777,
                'retroAgentSolde' => 666666,
            ], 2),
            $this->colonnes(),
            $entreprise,
            $anomalies,
        );

        $interdits = ['999999', '888888', '777777', '666666'];
        foreach ($operations as $operation) {
            $ecrit = json_encode($operation->toArray(), \JSON_UNESCAPED_UNICODE);
            foreach ($interdits as $valeur) {
                self::assertStringNotContainsString(
                    $valeur,
                    (string) $ecrit,
                    sprintf('Un résultat (%s) a été relu : il devait être ignoré.', $valeur),
                );
            }
        }
    }

    /**
     * ⚠ LES LIBELLÉS DES COLONNES DE SAISIE SONT GELÉS, ET CE TEST EST LEUR VERROU.
     *
     * La feuille `DONNEES` porte un filtre automatique, dont la plage doit être contiguë :
     * une ligne de codes techniques intercalée y entrerait, et Excel proposerait
     * « policeReference » parmi les valeurs de la colonne. La relecture se fait donc par
     * LIBELLÉ.
     *
     * En contrepartie, renommer l'un de ces libellés rend illisibles tous les fichiers déjà
     * distribués — et l'utilisateur n'aurait pour indice qu'une colonne « absente ». Un
     * renommage doit donc être un geste conscient : il fait échouer ce test.
     */
    public function testLesLibellesDesColonnesDeSaisieSontGeles(): void
    {
        $attendus = [
            '_action' => '_action',
            'id' => 'id',
            'policeDateEffet' => 'Police · Date d\'effet',
            'policeEcheance' => 'Police · Échéance',
            'policeReference' => 'Police · Référence',
            'policeNumeroAvenant' => 'Police · N° avenant',
            'trancheNom' => 'Tranche · Nom',
            'tranchePayableAt' => 'Tranche · Payable à partir du',
            'trancheEcheanceAt' => 'Tranche · Échéance de paiement',
            'tranchePart' => 'Tranche · Part (%)',
            'trancheMontantFlat' => 'Tranche · Montant fixe',
            'assure' => 'Assuré',
            'risque' => 'Risque',
            'assureur' => 'Assureur',
            'portefeuille' => 'Portefeuille',
            'commissionRevenus' => 'Commission · Revenus',
            'intermediaire' => 'Intermédiaire · Nom',
            'intermediairePart' => 'Intermédiaire · Part',
            'ouverturePrimeEncaissee' => 'Ouverture · Prime encaissée',
            'ouverturePrimeLe' => 'Ouverture · Prime encaissée le',
            'ouvertureCommissionEncaissee' => 'Ouverture · Commission encaissée',
            'ouvertureCommissionLe' => 'Ouverture · Commission encaissée le',
            'ouvertureRetroReversee' => 'Ouverture · Rétro reversée',
            'ouvertureRetroLe' => 'Ouverture · Rétro reversée le',
        ];

        $colonnes = $this->colonnes();

        $saisies = [];
        foreach ($colonnes as $code => $colonne) {
            // ⚠ LES COLONNES DYNAMIQUES NE PEUVENT PAS ÊTRE GELÉES : il y en a une par
            // type de chargement du cabinet, et leur libellé EST le nom du type. Les
            // inscrire ici rendrait ce test faux au premier cabinet qui renomme un poste.
            // Leur stabilité tient au CODE, vérifiée juste après.
            if (str_starts_with($code, CatalogueDesColonnes::PREFIXE_CHARGEMENT)
                || str_starts_with($code, CatalogueDesColonnes::PREFIXE_REVENU)) {
                continue;
            }
            if (!$colonne->lectureSeule()) {
                $saisies[$code] = $colonne->libelle;
            }
        }

        self::assertSame($attendus, $saisies);
    }

    /**
     * ⚠ LE CODE D'UNE COLONNE DYNAMIQUE NE BOUGE PAS D'UNE ÉCRITURE À L'AUTRE.
     *
     * Il est dérivé du nom du type par la forme comparable du projet : ni la casse, ni les
     * accents, ni un espace de trop ne le changent. C'est ce qui permet de retrouver la
     * colonne d'un fichier exporté hier, et ce qui ramène à UNE colonne les six
     * « Prime nette » que porte le catalogue réel.
     */
    public function testLeCodeDUneColonneDynamiqueEstStable(): void
    {
        $attendu = CatalogueDesColonnes::codeDynamique(CatalogueDesColonnes::PREFIXE_CHARGEMENT, 'Prime nette');

        self::assertSame('chargement_prime_nette', $attendu);

        foreach (['PRIME NETTE', 'prime  nette', 'Prime  Nette ', 'Primé nette'] as $variante) {
            self::assertSame(
                $attendu,
                CatalogueDesColonnes::codeDynamique(CatalogueDesColonnes::PREFIXE_CHARGEMENT, $variante),
                $variante,
            );
        }

        self::assertNull(CatalogueDesColonnes::codeDynamique(CatalogueDesColonnes::PREFIXE_CHARGEMENT, '  '));
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Outillage
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * LE CATALOGUE DU CABINET DE TEST — types de chargement et de revenu compris.
     *
     * ⚠ CES COLONNES SONT DYNAMIQUES : une par type du cabinet. Un catalogue construit
     * sans elles n'aurait aucune colonne de chargement, et les tests de reprise
     * porteraient sur un fichier que l'application ne produit jamais.
     *
     * @return array<string, \App\Echange\Etat\ColonneEtat>
     */
    private function colonnes(): array
    {
        return CatalogueDesColonnes::pour(
            'ARCA',
            'TVA',
            ['Commission Ordinaire', 'Honoraire de gestion'],
        );
    }

    /**
     * Le code de la colonne d'une FONCTION de chargement.
     *
     * ⚠ D'UNE FONCTION, ET NON D'UN NOM DE TYPE : la prime se décompose en quatre
     * colonnes — prime nette, fronting, frais accessoires, taxe — quel que soit le nombre
     * de postes que le cabinet a nommés.
     */
    private function colonneDeChargement(int $fonction): string
    {
        return CatalogueDesColonnes::codeDeFonction($fonction);
    }

    /** @param array<string, mixed> $valeurs */
    private function ligne(array $valeurs, int $numero): LigneLue
    {
        $colonnes = [];
        foreach (array_keys($this->colonnes()) as $code) {
            $colonnes[$code] = 'A';
        }

        return new LigneLue('DONNEES', 'Tranche', $numero, $valeurs, $colonnes);
    }

    /**
     * @param array<int, MutationOperation> $operations
     *
     * @return array<string, int>
     */
    private function comptesParEntite(array $operations): array
    {
        $comptes = [];
        foreach ($operations as $operation) {
            $comptes[$operation->entityShortName] = ($comptes[$operation->entityShortName] ?? 0) + 1;
        }

        return $comptes;
    }

    /**
     * La PREMIÈRE opération portant sur cette entité, ou null.
     *
     * @param array<int, MutationOperation> $operations
     */
    private function operation(array $operations, string $entite): ?MutationOperation
    {
        foreach ($operations as $operation) {
            if ($operation->entityShortName === $entite) {
                return $operation;
            }
        }

        return null;
    }

    /**
     * @param Anomalie[] $anomalies
     *
     * @return Anomalie[]
     */
    private function erreurs(array $anomalies): array
    {
        return array_values(array_filter(
            $anomalies,
            static fn (Anomalie $a): bool => $a->gravite === Anomalie::ERREUR,
        ));
    }

    /** Tout ce que les anomalies reprochent — pour qu'un echec de test dise pourquoi. */
    private function messages(array $anomalies): string
    {
        return implode(' | ', array_map(static fn (Anomalie $a): string => $a->message, $anomalies));
    }

    private function reconstitueur(): ReconstitueurDeTranche
    {
        $service = static::getContainer()->get(ReconstitueurDeTranche::class);
        $service->reinitialiser();

        return $service;
    }

    /** Un cabinet avec les catalogues que la reprise mobilise, et rien de plus. */
    private function cabinet(): Entreprise
    {
        $em = $this->em();

        $owner = (new Utilisateur())->setEmail(self::OWNER_EMAIL)->setNom('Reprise')->setVerified(true)->setPassword('x');
        $em->persist($owner);

        $entreprise = (new Entreprise())->setNom(self::ENT)->setLicence('LIC')->setAdresse('1 rue')
            ->setTelephone('+2430000')->setRccm('R')->setIdnat('I')->setNumimpot('N');
        $entreprise->setUtilisateur($owner);
        $em->persist($entreprise);
        $em->flush();

        foreach ([['Prime nette', 1], ['Frais accessoires', 3]] as [$nom, $fonction]) {
            $chargement = (new Chargement())->setNom($nom)->setFonction($fonction);
            $chargement->setEntreprise($entreprise);
            $em->persist($chargement);
        }

        foreach ([['Commission Ordinaire', null], ['Honoraire de gestion', 2.0]] as [$nom, $taux]) {
            $type = (new TypeRevenu())->setNom($nom)->setShared(false)->setMultipayments(true)
                ->setRedevable(TypeRevenu::REDEVABLE_ASSUREUR);
            if ($taux !== null) {
                $type->setPourcentage($taux);
            }
            $type->setEntreprise($entreprise);
            $em->persist($type);
        }

        $em->flush();

        return $entreprise;
    }

    /**
     * UNE POLICE COMPLÈTE EN BASE — la situation d'un cabinet qui a déjà repris une partie
     * de son portefeuille.
     *
     * Les niveaux nommés (client, risque, assureur) sont RÉUTILISÉS s'ils existent : les
     * dupliquer ici rendrait leur libellé ambigu et le refus porterait sur eux, masquant
     * ce que le test veut observer.
     *
     * @return int l'identifiant de l'échéance créée
     */
    private function policeEnBase(Entreprise $entreprise, string $reference, string $nomTranche): int
    {
        $em = $this->em();

        $client = $em->getRepository(Client::class)->findOneBy(['entreprise' => $entreprise, 'nom' => 'KIN AVIA'])
            ?? $this->attacher((new Client())->setNom('KIN AVIA')->setExonere(false), $entreprise);

        $risque = $em->getRepository(Risque::class)->findOneBy(['entreprise' => $entreprise, 'code' => 'RC Aviation'])
            ?? $this->attacher(
                (new Risque())->setCode('RC Aviation')->setNomComplet('RC Aviation')->setImposable(true),
                $entreprise,
            );

        $assureur = $em->getRepository(Assureur::class)->findOneBy(['entreprise' => $entreprise, 'nom' => 'SFA CONGO'])
            ?? $this->attacher((new Assureur())->setNom('SFA CONGO'), $entreprise);

        $piste = $this->attacher(
            (new Piste())->setNom($reference)->setTypeAvenant(1)->setDescriptionDuRisque('RC Aviation')
                ->setExercice(2026)->setClient($client)->setRisque($risque),
            $entreprise,
        );

        $cotation = $this->attacher(
            (new Cotation())->setNom($reference)->setDuree(12)->setPiste($piste)->setAssureur($assureur),
            $entreprise,
        );

        $this->attacher(
            (new Avenant())->setReferencePolice($reference)->setDescription($reference)
                ->setStartingAt(new \DateTimeImmutable('2026-01-01'))
                ->setEndingAt(new \DateTimeImmutable('2026-12-31'))
                ->setCotation($cotation),
            $entreprise,
        );

        $tranche = $this->attacher(
            (new Tranche())->setNom($nomTranche)->setPayableAt(new \DateTimeImmutable('2026-01-15'))
                ->setCotation($cotation),
            $entreprise,
        );

        $em->flush();

        return (int) $tranche->getId();
    }

    /**
     * Pose le cabinet sur une entité et la persiste — le scoping du trait d'audit est
     * obligatoire, et l'oublier ferait échouer l'insertion sur une colonne NOT NULL.
     *
     * @template T of object
     *
     * @param T $entite
     *
     * @return T
     */
    private function attacher(object $entite, Entreprise $entreprise): object
    {
        $entite->setEntreprise($entreprise);
        $this->em()->persist($entite);
        $this->em()->flush();

        return $entite;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * ⚠ LE NETTOYAGE EST SCOPÉ À CE SEUL CABINET.
     *
     * Un `DELETE FROM entreprise WHERE 1` écrit ici a déjà vidé la base de test partagée,
     * emportant les fixtures de suites entières. La base est commune : ce qu'on efface,
     * on l'efface pour tout le monde.
     */
    private function nettoyer(): void
    {
        $cnx = $this->em()->getConnection();
        $noms = [self::ENT];

        // ⚠ L'ORDRE COMPTE : l'enfant part avant le parent, sans quoi la première clé
        // étrangère venue fait échouer la suppression du cabinet — et le test suivant
        // hérite d'un portefeuille qu'il croit vide.
        foreach ([
            'paiement_prime', 'reversement_retro_agent', 'tranche', 'avenant',
            'chargement_pour_prime', 'revenu_pour_courtier', 'cotation', 'piste',
            'client', 'risque', 'assureur',
            'chargement', 'type_revenu', 'invite',
        ] as $table) {
            $cnx->executeStatement(
                sprintf('DELETE t FROM `%s` t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom IN (:noms)', $table),
                ['noms' => $noms],
                ['noms' => ArrayParameterType::STRING],
            );
        }

        $cnx->executeStatement(
            'UPDATE utilisateur u JOIN entreprise e ON u.connected_to_id = e.id SET u.connected_to_id = NULL WHERE e.nom IN (:noms)',
            ['noms' => $noms],
            ['noms' => ArrayParameterType::STRING],
        );
        $cnx->executeStatement('DELETE FROM entreprise WHERE nom IN (:noms)', ['noms' => $noms], ['noms' => ArrayParameterType::STRING]);
        $cnx->executeStatement('DELETE FROM utilisateur WHERE email = :email', ['email' => self::OWNER_EMAIL]);
    }
}
