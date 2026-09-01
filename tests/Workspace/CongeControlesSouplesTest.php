<?php

namespace App\Tests\Workspace;

use App\Entity\DemandeConge;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\MouvementConge;
use App\Entity\ParametresConge;
use App\Entity\PeriodeBlocage;
use App\Entity\RolesEnAdministration;
use App\Entity\TypeAbsence;
use App\Entity\Utilisateur;
use App\Service\Conge\DemandeCongeValidator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * LES TROIS CONTRÔLES QU'UN VALIDEUR PEUT FRANCHIR — CTRL-03, CTRL-04, CTRL-05.
 *
 * Préavis, plafond d'absents simultanés, période de blocage : pour un collaborateur
 * ordinaire ils refusent ; pour un valideur ils signalent, et le signalement est
 * CONSERVÉ sur la demande puis repris dans l'e-mail.
 *
 * ── CE QUE CE TEST TIENT VRAIMENT ───────────────────────────────────────────────────
 * Trois choses, et la troisième est la plus importante :
 *
 *  1. le contrôle refuse quand il doit refuser ;
 *  2. le valideur passe, mais son passage LAISSE UNE TRACE — un contournement silencieux
 *     se découvre toujours trop tard ;
 *  3. chaque contrôle SE DÉSACTIVE franchement. Un cabinet qui ne peut pas éteindre une
 *     règle apprend à la contourner, et un contournement appris ne se désapprend pas.
 */
class CongeControlesSouplesTest extends KernelTestCase
{
    private const OWNER_EMAIL = 'phpunit-conge-souples@test.local';
    private const ENT = 'PHPUnit Congés Contrôles SARL';

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

    private function validator(): DemandeCongeValidator
    {
        return static::getContainer()->get(DemandeCongeValidator::class);
    }

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email = :e', ['e' => self::OWNER_EMAIL]);

        // `invite` se référence ELLE-MÊME par `manager_id` : supprimer un responsable
        // avant ses assistants viole la contrainte. On dénoue la hiérarchie d'abord —
        // c'est précisément elle que ce test met en place.
        $conn->executeStatement(
            'UPDATE invite i JOIN entreprise e ON i.entreprise_id = e.id SET i.manager_id = NULL WHERE e.nom = :nom',
            ['nom' => self::ENT],
        );

