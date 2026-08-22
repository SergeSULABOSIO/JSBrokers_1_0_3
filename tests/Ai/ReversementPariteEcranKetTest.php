<?php

namespace App\Tests\Ai;

use App\Ai\Scope\AiScope;
use App\Ai\Tool\SignalerReversementRetroAgentTool;
use App\Entity\CompteBancaire;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Utilisateur;
use App\Service\Retro\DefautsDuVersement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * UN REVERSEMENT DIT À KET DOIT ÊTRE LE MÊME QU'UN REVERSEMENT FAIT À L'ÉCRAN.
 *
 * Deux chemins mènent à cet acte : le picker du rapport de production, qui persiste
 * directement, et `signaler_reversement_retro_agent`, qui passe par le moteur de mutation.
 * Ce que l'utilisateur ne précise pas, chacun le comble — et c'est là que la parité se perd.
 *
 * Elle S'ÉTAIT perdue, à deux endroits :
 *
 *  1. LE COMPTE DÉBITÉ n'existait que du côté de l'écran. L'outil ne l'écrivait jamais :
 *     tout reversement demandé à Ket partait donc EN CAISSE, alors que le même geste à la
 *     souris passait par la banque. Deux comptabilités pour un seul acte, et rien à l'écran
 *     pour le signaler.
 *
 *  2. LA RÉFÉRENCE était calculée deux fois, par deux formules jumelles. Un commentaire
 *     promettait « le même schéma que le picker » — une promesse que rien ne tenait.
 *
 * Ce test verrouille la source unique, pas les deux copies : il vérifie que les décisions
 * viennent de `DefautsDuVersement` et que personne n'en garde un double.
 */
class ReversementPariteEcranKetTest extends KernelTestCase
{
    private const OWNER_EMAIL = 'phpunit-parite-versement@test.local';
    private const ENTREPRISE_NOM = 'PHPUnit Parite Versement SARL';
    private const AUTRE_NOM = 'PHPUnit Parite Voisine SARL';

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

    private function defauts(): DefautsDuVersement
    {
        return static::getContainer()->get(DefautsDuVersement::class);
    }

