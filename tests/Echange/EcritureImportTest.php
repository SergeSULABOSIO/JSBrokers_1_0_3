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
     * ⚠ « 10 » N'EST PAS « 10 % », ET LE CONTRÔLE DOIT LE DIRE.
     *
     * Écrit sans son signe pourcent, un taux de commission devient un montant forfaitaire.
     * C'est licite en soi — un forfait existe —, si bien que rien ne s'y opposait : la
     * reprise écrivait, et la note affichait un solde négatif. Le fichier porte pourtant de
     * quoi trancher, sur la même ligne : la commission déjà encaissée.
     */
    public function testUnTauxEcritSansPourcentEstRefuseEtExplique(): void
    {
        [$entreprise, $invite] = $this->fixture();
        $this->catalogueDeRevenu($entreprise, $invite);

        $run = $this->importer($entreprise, $invite, [
            $this->uneEcheance() + [
                'tranchePart' => 100,
                'chargement_prime_nette' => 10000,
                // Le pourcent manque : ceci se lit « dix unités monétaires ».
                'commissionRevenus' => 'Commission Ordinaire = 10',
                'ouvertureCommissionEncaissee' => 1000,
                'ouvertureCommissionLe' => '20/01/2026',
            ],
        ]);

        self::assertSame(
            EchangeImportRun::STATUT_ECHEC,
            $run->getStatut(),
            'Un forfait de 10 ne peut pas produire 1 000 d\'encaissement : la reprise doit refuser.',
        );

        // ⚠ LE REPROCHE DOIT PORTER LA SOLUTION. « Valeur invalide » laisserait l'utilisateur
        // devant un refus sans savoir quoi corriger — le format à écrire est la seule chose
        // qu'il lui manque.
        self::assertStringContainsString('%', $this->motif($run));
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
     * ⚠ SUPPRIMER UNE PISTE REPRISE NE DOIT PAS RENDRE UNE ERREUR 500 MUETTE.
     *
     * Depuis que la reprise enregistre la commission déjà encaissée, elle crée une note et
     * son article — lequel référence le revenu du courtier. Supprimer la piste fait donc
     * remonter la cascade jusqu'à ce revenu, que la base refuse d'effacer tant qu'une
     * facture s'y rattache. C'est un REFUS, pas une panne : et il vaut mieux qu'il en soit
     * ainsi, car détruire la note en cascade détruirait une pièce comptable.
     *
     * L'écran rendait « Erreur lors de la suppression » en 500, sans dire ni pourquoi ni
     * quoi faire. Il dit désormais ce qui bloque.
     */
    public function testSupprimerUnePisteRepriseExpliqueCeQuiLaRetient(): void
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

        $this->client->request('DELETE', '/admin/piste/api/delete/' . $idPiste);

        self::assertSame(
            409,
            $this->client->getResponse()->getStatusCode(),
            'Un élément encore utilisé se REFUSE (409), il ne fait pas planter le serveur (500).',
        );

        // ⚠ LE REFUS DOIT NOMMER CE QUI BLOQUE, sans quoi l'utilisateur reclique et conclut
        // à une panne. On ne fige pas LAQUELLE des contraintes saute la première — cela
        // dépend de l'ordre dans lequel Doctrine démonte la cascade, qui ne nous appartient
        // pas —, mais le message doit désigner quelque chose et dire quoi faire.
        // ⚠ ON DÉCODE AVANT DE COMPARER : `json_encode` échappe l'apostrophe en `'`,
        // et une recherche sur la charge brute ne trouverait jamais un texte français.
        $message = (string) (json_decode(
            (string) $this->client->getResponse()->getContent(),
            true,
        )['message'] ?? '');

        self::assertStringContainsString("s'y rattache encore", $message);
        self::assertStringContainsString("Supprimez-la d'abord", $message);
        self::assertStringNotContainsString(
            'Erreur lors de la suppression',
            $message,
            'Le message générique ne dit ni pourquoi ni quoi faire : c\'est lui qu\'on remplace.',
        );

        // ⚠ ET RIEN N'A ÉTÉ DÉTRUIT AU PASSAGE. Un refus qui aurait déjà emporté les
        // chargements ou les revenus laisserait un dossier à moitié démantelé.
        self::assertSame(
            1,
            (int) $this->em()->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM note WHERE entreprise_id = ?',
                [$entreprise->getId()],
            ),
            'La pièce comptable survit au refus.',
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
     * ⚠ ON NE CRÉE JAMAIS UN TYPE À LA VOLÉE depuis un classeur : il porte un taux et un
     * redevable qu'un simple nom ne suffit pas à définir. Le cabinet doit donc l'avoir.
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
