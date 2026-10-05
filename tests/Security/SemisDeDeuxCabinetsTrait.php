<?php

namespace App\Tests\Security;

use App\Entity\Article;
use App\Entity\Assureur;
use App\Entity\AutoriteFiscale;
use App\Entity\Avenant;
use App\Entity\Bordereau;
use App\Entity\Chargement;
use App\Entity\Classeur;
use App\Entity\Client;
use App\Entity\CompteBancaire;
use App\Entity\ConditionPartage;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Groupe;
use App\Entity\Invite;
use App\Entity\Partenaire;
use App\Entity\Piste;
use App\Entity\Portefeuille;
use App\Entity\RevenuPourCourtier;
use App\Entity\Risque;
use App\Entity\Taxe;
use App\Entity\Tranche;
use App\Entity\TypeRevenu;
use App\Entity\Utilisateur;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\ChargeCourtier;
use App\Entity\ChargementPourPrime;
use App\Entity\Charge;
use App\Entity\Contact;
use App\Entity\DemandeConge;
use App\Entity\Depense;
use App\Entity\DepenseCourtier;
use App\Entity\Document;
use App\Entity\Feedback;
use App\Entity\Fournisseur;
use App\Entity\JourFerie;
use App\Entity\ModelePieceSinistre;
use App\Entity\Monnaie;
use App\Entity\Note;
use App\Entity\NotificationSinistre;
use App\Entity\OffreIndemnisationSinistre;
use App\Entity\Operation;
use App\Entity\Paiement;
use App\Entity\PaiementPrime;
use App\Entity\ParametresConge;
use App\Entity\PeriodeBlocage;
use App\Entity\PieceSinistre;
use App\Entity\RegimeTravail;
use App\Entity\ReversementRetroAgent;
use App\Entity\RolesEnAdministration;
use App\Entity\RolesEnFinance;
use App\Entity\RolesEnMarketing;
use App\Entity\RolesEnProduction;
use App\Entity\RolesEnSinistre;
use App\Entity\Tache;
use App\Entity\TypeAbsence;

/**
 * DEUX CABINETS COMPLETS, SYMETRIQUES, ET CE QU'ILS CONTIENNENT.
 *
 * Un semis partage par les tests de cloisonnement, pour une raison de fond : une liste
 * vide a DEUX causes -- « on te le refuse » et « il n'y a rien a montrer ». Un test qui
 * ne seme pas les confond et passe au vert sur une base vide. C'est ce qui a laisse
 * vivre la fuite d'ArticleAutocompleteField, dont aucune ligne n'existait en base de
 * test : son filtre etait commente, et personne ne pouvait le voir.
 *
 * `CLASSES_SEMEES` declare ce que la recette couvre REELLEMENT. Les tests s'en servent
 * pour refuser de juger un alias ou une route dont la classe n'est pas semee, plutot
 * que de conclure a tort.
 */
trait SemisDeDeuxCabinetsTrait
{
    private const OWNER_A_EMAIL = 'phpunit-endpoints-a@test.local';
    private const OWNER_B_EMAIL = 'phpunit-endpoints-b@test.local';
    private const ENTREPRISE_A_NOM = 'PHPUnit Endpoints Cabinet A SARL';
    private const ENTREPRISE_B_NOM = 'PHPUnit Endpoints Cabinet B SARL';

