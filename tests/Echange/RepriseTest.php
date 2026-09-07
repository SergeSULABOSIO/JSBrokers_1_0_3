<?php

namespace App\Tests\Echange;

use App\Ai\Mutation\MutationOperation;
use App\Echange\Classeur\LigneLue;
use App\Echange\Etat\CatalogueDesColonnes;
use App\Echange\Reprise\ReconstitueurDeTranche;
use App\Echange\Service\Anomalie;
use App\Entity\Chargement;
use App\Entity\Entreprise;
use App\Entity\TypeRevenu;
use App\Entity\Utilisateur;
use Doctrine\DBAL\ArrayParameterType;
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
                'assure' => 'KIN AVIA',
                'assureur' => 'SFA CONGO',
                'trancheNom' => 'Une échéance sur deux',
                'tranchePart' => 50,
                $this->colonneDeChargement('Prime nette') => 5000,
                $this->colonneDeChargement('Frais accessoires') => 250,
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
                'assure' => 'KIN AVIA',
                'assureur' => 'SFA CONGO',
                $this->colonneDeChargement('Prime nette') => 7000,
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
     * ⚠ UN TYPE INCONNU EST UN REFUS QUI DIT QUOI CRÉER — jamais une création à la volée.
     *
     * Un type de chargement porte une fonction dans le calcul de la prime ; le fabriquer
     * depuis un simple nom donnerait une configuration muette. On refuse en nommant ce qui
     * manque.
     *
     * ⚠ CE CAS EST DEVENU CELUI D'UN FICHIER ÉTRANGER, et c'est un progrès : les colonnes
     * étant dérivées du catalogue DU CABINET, un type absent n'a plus de colonne où
     * s'écrire. Il ne reste que le classeur venu d'ailleurs — ou retouché à la main —,
     * dont les colonnes ne correspondent à rien d'ici.
     */
    public function testUnTypeInconnuEstRefuseEtNonCree(): void
    {
        $entreprise = $this->cabinet();
        $anomalies = [];

        // Le catalogue de colonnes connaît ce type ; le CABINET, non — exactement ce que
        // produit un fichier venu d'un autre cabinet.
        $colonnes = CatalogueDesColonnes::pour('ARCA', 'TVA', ['Taxe parafiscale inconnue'], []);

        $this->reconstitueur()->pour(
            $this->ligne([
                'policeReference' => 'POL/2026/011',
                'assure' => 'KIN AVIA',
                'assureur' => 'SFA CONGO',
                $this->colonneDeChargement('Taxe parafiscale inconnue') => 999,
            ], 2),
            $colonnes,
            $entreprise,
            $anomalies,
        );

        $erreurs = $this->erreurs($anomalies);
        self::assertCount(1, $erreurs);
        self::assertStringContainsString('aucun élément de votre configuration', $erreurs[0]->message);
    }

    /**
     * ⚠ UN NOM DE CATALOGUE PORTÉ PAR PLUSIEURS TYPES N'EST PAS UN REFUS — mais un
     * AVERTISSEMENT.
     *
     * Mesuré sur le cabinet réel : son catalogue porte « Prime nette » SIX fois et
     * « Commission Ordinaire » six fois, séquelles d'une initialisation rejouée, et les
     * doublons y sont rigoureusement identiques. Refuser aurait bloqué toutes les lignes
     * du portefeuille, pour un choix sans conséquence. On retient donc le premier, et on
     * le DIT — l'utilisateur apprend qu'il a un catalogue à nettoyer, sans que sa reprise
     * en dépende.
     *
     * La différence avec un client homonyme est de nature : deux « SARL Martin » sont deux
     * affaires, deux « Prime nette » sont un même poste d'assiette écrit deux fois.
     */
    public function testUnCatalogueEnDoubleAvertitSansBloquer(): void
    {
        $entreprise = $this->cabinet();

        // Le même nom, une seconde fois : exactement ce que porte le cabinet réel.
        $em = $this->em();
        $doublon = (new Chargement())->setNom('Prime nette')->setFonction(1);
        $doublon->setEntreprise($entreprise);
        $em->persist($doublon);
        $em->flush();

        $anomalies = [];
        $operations = $this->reconstitueur()->pour(
            $this->ligne([
                'policeReference' => 'POL/2026/012',
                'assure' => 'KIN AVIA',
                'assureur' => 'SFA CONGO',
                $this->colonneDeChargement('Prime nette') => 10000,
            ], 2),
            $this->colonnes(),
            $entreprise,
            $anomalies,
        );

        self::assertSame([], $this->erreurs($anomalies), 'Un doublon de catalogue ne doit pas bloquer.');
        self::assertNotSame([], $anomalies, 'Mais il doit être dit.');
        self::assertStringContainsString('le premier a été retenu', $anomalies[0]->message);
        self::assertNotSame([], $operations, 'Et la ligne doit tout de même produire ses écritures.');
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
                'policeDateEffet' => '2026-01-31',
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
     * ⚠ LA COMMISSION ENCAISSÉE N'EST PAS REPRISE — ET LE FICHIER LE DIT.
     *
     * Elle passait par une note d'un seul article, soldée par un règlement du même
     * montant. Mais `Note::$validated` et `Note::$signature` sont NON NULLES en base et
     * ABSENTES de `NoteType` : le contrôle à blanc les réclame — elles sont obligatoires —
     * sans qu'aucun champ ne permette de les fournir. Constaté sur un portefeuille réel :
     * CINQUANTE erreurs bloquantes, une par échéance encaissée, rendant toute la reprise
     * inutilisable pour une fonction secondaire.
     *
     * ⚠ ET ON NE FOURNIT PAS CES CHAMPS « POUR FAIRE PASSER » LE DRY-RUN : le contrôle
     * cesserait de se plaindre et l'écriture échouerait en SQL sur une contrainte NOT
     * NULL. Ce qu'une note de reprise doit porter — validée ? signée par qui ? — est une
     * question métier.
     *
     * ⚠ PERDRE UN CHIFFRE EN SILENCE SERAIT PIRE QUE DE NE PAS LE REPRENDRE : d'où
     * l'avertissement, qui nomme le montant et où le saisir. C'est ce que ce test garde —
     * un jour, il faudra le retourner.
     */
    public function testLaCommissionEncaisseeEstSignaleeEtNonEcrite(): void
    {
        $entreprise = $this->cabinet();
        $anomalies = [];

        $operations = $this->reconstitueur()->pour(
            $this->ligne([
                'policeReference' => 'POL/2026/022',
                'assure' => 'KIN AVIA',
                'assureur' => 'SFA CONGO',
                'trancheNom' => 'Prime unique',
                'commissionRevenus' => 'Commission Ordinaire',
                'ouvertureCommissionEncaissee' => 1200,
            ], 2),
            $this->colonnes(),
            $entreprise,
            $anomalies,
        );

        self::assertNull($this->operation($operations, 'Note'), 'Aucune note ne doit être posée.');

        // ⚠ UN AVERTISSEMENT, ET NON UNE ERREUR : le reste de la ligne doit passer.
        self::assertSame([], $this->erreurs($anomalies));
        self::assertNotSame([], $anomalies);
        self::assertStringContainsString('1 200,00', $anomalies[0]->message, 'Le montant laissé de côté doit être nommé.');
        self::assertStringContainsString('rubrique Notes', $anomalies[0]->message, 'Et l\'endroit où le saisir.');

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
        self::assertStringContainsString('aucun intermédiaire', $erreurs[0]->message);
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
            ['Prime nette', 'Frais accessoires'],
            ['Commission Ordinaire', 'Honoraire de gestion'],
        );
    }

    /** Le code de la colonne d'un chargement, tel que le catalogue le forme. */
    private function colonneDeChargement(string $nom): string
    {
        return (string) CatalogueDesColonnes::codeDynamique(
            CatalogueDesColonnes::PREFIXE_CHARGEMENT,
            $nom,
        );
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

        foreach (['chargement', 'type_revenu', 'invite'] as $table) {
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