        foreach ([
            'periode_blocage', 'parametres_conge', 'mouvement_conge', 'historique_demande',
            'demande_conge', 'regime_travail', 'jour_ferie', 'type_absence',
            'roles_en_administration', 'invite',
        ] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :nom",
                ['nom' => self::ENT],
            );
        }
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :nom', ['nom' => self::ENT]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :email', ['email' => self::OWNER_EMAIL]);
        $this->em()->clear();
    }

    /**
     * Un cabinet, un responsable et deux collaborateurs qui lui répondent : c'est la seule
     * structure organisationnelle du projet, et donc l'« équipe » de CTRL-04.
     *
     * @return array{entreprise: Entreprise, chef: Invite, alice: Invite, bob: Invite,
     *               ca: TypeAbsence, parametres: ParametresConge}
     */
    private function semer(): array
    {
        $em = $this->em();

        $owner = (new Utilisateur())->setEmail(self::OWNER_EMAIL)->setNom('Contrôles')->setVerified(true)->setPassword('x');
        $em->persist($owner);

        $ent = (new Entreprise())->setNom(self::ENT)->setLicence('LIC')->setAdresse('1 rue')
            ->setTelephone('+2430000')->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($owner);
        $em->persist($ent);
        $owner->setConnectedTo($ent);

        $chef = (new Invite())->setNom('Le Patron')->setProprietaire(true);
        $chef->setUtilisateur($owner)->setEntreprise($ent);
        $em->persist($chef);

        $alice = (new Invite())->setNom('Alice Mukendi')->setProprietaire(false);
        $alice->setEntreprise($ent)->setManager($chef);
        $em->persist($alice);

        $bob = (new Invite())->setNom('Bob Kabila')->setProprietaire(false);
        $bob->setEntreprise($ent)->setManager($chef);
        $em->persist($bob);

        foreach ([$alice, $bob] as $agent) {
            $roles = (new RolesEnAdministration())->setNom('Congés');
            $roles->setAccessConge([Invite::ACCESS_LECTURE, Invite::ACCESS_ECRITURE]);
            $roles->setInvite($agent)->setEntreprise($ent);
            $em->persist($roles);
        }

        $ca = (new TypeAbsence())->setCode(TypeAbsence::CODE_CONGE_ANNUEL)->setLibelle('Congé annuel')
            ->setDecompte(true)->setJustificatifRequis(false)->setAutoriseDemiJournee(true)->setActif(true);
        $ca->setEntreprise($ent);
        $em->persist($ca);

        // Les réglages du cabinet : préavis de 5 jours, un seul absent à la fois.
        $parametres = new ParametresConge();
        $parametres->setDelaiPreavisJours(5);
        $parametres->setMaxAbsentsSimultanes(1);
        $parametres->setEntreprise($ent);
        $em->persist($parametres);

        foreach ([$alice, $bob] as $agent) {
            $dotation = (new MouvementConge())
                ->setAgent($agent)->setExercice((int) (new \DateTimeImmutable('+60 days'))->format('Y'))
                ->setTypeAbsence($ca)->setNature(MouvementConge::NATURE_DOTATION)->setQuantite('40.0');
            $dotation->setEntreprise($ent);
            $em->persist($dotation);
        }

        $em->flush();
        $em->refresh($alice);
        $em->refresh($bob);

        return ['entreprise' => $ent, 'chef' => $chef, 'alice' => $alice, 'bob' => $bob, 'ca' => $ca, 'parametres' => $parametres];
    }

    private function demande(array $s, Invite $agent, string $debut, string $fin, float $jours = 3.0): DemandeConge
    {
        $demande = new DemandeConge();
        $demande->setAgent($agent)->setTypeAbsence($s['ca']);
        $demande->setDateDebut(new \DateTimeImmutable($debut));
        $demande->setDateFin(new \DateTimeImmutable($fin));
        $demande->setEntreprise($s['entreprise']);
        $demande->setNbJours(number_format($jours, 1, '.', ''));

        return $demande;
    }

    private function contient(array $messages, string $fragment): bool
    {
        foreach ($messages as $message) {
            if (mb_stripos($message, $fragment) !== false) {
                return true;
            }
        }

        return false;
    }

    // ═══════════ CTRL-03 : le délai de préavis ═══════════

    public function testUnPreavisTropCourtRefuseLaDemande(): void
    {
        $s = $this->semer();
        // Demain : il ne reste aucun jour ouvrable de préavis.
        $demande = $this->demande($s, $s['alice'], '+1 day', '+3 days');

        $controle = $this->validator()->controler($demande, peutContourner: false);

        self::assertTrue($controle->estBloquee());
        self::assertTrue($this->contient($controle->violations, 'Préavis insuffisant'));
    }

    public function testUnPreavisRespecteNeGeneRien(): void
    {
        $s = $this->semer();
        // Dans deux mois : largement au-delà des cinq jours ouvrables demandés.
        $demande = $this->demande($s, $s['alice'], '+60 days', '+62 days');

        $controle = $this->validator()->controler($demande, peutContourner: false);

        self::assertFalse($controle->estBloquee(), implode(' | ', $controle->violations));
        self::assertFalse($controle->aDesContournements());
    }

    /** UN CABINET QUI NE VEUT PAS DE PRÉAVIS DOIT POUVOIR L'ÉTEINDRE. */
    public function testUnPreavisAZeroDesactiveLeControle(): void
    {
        $s = $this->semer();
        $s['parametres']->setDelaiPreavisJours(0);
        $this->em()->flush();

        $demande = $this->demande($s, $s['alice'], '+1 day', '+3 days');

        self::assertFalse($this->validator()->controler($demande)->estBloquee());
    }

    /** Le valideur passe outre — et son passage laisse une trace. */
    public function testLeValideurFranchitLePreavisEtLaisseUneTrace(): void
    {
        $s = $this->semer();
        $demande = $this->demande($s, $s['alice'], '+1 day', '+3 days');

        $controle = $this->validator()->controler($demande, peutContourner: true);

        self::assertFalse($controle->estBloquee(), 'Un valideur doit pouvoir passer outre.');
        self::assertTrue($controle->aDesContournements(), "…mais jamais en silence.");
        self::assertTrue($this->contient($controle->avertissements, 'Préavis insuffisant'));
        self::assertNotNull($controle->contournementsEnTexte());
    }

    // ═══════════ CTRL-05 : les périodes de blocage ═══════════

    public function testUnePeriodeDeBlocageRefuseLaDemande(): void
    {
        $s = $this->semer();

        $blocage = new PeriodeBlocage();
        $blocage->setLibelle("Clôture de l'exercice");
        $blocage->setDateDebut(new \DateTimeImmutable('+59 days'));
        $blocage->setDateFin(new \DateTimeImmutable('+70 days'));
        $blocage->setActif(true);
        $blocage->setEntreprise($s['entreprise']);
        $s['parametres']->addPeriodesBlocage($blocage);
        $this->em()->persist($blocage);
        $this->em()->flush();

        $demande = $this->demande($s, $s['alice'], '+60 days', '+62 days');
        $controle = $this->validator()->controler($demande, peutContourner: false);

        self::assertTrue($controle->estBloquee());
        self::assertTrue($this->contient($controle->violations, "Clôture de l'exercice"));
    }

    /**
     * UNE PÉRIODE DÉSACTIVÉE NE BLOQUE PLUS, mais elle reste en base : elle explique des
     * refus passés, et l'effacer les rendrait incompréhensibles.
     */
    public function testUnePeriodeDesactiveeNeBloquePlus(): void
    {
        $s = $this->semer();

        $blocage = new PeriodeBlocage();
        $blocage->setLibelle("Clôture de l'exercice");
        $blocage->setDateDebut(new \DateTimeImmutable('+59 days'));
        $blocage->setDateFin(new \DateTimeImmutable('+70 days'));
        $blocage->setActif(false);
        $blocage->setEntreprise($s['entreprise']);
        $this->em()->persist($blocage);
        $this->em()->flush();

        $demande = $this->demande($s, $s['alice'], '+60 days', '+62 days');

        self::assertFalse($this->validator()->controler($demande)->estBloquee());
    }

    /** Entrer d'un seul jour dans la période suffit : le chevauchement n'est pas l'inclusion. */
    public function testUnSeulJourDansLaPeriodeSuffitAlaBloquer(): void
    {
        $s = $this->semer();

        $blocage = new PeriodeBlocage();
        $blocage->setLibelle('Campagne de renouvellement');
        $blocage->setDateDebut(new \DateTimeImmutable('+62 days'));
        $blocage->setDateFin(new \DateTimeImmutable('+90 days'));
        $blocage->setActif(true);
        $blocage->setEntreprise($s['entreprise']);
        $this->em()->persist($blocage);
        $this->em()->flush();

        // La demande commence AVANT le blocage et n'y entre que par son dernier jour.
        $demande = $this->demande($s, $s['alice'], '+58 days', '+62 days');

        self::assertTrue($this->validator()->controler($demande)->estBloquee());
    }

    // ═══════════ CTRL-04 : les absents simultanés ═══════════

    /**
     * Bob est déjà absent, le plafond est à un : Alice ne peut pas partir en même temps.
     *
     * Les deux répondent au même responsable — c'est ce qui fait d'eux une « équipe » au
     * sens de ce contrôle, le projet n'ayant aucune notion de service.
     */
    public function testUnCollegueDejaAbsentBloqueLaDemande(): void
    {
        $s = $this->semer();

        $absenceBob = $this->demande($s, $s['bob'], '+60 days', '+62 days');
        $absenceBob->setStatut(DemandeConge::STATUT_APPROUVEE);
        $this->em()->persist($absenceBob);
        $this->em()->flush();

        $demande = $this->demande($s, $s['alice'], '+61 days', '+63 days');
        $controle = $this->validator()->controler($demande, peutContourner: false);

        self::assertTrue($controle->estBloquee());
        self::assertTrue($this->contient($controle->violations, "Plafond d'absences atteint"));
        self::assertTrue($this->contient($controle->violations, 'Bob Kabila'), 'Le message doit NOMMER qui est absent.');
        self::assertTrue($this->contient($controle->violations, 'de votre équipe'));
    }

    /**
     * UNE DEMANDE EN ATTENTE N'EST PAS UNE ABSENCE. La compter refuserait des congés au
     * nom de quelque chose qui pourrait ne jamais arriver.
     */
    public function testUneDemandeEnAttenteNeComptePasDansLePlafond(): void
    {
        $s = $this->semer();

        $enAttente = $this->demande($s, $s['bob'], '+60 days', '+62 days');
        $enAttente->setStatut(DemandeConge::STATUT_SOUMISE);
        $this->em()->persist($enAttente);
        $this->em()->flush();

        $demande = $this->demande($s, $s['alice'], '+61 days', '+63 days');

        self::assertFalse($this->validator()->controler($demande)->estBloquee());
    }

    public function testUnPlafondVideDesactiveLeControle(): void
    {
        $s = $this->semer();
        $s['parametres']->setMaxAbsentsSimultanes(null);
        $this->em()->flush();

        $absenceBob = $this->demande($s, $s['bob'], '+60 days', '+62 days');
        $absenceBob->setStatut(DemandeConge::STATUT_APPROUVEE);
        $this->em()->persist($absenceBob);
        $this->em()->flush();

        $demande = $this->demande($s, $s['alice'], '+61 days', '+63 days');

        self::assertFalse($this->validator()->controler($demande)->estBloquee());
    }

    /**
     * SANS RESPONSABLE, LE PÉRIMÈTRE EST LE CABINET — et le message le dit.
     *
     * « 3 absents de votre équipe » et « 3 absents du cabinet » ne s'entendent pas pareil,
     * et le second signale au passage qu'il manque un rattachement hiérarchique.
     */
    public function testSansResponsableLePerimetreEstLeCabinetEtLeDit(): void
    {
        $s = $this->semer();
        $s['alice']->setManager(null);
        $this->em()->flush();
        $this->em()->refresh($s['alice']);

        $absenceBob = $this->demande($s, $s['bob'], '+60 days', '+62 days');
        $absenceBob->setStatut(DemandeConge::STATUT_APPROUVEE);
        $this->em()->persist($absenceBob);
        $this->em()->flush();

        $demande = $this->demande($s, $s['alice'], '+61 days', '+63 days');
        $controle = $this->validator()->controler($demande, peutContourner: false);

        self::assertTrue($controle->estBloquee());
        self::assertTrue($this->contient($controle->violations, 'du cabinet'));
    }

    // ═══════════ Les contrôles DURS ne se contournent jamais ═══════════

    /**
     * LE SOLDE NE SE CONTOURNE PAS, MÊME PAR UN VALIDEUR.
     *
     * C'est la ligne de partage : le préavis est une règle d'organisation, le solde est un
     * droit. Un valideur qui pourrait approuver au-delà du solde rendrait le compteur
     * décoratif.
     */
    public function testUnValideurNeContournePasLeSolde(): void
    {
        $s = $this->semer();
        $demande = $this->demande($s, $s['alice'], '+60 days', '+120 days', 100.0);

        $controle = $this->validator()->controler($demande, peutContourner: true);

        self::assertTrue($controle->estBloquee(), 'Le solde reste un refus, quel que soit le statut.');
        self::assertTrue($this->contient($controle->violations, 'Solde insuffisant'));
    }

    /** Un chevauchement non plus : deux absences simultanées de la même personne n'existent pas. */
    public function testUnValideurNeContournePasLeChevauchement(): void
    {
        $s = $this->semer();

        $premiere = $this->demande($s, $s['alice'], '+60 days', '+65 days');
        $premiere->setStatut(DemandeConge::STATUT_APPROUVEE);
        $this->em()->persist($premiere);
        $this->em()->flush();

        $seconde = $this->demande($s, $s['alice'], '+62 days', '+68 days');
        $controle = $this->validator()->controler($seconde, peutContourner: true);

        self::assertTrue($controle->estBloquee());
        self::assertTrue($this->contient($controle->violations, 'chevauche'));
    }

    // ═══════════ Les réglages par défaut ═══════════

    /**
     * UN CABINET SANS RÉGLAGES N'EST PAS UN CABINET SANS RÈGLES : c'est un cabinet aux
     * valeurs par défaut. Le repository rend toujours un objet, sans rien écrire.
     */
    public function testUnCabinetSansReglagesRetombeSurLesDefauts(): void
    {
        $s = $this->semer();
        $this->em()->remove($s['parametres']);
        $this->em()->flush();
        $this->em()->clear();

        $entreprise = $this->em()->getRepository(Entreprise::class)->findOneBy(['nom' => self::ENT]);
        $parametres = static::getContainer()->get(\App\Repository\ParametresCongeRepository::class)
            ->pourEntreprise($entreprise);

        self::assertNull($parametres->getId(), 'Une lecture ne doit pas créer une ligne en base.');
        self::assertSame(ParametresConge::PREAVIS_DEFAUT, $parametres->getDelaiPreavisJours());
        self::assertFalse($parametres->controleAbsentsSimultanes(), 'Aucun plafond par défaut : on ne devine pas la taille des équipes.');
    }
}
