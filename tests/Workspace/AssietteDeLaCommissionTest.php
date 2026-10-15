<?php

namespace App\Tests\Workspace;

use App\Ai\Mutation\MutationOperation;
use App\Ai\Scope\AiScope;
use App\Entity\Chargement;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\TypeRevenu;
use App\Entity\Utilisateur;
use App\Service\Workspace\WorkspaceMutationService;
use App\Services\ServiceInitialisationEntreprise;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * UNE COMMISSION NE SE CALCULE PAS SUR UNE TAXE (chantier « Assiette et source unique de la
 * commission », écart 1 — décision du 2026-10-15).
 *
 * L'Art. 2 du barème ARCA exprime les taux sur la prime nette, hors impôts et taxes. Le
 * « chargement cible » d'un type de revenu acceptait pourtant la TVA ou l'ARCA : un type à
 * 10 % visant la TVA facturait une commission sur une taxe. Refusé à l'écran, chez Ket et dans
 * le chargement lui-même (qui ne peut plus DEVENIR une taxe quand un type le vise).
 *
 * Et le sélecteur ne liste que les chargements du cabinet : sans filtre, il proposait ceux
 * de tous les cabinets.
 */
class AssietteDeLaCommissionTest extends WebTestCase
{
    private const EMAIL = 'phpunit-assiette-commission@test.local';
    private const EMAIL_AUTRE = 'phpunit-assiette-commission-autre@test.local';
    private const CABINET = 'PHPUnit Assiette Commission SARL';
    private const CABINET_AUTRE = 'PHPUnit Assiette Commission Voisin SARL';

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
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email IN (?, ?)', [self::EMAIL, self::EMAIL_AUTRE]);
        foreach ($conn->fetchFirstColumn('SELECT id FROM entreprise WHERE nom IN (?, ?)', [self::CABINET, self::CABINET_AUTRE]) as $id) {
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
        $conn->executeStatement('DELETE FROM utilisateur WHERE email IN (?, ?)', [self::EMAIL, self::EMAIL_AUTRE]);
        $this->em()->clear();
    }

    /**
     * Un cabinet semé comme en production (Prime nette, Fronting, Frais accessoires, TVA, ARCA ;
     * type « Commission » sur la prime nette).
     *
     * @return array{entreprise: int, invite: int}
     */
    private function semer(string $nom, string $email, bool $connecter = true): array
    {
        $em = $this->em();
        $owner = (new Utilisateur())->setEmail($email)->setNom('Propriétaire')->setVerified(true)->setPassword('x');
        $em->persist($owner);
        $cabinet = (new Entreprise())->setNom($nom)->setLicence('L')->setAdresse('1 rue')
            ->setTelephone('+243000000065')->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($owner);
        $em->persist($cabinet);
        $owner->setConnectedTo($cabinet);
        $invite = (new Invite())->setNom('Propriétaire')->setUtilisateur($owner)->setEntreprise($cabinet)->setProprietaire(true);
        $em->persist($invite);
        $em->flush();
        static::getContainer()->get(ServiceInitialisationEntreprise::class)->initialiser($cabinet, $invite);
        $em->flush();

        if ($connecter) {
            $this->client->loginUser($owner);
        }

        return ['entreprise' => (int) $cabinet->getId(), 'invite' => (int) $invite->getId()];
    }

    private function chargement(int $entreprise, string $nom): Chargement
    {
        return $this->em()->getRepository(Chargement::class)->findOneBy(['entreprise' => $entreprise, 'nom' => $nom]);
    }

    private function soumettre(string $rubrique, array $s, array $champs): array
    {
        $this->client->request('POST', "/admin/$rubrique/api/submit", $champs + ['idEntreprise' => $s['entreprise'], 'idInvite' => $s['invite']]);
        $reponse = $this->client->getResponse();

        return ['statut' => $reponse->getStatusCode(), 'texte' => json_encode(json_decode((string) $reponse->getContent(), true), JSON_UNESCAPED_UNICODE)];
    }