    /**
     * CE QUE LA RECETTE CREE REELLEMENT, declare ici et nulle part ailleurs.
     *
     * Les tests s'en servent pour refuser de juger ce qu'ils n'ont pas seme. Toute
     * entite ajoutee a semerUnCabinet() doit paraitre ici : sinon un test conclurait
     * « refuse » la ou il faut lire « rien a montrer ».
     *
     * Piste et Cotation y figurent bien qu'aucun champ d'autocompletion ne les vise :
     * les routes /api/get-form et /api/delete, elles, les visent.
     *
     * @var list<class-string>
     */
    private const CLASSES_SEMEES = [
        Article::class, Assureur::class, AutoriteFiscale::class, Avenant::class,
        Bordereau::class, Chargement::class, Classeur::class, Client::class,
        CompteBancaire::class, ConditionPartage::class, Cotation::class, Groupe::class,
        Invite::class, Partenaire::class, Piste::class, Portefeuille::class,
        RevenuPourCourtier::class, Risque::class, Taxe::class, Tranche::class,
        TypeRevenu::class,
        // Les 29 ajoutees pour que le test de cloisonnement puisse JUGER ces classes :
        // sans entite semee, « refuse » et « rien a montrer » restent indistincts.
        ChargeCourtier::class, ChargementPourPrime::class, Contact::class, DemandeConge::class,
        DepenseCourtier::class, Document::class, Feedback::class, Fournisseur::class,
        JourFerie::class, ModelePieceSinistre::class, Monnaie::class, Note::class,
        NotificationSinistre::class, OffreIndemnisationSinistre::class, Operation::class,
        Paiement::class, PaiementPrime::class, ParametresConge::class, PeriodeBlocage::class,
        PieceSinistre::class, RegimeTravail::class, ReversementRetroAgent::class,
        RolesEnAdministration::class, RolesEnFinance::class, RolesEnMarketing::class,
        RolesEnProduction::class, RolesEnSinistre::class, Tache::class, TypeAbsence::class,
    ];

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();
        $noms = [self::ENTREPRISE_A_NOM, self::ENTREPRISE_B_NOM];
        $emails = [self::OWNER_A_EMAIL, self::OWNER_B_EMAIL];

        // Enfants d'abord : chaque table ne part qu'une fois ses references levees.
        $tables = [
            // Les enfants d'abord. `operation` est traitee a part : elle ne porte pas
            // d'entreprise_id, elle la tient de son bordereau.
            'paiement_prime', 'article', 'revenu_pour_courtier', 'tranche', 'avenant',
            'depense_courtier', 'charge_courtier', 'note', 'paiement', 'reversement_retro_agent',
            'piece_sinistre', 'offre_indemnisation_sinistre', 'notification_sinistre',
            'modele_piece_sinistre', 'demande_conge', 'jour_ferie', 'parametres_conge',
            'periode_blocage', 'regime_travail', 'type_absence', 'document', 'feedback',
            'fournisseur', 'tache', 'chargement_pour_prime', 'contact', 'monnaie',
            'roles_en_administration', 'roles_en_finance', 'roles_en_marketing',
            'roles_en_production', 'roles_en_sinistre', 'bordereau',
            'cotation', 'piste', 'condition_partage', 'autorite_fiscale', 'taxe',
            'compte_bancaire', 'chargement', 'classeur', 'client', 'groupe',
            'portefeuille', 'partenaire', 'assureur', 'risque', 'type_revenu', 'invite',
        ];
        // `operation` ne porte pas d'entreprise_id : on la denoue par son bordereau,
        // AVANT que celui-ci ne parte.
        $conn->executeStatement(
            'DELETE o FROM operation o JOIN bordereau b ON o.bordereau_id = b.id'
            . ' JOIN entreprise e ON b.entreprise_id = e.id WHERE e.nom IN (:noms)',
            ['noms' => $noms],
            ['noms' => ArrayParameterType::STRING],
        );

