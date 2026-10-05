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
            'article', 'revenu_pour_courtier', 'tranche', 'avenant', 'bordereau',
            'cotation', 'piste', 'condition_partage', 'autorite_fiscale', 'taxe',
            'compte_bancaire', 'chargement', 'classeur', 'client', 'groupe',
            'portefeuille', 'partenaire', 'assureur', 'risque', 'type_revenu', 'invite',
        ];
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
        return array_map('intval', $this->em()
            ->createQuery(sprintf('SELECT e.id FROM %s e WHERE e.entreprise = :ese', $classe))
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
}