    /** Les champs d'un type de revenu, visant le chargement donné. */
    private function typeRevenu(string $nom, Chargement $cible): array
    {
        return [
            'nom' => $nom,
            'typeChargement' => (string) $cible->getId(),
            'pourcentage' => '10',
            'redevable' => (string) TypeRevenu::REDEVABLE_ASSUREUR,
            'shared' => '0',
            'multipayments' => '1',
            'appliquerPourcentageDuRisque' => '0',
        ];
    }

    public function testUnTypeDeRevenuNeViseJamaisUneTaxe(): void
    {
        $s = $this->semer(self::CABINET, self::EMAIL);

        foreach (['TVA', 'ARCA'] as $taxe) {
            $refus = $this->soumettre('typerevenu', $s, $this->typeRevenu('Commission sur ' . $taxe, $this->chargement($s['entreprise'], $taxe)));
            self::assertSame(422, $refus['statut'], $taxe);
        }
        self::assertNull($this->em()->getRepository(TypeRevenu::class)->findOneBy(['nom' => 'Commission sur TVA']));

        $permis = $this->soumettre('typerevenu', $s, $this->typeRevenu('Commission sur la prime', $this->chargement($s['entreprise'], 'Prime nette')));
        self::assertSame(200, $permis['statut'], $permis['texte']);
    }

    /** Ket se heurte au même refus, dès le contrôle à blanc. */
    public function testKetNeCreePasUnTypeDeRevenuSurUneTaxe(): void
    {
        $s = $this->semer(self::CABINET, self::EMAIL);
        $em = $this->em();
        $scope = new AiScope($em->find(Entreprise::class, $s['entreprise']), $em->find(Invite::class, $s['invite']));

        $analyse = static::getContainer()->get(WorkspaceMutationService::class)->analyserOperation(
            new MutationOperation('create', 'TypeRevenu', null, [
                'nom' => 'Commission sur TVA',
                'typeChargement' => (int) $this->chargement($s['entreprise'], 'TVA')->getId(),
                'pourcentage' => 10,
                'redevable' => TypeRevenu::REDEVABLE_ASSUREUR,
                'shared' => false,
                'multipayments' => true,
                'appliquerPourcentageDuRisque' => false,
            ]),
            $scope,
        );

        self::assertFalse($analyse['ok']);
    }

    /**
     * LA GARDE SERVEUR, sans le formulaire : le formulaire écarte déjà la taxe (« choix
     * invalide »), si bien qu'un test par l'écran ne prouve pas la règle métier. Ici, elle
     * seule : un type dont la cible est une taxe est refusé, et le motif le dit.
     */
    public function testLaGardeMetierRefuseUneTaxeCommeAssiette(): void
    {
        $s = $this->semer(self::CABINET, self::EMAIL);
        $type = (new TypeRevenu())->setNom('Sur TVA')->setTypeChargement($this->chargement($s['entreprise'], 'TVA'));

        $refus = static::getContainer()->get(\App\Service\Workspace\ChampsObligatoiresInspector::class)
            ->incoherencesMetier($type, 'TypeRevenu');

        self::assertSame(['typeChargement' => ['Une commission ne se calcule pas sur une taxe : « TVA » en est une. '
            . 'Choisissez la prime nette, ou une autre composante de la prime.']], $refus);
    }