    private function outil(): SignalerReversementRetroAgentTool
    {
        return static::getContainer()->get(SignalerReversementRetroAgentTool::class);
    }

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();
        foreach ([self::ENTREPRISE_NOM, self::AUTRE_NOM] as $nom) {
            foreach (['compte_bancaire', 'invite'] as $table) {
                $conn->executeStatement(
                    "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :nom",
                    ['nom' => $nom],
                );
            }
            $conn->executeStatement('DELETE FROM entreprise WHERE nom = :nom', ['nom' => $nom]);
        }
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :email', ['email' => self::OWNER_EMAIL]);
    }

    /**
     * Deux cabinets, chacun avec ses comptes. Le voisin n'existe que pour éprouver le
     * scoping : un identifiant de compte DICTÉ ne doit pas franchir la frontière.
     *
     * @return array{entreprise: Entreprise, invite: Invite, comptes: array<string, CompteBancaire>, voisin: CompteBancaire}
     */
    private function semer(): array
    {
        $em = $this->em();

        $owner = (new Utilisateur())->setEmail(self::OWNER_EMAIL)->setNom('Parite Owner')
            ->setVerified(true)->setPassword('x');
        $em->persist($owner);

        $faireEntreprise = static function (string $nom) use ($em, $owner): Entreprise {
            $e = (new Entreprise())->setNom($nom)->setLicence('LIC')->setAdresse('1 rue')
                ->setTelephone('+2430000')->setRccm('RCCM')->setIdnat('IDNAT')->setNumimpot('IMP');
            $e->setUtilisateur($owner);
            $em->persist($e);

            return $e;
        };

        $entreprise = $faireEntreprise(self::ENTREPRISE_NOM);
        $voisine = $faireEntreprise(self::AUTRE_NOM);

        $faireCompte = static function (string $intitule, Entreprise $e) use ($em): CompteBancaire {
            $c = (new CompteBancaire())->setIntitule($intitule)->setNumero('00' . $intitule)
                ->setBanque('Banque ' . $intitule)->setCodeSwift('SWIFT');
            $c->setEntreprise($e);
            $em->persist($c);

            return $c;
        };

        // Volontairement semés dans le DÉSORDRE alphabétique : le compte proposé est le
        // premier par intitulé, pas le premier créé.
        $zeta = $faireCompte('Zeta Bank', $entreprise);
        $alpha = $faireCompte('Alpha Bank', $entreprise);
        $voisin = $faireCompte('Voisine Bank', $voisine);

        $gestionnaire = (new Invite())->setNom('Gestionnaire')->setProprietaire(true);
        $gestionnaire->setUtilisateur($owner)->setEntreprise($entreprise);
        $em->persist($gestionnaire);

        $em->flush();

        return [
            'entreprise' => $entreprise,
            'invite' => $gestionnaire,
            'comptes' => ['alpha' => $alpha, 'zeta' => $zeta],
            'voisin' => $voisin,
        ];
    }

    /** La formule de référence n'existe qu'à un seul endroit, et ce n'est ni l'un ni l'autre. */
    public function testAucunDesDeuxCheminsNeGardeSaPropreFormuleDeReference(): void
    {
        $controleur = (string) file_get_contents(__DIR__ . '/../../src/Controller/Admin/RetroAgentController.php');
        $outil = (string) file_get_contents(__DIR__ . '/../../src/Ai/Tool/SignalerReversementRetroAgentTool.php');

        self::assertStringNotContainsString("'RETRO-' .", $controleur, 'Le controleur recopie la formule.');
        self::assertStringNotContainsString("'RETRO-' .", $outil, "L'outil recopie la formule.");

        // Et tous deux passent par le service.
        self::assertStringContainsString('defautsDuVersement->reference(', $controleur);
        self::assertStringContainsString('defautsDuVersement->reference(', $outil);

        self::assertSame(
            'RETRO-21082026-143512',
            $this->defauts()->reference(new \DateTimeImmutable('2026-08-21 14:35:12')),
        );
    }

    /** Le compte proposé : le premier par intitulé, le même pour les deux chemins. */
    public function testLeCompteProposeEstLePremierParIntitule(): void
    {
        $s = $this->semer();

        $propose = $this->defauts()->comptePropose($s['entreprise']);
        self::assertNotNull($propose);
        self::assertSame('Alpha Bank', $propose->getIntitule(), 'Le premier par intitule, pas le premier cree.');

        // La liste offerte à l'écran est la même, dans le même ordre : c'est ce qui rend
        // « le premier de la liste » et « le compte proposé » interchangeables.
        $comptes = $this->defauts()->comptes($s['entreprise']);
        self::assertSame($propose->getId(), $comptes[0]->getId());
        self::assertCount(2, $comptes, "Le compte du cabinet voisin n'a rien a faire ici.");
    }

    /** Ket sait qu'un versement se débite d'un compte — et lequel par défaut. */
    public function testLOutilExposeLeCompteDebiteEtLAnnonce(): void
    {
        $outil = $this->outil();

        self::assertArrayHasKey(
            'compteBancaireId',
            $outil->schema()['properties'],
            'Le compte est absent du schema.',
        );
        // La description est ce que le modèle LIT : un champ au schéma qu'aucune phrase
        // n'explique reste un champ que le modèle n'emploie jamais.
        self::assertStringContainsString('compteBancaireId', $outil->description());
        self::assertStringContainsString('ESPÈCES', $outil->description());
    }

    /**
     * KET EXIGE LE JUSTIFICATIF, exactement comme l'écran — et le DIT.
     *
     * Sans cette symétrie, « paie Alice » dit à l'assistant serait le contournement de la
     * règle : le versement passerait sans preuve là où le picker l'aurait refusé. Et un
     * refus muet ne vaut rien — le modèle improviserait une explication, ou annoncerait un
     * obstacle sans dire quoi faire.
     */
    public function testKetExigeLeJustificatifEtDitCommentLeFournir(): void
    {
        $outil = $this->outil();

        self::assertContains('fichierId', $outil->schema()['required'], 'La pièce doit être exigée par le schéma.');
        self::assertStringContainsString('fichierId', $outil->description());
        self::assertStringContainsString('JUSTIFICATIF', $outil->description());

        // Le message est celui de l'écran : une seule règle, une seule formulation.
        $regle = static::getContainer()->get(\App\Service\Retro\JustificatifExige::class);
        self::assertFalse($regle->estSatisfait(false));
        self::assertTrue($regle->estSatisfait(true));
        self::assertStringContainsString('justificatif', $regle->messageAssistant());
        self::assertStringContainsString('fichierId', $regle->messageAssistant());
    }

    /**
     * LA PIÈCE VA DANS LE MÊME PLAN, ET UNE SEULE FOIS.
     *
     * On inspecte la fabrication du plan, pas son exécution : c'est là que se joue la
     * consigne « un seul Document en base ». La pièce est posée sur la PREMIÈRE opération
     * — celle qui recevra le plus petit identifiant, donc le porteur du lot au sens de
     * LotDeVersement, la même ligne que celle sur laquelle l'écran dépose la sienne.
     */
    public function testLaPieceEstPoseeUneSeuleFoisSurLaPremiereLigne(): void
    {
        $s = $this->semer();
        $outil = $this->outil();

        $classer = new \ReflectionMethod(SignalerReversementRetroAgentTool::class, 'classerLaPiece');
        $classer->setAccessible(true);

        // Deux lignes, comme un virement qui solde deux affaires.
        $operations = [
            ['op' => 'create', 'entite' => 'ReversementRetroAgent', 'champs' => ['montant' => 120.0]],
            ['op' => 'create', 'entite' => 'ReversementRetroAgent', 'champs' => ['montant' => 80.0]],
        ];

        $piece = (new \App\Entity\AssistantConversationFichier())->setNomOriginal('bordereau.pdf');
        $avec = $classer->invoke($outil, $operations, $piece);

        self::assertCount(2, $avec, 'Aucune opération ne doit être ajoutée ni retirée.');
        self::assertArrayHasKey('collections', $avec[0], 'La pièce va sur la PREMIÈRE ligne.');
        self::assertArrayNotHasKey('collections', $avec[1], 'La seconde ligne ne reçoit AUCUNE copie.');

        $elements = $avec[0]['collections'][0]['elements'];
        self::assertCount(1, $elements, 'Un seul Document, donc un seul fichier en base.');
        self::assertSame('bordereau.pdf', $elements[0]['champs']['nom']);
    }
    /**
     * Le compte réellement retenu par l'outil, dans les quatre cas.
     *
     * On passe par la réflexion : cette décision est privée, et c'est justement elle qui
     * avait divergé. La tester à travers un plan complet exigerait une commission
     * encaissée et un fil de conversation — beaucoup de décor pour une règle de trois
     * lignes, dont l'une est une garde de sécurité.
     */
    public function testLeCompteRetenuParKetSuitLaMemeRegleQueLEcran(): void
    {
        $s = $this->semer();
        $scope = new AiScope($s['entreprise'], $s['invite']);

        $resoudre = new \ReflectionMethod(SignalerReversementRetroAgentTool::class, 'resoudreCompte');
        $resoudre->setAccessible(true);
        $outil = $this->outil();

        $attendu = $s['comptes']['alpha']->getId();

        // Rien de dicté : le compte proposé, exactement comme l'écran le présélectionne.
        self::assertSame($attendu, $resoudre->invoke($outil, [], $scope));

        // Un compte dicté, du bon cabinet : c'est celui-là.
        self::assertSame(
            $s['comptes']['zeta']->getId(),
            $resoudre->invoke($outil, ['compteBancaireId' => $s['comptes']['zeta']->getId()], $scope),
        );

        // « En espèces » : aucun compte, la sortie est comptabilisée en caisse.
        self::assertNull($resoudre->invoke($outil, ['compteBancaireId' => 0], $scope));

        // Le compte d'un AUTRE cabinet est ignoré, et l'on retombe sur le compte proposé :
        // un identifiant dicté ne débite pas la banque du voisin.
        self::assertSame(
            $attendu,
            $resoudre->invoke($outil, ['compteBancaireId' => $s['voisin']->getId()], $scope),
            'Un identifiant hors perimetre ne doit jamais etre ecrit tel quel.',
        );
    }
}
