<?php

namespace App\Tests\Workspace;

use App\Entity\Avenant;
use App\Entity\Chargement;
use App\Entity\Client;
use App\Entity\ConditionPartage;
use App\Entity\Contact;
use App\Entity\Entreprise;
use App\Entity\Feedback;
use App\Entity\Invite;
use App\Entity\Monnaie;
use App\Entity\Note;
use App\Entity\Piste;
use App\Entity\Risque;
use App\Entity\Taxe;
use App\Entity\TypeRevenu;
use App\Entity\Utilisateur;
use App\Service\Workspace\ChampsObligatoiresInspector;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Beaucoup de champs de JS Brokers ne persistent pas du texte libre mais un CODE issu
 * d'une constante (`Piste::AVENANT_SOUSCRIPTION = 0`). L'écran le rend en clair, mais la
 * correspondance code ↔ sens n'était transmise à personne d'autre : l'assistant laissait
 * donc ces champs vides, et l'import de fichier les remplissait faux.
 *
 * Ces tests verrouillent les trois garanties :
 *  1. les valeurs acceptées sont DÉRIVÉES du FormType (aucune redéclaration) ;
 *  2. un champ qui admet un défaut non ambigu le porte sur sa PROPRIÉTÉ, donc l'écran,
 *     l'assistant et l'import partent tous du même `new` ;
 *  3. un DISCRIMINANT (débit/crédit, type d'avenant…) n'a pas de défaut et est EXIGÉ.
 *
 * WebTestCase + loginUser : les champs autocomplete des FormType scopent sur l'entreprise
 * active de l'utilisateur (getConnectedTo()). Sans utilisateur authentifié, l'arbre des
 * formulaires ne se construit pas et aucun descripteur n'est dérivé.
 */
class ChampsCodesConstantsTest extends WebTestCase
{
    private const ENT = 'PHPUnit-ChampsCodes';
    private const OWNER = 'phpunit-champscodes-owner@test.local';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private ChampsObligatoiresInspector $inspector;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->inspector = static::getContainer()->get(ChampsObligatoiresInspector::class);
        $this->cleanUp();
        $this->seedWorkspace();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        parent::tearDown();
    }

    private function cleanUp(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email = :o', ['o' => self::OWNER]);
        $conn->executeStatement('DELETE i FROM invite i JOIN entreprise e ON i.entreprise_id = e.id WHERE e.nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :o', ['o' => self::OWNER]);
        $this->em->clear();
    }

    private function seedWorkspace(): void
    {
        $owner = (new Utilisateur())->setEmail(self::OWNER)->setNom('PHPUnit')->setVerified(true);
        $owner->setPassword('x');
        $this->em->persist($owner);

        $ent = (new Entreprise())
            ->setNom(self::ENT)->setLicence('LIC')->setAdresse('1 rue')->setTelephone('+243000')
            ->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($owner);
        $this->em->persist($ent);

        $inv = (new Invite())->setNom('Testeur')->setUtilisateur($owner)->setEntreprise($ent)->setProprietaire(true);
        $this->em->persist($inv);
        $owner->setConnectedTo($ent);
        $this->em->flush();
        $this->client->loginUser($owner);
    }

    // ───────────────── 1. Les valeurs viennent du FormType ─────────────────

    public function testLesValeursDUnChoixSontDeriveesDuFormulaire(): void
    {
        $choix = $this->inspector->choixDisponibles('Piste', Piste::class, 'typeAvenant');

        $this->assertCount(6, $choix, 'Les 6 types d’avenant doivent être exposés.');
        $this->assertSame('Souscription', $choix[Piste::AVENANT_SOUSCRIPTION]);
        $this->assertSame('Renouvellement', $choix[Piste::AVENANT_RENOUVELLEMENT]);
        $this->assertSame('Résiliation', $choix[Piste::AVENANT_RESILIATION]);
    }

    public function testLeLibelleNEstJamaisLeHtmlDeChoiceLabel(): void
    {
        // PisteType::choice_label rend du HTML de présentation (« <div><strong>… ») :
        // le lire comme libellé de valeur produirait un texte inutilisable.
        $choix = $this->inspector->choixDisponibles('Piste', Piste::class, 'typeAvenant');

        $this->assertNotSame([], $choix);
        foreach ($choix as $libelle) {
            $this->assertStringNotContainsString('<', $libelle);
        }
    }

    public function testLesValeursDUneNoteSontExposeesAvecLeurSens(): void
    {
        $type = $this->inspector->choixDisponibles('Note', Note::class, 'type');
        $this->assertSame('Débit', $type[Note::TYPE_NOTE_DE_DEBIT]);
        $this->assertSame('Crédit', $type[Note::TYPE_NOTE_DE_CREDIT]);

        $dest = $this->inspector->choixDisponibles('Note', Note::class, 'addressedTo');
        $this->assertCount(4, $dest, 'Les 4 destinataires doivent être exposés.');
        $this->assertSame("L'autorité fiscale", $dest[Note::TO_AUTORITE_FISCALE]);
    }

    public function testUnChampDeTexteLibreNaAucunChoix(): void
    {
        $this->assertSame([], $this->inspector->choixDisponibles('Piste', Piste::class, 'nom'));
    }

    public function testLesChoixDUnChampBooleenSontExposes(): void
    {
        // « exonere » est rendu par un ChoiceType Oui/Non : les deux codes doivent sortir,
        // en clés de tableau utilisables ('0'/'1' et non false/true écrasés en 0).
        $choix = $this->inspector->choixDisponibles('Client', Client::class, 'exonere');

        $this->assertNotSame([], $choix, 'Le champ exonere est une liste fermée Oui/Non.');
        $this->assertSame(['0', '1'], array_map('strval', array_keys($choix)));
    }

    public function testLAideMetierDuFormulaireEstRemontee(): void
    {
        $d = $this->inspector->descripteursChamps('Avenant', Avenant::class);

        $this->assertArrayHasKey('renewalStatus', $d);
        $this->assertStringContainsString('En cours', (string) $d['renewalStatus']['aide']);
    }

    public function testLeLibelleLisibleVientDuFormulaire(): void
    {
        // Façade libellesFormulaire() : non-régression du 422 nommant le champ.
        $this->assertSame('Type de note', $this->inspector->libelleChamp(Note::class, 'type'));
    }

    public function testLeDefautTenuParLeFormulaireEstDistingue(): void
    {
        // TrancheType::modeCalcul porte 'data' => 'pourcentage'. Cette option PRIME sur la
        // valeur de l'entité : un défaut posé sur la propriété y serait écrasé en silence,
        // d'où la nécessité de la LIRE plutôt que de la déplacer.
        $d = $this->inspector->descripteursChamps('Tranche', \App\Entity\Tranche::class);

        $this->assertArrayHasKey('modeCalcul', $d);
        $this->assertTrue($d['modeCalcul']['aFormulaireData']);
        $this->assertSame('pourcentage', $d['modeCalcul']['defautFormulaire']);
    }

    public function testLePiegePourcentageResteDetecte(): void
    {
        // Non-régression de la façade : champsPourcentage() passe désormais par le
        // descripteur unique et doit rendre exactement ce qu'il rendait.
        $descripteurs = $this->inspector->descripteursChamps('ConditionPartage', ConditionPartage::class);
        $champs = $this->inspector->champsPourcentage('ConditionPartage', ConditionPartage::class);

        foreach ($champs as $champ) {
            $this->assertTrue($descripteurs[$champ]['pourcentage']);
        }
        foreach ($descripteurs as $champ => $d) {
            $this->assertSame($d['pourcentage'], in_array($champ, $champs, true), $champ);
        }
    }

    public function testUneEntiteSansFormTypeExploitableNeCassePas(): void
    {
        $this->assertSame([], $this->inspector->descripteursChamps('EntiteQuiNexistePas', Piste::class));
    }

    // ───────────────── 2. Les défauts vivent sur les propriétés ─────────────────

    /**
     * @dataProvider defautsAttendus
     */
    public function testUnDefautNonAmbiguEstPorteParLaPropriete(string $fqcn, string $getter, mixed $attendu): void
    {
        $entite = new $fqcn();

        $this->assertSame(
            $attendu,
            $entite->{$getter}(),
            sprintf('%s::%s() doit valoir son défaut dès la construction.', $fqcn, $getter),
        );
    }

    /** @return array<string, array{0: string, 1: string, 2: mixed}> */
    public static function defautsAttendus(): array
    {
        return [
            'piste : renouvelable est le cas normal' => [Piste::class, 'getRenewalCondition', Piste::RENEWAL_CONDITION_RENEWABLE],
            'client : non exonéré' => [Client::class, 'isExonere', false],
            'risque : branche IARD dominante' => [Risque::class, 'getBranche', Risque::BRANCHE_IARD_OU_NON_VIE],
            'contact : « autres » est le fourre-tout' => [Contact::class, 'getType', Contact::TYPE_CONTACT_AUTRES],
            'feedback : canal indéterminé' => [Feedback::class, 'getType', Feedback::TYPE_UNDEFINED],
            'monnaie : aucune fonction' => [Monnaie::class, 'getFonction', Monnaie::FONCTION_AUCUNE],
            'type de revenu : taux du chargement' => [TypeRevenu::class, 'getModeCalcul', TypeRevenu::MODE_CALCUL_POURCENTAGE_CHARGEMENT],
            'partage : sans seuil' => [ConditionPartage::class, 'getFormule', ConditionPartage::FORMULE_NE_SAPPLIQUE_PAS_SEUIL],
            'partage : aucun risque ciblé' => [ConditionPartage::class, 'getCritereRisque', ConditionPartage::CRITERE_PAS_RISQUES_CIBLES],
            'partage : commission pure du risque' => [ConditionPartage::class, 'getUniteMesure', ConditionPartage::UNITE_SOMME_COMMISSION_PURE_RISQUE],
        ];
    }

    public function testUnChampNonNullableAvecDefautNEstPlusReclame(): void
    {
        // Le défaut de propriété doit RETIRER le champ des obligatoires : sans lui,
        // la création échouait au flush (colonne NOT NULL).
        $meta = $this->em->getClassMetadata(Risque::class);

        $this->assertFalse(
            $this->inspector->scalaireRequis($meta, new Risque(), 'branche'),
            'Un champ doté d’un défaut de propriété ne doit plus être réclamé.',
        );
    }

    public function testLaViolationNotNullDeConditionPartageEstFermee(): void
    {
        // critereRisque : colonne NOT NULL, champ de formulaire `required: false`, aucun
        // défaut — la création depuis l'écran pouvait donc violer l'intégrité.
        $meta = $this->em->getClassMetadata(ConditionPartage::class);

        $this->assertFalse($meta->isNullable('critereRisque'), 'Prérequis : la colonne est NOT NULL.');
        $this->assertSame([], $this->inspector->champsManquants(new ConditionPartage(), ['critereRisque']));
    }

    public function testLaValeurDuDefautDeColonneEstLisibleEtPlusSeulementComptee(): void
    {
        // `closed` porte options: ['default' => false] : aUnDefaut() répondait oui/non,
        // la valeur était lue puis jetée — on ne pouvait donc pas l'annoncer.
        $meta = $this->em->getClassMetadata(Piste::class);

        $this->assertTrue($this->inspector->aUnDefaut($meta, 'closed'));
        $this->assertFalse($this->inspector->defautColonne($meta, 'closed'));
        $this->assertNull($this->inspector->defautColonne($meta, 'nom'), 'Un champ sans défaut de colonne rend null.');
    }

    // ───────────────── 3. Les discriminants sont exigés, jamais devinés ─────────────────

    public function testUnDiscriminantNaPasDeDefaut(): void
    {
        $this->assertNull((new Piste())->getTypeAvenant(), 'Le type d’avenant ne se devine pas.');
        $this->assertNull((new Note())->getType(), 'Débit ou crédit ne se devine pas.');
        $this->assertNull((new Note())->getAddressedTo(), 'Le destinataire d’une note ne se devine pas.');
        $this->assertNull((new Taxe())->getRedevable(), 'Le redevable d’une taxe ne se devine pas.');
        $this->assertNull((new Chargement())->getFonction(), 'La fonction d’un chargement ne se devine pas.');
    }

    public function testUnDiscriminantSurColonneNullableEstQuandMemeExige(): void
    {
        // C'est le cœur de la règle : la nullabilité tolère l'historique, elle ne
        // dispense pas d'un choix explicite à la création.
        $metaTaxe = $this->em->getClassMetadata(Taxe::class);
        $this->assertTrue($metaTaxe->isNullable('redevable'), 'Prérequis du test : la colonne est nullable.');
        $this->assertTrue(
            $this->inspector->scalaireRequis($metaTaxe, new Taxe(), 'redevable'),
            'Le redevable d’une taxe doit être exigé malgré la nullabilité.',
        );

        $metaChargement = $this->em->getClassMetadata(Chargement::class);
        $this->assertTrue($metaChargement->isNullable('fonction'));
        $this->assertTrue($this->inspector->scalaireRequis($metaChargement, new Chargement(), 'fonction'));
    }

    public function testUnDiscriminantRenseigneNEstPlusReclame(): void
    {
        $taxe = (new Taxe())->setRedevable(Taxe::REDEVABLE_COURTIER);

        $this->assertFalse(
            $this->inspector->scalaireRequis($this->em->getClassMetadata(Taxe::class), $taxe, 'redevable'),
            'Un discriminant fourni ne doit plus être réclamé.',
        );
    }

    public function testUnDiscriminantVideRemonteEnErreurNommeeCoteEcran(): void
    {
        // Chemin HTTP : champsManquants() alimente le 422 de ControllerUtilsTrait.
        $manquants = $this->inspector->champsManquants(new Note(), ['nom', 'type', 'addressedTo']);

        $this->assertArrayHasKey('type', $manquants);
        $this->assertArrayHasKey('addressedTo', $manquants);
    }

    public function testUnChampNullableOrdinaireResteFacultatif(): void
    {
        // Contre-exemple : la civilité d'un client ne structure rien, elle reste libre.
        $this->assertFalse(
            $this->inspector->scalaireRequis($this->em->getClassMetadata(Client::class), new Client(), 'civilite'),
            'Un champ nullable non discriminant ne doit pas devenir obligatoire.',
        );
    }
}