    /** Ket crée toujours un type de revenu sur la prime nette — et l'exécution refuse la taxe. */
    public function testKetCreeUnTypeSurLaPrimeNetteEtRefuseLaTaxeALExecution(): void
    {
        $s = $this->semer(self::CABINET, self::EMAIL);
        $em = $this->em();
        $scope = new AiScope($em->find(Entreprise::class, $s['entreprise']), $em->find(Invite::class, $s['invite']));
        $service = static::getContainer()->get(WorkspaceMutationService::class);
        $op = fn (string $nom, string $cible) => new MutationOperation('create', 'TypeRevenu', null, [
            'nom' => $nom,
            'typeChargement' => (int) $this->chargement($s['entreprise'], $cible)->getId(),
            'pourcentage' => 10,
            'redevable' => TypeRevenu::REDEVABLE_ASSUREUR,
            'shared' => false,
            'multipayments' => true,
            'appliquerPourcentageDuRisque' => false,
        ]);

        $analyse = $service->analyserOperation($op('Honoraires Ket', 'Prime nette'), $scope);
        self::assertTrue($analyse['ok'], json_encode($analyse, JSON_UNESCAPED_UNICODE));

        try {
            $em->wrapInTransaction(fn () => $service->executer($op('Sur ARCA', 'ARCA'), $scope, $em->find(Utilisateur::class, $this->em()->find(Entreprise::class, $s['entreprise'])->getUtilisateur()->getId())));
            self::fail('L’exécution refuse une taxe comme assiette.');
        } catch (\App\Service\Workspace\MutationException) {
        }
        $this->em()->clear();
        self::assertNull($this->em()->getRepository(TypeRevenu::class)->findOneBy(['nom' => 'Sur ARCA']));
    }

    /**
     * UN TYPE D'AVANT LE FILTRE, QUI VISE UNE TAXE : son édition ne bascule pas en silence sur
     * une autre base. Le champ revient vide (la taxe n'est plus proposée) et il est
     * obligatoire : enregistrer sans le choisir est refusé, et la cible reste ce qu'elle était.
     */
    public function testLEditionDUnAncienTypeSurUneTaxeObligeAChoisir(): void
    {
        $s = $this->semer(self::CABINET, self::EMAIL);
        $em = $this->em();
        $tva = $this->chargement($s['entreprise'], 'TVA');
        $ancien = (new TypeRevenu())->setNom('Ancien sur TVA')->setTypeChargement($tva)->setPourcentage(5)
            ->setRedevable(TypeRevenu::REDEVABLE_ASSUREUR)->setShared(false)->setMultipayments(true);
        $ancien->setEntreprise($em->find(Entreprise::class, $s['entreprise']));
        $em->persist($ancien);
        $em->flush();

        // Le formulaire rendu ne présélectionne aucune option réelle : l'option vide d'abord.
        $this->client->request('GET', '/admin/typerevenu/api/get-form/' . $ancien->getId());
        self::assertStringContainsString('Choisissez la composante de la prime…', (string) $this->client->getResponse()->getContent());

        // Ce que le navigateur enverrait : le champ vide, un autre champ modifié.
        $champs = $this->typeRevenu('Ancien renommé', $tva);
        $champs['typeChargement'] = '';
        $refus = $this->soumettre('typerevenu', $s, ['id' => (string) $ancien->getId()] + $champs);

        self::assertSame(422, $refus['statut'], $refus['texte']);
        $this->em()->clear();
        $relu = $this->em()->find(TypeRevenu::class, $ancien->getId());
        self::assertSame('Ancien sur TVA', $relu->getNom(), 'Rien n’est enregistré.');
        self::assertSame($tva->getId(), $relu->getTypeChargement()?->getId(), 'La base ne change pas en silence.');
    }

    /** Un chargement visé par un type de revenu ne DEVIENT pas une taxe. */
    public function testUnChargementCibleNeDevientPasUneTaxe(): void
    {
        $s = $this->semer(self::CABINET, self::EMAIL);
        $primeNette = $this->chargement($s['entreprise'], 'Prime nette');

        $refus = $this->soumettre('chargement', $s, ['id' => (string) $primeNette->getId(), 'fonction' => (string) Chargement::FONCTION_TAXE]);

        self::assertSame(422, $refus['statut']);
        self::assertStringContainsString('Commission', $refus['texte'], 'Le refus nomme le type de revenu qui le vise.');
        $this->em()->clear();
        self::assertSame(Chargement::FONCTION_PRIME_NETTE, $this->em()->find(Chargement::class, $primeNette->getId())->getFonction());

        // Un chargement que rien ne vise, lui, peut changer de fonction.
        $libre = $this->soumettre('chargement', $s, ['id' => (string) $this->chargement($s['entreprise'], 'Frais accessoires')->getId(), 'fonction' => (string) Chargement::FONCTION_TAXE]);
        self::assertSame(200, $libre['statut'], $libre['texte']);
    }

