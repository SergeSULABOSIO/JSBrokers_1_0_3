<?php

namespace App\Tests\Echange;

use App\Echange\Canevas\CanevasDEchange;
use App\Echange\Service\Anomalie;
use App\Echange\Service\ImportateurJsbx;
use App\Entity\EchangeImportRun;
use App\Entity\EchangeOccurrence;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * L'ÉCRITURE : ce que la confirmation fait réellement.
 *
 * ── CE QUI EST GARDÉ ICI ────────────────────────────────────────────────────────────
 * La confirmation est le SEUL geste de toute la rubrique qui écrive en base. Ces tests
 * vérifient qu'elle écrit ce que le rapport avait annoncé, qu'elle refuse ce qui n'a pas
 * été autorisé, qu'elle ne s'exécute qu'une fois, et qu'elle ne laisse derrière elle ni
 * fichier ni trace mensongère.
 *
 * ⚠ IL N'Y A PLUS DE « TOUT OU RIEN » À L'ÉCHELLE DU FICHIER, et c'est délibéré. L'import
 * avance par paliers, chacun dans sa transaction : une erreur à la deux millième ligne
 * n'annule plus les mille neuf cent quatre-vingt-dix-neuf premières. Ce qui n'est
 * acceptable que parce que la reprise est IDEMPOTENTE — redéposer le fichier reprend là
 * où il s'était arrêté sans rien dupliquer, ce que `RepriseParPaliersTest` prouve.
 *
 * Ce qui reste vrai, et qui est testé ici : un PALIER qui échoue ne conserve rien.
 */
class EcritureImportTest extends WebTestCase
{
    use ClasseurDeRepriseTrait;