        foreach ($tables as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom IN (:noms)",
                ['noms' => $noms],
                ['noms' => ArrayParameterType::STRING],
            );
        }

        // Denoue la FK circulaire utilisateur.connected_to_id avant de supprimer l'entreprise.
        $conn->executeStatement(
            'UPDATE utilisateur SET connected_to_id = NULL WHERE email IN (:emails)',
            ['emails' => $emails],
            ['emails' => ArrayParameterType::STRING],
        );
        $conn->executeStatement(
            'DELETE FROM entreprise WHERE nom IN (:noms)',
            ['noms' => $noms],
            ['noms' => ArrayParameterType::STRING],
        );
        $conn->executeStatement(
            'DELETE FROM utilisateur WHERE email IN (:emails)',
            ['emails' => $emails],
            ['emails' => ArrayParameterType::STRING],
        );
    }

    /**
     * Les deux cabinets, complets et symetriques.
     *
     * @return array{a: array{owner: int, entreprise: int}, b: array{owner: int, entreprise: int}, classes: list<class-string>}
     */
    private function semerLesDeuxCabinets(): array
    {
        $a = $this->semerUnCabinet('A', self::ENTREPRISE_A_NOM, self::OWNER_A_EMAIL);
        $b = $this->semerUnCabinet('B', self::ENTREPRISE_B_NOM, self::OWNER_B_EMAIL);

        $em = $this->em();
        $em->flush();

        $ids = [
            'a' => ['owner' => $a['owner']->getId(), 'entreprise' => $a['entreprise']->getId()],
            'b' => ['owner' => $b['owner']->getId(), 'entreprise' => $b['entreprise']->getId()],
            // CE QUE CETTE RECETTE COUVRE, declare ici et nulle part ailleurs. Le test de
            // couverture compare cette liste aux classes que le registre expose : un alias
            // dont la classe manque ici rendrait « liste vide » indecidable.
            'classes' => self::CLASSES_SEMEES,
        ];

        $em->clear();

        return $ids;
    }

    /**
     * UNE entite par classe autocompletee, dans un cabinet complet.
     *
     * @return array{owner: Utilisateur, entreprise: Entreprise}
     */
    private function semerUnCabinet(string $suffixe, string $nomEntreprise, string $email): array
    {
        $em = $this->em();

        $owner = (new Utilisateur())
            ->setEmail($email)
            ->setNom('PHPUnit Endpoints ' . $suffixe)
            ->setVerified(true)
            ->setPassword('irrelevant');
        $em->persist($owner);

        $entreprise = (new Entreprise())
            ->setNom($nomEntreprise)
            ->setLicence('LIC-' . $suffixe)
            ->setAdresse('1 rue des Endpoints')
            ->setTelephone('+243000000' . \ord($suffixe))
            ->setRccm('RCCM-' . $suffixe)
            ->setIdnat('IDNAT-' . $suffixe)
            ->setNumimpot('IMP-' . $suffixe)
            ->setUtilisateur($owner);
        $em->persist($entreprise);

        // L'entreprise ACTIVE : c'est elle que setFiltreEntreprise() lit via getConnectedTo().
        $owner->setConnectedTo($entreprise);

        $invite = (new Invite())->setNom('Proprietaire ' . $suffixe);
        $invite->setUtilisateur($owner)->setEntreprise($entreprise)->setProprietaire(true);
        $em->persist($invite);

        $portefeuille = (new Portefeuille())->setNom('Portefeuille ' . $suffixe)->setGestionnaire($invite);
        $portefeuille->setEntreprise($entreprise);
        $em->persist($portefeuille);

        $groupe = (new Groupe())->setNom('Groupe ' . $suffixe)->setDescription('Groupe de test');
        $groupe->setEntreprise($entreprise);
        $em->persist($groupe);

        $client = (new Client())->setNom('Client ' . $suffixe)->setExonere(false);
        $client->setEntreprise($entreprise)->setPortefeuille($portefeuille);
        $em->persist($client);

        $assureur = (new Assureur())->setNom('Assureur ' . $suffixe);
        $assureur->setEntreprise($entreprise);
        $em->persist($assureur);

        $risque = (new Risque())
            ->setCode('R' . $suffixe)
            ->setBranche(Risque::BRANCHE_IARD_OU_NON_VIE)
            ->setNomComplet('Risque ' . $suffixe)
            ->setImposable(true);
        $risque->setEntreprise($entreprise);
        $em->persist($risque);

        // Dates CALCULEES : un test soumis a une date figee se met a mentir tout seul.
        $maintenant = new \DateTimeImmutable('now');

        $piste = (new Piste())
            ->setNom('Piste ' . $suffixe)
            ->setTypeAvenant(Piste::AVENANT_SOUSCRIPTION)
            ->setDescriptionDuRisque('Risque de test endpoints')
            ->setExercice((int) $maintenant->format('Y'))
            ->setClient($client);
        $piste->setEntreprise($entreprise)->setInvite($invite);
        $em->persist($piste);

        $cotation = (new Cotation())->setNom('Cotation ' . $suffixe)->setDuree(12);
        $cotation->setPiste($piste)->setAssureur($assureur);
        $cotation->setEntreprise($entreprise);
        $em->persist($cotation);

        $avenant = (new Avenant())
            ->setStartingAt($maintenant->modify('-30 days'))
            ->setEndingAt($maintenant->modify('+335 days'))
            ->setDescription('Avenant de test endpoints')
            ->setReferencePolice('POL-' . $suffixe . '-001')
            ->setNonRenouvelable(false)
            ->setCotation($cotation);
        $avenant->setEntreprise($entreprise);
        $em->persist($avenant);

        // `shared`, `multipayments` et `redevable` sont NOT NULL sans defaut.
        $typeRevenu = (new TypeRevenu())
            ->setNom('Commission ' . $suffixe)
            ->setShared(false)
            ->setMultipayments(false)
            ->setRedevable(TypeRevenu::REDEVABLE_ASSUREUR);
        $typeRevenu->setEntreprise($entreprise);
        $em->persist($typeRevenu);

        $revenu = (new RevenuPourCourtier())->setNom('Revenu ' . $suffixe);
        $revenu->setCotation($cotation)->setTypeRevenu($typeRevenu);
        $revenu->setEntreprise($entreprise);
        $em->persist($revenu);

        $tranche = (new Tranche())
            ->setNom('Tranche ' . $suffixe)
            ->setPourcentage(100.0) // 100 % en POINTS (convention du projet).
            ->setPayableAt($maintenant->modify('-30 days'))
            ->setEcheanceAt($maintenant->modify('-5 days'));
        $tranche->setCotation($cotation);
        $tranche->setEntreprise($entreprise);
        $em->persist($tranche);

        $article = (new Article())->setQuantite(1.0)->setTranche($tranche);
        $article->setEntreprise($entreprise);
        $em->persist($article);

        $taxe = (new Taxe())
            ->setCode('T' . $suffixe)
            ->setDescription('Taxe de test endpoints')
            ->setTauxIARD('10')
            ->setTauxVIE('5')
            ->setRedevable(Taxe::REDEVABLE_ASSUREUR);
        $taxe->setEntreprise($entreprise);
        $em->persist($taxe);

        $autorite = (new AutoriteFiscale())
            ->setNom('Autorite ' . $suffixe)
            ->setAbreviation('A' . $suffixe)
            ->setTaxe($taxe);
        $autorite->setEntreprise($entreprise);
        $em->persist($autorite);

        $compte = (new CompteBancaire())
            ->setIntitule('Compte ' . $suffixe)
            ->setNumero('0000-' . $suffixe)
            ->setBanque('Banque ' . $suffixe)
            ->setCodeSwift('FERMCDK' . $suffixe);
        $compte->setEntreprise($entreprise);
        $em->persist($compte);

        $chargement = (new Chargement())->setNom('Chargement ' . $suffixe);
        $chargement->setEntreprise($entreprise);
        $em->persist($chargement);

        $classeur = (new Classeur())->setNom('Classeur ' . $suffixe)->setDescription('Classeur de test');
        $classeur->setEntreprise($entreprise);
        $em->persist($classeur);

        $partenaire = (new Partenaire())->setNom('Partenaire ' . $suffixe)->setPart(50.0);
        $partenaire->setEntreprise($entreprise);
        $em->persist($partenaire);

        $condition = (new ConditionPartage())
            ->setNom('Condition ' . $suffixe)
            ->setFormule(ConditionPartage::FORMULE_NE_SAPPLIQUE_PAS_SEUIL)
            ->setSeuil(0.0)
            ->setCritereRisque(ConditionPartage::CRITERE_PAS_RISQUES_CIBLES)
            ->setPartenaire($partenaire);
        $condition->setEntreprise($entreprise);
        $em->persist($condition);

        $bordereau = (new Bordereau())
            ->setNom('Bordereau ' . $suffixe)
            ->setType(Bordereau::TYPE_BOREDERAU_PRODUCTION)
            ->setReference('BDX-' . $suffixe . '-001')
            ->setReceivedAt($maintenant->modify('-10 days'))
            ->setPeriodeDebut($maintenant->modify('-40 days'))
            ->setPeriodeFin($maintenant->modify('-10 days'))
            ->setAssureur($assureur)
            ->setInvite($invite)
            ->setEntreprise($entreprise);
        $em->persist($bordereau);

        $this->semerLeReste($entreprise, $invite, $suffixe, $maintenant, [
            'client' => $client, 'assureur' => $assureur, 'cotation' => $cotation,
            'tranche' => $tranche, 'revenu' => $revenu, 'bordereau' => $bordereau,
            'partenaire' => $partenaire, 'risque' => $risque, 'classeur' => $classeur,
        ]);

        return ['owner' => $owner, 'entreprise' => $entreprise];
    }

    /**
     * Rattache un compte EXISTANT a un second cabinet, sans toucher a son cabinet actif.
     *
     * Rien ne l interdit : Utilisateur::$invites est une OneToMany, et aucune contrainte
     * d unicite ne protege le couple (utilisateur, entreprise). C est meme le
     * fonctionnement normal du produit -- la bascule de workspace existe pour cela.
     */
    private function rattacherAuCabinet(int $ownerId, int $entrepriseId, string $nom): void
    {
        $em = $this->em();
        $utilisateur = $em->getRepository(Utilisateur::class)->find($ownerId);
        $entreprise = $em->getRepository(Entreprise::class)->find($entrepriseId);

        $invite = (new Invite())->setNom($nom);
        $invite->setUtilisateur($utilisateur)->setEntreprise($entreprise)->setProprietaire(true);
        $em->persist($invite);
        $em->flush();
        $em->clear();
    }

    /**
     * Les identifiants qu'une entreprise possede, pour une classe donnee.
     *
     * @return list<int>
     */
    private function identifiantsDuCabinet(string $classe, int $entrepriseId): array
    {
        // UNE CLASSE SUR CINQUANTE N'A PAS D'`entreprise` : `Operation` la tient de son
        // bordereau. Le chemin est ecrit ici comme il l'est dans AppartenanceAuCabinet --
        // deux endroits, mais aucun des deux ne DEVINE : le deviner reviendrait a traiter
        // « pas d'entreprise » comme « globale », ce qui ouvrirait toutes les
        // sous-entites qu'un parent protege.
        $dql = $classe === Operation::class
            ? 'SELECT e.id FROM ' . Operation::class . ' e JOIN e.bordereau b WHERE b.entreprise = :ese'
            : sprintf('SELECT e.id FROM %s e WHERE e.entreprise = :ese', $classe);

        return array_map('intval', $this->em()->createQuery($dql)
            ->setParameter('ese', $entrepriseId)
            ->getSingleColumnResult());
    }


    /** L'identifiant de la premiere Invite d'un compte dans un cabinet donne. */
    private function premiereInviteDe(int $ownerId, int $entrepriseId): ?int
    {
        $invite = $this->em()->getRepository(Invite::class)->findOneBy([
            'utilisateur' => $this->em()->getRepository(Utilisateur::class)->find($ownerId),
            'entreprise' => $this->em()->getRepository(Entreprise::class)->find($entrepriseId),
        ]);

        return $invite?->getId();
    }

    /** Repose le cabinet actif d'un compte, sans passer par les routes de bascule. */
    private function remettreLeCabinetActif(int $ownerId, int $entrepriseId): void
    {
        $em = $this->em();
        $utilisateur = $em->getRepository(Utilisateur::class)->find($ownerId);
        $utilisateur->setConnectedTo($em->getRepository(Entreprise::class)->find($entrepriseId));
        $em->flush();
        $em->clear();
    }

    /**
     * Revoque l'acces d'un compte a un cabinet.
     *
     * Invite n'a aucun champ d'etat -- ni acceptation, ni revocation : revoquer, c'est
     * SUPPRIMER la ligne. C'est ce que fait la console des invites, et c'est pourquoi
     * rien ne remet connectedTo a zero au passage.
     */
    private function revoquerLInvite(int $ownerId, int $entrepriseId): void
    {
        $em = $this->em();
        $invites = $em->getRepository(Invite::class)->findBy([
            'utilisateur' => $em->getRepository(Utilisateur::class)->find($ownerId),
            'entreprise' => $em->getRepository(Entreprise::class)->find($entrepriseId),
        ]);
        foreach ($invites as $invite) {
            $em->remove($invite);
        }
        $em->flush();
        $em->clear();
    }

    /**
     * LES VINGT-NEUF AUTRES CLASSES QU'UNE ROUTE PEUT ATTEINDRE.
     *
     * Elles ne sont pas semees pour le plaisir de l'exhaustivite. Les tests de
     * cloisonnement n'osent juger que ce qu'ils ont seme : sans ligne en base, « on te le
     * refuse » et « il n'y a rien a montrer » sont indistinguables, et l'assertion passe
     * au vert sans rien prouver. C'est ce qui a laisse vivre la fuite d'Article.
     *
     * Chaque entite est reduite au STRICT NECESSAIRE -- les colonnes NOT NULL sans defaut,
     * et les parents sans lesquels la ligne n'existerait pas. On ne cherche pas a
     * reproduire un cabinet realiste : on cherche une ligne par classe, rattachee au bon
     * cabinet.
     *
     * @param array<string, object> $socle les entites deja creees dont celles-ci dependent
     */
    private function semerLeReste(
        Entreprise $entreprise,
        Invite $invite,
        string $suffixe,
        \DateTimeImmutable $maintenant,
        array $socle,
    ): void {
        $em = $this->em();

        $monnaie = (new Monnaie())->setNom('Dollar ' . $suffixe)->setCode('US' . $suffixe)
            ->setTauxusd('1')->setFonction(Monnaie::FONCTION_SAISIE_ET_AFFICHAGE)->setLocale(false);
        $monnaie->setEntreprise($entreprise);
        $em->persist($monnaie);

        $contact = (new Contact())->setNom('Contact ' . $suffixe)->setTelephone('+243000000001')
            ->setType(Contact::TYPE_CONTACT_PRODUCTION);
        $contact->setEntreprise($entreprise);
        $em->persist($contact);

        $document = (new Document())->setNom('Document ' . $suffixe);
        $document->setEntreprise($entreprise);
        $em->persist($document);

        $feedback = (new Feedback())->setDescription('Retour ' . $suffixe)->setType(Feedback::TYPE_CALL);
        $feedback->setEntreprise($entreprise);
        $em->persist($feedback);

        $fournisseur = (new Fournisseur())->setNom('Fournisseur ' . $suffixe)->setActif(true);
        $fournisseur->setEntreprise($entreprise);
        $em->persist($fournisseur);

        $tache = (new Tache())->setDescription('Tache ' . $suffixe)
            ->setToBeEndedAt($maintenant->modify('+7 days'))->setClosed(false);
        $tache->setEntreprise($entreprise);
        $em->persist($tache);

        $chargementPrime = (new ChargementPourPrime())->setNom('Chargement prime ' . $suffixe);
        $chargementPrime->setEntreprise($entreprise);
        $em->persist($chargementPrime);

        // FINANCES
        $charge = (new ChargeCourtier())->setCode('CH' . $suffixe)->setLibelle('Charge ' . $suffixe)
            ->setCompteOhada('6010')->setComportement(Charge::COMPORTEMENT_FIXE)
            ->setPeriodicite(Charge::PERIODICITE_MENSUELLE)->setActif(true);
        $charge->setEntreprise($entreprise);
        $em->persist($charge);

        $depense = (new DepenseCourtier())->setDateDepense($maintenant->modify('-3 days'))
            ->setMontant('100')->setTauxTva('16')->setMoyenPaiement(Depense::MOYEN_BANQUE)
            ->setStatut(Depense::STATUT_ENGAGEE)->setCharge($charge);
        $depense->setEntreprise($entreprise);
        $em->persist($depense);

        $note = (new Note())->setNom('Note ' . $suffixe)->setType(Note::TYPE_NOTE_DE_DEBIT)
            ->setAddressedTo(Note::TO_CLIENT)->setReference('NDD-' . $suffixe)
            ->setValidated(false)->setSignature('Signature ' . $suffixe);
        $note->setEntreprise($entreprise);
        $em->persist($note);

        $paiement = (new Paiement())->setMontant(50.0)->setPaidAt($maintenant->modify('-2 days'));
        $paiement->setEntreprise($entreprise);
        $em->persist($paiement);

        $paiementPrime = (new PaiementPrime())->setPaidAt($maintenant->modify('-2 days'))
            ->setMontant(75.0)->setReference('PP-' . $suffixe)->setTranche($socle['tranche']);
        $paiementPrime->setEntreprise($entreprise);
        $em->persist($paiementPrime);

        $reversement = (new ReversementRetroAgent())->setMontant(25.0)
            ->setPaidAt($maintenant->modify('-1 day'))->setReference('RV-' . $suffixe);
        $reversement->setEntreprise($entreprise);
        $em->persist($reversement);

        // `Operation` est la SEULE des cinquante classes a ne pas porter `entreprise` :
        // elle la tient de son bordereau. C'est le chemin declare dans AppartenanceAuCabinet.
        $operation = (new Operation())->setReferencePolice('POL-' . $suffixe . '-001')
            ->setNumeroAvenant('AV-1')->setMontantHT(1000.0)->setBordereau($socle['bordereau']);
        $em->persist($operation);

        // SINISTRES
        $modelePiece = (new ModelePieceSinistre())->setNom('Modele ' . $suffixe)->setObligatoire(true);
        $modelePiece->setEntreprise($entreprise);
        $em->persist($modelePiece);

        $notification = (new NotificationSinistre())->setOccuredAt($maintenant->modify('-20 days'));
        $notification->setEntreprise($entreprise);
        $em->persist($notification);

        $piece = (new PieceSinistre())->setDescription('Piece ' . $suffixe)
            ->setReceivedAt($maintenant->modify('-15 days'))->setFourniPar('Client ' . $suffixe);
        $piece->setEntreprise($entreprise);
        $em->persist($piece);

        $offre = (new OffreIndemnisationSinistre())->setMontantPayable(500.0)
            ->setBeneficiaire('Beneficiaire ' . $suffixe);
        $offre->setEntreprise($entreprise);
        $em->persist($offre);

        // CONGES
        $typeAbsence = (new TypeAbsence())->setCode('TA' . $suffixe)->setLibelle('Conges ' . $suffixe)
            ->setDecompte(true)->setJustificatifRequis(false)->setAutoriseDemiJournee(true)->setActif(true);
        $typeAbsence->setEntreprise($entreprise);
        $em->persist($typeAbsence);

        $demande = (new DemandeConge())->setDateDebut($maintenant->modify('+10 days'))
            ->setDateFin($maintenant->modify('+12 days'))->setDemiJourneeDebut(false)
            ->setDemiJourneeFin(false)->setStatut(DemandeConge::STATUT_BROUILLON)
            ->setOrigine(DemandeConge::ORIGINE_UI);
        $demande->setEntreprise($entreprise);
        $em->persist($demande);

        $jourFerie = (new JourFerie())->setDate($maintenant->modify('+30 days'))
            ->setLibelle('Ferie ' . $suffixe)->setExercice((int) $maintenant->format('Y'));
        $jourFerie->setEntreprise($entreprise);
        $em->persist($jourFerie);

        $parametres = (new ParametresConge())->setDelaiPreavisJours(7)->setSeuilAlerteReport('5')
            ->setRelanceApresJours(3)->setDotationAnnuelle('20');
        $parametres->setEntreprise($entreprise);
        $em->persist($parametres);

        $periode = (new PeriodeBlocage())->setLibelle('Blocage ' . $suffixe)
            ->setDateDebut($maintenant->modify('+60 days'))->setDateFin($maintenant->modify('+70 days'))
            ->setActif(true);
        $periode->setEntreprise($entreprise);
        $em->persist($periode);

        $regime = (new RegimeTravail())->setJoursOuvres([1, 2, 3, 4, 5])->setTauxOccupation('100')
            ->setDateDebut($maintenant->modify('-365 days'));
        $regime->setEntreprise($entreprise);
        $em->persist($regime);

        // LES CINQ JEUX DE ROLES. Leurs colonnes d'acces sont des tableaux NOT NULL : un
        // tableau VIDE est un jeu de roles valide -- celui qui n'accorde rien.
        foreach ([
            RolesEnAdministration::class, RolesEnFinance::class, RolesEnMarketing::class,
            RolesEnProduction::class, RolesEnSinistre::class,
        ] as $classeDeRoles) {
            $roles = new $classeDeRoles();
            $roles->setNom('Roles ' . $suffixe);
            $roles->setEntreprise($entreprise);
            $em->persist($roles);
        }
    }
}