    /**
     * UN TYPE NEUF S'ADOSSE AU TAUX DU RISQUE (décision du 2026-10-15). Il s'ouvrait sur 10 %
     * ET le drapeau : le pourcentage du type passant avant le risque, le drapeau ne servait à
     * rien. Désormais le pourcentage est vide, le drapeau coché — et le type enregistré tel
     * quel facture bien le taux du risque.
     */
    public function testUnTypeNeufSAdosseAuTauxDuRisque(): void
    {
        $s = $this->semer(self::CABINET, self::EMAIL);

        $this->client->request('GET', '/admin/typerevenu/api/get-form');
        $html = (string) $this->client->getResponse()->getContent();
        self::assertMatchesRegularExpression('/<input[^>]*name="pourcentage"[^>]*>/', $html);
        preg_match('/<input[^>]*name="pourcentage"[^>]*>/', $html, $pourcentage);
        self::assertDoesNotMatchRegularExpression('/value="[^"]+"/', $pourcentage[0], 'Le pourcentage s’ouvre vide.');
        // Le drapeau est un groupe de boutons radio : c'est l'option « oui » qui est cochée.
        preg_match('/<input[^>]*name="appliquerPourcentageDuRisque"[^>]*value="1"[^>]*>/', $html, $drapeau);
        self::assertStringContainsString('checked', $drapeau[0] ?? '', 'Le drapeau « taux du risque » s’ouvre coché.');

        // Ce que le formulaire neuf envoie, sans retouche : le taux du risque s'applique.
        $champs = ['pourcentage' => '', 'montantflat' => '0', 'appliquerPourcentageDuRisque' => '1']
            + $this->typeRevenu('Commission au taux du risque', $this->chargement($s['entreprise'], 'Prime nette'));
        $permis = $this->soumettre('typerevenu', $s, $champs);
        self::assertSame(200, $permis['statut'], $permis['texte']);

        $type = $this->em()->getRepository(TypeRevenu::class)->findOneBy(['nom' => 'Commission au taux du risque']);
        $tarif = \App\Service\Commission\TarifDuRevenu::deType($type, (new \App\Entity\Risque())->setPourcentageCommissionSpecifiqueHT(15));
        self::assertSame(['taux' => 15.0, 'forfait' => null, 'origine' => 'risque'], $tarif);
    }

    /** Le sélecteur ne propose que les chargements du cabinet, et jamais une taxe. */
    public function testLeSelecteurNeProposeQueLesChargementsDuCabinetHorsTaxes(): void
    {
        $voisin = $this->semer(self::CABINET_AUTRE, self::EMAIL_AUTRE, connecter: false);
        $s = $this->semer(self::CABINET, self::EMAIL);
        $primeNetteVoisine = $this->chargement($voisin['entreprise'], 'Prime nette');

        // Le chargement d'un autre cabinet est refusé comme tout identifiant étranger : 404,
        // sans confirmer qu'il existe (garde déjà en place à l'écriture).
        $refus = $this->soumettre('typerevenu', $s, $this->typeRevenu('Commission voisine', $primeNetteVoisine));
        self::assertSame(404, $refus['statut']);
        self::assertNull($this->em()->getRepository(TypeRevenu::class)->findOneBy(['nom' => 'Commission voisine']));

        // Le formulaire rendu ne liste ni les taxes, ni les chargements du voisin.
        $this->client->request('GET', '/admin/typerevenu/api/get-form');
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('value="' . $this->chargement($s['entreprise'], 'Prime nette')->getId() . '"', $html);
        foreach ([$this->chargement($s['entreprise'], 'TVA'), $this->chargement($s['entreprise'], 'ARCA'), $primeNetteVoisine] as $exclu) {
            self::assertStringNotContainsString('value="' . $exclu->getId() . '"', $html, (string) $exclu->getNom());
        }
    }
}