    private const OWNER_EMAIL = 'phpunit-echange-ecr@test.local';
    private const ENT = 'PHPUnit Écriture SARL';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->nettoyer();
    }

    protected function tearDown(): void
    {
        $this->effacerLesClasseurs();
        $this->nettoyer();
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Ce que l'écriture produit
    // ─────────────────────────────────────────────────────────────────────────────

    /** Une ligne nouvelle crée toute sa chaîne : client, opportunité, proposition, police, échéance. */
    public function testUneLigneAjouteeCreeTouteSaChaine(): void
    {
        [$entreprise, $invite] = $this->fixture();

        $run = $this->importer($entreprise, $invite, [$this->uneEcheance()]);

        self::assertSame(EchangeImportRun::STATUT_TERMINE, $run->getStatut(), $this->motif($run));
        self::assertSame(
            ['clients' => 1, 'polices' => 1, 'propositions' => 1, 'echeances' => 1],
            $this->portefeuille($entreprise),
        );
    }

    /**
     * ⚠ UNE LIGNE QUI PORTE SON IDENTIFIANT NE TOUCHE PAS À SON ASCENDANCE.
     *
     * C'est le geste du correctif : on exporte, on corrige une valeur, on redépose. Refaire
     * toute la chaîne écrirait des modifications que personne n'a demandées, et le journal
     * annoncerait cinq écritures pour une.
     */
    public function testUneValeurCorrigeeEstEcriteSansRefaireLAscendance(): void
    {
        [$entreprise, $invite] = $this->fixture();
        $this->importer($entreprise, $invite, [$this->uneEcheance()]);

        $idTranche = (int) $this->em()->getConnection()->fetchOne(
            'SELECT id FROM tranche WHERE entreprise_id = ?',
            [$entreprise->getId()],
        );

        $run = $this->importer($entreprise, $invite, [[
            'id' => $idTranche,
            'trancheNom' => 'Prime corrigée',
            'tranchePayableAt' => '15/01/2026',
        ]]);

        self::assertSame(EchangeImportRun::STATUT_TERMINE, $run->getStatut(), $this->motif($run));
        self::assertSame(
            'Prime corrigée',
            $this->em()->getConnection()->fetchOne('SELECT nom FROM tranche WHERE id = ?', [$idTranche]),
        );
        self::assertSame(
            ['clients' => 1, 'polices' => 1, 'propositions' => 1, 'echeances' => 1],
            $this->portefeuille($entreprise),
            'Rien n\'a été recréé au passage.',
        );
    }

    /**
     * ⚠ LA COMMISSION DÉJÀ ENCAISSÉE ARRIVE EN BASE, ET LE PORTEFEUILLE LA COMPTE.
     *
     * C'est le test qui compte vraiment : les autres vérifient les OPÉRATIONS produites,
     * celui-ci vérifie le CHIFFRE que le courtier lira à l'écran. Entre les deux il y a le
     * circuit d'écriture complet — le formulaire de la note, celui de son article, celui de
     * son règlement — et deux colonnes NON NULLES qu'aucun d'eux ne déclare.
     *
     * ⚠ ET IL VÉRIFIE UN ENCAISSEMENT PARTIEL, à dessein. Le montant d'un article n'est pas
     * libre : il se calcule du revenu facturé. Ce qui est repris, c'est le RÈGLEMENT, dont
     * le calcul tire la proportion payée — la commission encaissée vaut donc exactement ce
     * qui a été versé, qu'il solde la note ou non.
     */
    public function testLaCommissionEncaisseeEstEcriteEtComptee(): void
    {
        [$entreprise, $invite] = $this->fixture();
        $this->catalogueDeRevenu($entreprise, $invite);

        $run = $this->importer($entreprise, $invite, [
            $this->uneEcheance() + [
                // ⚠ UNE COMMISSION SE CALCULE D'UN TAUX SUR UNE PRIME : sans l'un ou
                // l'autre elle vaut zéro, et il n'y aurait rien à encaisser.
                'tranchePart' => 100,
                'chargement_prime_nette' => 10000,
                'commissionRevenus' => 'Commission Ordinaire = 10%',
                // Encaissement PARTIEL : la commission vaut 1 000, il en est rentré 750.
                'ouvertureCommissionEncaissee' => 750,
                'ouvertureCommissionLe' => '20/01/2026',
            ],
        ]);

        self::assertSame(EchangeImportRun::STATUT_TERMINE, $run->getStatut(), $this->motif($run));

        $cnx = $this->em()->getConnection();
        $id = $entreprise->getId();

        self::assertSame(
            1,
            (int) $cnx->fetchOne('SELECT COUNT(*) FROM note WHERE entreprise_id = ?', [$id]),
            'Une note, et une seule : le calcul ne saurait pas répartir une note groupée.',
        );
        self::assertSame(
            750.0,
            (float) $cnx->fetchOne(
                'SELECT SUM(p.montant) FROM paiement p JOIN note n ON n.id = p.note_id WHERE n.entreprise_id = ?',
                [$id],
            ),
            'Le règlement porte le montant encaissé.',
        );

        // ⚠ L'ARTICLE DOIT PORTER LES DEUX LIENS : sans `revenu_facture_id`, le montant
        // vaut zéro et la note ne compte rien, quel que soit son règlement.
        $article = $cnx->fetchAssociative(
            'SELECT a.tranche_id, a.revenu_facture_id FROM article a
             JOIN note n ON n.id = a.note_id WHERE n.entreprise_id = ?',
            [$id],
        );
        self::assertNotFalse($article, 'L\'article doit exister.');
        self::assertNotNull($article['tranche_id']);
        self::assertNotNull($article['revenu_facture_id']);

        // LE CHIFFRE QUE L'ÉCRAN AFFICHE — celui pour lequel tout ce qui précède existe.
        // ⚠ ON REPART D'UNE LECTURE PROPRE. L'écriture a vidé l'unité de travail : une
        // entité gardée d'avant ne verrait pas les articles qu'on vient de lui rattacher.
        $this->em()->clear();
        $tranche = $this->em()->getRepository(\App\Entity\Tranche::class)
            ->findOneBy(['entreprise' => $entreprise]);
        self::assertNotNull($tranche);

        self::assertEqualsWithDelta(
            750.0,
            static::getContainer()->get(\App\Services\Canvas\Indicator\IndicatorCalculationHelper::class)
                ->getTrancheMontantCommissionEncaissee($tranche),
            0.01,
            'Le portefeuille doit afficher exactement ce qui a été encaissé.',
        );
    }

    /**
     * ⚠ ON N'ENCAISSE JAMAIS PLUS QU'ON NE DOIT — l'assertion qui manquait.
     *
     * Le test voisin vérifie la commission encaissée, et il est ARITHMÉTIQUEMENT AVEUGLE au
     * défaut : l'indicateur calcule `(payé / payable) × montantArticle`, ce qui se simplifie
     * en `payé` pour TOUTE valeur d'article non nulle. Il restait vert pendant qu'un cabinet
     * réel lisait « Montant total 5,80 · Montant payé 1 160,00 · Solde −1 154,20 ».
     *
     * Celui-ci regarde ce que le courtier voit vraiment : le montant DÛ de la note. Il ne
     * fige aucun montant absolu — les taxes de la maison changeraient le TTC —, mais il
     * tient l'invariante qui, elle, ne se négocie pas : une note ne peut pas avoir encaissé
     * plus qu'elle ne réclame.
     */
    public function testLaNoteDeRepriseNAffichePasDeSoldeNegatif(): void
    {
        [$entreprise, $invite] = $this->fixture();
        $this->catalogueDeRevenu($entreprise, $invite);

        $run = $this->importer($entreprise, $invite, [
            $this->uneEcheance() + [
                'tranchePart' => 100,
                'chargement_prime_nette' => 10000,
                'commissionRevenus' => 'Commission Ordinaire = 10%',
                'ouvertureCommissionEncaissee' => 750,
                'ouvertureCommissionLe' => '20/01/2026',
            ],
        ]);

        self::assertSame(EchangeImportRun::STATUT_TERMINE, $run->getStatut(), $this->motif($run));

        $this->em()->clear();
        $note = $this->em()->getRepository(\App\Entity\Note::class)->findOneBy(['entreprise' => $entreprise]);
        self::assertNotNull($note, 'La reprise doit avoir créé la note de commission.');

        $helper = static::getContainer()->get(\App\Services\Canvas\Indicator\IndicatorCalculationHelper::class);
        $du = $helper->getNoteMontantPayable($note);
        $paye = $helper->getNoteMontantPaye($note);

        // Le taux vaut 10 % d'une prime de 10 000 : le dû est de l'ordre du millier, jamais
        // de la dizaine. C'est ce seuil que le défaut franchissait — il rendait 10, ou 11,60
        // une fois la taxe appliquée.
        self::assertGreaterThan(
            100.0,
            $du,
            'Le montant dû se calcule SUR la prime : un taux pris pour un forfait le réduirait à quelques unités.',
        );
        self::assertGreaterThanOrEqual(
            $paye,
            $du,
            'Une note ne peut pas avoir encaissé plus qu\'elle ne réclame : le solde ne peut pas être négatif.',
        );
    }

    /**
     * ⚠ « 10 » EST « 10 % », ET LA REPRISE DOIT L'ÉCRIRE AINSI.
     *
     * C'était l'inverse : écrit sans son signe pourcent, un taux devenait un montant
     * forfaitaire, et la reprise refusait la ligne. Or l'aide de la colonne dictait
     * exactement cette saisie — « Commission = 12 » … « elle est alors EN POINTS » — et
     * c'est ce qu'un courtier écrit naturellement. Le gabarit ne transporte que des taux.
     */
    public function testUnTauxEcritSansPourcentEstUnTaux(): void
    {
        [$entreprise, $invite] = $this->fixture();
        $this->catalogueDeRevenu($entreprise, $invite);

        $run = $this->importer($entreprise, $invite, [
            $this->uneEcheance() + [
                'tranchePart' => 100,
                'chargement_prime_nette' => 10000,
                'commissionRevenus' => 'Commission Ordinaire = 10',
                'ouvertureCommissionEncaissee' => 1000,
                'ouvertureCommissionLe' => '20/01/2026',
            ],
        ]);

        self::assertSame(EchangeImportRun::STATUT_TERMINE, $run->getStatut(), $this->motif($run));

        $taux = $this->em()->getConnection()->fetchOne(
            'SELECT taux_exceptionel FROM revenu_pour_courtier WHERE entreprise_id = ?',
            [$entreprise->getId()],
        );

        self::assertEquals(10.0, (float) $taux, '« 10 » vaut dix POINTS, pas dix unités monétaires.');
    }

    /**
     * ⚠ ET LE GARDE-FOU S'EST RETOURNÉ, POUR LES CLASSEURS D'AVANT.
     *
     * Sous l'ancienne convention, « Commission Ordinaire = 5000 » désignait un forfait de
     * cinq mille. Relu sous la règle d'aujourd'hui, il vaudrait cinq mille POUR CENT — une
     * commission cinquante fois la prime, écrite sans que rien ne bronche. Aucun taux de
     * courtage n'approche cent : au-delà, ce n'est pas un taux, et le refus nomme la
     * correction.
     */
    public function testUnTauxAberrantTrahitUnForfaitEcritALAncienne(): void
    {
        [$entreprise, $invite] = $this->fixture();
        $this->catalogueDeRevenu($entreprise, $invite);

        $run = $this->importer($entreprise, $invite, [
            $this->uneEcheance() + [
                'tranchePart' => 100,
                'chargement_prime_nette' => 10000,
                'commissionRevenus' => 'Commission Ordinaire = 5000',
            ],
        ]);

        self::assertSame(EchangeImportRun::STATUT_ECHEC, $run->getStatut(), $this->motif($run));

        // ⚠ LE REPROCHE DOIT PORTER LA SOLUTION. « Valeur invalide » laisserait
        // l'utilisateur devant un refus sans savoir quoi corriger — la forme à écrire est
        // la seule chose qui lui manque.
        self::assertStringContainsString('(forfait)', $this->motif($run));
        self::assertStringContainsString('Commission Ordinaire', $this->motif($run));
    }

    /**
     * ⚠ LES ÉCHÉANCES D'UNE POLICE PARTAGENT CENT POUR CENT DE SA PRIME.
     *
     * Écrire 100 sur chacune est le geste le plus naturel du monde — chaque ligne décrit
     * « toute » son échéance —, et il rendait la prime de la police multipliée par son
     * nombre d'échéances. Chaque ligne étant plausible isolément, seul un regard sur la
     * police entière peut le voir.
     */
    public function testLesPartsDUnePoliceDoiventFaireCentPourCent(): void
    {
        [$entreprise, $invite] = $this->fixture();
        $this->catalogueDeRevenu($entreprise, $invite);

        $premiere = $this->uneEcheance() + [
            'tranchePart' => 100,
            'chargement_prime_nette' => 10000,
            'trancheNom' => 'Premier terme',
            'tranchePayableAt' => '15/01/2026',
        ];
        $seconde = $this->uneEcheance() + [
            'tranchePart' => 100,
            'chargement_prime_nette' => 10000,
            'trancheNom' => 'Second terme',
            'tranchePayableAt' => '15/07/2026',
        ];

        $run = $this->importer($entreprise, $invite, [$premiere, $seconde]);

        self::assertSame(
            EchangeImportRun::STATUT_ECHEC,
            $run->getStatut(),
            'Deux échéances à 100 % feraient une prime double : la reprise doit refuser.',
        );
        self::assertStringContainsString('100', $this->motif($run));
    }

    /**
     * ⚠ LE TABLEAU DE BORD DOIT S'OUVRIR SUR L'EXERCICE QUI PORTE LES DONNÉES.
     *
     * C'est le trou par lequel le défaut est passé : aucun test ne faisait vivre un
     * encaissement sur un exercice ANTÉRIEUR, et aucun ne reliait la reprise aux
     * indicateurs. Un cabinet qui reprenait son historique ouvrait donc son écran d'accueil
     * sur l'année en cours — vide — et lisait 0,00 partout. Ses données existaient, elles
     * étaient justes, et rien ne le lui disait : il concluait que la reprise n'avait rien
     * écrit.
     *
     * ⚠ LES DATES SE CALCULENT, ELLES NE SE FIGENT PAS. Écrire « 2025 » ferait passer ce
     * test jusqu'au 31 décembre, puis mentir pour toujours.
     */
    public function testLeTableauDeBordSOuvreSurLExerciceQuiPorteLesDonnees(): void
    {
        [$entreprise, $invite] = $this->fixture();
        $this->catalogueDeRevenu($entreprise, $invite);

        $exercicePasse = (int) date('Y') - 1;

        $run = $this->importer($entreprise, $invite, [
            $this->uneEcheance() + [
                'policeDateEffet' => '01/01/' . $exercicePasse,
                'policeEcheance' => '31/12/' . $exercicePasse,
                'tranchePayableAt' => '15/01/' . $exercicePasse,
                'tranchePart' => 100,
                'chargement_prime_nette' => 10000,
                'commissionRevenus' => 'Commission Ordinaire = 10%',
                'ouvertureCommissionEncaissee' => 750,
                'ouvertureCommissionLe' => '20/01/' . $exercicePasse,
            ],
        ]);

        self::assertSame(EchangeImportRun::STATUT_TERMINE, $run->getStatut(), $this->motif($run));

        $this->em()->clear();
        $entreprise = $this->em()->find(Entreprise::class, $entreprise->getId());
        $exercices = static::getContainer()->get(\App\Services\ExercicesDuCabinet::class);

        self::assertSame(
            $exercicePasse,
            $exercices->defaut($entreprise),
            'On ouvre sur le dernier exercice qui porte quelque chose, jamais sur l\'année de l\'horloge.',
        );

        // ⚠ ET L'ANNÉE COURANTE RESTE OFFERTE, même vide : c'est l'exercice qu'on ouvre, et
        // s'en trouver privé enfermerait l'utilisateur dans son passé.
        $offerts = $exercices->disponibles($entreprise);
        self::assertContains($exercicePasse, $offerts);
        self::assertContains((int) date('Y'), $offerts);

        // Une année que ce cabinet n'a jamais connue — un lien partagé, une URL bricolée —
        // ne doit pas interroger la base sur une plage qui ne rendra rien.
        self::assertSame(
            $exercicePasse,
            $exercices->retenir($entreprise, $exercicePasse - 40),
            'Un exercice inconnu retombe sur le défaut.',
        );
    }

    /**
     * ⚠ LA FRANCHISE EXONÈRE VRAIMENT — ET ELLE NE FUIT PAS.
     *
     * C'est le test qui tient la promesse commerciale faite sur le site public : les
     * premières lignes de reprise sont offertes, les suivantes paient le métrage
     * d'écriture ordinaire. Les deux moitiés comptent autant l'une que l'autre : une
     * exonération qui déborderait rendrait la reprise gratuite pour toujours, et une
     * franchise qui ne s'appliquerait pas ferait payer ce qu'on a annoncé offert.
     *
     * ⚠ ON ABAISSE LE SEUIL PLUTÔT QUE DE FABRIQUER MILLE LIGNES. Le paramètre est en
     * console précisément pour cela ; un fichier de mille lignes ferait un test de dix
     * minutes qui ne vérifierait rien de plus.
     */
    public function testLesLignesOffertesNeDebitentRienEtLesSuivantesPaient(): void
    {
        [$entreprise, $invite] = $this->fixture();
        $this->catalogueDeRevenu($entreprise, $invite);

        $idProprietaire = (int) $entreprise->getUtilisateur()->getId();
        $parametres = static::getContainer()->get(\App\Token\ParametresTokenService::class);

        // ⚠ LE SOLDE SE RELIT, IL NE SE RAFRAÎCHIT PAS. L'écriture d'un import vide l'unité
        // de travail : l'objet gardé d'avant n'y est plus géré, et `refresh()` lèverait
        // « Entity is not managed » — sur le compte du propriétaire, c'est-à-dire au pire
        // endroit possible.
        $solde = fn (): int => (int) $this->em()->find(Utilisateur::class, $idProprietaire)->getPaidTokens();

        // ── Première reprise : UNE ligne offerte ────────────────────────────────────
        $this->reglerLaFranchise(1);

        $avant = $solde();
        $run = $this->importer($entreprise, $invite, [$this->uneEcheance()]);
        self::assertSame(EchangeImportRun::STATUT_TERMINE, $run->getStatut(), $this->motif($run));

        self::assertSame(
            $avant,
            $solde(),
            'Une ligne couverte par la franchise ne doit RIEN débiter, quel que soit le nombre '
            . 'd\'enregistrements qu\'elle fait naître.',
        );
        self::assertSame(1, $run->getLignesFranchisees(), 'La ligne offerte est décomptée sur le run.');

        // ── Seconde reprise : la franchise est épuisée ──────────────────────────────
        $avant = $solde();
        $run = $this->importer($entreprise, $invite, [
            $this->uneEcheance() + ['policeReference' => 'POL/2026/002'],
        ]);
        self::assertSame(EchangeImportRun::STATUT_TERMINE, $run->getStatut(), $this->motif($run));

        self::assertLessThan(
            $avant,
            $solde(),
            'Passé la franchise, chaque ligne paie le métrage d\'écriture de ses enregistrements.',
        );
        self::assertSame(0, $run->getLignesFranchisees(), 'Plus rien n\'est offert : le run ne décompte rien.');

        // ⚠ ON REND LE BARÈME COMME ON L'A TROUVÉ. Ce réglage est un SINGLETON de la
        // plateforme, partagé par toute la suite : le laisser à une ligne offerte ferait
        // échouer, bien plus tard et sans rapport apparent, n'importe quel test qui
        // suppose la franchise ordinaire.
        $this->reglerLaFranchise(null);
        $parametres->refresh();
    }

    /**
     * ⚠ UN BOUTON DÉSACTIVÉ N'EST PAS UNE GARDE.
     *
     * L'écran ferme la confirmation quand le solde ne suit pas, mais la route reste
     * appelable directement — et le solde a pu fondre entre l'annonce et l'accord, dans un
     * autre onglet. Le verrou réel vit au serveur, contre le coût FIGÉ dans le rapport, et
     * il refuse AVANT d'avoir écrit la moindre ligne.
     */
    public function testLaConfirmationEstRefuseeSiLeSoldeNeCouvrePas(): void
    {
        [$entreprise, $invite] = $this->fixture();
        $this->catalogueDeRevenu($entreprise, $invite);

        // Plus une seule ligne offerte : tout ce fichier est facturable.
        $this->reglerLaFranchise(0);

        $run = $this->importateur()->controler(
            $this->classeurDeReprise($entreprise, [$this->uneEcheance()]),
            'reprise.xlsx',
            $entreprise,
            $invite,
        );
        self::assertSame(EchangeImportRun::STATUT_EN_ATTENTE_CONFIRMATION, $run->getStatut(), $this->motif($run));
        self::assertGreaterThan(
            0,
            (int) ($run->getRapport()['tokens_estimes'] ?? 0),
            'Le contrôle doit avoir chiffré la reprise : c\'est ce chiffre que la garde compare.',
        );

        // On vide le compte du propriétaire APRÈS l'annonce — exactement le cas que la
        // garde existe pour attraper.
        $proprietaire = $this->em()->find(Utilisateur::class, (int) $entreprise->getUtilisateur()->getId());
        $proprietaire->setPaidTokens(0);
        $proprietaire->setFreeTokens(0);
        // ⚠ ET ON FIGE LA FENÊTRE GRATUITE. Sans cela, `getBalance()` la trouve périmée,
        // la renouvelle, et rend mille tokens : le compte qu'on croyait vide ne l'est pas,
        // et le test passerait sans avoir rien éprouvé.
        $proprietaire->setFreeWindowStartedAt(new \DateTimeImmutable());
        $this->em()->flush();

        $avant = $this->portefeuille($entreprise);

        try {
            $this->importateur()->demarrerLEcriture($run, $proprietaire);
            self::fail('La confirmation devait être refusée : le solde ne couvre pas la reprise.');
        } catch (\App\Token\InsufficientTokensException $e) {
            self::assertGreaterThan(0, $e->required);
            self::assertSame(0, $e->available);
        }

        self::assertSame(
            $avant,
            $this->portefeuille($entreprise),
            'Un refus de budget n\'écrit RIEN : il tombe avant le premier palier.',
        );

        $this->reglerLaFranchise(null);
        static::getContainer()->get(\App\Token\ParametresTokenService::class)->refresh();
    }

    /**
     * ⚠ SUPPRIMER UNE PISTE REPRISE EMPORTE SA FACTURE.
     *
     * Depuis que la reprise enregistre la commission déjà encaissée, elle crée une note et
     * son article, lequel référence le revenu du courtier. La base refusait donc la
     * suppression, et l'écran renvoyait l'utilisateur à un travail manuel qu'il ne pouvait
     * pas mener à bien : effacer à la main la note, ses lignes, son règlement, ses
     * chargements, ses commissions, ses échéances, dans le bon ordre. Personne ne le fait.
     *
     * ⚠ LA NOTE NE PART QUE PARCE QU'ELLE SE VIDE ENTIÈREMENT. Une facture à cheval sur
     * deux affaires est CONSERVÉE, amputée de ses seules lignes concernées
     * (cf. SuppressionEnChaineTest).
     */
    public function testSupprimerUnePisteRepriseEmporteToutSonDossier(): void
    {
        [$entreprise, $invite] = $this->fixture();
        $this->catalogueDeRevenu($entreprise, $invite);

        $run = $this->importer($entreprise, $invite, [
            $this->uneEcheance() + [
                'tranchePart' => 100,
                'chargement_prime_nette' => 10000,
                'commissionRevenus' => 'Commission Ordinaire = 10%',
                'ouvertureCommissionEncaissee' => 750,
                'ouvertureCommissionLe' => '20/01/2026',
            ],
        ]);
        self::assertSame(EchangeImportRun::STATUT_TERMINE, $run->getStatut(), $this->motif($run));

        $idPiste = (int) $this->em()->getConnection()->fetchOne(
            'SELECT id FROM piste WHERE entreprise_id = ?',
            [$entreprise->getId()],
        );
        self::assertGreaterThan(0, $idPiste, 'La reprise doit avoir créé une opportunité.');
        self::assertSame(1, $this->compter('note', $entreprise), 'La reprise a bien créé une facture.');

        $this->client->request('DELETE', '/admin/piste/api/delete/' . $idPiste);

        self::assertSame(
            200,
            $this->client->getResponse()->getStatusCode(),
            'La suppression emporte toute la chaîne, sans violer aucune contrainte.',
        );

        // ⚠ ON DÉCODE AVANT DE COMPARER : `json_encode` échappe l'apostrophe, et une
        // recherche sur la charge brute ne trouverait jamais un texte français.
        $message = (string) (json_decode(
            (string) $this->client->getResponse()->getContent(),
            true,
        )['message'] ?? '');

        self::assertStringContainsString('lié', $message, 'Le compte rendu dit ce qui est parti avec.');
        self::assertStringNotContainsString(
            'Erreur lors de la suppression',
            $message,
            'Le message générique ne dit ni pourquoi ni quoi faire.',
        );

        // ⚠ TOUTE LA CHAÎNE EST PARTIE, facture et règlement compris : c'est la demande
        // d'origine — « il faut détruire la note aussi ».
        foreach (['piste', 'cotation', 'avenant', 'tranche', 'revenu_pour_courtier', 'article', 'note'] as $table) {
            self::assertSame(0, $this->compter($table, $entreprise), sprintf('La table %s est vide.', $table));
        }
    }

    /** Nombre de lignes d'une table pour ce cabinet. */
    private function compter(string $table, \App\Entity\Entreprise $entreprise): int
    {
        return (int) $this->em()->getConnection()->fetchOne(
            sprintf('SELECT COUNT(*) FROM `%s` WHERE entreprise_id = ?', $table),
            [$entreprise->getId()],
        );
    }

    /** Pose le seuil de franchise en base (null = repli sur le barème), et vide le cache. */
    private function reglerLaFranchise(?int $lignes): void
    {
        $depot = static::getContainer()->get(\App\Repository\PlateformeParametresRepository::class);
        $params = $depot->getSingleton();
        $params->setEchangeFranchiseLignes($lignes);
        $this->em()->flush();

        // ⚠ SANS CE `refresh()`, LE BARÈME RESTERAIT CELUI D'AVANT. `ParametresTokenService`
        // cache ses valeurs pour la requête : le test tournerait sur mille lignes offertes
        // et passerait sans rien avoir vérifié.
        static::getContainer()->get(\App\Token\ParametresTokenService::class)->refresh();
    }

    /**
     * ⚠ UN REDÉPÔT NE DOUBLE PAS L'ENCAISSEMENT.
     *
     * Un solde d'ouverture ne se relit pas : l'échéance étant retrouvée au second dépôt,
     * aucune écriture d'ouverture n'est rejouée. Sans cette garde, chaque aller-retour du
     * même fichier ajouterait une note — et la commission encaissée doublerait sans que
     * rien ne le signale.
     */
    public function testUnRedepotNeDoublePasLaCommissionEncaissee(): void
    {
        [$entreprise, $invite] = $this->fixture();
        $this->catalogueDeRevenu($entreprise, $invite);

        $lignes = [
            $this->uneEcheance() + [
                'tranchePart' => 100,
                'chargement_prime_nette' => 10000,
                'commissionRevenus' => 'Commission Ordinaire = 10%',
                'ouvertureCommissionEncaissee' => 750,
            ],
        ];

        $this->importer($entreprise, $invite, $lignes);
        $run = $this->importer($entreprise, $invite, $lignes);

        self::assertSame(EchangeImportRun::STATUT_TERMINE, $run->getStatut(), $this->motif($run));
        self::assertSame(
            1,
            (int) $this->em()->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM note WHERE entreprise_id = ?',
                [$entreprise->getId()],
            ),
            'La note d\'ouverture ne se rejoue pas.',
        );
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Ce que l'écriture refuse
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * ⚠ UNE SUPPRESSION NON AUTORISÉE AU DÉPÔT BLOQUE TOUT L'IMPORT.
     *
     * Passée sous silence, l'utilisateur croirait ses lignes supprimées. La case est
     * décochée par défaut, et le reste : une colonne « Action » mal recopiée ne doit pas
     * pouvoir vider un portefeuille.
     */
    public function testUneSuppressionNonAutoriseeBloqueToutLImport(): void
    {
        [$entreprise, $invite] = $this->fixture();
        $this->importer($entreprise, $invite, [$this->uneEcheance()]);

        $idTranche = (int) $this->em()->getConnection()->fetchOne(
            'SELECT id FROM tranche WHERE entreprise_id = ?',
            [$entreprise->getId()],
        );

        $run = $this->importer($entreprise, $invite, [[
            'id' => $idTranche,
            CanevasDEchange::COL_ACTION => CanevasDEchange::ACTION_SUPPRIMER,
        ]]);

        self::assertSame(EchangeImportRun::STATUT_ECHEC, $run->getStatut());
        self::assertNotNull($this->anomalieDeCode($run, Anomalie::SUPPRESSION_REFUSEE), $this->motif($run));
        self::assertSame(1, $this->portefeuille($entreprise)['echeances'], 'L\'échéance est toujours là.');
    }

    /** Autorisée explicitement, la même suppression s'exécute. */
    public function testUneSuppressionAutoriseeEstExecutee(): void
    {
        [$entreprise, $invite] = $this->fixture();
        $this->importer($entreprise, $invite, [$this->uneEcheance()]);

        $idTranche = (int) $this->em()->getConnection()->fetchOne(
            'SELECT id FROM tranche WHERE entreprise_id = ?',
            [$entreprise->getId()],
        );

        $run = $this->importer($entreprise, $invite, [[
            'id' => $idTranche,
            CanevasDEchange::COL_ACTION => CanevasDEchange::ACTION_SUPPRIMER,
        ]], suppressions: true);

        self::assertSame(EchangeImportRun::STATUT_TERMINE, $run->getStatut(), $this->motif($run));
        self::assertSame(0, $this->portefeuille($entreprise)['echeances']);
        self::assertSame(1, $this->portefeuille($entreprise)['polices'], 'La police survit à son échéance.');
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Ce qui ne s'exécute pas deux fois
    // ─────────────────────────────────────────────────────────────────────────────

    /** Une confirmation ne vaut qu'une fois : le second appel est refusé, pas rejoué. */
    public function testUneConfirmationNeVautQuUneFois(): void
    {
        [$entreprise, $invite] = $this->fixture();
        $run = $this->importer($entreprise, $invite, [$this->uneEcheance()]);

        self::assertSame(EchangeImportRun::STATUT_TERMINE, $run->getStatut(), $this->motif($run));

        $this->expectException(\App\Echange\Service\ImportImpossibleException::class);
        $this->importateur()->executer($run, $invite->getUtilisateur());
    }

    /** Un contrôle annulé ne s'exécute plus, même si l'on garde son identifiant sous la main. */
    public function testUnControleAnnuleNeSExecutePlus(): void
    {
        [$entreprise, $invite] = $this->fixture();

        $run = $this->importateur()->controler(
            $this->classeurDeReprise($entreprise, [$this->uneEcheance()]),
            'annule.xlsx',
            $entreprise,
            $invite,
        );
        $this->importateur()->annuler($run);

        $this->expectException(\App\Echange\Service\ImportImpossibleException::class);
        $this->importateur()->executer($run, $invite->getUtilisateur());
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Ce que l'écriture laisse derrière elle
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * ⚠ LE DÉPÔT EST EFFACÉ UNE FOIS LES DONNÉES EN BASE. Un classeur de reprise porte le
     * nom, l'adresse et les primes de tous les clients d'un cabinet : il n'a rien à faire
     * sur le disque une fois qu'il a servi.
     */
    public function testLeDepotEstEffaceApresUnImportAbouti(): void
    {
        [$entreprise, $invite] = $this->fixture();

        $chemin = $this->classeurDeReprise($entreprise, [$this->uneEcheance()]);
        $run = $this->importateur()->controler($chemin, 'depot.xlsx', $entreprise, $invite);
        $run = $this->importateur()->executer($run, $invite->getUtilisateur());

        self::assertSame(EchangeImportRun::STATUT_TERMINE, $run->getStatut(), $this->motif($run));
        self::assertNull($run->getCheminFichier());
        self::assertFileDoesNotExist($chemin);
    }

    /** Un import abouti est tracé — sans forfait : chaque ligne a déjà payé son métrage. */
    public function testUnImportAboutiEstTraceSansForfait(): void
    {
        [$entreprise, $invite] = $this->fixture();
        $this->importer($entreprise, $invite, [$this->uneEcheance()]);

        $occurrence = $this->em()->getConnection()->fetchAssociative(
            'SELECT type, tokens_debites, nb_lignes FROM echange_occurrence WHERE entreprise_id = ?',
            [$entreprise->getId()],
        );

        self::assertNotFalse($occurrence, 'L\'import doit laisser une trace.');
        self::assertSame(EchangeOccurrence::TYPE_IMPORT, $occurrence['type']);
        self::assertSame(0, (int) $occurrence['tokens_debites'], 'L\'importation n\'a jamais de forfait.');
        self::assertSame(1, (int) $occurrence['nb_lignes']);
    }

    /** Un import en échec ne compte aucune occurrence : il n'a rien produit. */
    public function testUnImportEnEchecNeCompteAucuneOccurrence(): void
    {
        [$entreprise, $invite] = $this->fixture();

        // Une ligne sans référence de police ne peut pas être rattachée : le contrôle
        // échoue, et la confirmation ne s'ouvre jamais.
        $run = $this->importateur()->controler(
            $this->classeurDeReprise($entreprise, [['assure' => 'KIN AVIA', 'trancheNom' => 'Prime']]),
            'echec.xlsx',
            $entreprise,
            $invite,
        );

        self::assertSame(EchangeImportRun::STATUT_ECHEC, $run->getStatut());
        self::assertSame(
            0,
            (int) $this->em()->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM echange_occurrence WHERE entreprise_id = ?',
                [$entreprise->getId()],
            ),
        );
    }

    /**
     * ⚠ UN TAUX QUI NE PEUT PAS PRODUIRE CE QUI A ÉTÉ ENCAISSÉ EST UN POINT DE BLOCAGE.
     *
     * Toutes les autres déductions de la reprise ARRANGENT la ligne : un nom inconnu se
     * rattache, une colonne vide prend un défaut. Celle-ci ne s\'arrange pas. Le taux dit
     * une chose, l\'encaissement en dit une autre, et RIEN dans le fichier ne départage.
     *
     * Deviner coûterait cher des deux côtés : retenir le taux ferait une note
     * éternellement en solde négatif, retenir l\'encaissement inventerait un taux que le
     * cabinet n\'a jamais pratiqué. On montre donc les deux chiffres et on rend la main —
     * l\'erreur vient souvent des données d\'origine, et c\'est là qu\'il faut la corriger.
     */
    public function testUnEncaissementSuperieurAuTauxEstUnPointDeBlocage(): void
    {
        [$entreprise, $invite] = $this->fixture();
        $this->catalogueDeRevenu($entreprise, $invite);

        $run = $this->importer($entreprise, $invite, [
            $this->uneEcheance() + [
                'tranchePart' => 100,
                'chargement_prime_nette' => 10000,
                // 1 % de 10 000 = 100 HT, soit 116 TTC au plus. On en annonce 5 000.
                'commissionRevenus' => 'Commission Ordinaire = 1',
                'ouvertureCommissionEncaissee' => 5000,
            ],
        ]);

        self::assertSame(EchangeImportRun::STATUT_ECHEC, $run->getStatut(), $this->motif($run));

        // ⚠ LE REPROCHE MONTRE LES DEUX CHIFFRES. Sans eux, l\'utilisateur sait qu\'il y a
        // un problème sans savoir de quel ordre de grandeur il se trompe.
        self::assertStringContainsString('5 000', $this->motif($run));
        self::assertStringContainsString('Commission · Revenus', $this->motif($run));
    }

    /**
     * ⚠ ET LA TAXE DE L\'ASSUREUR FAIT PARTIE DU PLAFOND.
     *
     * Ce qui arrive sur le compte du cabinet est TTC : l\'assureur précompte sa taxe — seize
     * pour cent au barème courant — et la verse avec la commission. Comparer un
     * encaissement TTC à un hors-taxes ferait refuser toutes les lignes justes dont le
     * courtier a correctement noté ce qu\'il a reçu.
     */
    public function testLaTaxeDeLAssureurEntreDansLePlafond(): void
    {
        [$entreprise, $invite] = $this->fixture();
        $this->catalogueDeRevenu($entreprise, $invite);
        $this->tvaAssureur($entreprise, $invite, 16.0);

        $run = $this->importer($entreprise, $invite, [
            $this->uneEcheance() + [
                'tranchePart' => 100,
                'chargement_prime_nette' => 10000,
                // 5 % de 10 000 = 500 HT ; avec 16 % de taxe, 580 TTC.
                'commissionRevenus' => 'Commission Ordinaire = 5',
                // 540 dépasse le HT, mais pas le TTC : la ligne est juste.
                'ouvertureCommissionEncaissee' => 540,
            ],
        ]);

        self::assertSame(
            EchangeImportRun::STATUT_TERMINE,
            $run->getStatut(),
            'Un encaissement TTC ne doit pas se comparer à un hors-taxes : ' . $this->motif($run),
        );
    }

    /**
     * ⚠ UNE AFFAIRE EXONÉRÉE N\'A PAS DE TAXE À ENCAISSER, DONC PAS DE MARGE.
     *
     * Un client exonéré, ou un risque non imposable : l\'assureur ne précompte rien, et ce
     * qui est encaissé EST le hors-taxes. Accorder quand même les seize pour cent
     * laisserait passer un taux faux d\'un sixième sur précisément les affaires où la marge
     * est nulle.
     */
    public function testUneAffaireExonereeNAccordeAucuneMargeDeTaxe(): void
    {
        [$entreprise, $invite] = $this->fixture();
        $this->catalogueDeRevenu($entreprise, $invite);
        $this->tvaAssureur($entreprise, $invite, 16.0);

        // Le client existe DÉJÀ, et il est exonéré : la reprise s\'y rattache et lit son
        // réglage. Un client que la passe crée, lui, naîtrait non exonéré.
        $this->clientExonere($entreprise, $invite, 'KIN AVIA');

        $run = $this->importer($entreprise, $invite, [
            $this->uneEcheance() + [
                'tranchePart' => 100,
                'chargement_prime_nette' => 10000,
                'commissionRevenus' => 'Commission Ordinaire = 5',
                // 540 passait grâce aux 16 % de taxe ; exonéré, le plafond est 500.
                'ouvertureCommissionEncaissee' => 540,
            ],
        ]);

        self::assertSame(EchangeImportRun::STATUT_ECHEC, $run->getStatut(), $this->motif($run));
        self::assertStringContainsString('540', $this->motif($run));
    }

    /**
     * ⚠ LA LIGNE 586 DU CABINET RÉEL, DE BOUT EN BOUT.
     *
     * Elle portait TROIS reproches à la fois, et un seul comptait :
     *
     *   1. avertissement — « Commission » lu comme « Commission Ordinaire » ;
     *   2. ERREUR — « Commission: 17,50 » lu comme un MONTANT FIXE de 17,50, contredit
     *      par 518,40 déjà encaissés ;
     *   3. avertissement — la commission de 518,40 n'a pas pu être enregistrée.
     *
     * Le deuxième était faux : 17,50 est un TAUX. Et le troisieme n'en était que la
     * conséquence — le terme ayant été refusé, aucun revenu n'était posé, donc
     * l'encaissement n'avait rien à facturer. Ce test tient les trois ensemble : c'est
     * leur enchaînement, et non chacun pris à part, qui rendait la reprise impossible.
     */
    public function testLaLigneQuiPortaitTroisReprochesNEnPortePlusQuUn(): void
    {
        [$entreprise, $invite] = $this->fixture();
        $this->catalogueDeRevenu($entreprise, $invite);

        $run = $this->importer($entreprise, $invite, [
            $this->uneEcheance() + [
                'tranchePart' => 100,
                'chargement_prime_nette' => 10000,
                // Le nom que le courtier écrit, et le taux tel qu'il le tape : sans signe
                // pourcent, avec la virgule décimale française.
                'commissionRevenus' => 'Commission = 17,50',
                'ouvertureCommissionEncaissee' => 518.40,
                'ouvertureCommissionLe' => '20/01/2026',
            ],
        ]);

        self::assertSame(EchangeImportRun::STATUT_TERMINE, $run->getStatut(), $this->motif($run));

        $cnx = $this->em()->getConnection();
        $id = $entreprise->getId();

        // ⚠ LE NOM DU CLASSEUR EST GARDÉ, LE TYPE EST DÉDUIT. Le cabinet n'a pas de
        // revenu nommé « Commission » ; il a « Commission Ordinaire ». Renommer le
        // revenu ferait perdre le libellé du courtier, et pour rien : le modèle sépare le
        // nom libre du type qui porte la configuration.
        $revenu = $cnx->fetchAssociative(
            'SELECT r.nom, r.taux_exceptionel, r.montant_flat_exceptionel, t.nom AS type
             FROM revenu_pour_courtier r JOIN type_revenu t ON t.id = r.type_revenu_id
             WHERE r.entreprise_id = ?',
            [$id],
        );

        self::assertNotFalse($revenu, 'Un revenu doit avoir été créé : ' . $this->motif($run));
        self::assertSame('Commission', $revenu['nom']);
        self::assertSame('Commission Ordinaire', $revenu['type']);
        self::assertEquals(17.5, (float) $revenu['taux_exceptionel'], '17,50 est un TAUX.');
        self::assertNull($revenu['montant_flat_exceptionel'], 'Et surtout pas un forfait.');

        // ⚠ ET CE TAUX COMPTE VRAIMENT. C'est tout l'enjeu : écrit mais ignoré au
        // calcul, il donnerait une note à zéro soldée par un règlement positif — le solde
        // négatif de départ, simplement deplace d'un cran.
        $this->em()->clear();
        $note = $this->em()->getRepository(\App\Entity\Note::class)->findOneBy(['entreprise' => $entreprise]);
        self::assertNotNull($note, 'La commission encaissée doit être enregistrée.');

        $helper = static::getContainer()->get(\App\Services\Canvas\Indicator\IndicatorCalculationHelper::class);
        $du = $helper->getNoteMontantPayable($note);

        self::assertGreaterThan(
            500.0,
            $du,
            '17,5 % de 10 000 vaut 1 750 : un dû de quelques unités trahirait un taux lu comme un forfait.',
        );
        self::assertGreaterThanOrEqual(
            $helper->getNoteMontantPaye($note),
            $du,
            'Une note ne peut pas avoir encaissé plus qu\'elle ne réclame : pas de solde négatif.',
        );

        // ⚠ IL RESTE UN SEUL REPROCHE, ET C'EST UN AVERTISSEMENT : le rattachement au
        // type est une déduction, pas une certitude, et l'utilisateur doit pouvoir la
        // vérifier. Les deux autres ont disparu.
        self::assertStringContainsString('Commission Ordinaire', $this->motif($run));
        self::assertStringNotContainsString('MONTANT FIXE', $this->motif($run));
        self::assertStringNotContainsString('n\'a pas pu être', $this->motif($run));
    }

    /**
     * ⚠ UN CLIENT SANS PORTEFEUILLE EST REPRIS QUAND MÊME.
     *
     * Le circuit d'écriture réclamait le portefeuille de destination dès que le déposant en
     * gérait plusieurs — juste dans une conversation, où l'assistant peut poser la
     * question ; mur absolu sur un fichier, où chaque ligne à colonne vide se voyait
     * opposer « Remplissez la colonne "Portefeuille" ». Un cabinet qui ne range pas encore
     * ses clients ne pouvait rien reprendre du tout.
     */
    public function testUnClientSansPortefeuilleEstReprisQuandMeme(): void
    {
        [$entreprise, $proprietaire] = $this->fixture();
        $this->deuxPortefeuillesGeres($entreprise, $proprietaire);

        $run = $this->importer($entreprise, $proprietaire, [$this->uneEcheance()]);

        self::assertSame(EchangeImportRun::STATUT_TERMINE, $run->getStatut(), $this->motif($run));

        $cnx = $this->em()->getConnection();
        $portefeuilleDuClient = $cnx->fetchOne(
            'SELECT portefeuille_id FROM client WHERE entreprise_id = ? AND nom = ?',
            [$entreprise->getId(), 'KIN AVIA'],
        );

        self::assertNotFalse($portefeuilleDuClient, 'Le client doit être repris : ' . $this->motif($run));
        self::assertNull($portefeuilleDuClient, 'Colonne vide = aucun portefeuille, et non un refus.');

        // ⚠ ET ON LE DIT, une fois : ces clients n'apparaîtront pas dans « Mon
        // portefeuille » tant qu'on ne les y aura pas rangés. Se taire laisserait croire à
        // une reprise incomplète.
        self::assertStringContainsString('sans portefeuille', $this->motif($run));
    }

    /**
     * ⚠ ET LE DÉPOSANT MONO-PORTEFEUILLE N'A RIEN PERDU. Quand il n'en gère qu'un, la
     * colonne vide n'est pas une question : il n'y a qu'une réponse possible, et le client
     * y est rangé comme avant. C'est la moitié de la règle qu'il ne fallait PAS toucher.
     */
    public function testUnClientRejointLUniquePortefeuilleDuDeposant(): void
    {
        [$entreprise, $proprietaire] = $this->fixture();
        $unique = $this->portefeuilleGere($entreprise, $proprietaire, 'Grands comptes');

        $run = $this->importer($entreprise, $proprietaire, [$this->uneEcheance()]);
        self::assertSame(EchangeImportRun::STATUT_TERMINE, $run->getStatut(), $this->motif($run));

        $portefeuilleDuClient = $this->em()->getConnection()->fetchOne(
            'SELECT portefeuille_id FROM client WHERE entreprise_id = ? AND nom = ?',
            [$entreprise->getId(), 'KIN AVIA'],
        );

        self::assertSame($unique, (int) $portefeuilleDuClient);
    }

    /**
     * ⚠ LA COMMISSION ORDINAIRE MANQUANTE EST INSTALLÉE UNE SEULE FOIS.
     *
     * C'est la faute la plus coûteuse de toute la reprise, et elle ne casse rien : elle
     * DUPLIQUE. Un type par ligne, et le catalogue du cabinet devient illisible — six
     * « Commission Ordinaire » dont personne ne sait laquelle ses polices emploient.
     * La convergence par repère est ce qui l'empêche ; ce test la tient.
     */
    public function testLaCommissionOrdinaireManquanteNEstInstalleeQuUneFois(): void
    {
        [$entreprise, $proprietaire] = $this->fixture();

        // Le cabinet n'a NI type de revenu NI prime nette : le filet doit poser les deux.
        //
        // ⚠ DEUX POLICES DISTINCTES, ET LES LIGNES SONT ÉCRITES EN ENTIER. `uneEcheance()`
        // AJOUTE des clés, elle n'en remplace aucune : une surcharge de `policeReference` y
        // serait silencieusement ignorée, et les deux lignes deviendraient deux échéances
        // d'une même police — ce qui exige des parts, et fait échouer sur autre chose.
        $run = $this->importer($entreprise, $proprietaire, [
            $this->uneEcheance(['commissionRevenus' => 'Commission']),
            [
                'policeReference' => 'POL/2026/002',
                'policeDateEffet' => '01/02/2026',
                'policeEcheance' => '31/01/2027',
                'trancheNom' => 'Prime unique',
                'tranchePayableAt' => '15/02/2026',
                'assure' => 'CONGO AIRWAYS',
                'risque' => 'RC Aviation',
                'assureur' => 'SFA CONGO',
                'commissionRevenus' => 'Commissions',
            ],
        ]);

        self::assertSame(EchangeImportRun::STATUT_TERMINE, $run->getStatut(), $this->motif($run));

        self::assertSame(1, $this->compter('type_revenu', $entreprise), 'UN type, pas un par ligne.');
        self::assertSame(1, $this->compter('chargement', $entreprise), 'UNE prime nette, pas une par ligne.');

        $type = $this->em()->getConnection()->fetchAssociative(
            'SELECT nom, redevable, appliquer_pourcentage_du_risque FROM type_revenu WHERE entreprise_id = ?',
            [$entreprise->getId()],
        );

        self::assertSame('Commission Ordinaire', $type['nom']);
        self::assertSame(\App\Entity\TypeRevenu::REDEVABLE_ASSUREUR, (int) $type['redevable']);
        self::assertSame(1, (int) $type['appliquer_pourcentage_du_risque'], 'Le taux vient du risque.');
    }

    /**
     * ⚠ UNE ÉCHÉANCE AJOUTÉE PLUS TARD ENCAISSE SA COMMISSION.
     *
     * Le registre de la reprise ne connaît que la passe en cours : une échéance neuve sous
     * une police reprise au dépôt précédent n'y trouvait aucun revenu, et l'encaissement
     * était refusé — « cette ligne ne dit pas de quelle commission il s'agit » — alors que
     * la proposition en portait un depuis le premier dépôt. C'est le cas du cabinet qui
     * reprend en plusieurs fois, c'est-à-dire de tous.
     */
    public function testUneEcheanceAjouteeApresCoupEncaisseSurLeRevenuDejaEnBase(): void
    {
        [$entreprise, $proprietaire] = $this->fixture();
        $this->catalogueDeRevenu($entreprise, $proprietaire);

        $premier = $this->importer($entreprise, $proprietaire, [
            $this->uneEcheance(['commissionRevenus' => 'Commission Ordinaire']),
        ]);
        self::assertSame(EchangeImportRun::STATUT_TERMINE, $premier->getStatut(), $this->motif($premier));

        $notesAvant = $this->compter('note', $entreprise);

        // Même police, deuxième échéance — et la colonne des revenus reste vide, puisque
        // la proposition existe déjà.
        //
        // ⚠ LE NOM ET LA DATE DOIVENT DIFFÉRER, sans quoi l'échéance est RETROUVÉE en
        // base et son solde d'ouverture n'est pas relu : c'est la règle qui empêche un
        // redépôt de doubler les encaissements, et elle masquerait ce que ce test observe.
        $second = $this->importer($entreprise, $proprietaire, [
            [
                'policeReference' => 'POL/2026/001',
                'policeDateEffet' => '01/01/2026',
                'policeEcheance' => '31/12/2026',
                'trancheNom' => 'Deuxième tranche',
                'tranchePayableAt' => '15/07/2026',
                'assure' => 'KIN AVIA',
                'risque' => 'RC Aviation',
                'assureur' => 'SFA CONGO',
                'ouvertureCommissionEncaissee' => 518.40,
            ],
        ]);
        self::assertSame(EchangeImportRun::STATUT_TERMINE, $second->getStatut(), $this->motif($second));

        self::assertSame($notesAvant + 1, $this->compter('note', $entreprise), $this->motif($second));

        // ⚠ ET L'ARTICLE DÉSIGNE BIEN UN REVENU : sans lui la note vaudrait zéro et ne
        // compterait dans aucun total — une coquille que l'écran afficherait sans jamais
        // l'additionner.
        $sansRevenu = (int) $this->em()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM article WHERE entreprise_id = ? AND revenu_facture_id IS NULL',
            [$entreprise->getId()],
        );
        self::assertSame(0, $sansRevenu, 'Un article sans revenu facturé vaut zéro.');
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Outillage
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * Dépose, contrôle et confirme — le parcours complet, en une ligne de test.
     *
     * ⚠ ON RECHARGE LE CABINET, comme le ferait une seconde requête. L'écriture vide
     * l'unité de travail pour repartir propre : les objets d'un import précédent sont
     * détachés, et les réutiliser tels quels ferait croire à Doctrine qu'on lui présente
     * un cabinet tout neuf.
     */
    private function importer(Entreprise $entreprise, Invite $invite, array $lignes, bool $suppressions = false): EchangeImportRun
    {
        $entreprise = $this->em()->find(Entreprise::class, $entreprise->getId());
        $invite = $this->em()->find(Invite::class, $invite->getId());

        $run = $this->importateur()->controler(
            $this->classeurDeReprise($entreprise, $lignes),
            'reprise.xlsx',
            $entreprise,
            $invite,
            $suppressions,
        );

        if ($run->getStatut() !== EchangeImportRun::STATUT_EN_ATTENTE_CONFIRMATION) {
            return $run;
        }

        return $this->importateur()->executer($run, $invite->getUtilisateur());
    }

    /**
     * Le type de revenu que la ligne nomme.
     *
     * ⚠ ON NE CRÉE PAS UN TYPE QUELCONQUE À LA VOLÉE depuis un classeur : il porte un
     * taux et un redevable qu'un simple nom ne suffit pas à définir. Le cabinet doit donc
     * l'avoir — à la seule exception de la commission ordinaire, dont on sait exactement
     * ce qu'elle est ({@see \App\Echange\Reprise\CommissionOrdinaire}).
     */
    private function catalogueDeRevenu(Entreprise $entreprise, Invite $invite): void
    {
        $em = $this->em();

        $chargement = (new \App\Entity\Chargement())
            ->setNom('Prime nette')
            ->setFonction(\App\Entity\Chargement::FONCTION_PRIME_NETTE);
        $chargement->setEntreprise($entreprise);
        $chargement->setInvite($invite);
        $em->persist($chargement);

        $type = (new \App\Entity\TypeRevenu())
            ->setNom('Commission Ordinaire')
            ->setShared(false)
            ->setMultipayments(true)
            ->setRedevable(\App\Entity\TypeRevenu::REDEVABLE_ASSUREUR)
            // ⚠ SON ASSIETTE : une commission se calcule SUR quelque chose. Sans elle,
            // `getCotationMontantChargementPrime()` rend zéro et la commission avec.
            ->setTypeChargement($chargement)
            ->setPourcentage(10.0);
        $type->setEntreprise($entreprise);
        $type->setInvite($invite);
        $em->persist($type);
        $em->flush();
    }

    private function uneEcheance(array $surcharges = []): array
    {
        return [
            'policeReference' => 'POL/2026/001',
            'policeDateEffet' => '01/01/2026',
            'policeEcheance' => '31/12/2026',
            'trancheNom' => 'Prime unique',
            'tranchePayableAt' => '15/01/2026',
            'assure' => 'KIN AVIA',
            'risque' => 'RC Aviation',
            'assureur' => 'SFA CONGO',
        ] + $surcharges;
    }

    /** @return array{clients: int, polices: int, propositions: int, echeances: int} */
    private function portefeuille(Entreprise $entreprise): array
    {
        $cnx = $this->em()->getConnection();
        $id = $entreprise->getId();

        return [
            'clients' => (int) $cnx->fetchOne('SELECT COUNT(*) FROM client WHERE entreprise_id = ?', [$id]),
            'polices' => (int) $cnx->fetchOne('SELECT COUNT(*) FROM avenant WHERE entreprise_id = ?', [$id]),
            'propositions' => (int) $cnx->fetchOne('SELECT COUNT(*) FROM cotation WHERE entreprise_id = ?', [$id]),
            'echeances' => (int) $cnx->fetchOne('SELECT COUNT(*) FROM tranche WHERE entreprise_id = ?', [$id]),
        ];
    }

    /** Un portefeuille dont le déposant est le gestionnaire. */
    private function portefeuilleGere(Entreprise $entreprise, Invite $invite, string $nom): int
    {
        $em = $this->em();

        $portefeuille = (new \App\Entity\Portefeuille())->setNom($nom);
        $portefeuille->setGestionnaire($em->find(Invite::class, $invite->getId()));
        $portefeuille->setEntreprise($em->find(Entreprise::class, $entreprise->getId()));
        $em->persist($portefeuille);
        $em->flush();

        return (int) $portefeuille->getId();
    }

    /**
     * DEUX portefeuilles gérés : la situation qui bloquait tout.
     *
     * ⚠ C'EST LE NOMBRE QUI COMPTE, pas les noms. À un seul, le circuit d'écriture range
     * le client d'office ; à deux, il n'a plus de réponse évidente — et c'est là qu'il
     * posait une question à un fichier.
     */
    private function deuxPortefeuillesGeres(Entreprise $entreprise, Invite $invite): void
    {
        $this->portefeuilleGere($entreprise, $invite, 'Grands comptes');
        $this->portefeuilleGere($entreprise, $invite, 'Particuliers');
    }

    /** La TVA que l'ASSUREUR précompte sur la commission du courtier. */
    private function tvaAssureur(Entreprise $entreprise, Invite $invite, float $taux): void
    {
        $em = $this->em();

        $taxe = (new \App\Entity\Taxe())->setCode('TVA')->setDescription('TVA sur commission');
        $taxe->setRedevable(\App\Entity\Taxe::REDEVABLE_ASSUREUR);
        $taxe->setTauxIARD((string) $taux);
        $taxe->setTauxVIE((string) $taux);
        $taxe->setEntreprise($em->find(Entreprise::class, $entreprise->getId()));
        $taxe->setInvite($em->find(Invite::class, $invite->getId()));
        $em->persist($taxe);
        $em->flush();

        // ⚠ LE BARÈME EST MÉMOÏSÉ PAR ENTREPRISE le temps d'une requête : sans redémarrage
        // du conteneur, la taxe qu'on vient de poser resterait invisible au calcul.
        static::getContainer()->get(\App\Services\ServiceTaxes::class)->reset();
    }

    /** Un client DÉJÀ en base, exonéré de taxes — que la reprise retrouvera par son nom. */
    private function clientExonere(Entreprise $entreprise, Invite $invite, string $nom): void
    {
        $em = $this->em();

        $client = (new \App\Entity\Client())->setNom($nom);
        $client->setExonere(true);
        $client->setEntreprise($em->find(Entreprise::class, $entreprise->getId()));
        $client->setInvite($em->find(Invite::class, $invite->getId()));
        $em->persist($client);
        $em->flush();
    }

    private function importateur(): ImportateurJsbx
    {
        return static::getContainer()->get(ImportateurJsbx::class);
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

    private function motif(EchangeImportRun $run): string
    {
        $messages = array_map(
            static fn (array $a): string => sprintf('[%s] %s', $a['gravite'] ?? '?', $a['message'] ?? ''),
            $run->getRapport()['anomalies'] ?? [],
        );

        return $messages === [] ? 'aucune anomalie signalée' : implode(' | ', $messages);
    }

    /** @return array{0: Entreprise, 1: Invite} */
    private function fixture(): array
    {
        $em = $this->em();

        $owner = (new Utilisateur())->setEmail(self::OWNER_EMAIL)->setNom('Écriture')->setVerified(true)->setPassword('x');
        $owner->setPaidTokens(1000000);
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
        $em->flush();

        $this->client->loginUser($owner);

        return [$entreprise, $proprietaire];
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
            $cnx->executeStatement('DELETE FROM utilisateur WHERE email = ?', [self::OWNER_EMAIL]);
        } finally {
            $cnx->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        }

        $this->em()->clear();
    }
}
