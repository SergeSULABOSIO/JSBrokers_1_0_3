<?php

namespace App\Tests\Ai;

use App\Ai\Parite\CouvertureDesEcrans;
use App\Ai\Presentation\Colonnes;
use App\Ai\Presentation\TableauMarkdown;
use App\Ai\Scope\AiScope;
use App\Ai\Tool\LireSoaTool;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Service\Soa\SoaContextBuilder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * LE RELEVÉ DE COMPTE QUE KET RESTITUE EST CELUI DE L'ÉCRAN.
 *
 * `lire_soa` était la dernière dette réelle de l'inventaire de parité : Ket savait
 * ENVOYER le relevé d'un client, pas le MONTRER. Depuis que le téléphone ne reçoit
 * que la conversation, cela voulait dire qu'un courtier en déplacement ne pouvait
 * pas consulter le compte de son client.
 *
 * ── CE QUE CE TEST PROTÈGE ───────────────────────────────────────────────────────
 *  - LA FORMULE PARTAGÉE. « Payé » et « Solde », par police comme par tranche, sont
 *    des PRORATA du taux de règlement global du client. Cette arithmétique était
 *    recopiée dans les deux gabarits du relevé ; elle vit maintenant dans
 *    `SoaContextBuilder`, et les gabarits comme l'outil la LISENT. Si une copie
 *    réapparaissait dans un gabarit, l'écran et Ket annonceraient un jour deux
 *    soldes différents sur le même compte — au client.
 *  - LA TRONCATURE SILENCIEUSE DES COLONNES. `TableauMarkdown` s'arrête à sept
 *    colonnes, sans rien dire, dans l'ordre de déclaration. Les tableaux du relevé
 *    en comptent neuf à l'écran : déclarés tels quels, ils auraient perdu « payé »
 *    et « solde » — les deux seules colonnes pour lesquelles on lit un relevé.
 *  - L'HONNÊTETÉ DES SECTIONS. Une section non demandée doit être NOMMÉE, sinon le
 *    modèle croit avoir vu tout le relevé et affirme « aucun sinistre » sur une
 *    section qu'il n'a simplement pas réclamée.
 */
class LireSoaToolTest extends KernelTestCase
{
    private const GABARITS = [
        'templates/admin/soa/_soa_sections.html.twig',
        'templates/admin/soa/soa_client_workspace.html.twig',
    ];

    private function outil(): LireSoaTool
    {
        self::bootKernel();

        return static::getContainer()->get(LireSoaTool::class);
    }

    /**
     * LA DETTE EST PAYÉE : L'ACTION D'ÉCRAN A UNE CONTREPARTIE NOMMÉE.
     */
    public function testLaConsultationDuReleveEstDesormaisCouverte(): void
    {
        self::assertSame(
            'lire_soa',
            CouvertureDesEcrans::COUVERTES['ui:soa.view-request'] ?? null,
            "La consultation du relevé doit être couverte par l'outil lire_soa.",
        );
        self::assertArrayNotHasKey(
            'ui:soa.view-request',
            CouvertureDesEcrans::ECRAN_SEULEMENT,
            "L'action ne peut plus figurer parmi les gestes réservés à l'écran.",
        );
    }

    /**
     * AUCUN TABLEAU NE DÉPASSE SEPT COLONNES.
     *
     * Vérifié sur les présentations RÉELLEMENT déclarées par l'outil, en appelant
     * ses constructeurs de tableau par réflexion : c'est le seul moyen de constater
     * ce que le rendu recevra, sans avoir à monter un client complet en base.
     */
    public function testAucunTableauNeDepasseLaLargeurDuRendu(): void
    {
        $outil = $this->outil();
        $reflexion = new \ReflectionClass($outil);

        // Chaque constructeur de tableau, appelé sur une collection VIDE : la
        // présentation est déclarée indépendamment des lignes, c'est donc elle
        // seule qu'on interroge ici.
        foreach (['polices' => [[], []], 'echeancier' => [[]], 'sinistres' => [[]]] as $nom => $arguments) {
            $methode = $reflexion->getMethod($nom);
            $methode->setAccessible(true);
            $resultat = $methode->invokeArgs($outil, $arguments);

            $colonnes = $resultat['presentation']['colonnes'] ?? [];
            self::assertNotEmpty($colonnes, "La section « {$nom} » doit déclarer ses colonnes.");
            self::assertLessThanOrEqual(
                TableauMarkdown::MAX_COLONNES,
                count($colonnes),
                sprintf(
                    "La section « %s » déclare %d colonnes : le rendu s'arrête à %d, EN SILENCE et dans "
                    . "l'ordre de déclaration. Les colonnes en trop — probablement « payé » et « solde » — "
                    . 'disparaîtraient de la réponse sans que rien ne le signale.',
                    $nom,
                    count($colonnes),
                    TableauMarkdown::MAX_COLONNES,
                ),
            );
        }

        // Les colonnes de montant sont déclarées comme telles : sans quoi elles se
        // rendraient sans monnaie et ne seraient pas totalisées.
        $methode = $reflexion->getMethod('polices');
        $methode->setAccessible(true);
        $colonnes = $methode->invokeArgs($outil, [[], []])['presentation']['colonnes'];
        foreach (['primeTTC', 'paye', 'solde'] as $cle) {
            self::assertSame(
                Colonnes::MONTANT,
                $colonnes[$cle] ?? null,
                "« {$cle} » est un montant : sans ce rôle, il se rend sans monnaie et ne se totalise pas.",
            );
        }
    }

    /**
     * LES SECTIONS PAR DÉFAUT SONT CELLES D'UNE QUESTION DE COMPTE.
     *
     * Tout servir par défaut coûterait des tokens à chaque question et noierait la
     * réponse ; n'en servir qu'une obligerait Ket à rappeler l'outil. Le trio
     * récapitulatif + échéancier + ratios répond à « où en est ce compte ? ».
     */
    public function testLesSectionsParDefautRepondentALaQuestionDeCompte(): void
    {
        $outil = $this->outil();
        $methode = new \ReflectionMethod($outil, 'sectionsDemandees');
        $methode->setAccessible(true);

        self::assertSame(
            ['recapitulatif', 'echeancier', 'ratios'],
            $methode->invoke($outil, []),
        );

        // Une demande explicite est respectée, mais RÉORDONNÉE dans l'ordre du
        // relevé : une pièce comptable se lit du général au détail.
        self::assertSame(
            ['recapitulatif', 'polices', 'sinistres'],
            $methode->invoke($outil, ['sections' => ['sinistres', 'recapitulatif', 'polices']]),
        );

        // Une section inconnue est écartée, et n'entraîne pas la réponse entière
        // dans un refus : le modèle a pu inventer un nom, le relevé reste servi.
        self::assertSame(
            ['polices'],
            $methode->invoke($outil, ['sections' => ['polices', 'section_imaginaire']]),
        );

        // Aucune section valide = les sections par défaut, jamais un relevé vide.
        self::assertSame(
            ['recapitulatif', 'echeancier', 'ratios'],
            $methode->invoke($outil, ['sections' => ['n_importe_quoi']]),
        );
    }

    /**
     * UN RETARD QUI N'EN EST PAS UN N'EST PAS RESTITUÉ.
     *
     * L'indicateur de retard d'une tranche vaut « Non » ou « N/A » quand tout va
     * bien. Restituer ces chaînes telles quelles ferait dire à Ket qu'une tranche
     * porte un retard nommé « Non ».
     */
    public function testLesNonRetardsNeSontPasRestitues(): void
    {
        $outil = $this->outil();
        $methode = new \ReflectionMethod($outil, 'retard');
        $methode->setAccessible(true);

        foreach (['Non', 'N/A', ''] as $valeur) {
            $tranche = new \stdClass();
            $tranche->retardPaiement = $valeur;
            self::assertNull(
                $methode->invoke($outil, $tranche),
                sprintf('« %s » n\'est pas un retard.', $valeur),
            );
        }

        $enRetard = new \stdClass();
        $enRetard->retardPaiement = '45 jours';
        self::assertSame('45 jours', $methode->invoke($outil, $enRetard));

        // Aucune valeur calculée posée du tout : pas de retard, et pas d'erreur.
        self::assertNull($methode->invoke($outil, new \stdClass()));
    }

    /**
     * L'OUTIL NE CAPTE PAS LES DEMANDES D'ENVOI.
     *
     * « envoie le SOA de X » appartient à `preparer_envoi_soa`. Les deux outils
     * partagent tout leur vocabulaire : sans cette exclusion, le chemin simulé
     * répondrait par une lecture à une demande d'envoi.
     */
    public function testLeCheminSimuleNeVolePasLesDemandesDEnvoi(): void
    {
        $outil = $this->outil();
        $scope = new AiScope(new Entreprise(), new Invite());

        self::assertNull($outil->match('Envoie le SOA de Kin Avia', $scope));
        self::assertNull($outil->match('Transmets le relevé de compte au client', $scope));

        self::assertSame(['nom' => 'kin avia'], $outil->match('Montre le relevé de compte de Kin Avia', $scope));
        self::assertSame(['nom' => 'kin avia'], $outil->match('Où en est le compte du client Kin Avia ?', $scope));
    }

    /**
     * LA FORMULE DU PRORATA N'EXISTE QU'À UN SEUL ENDROIT.
     *
     * C'est le cœur de la dette payée ici. Les deux gabarits du relevé
     * recalculaient « payé » et « solde » à partir du taux de règlement global du
     * client ; l'outil aurait été une troisième copie. La formule vit désormais
     * dans `SoaContextBuilder`, et ce test refuse son retour dans un gabarit.
     */
    public function testLaFormuleDuProrataNeReapparaitPasDansLesGabarits(): void
    {
        $racine = \dirname(__DIR__, 2);

        foreach (self::GABARITS as $gabarit) {
            $chemin = $racine . '/' . $gabarit;
            self::assertFileExists($chemin);
            $contenu = (string) file_get_contents($chemin);

            self::assertDoesNotMatchRegularExpression(
                '/montant_paye[^\\n]*\\/[^\\n]*montant_du|montant_du[^\\n]*>\\s*0\\s*\\?/',
                $contenu,
                sprintf(
                    "Le gabarit « %s » recalcule le taux de règlement du client.\n"
                    . "Cette formule appartient à SoaContextBuilder, qui la pose sur chaque ligne "
                    . "(`item.primePayee`, `item.primeSolde`) : l'écran, le relevé public et Ket "
                    . 'doivent lire la MÊME valeur, sinon ils finiront par annoncer au client deux '
                    . 'soldes différents sur le même compte.',
                    $gabarit,
                ),
            );

            // Et ils lisent bien la valeur partagée.
            self::assertStringContainsString(
                'item.primePayee',
                $contenu,
                sprintf('Le gabarit « %s » doit lire la colonne calculée par le service.', $gabarit),
            );
        }
    }

    /**
     * LES LIBELLÉS DE STATUT DU RELEVÉ VIENNENT DU PHP, PAS DE CHAQUE GABARIT.
     *
     * Ils étaient recopiés dans les deux gabarits, et l'outil en aurait fait une
     * troisième copie — avec, cette fois, un jeu de libellés DIFFÉRENT de celui de
     * l'écran (`AvenantIndicatorStrategy` dit « Résilié » là où le relevé dit
     * « Annulé »). Ket aurait alors décrit une police autrement que la pièce
     * remise au client.
     */
    public function testLesLibellesDeStatutViennentDuService(): void
    {
        $racine = \dirname(__DIR__, 2);

        foreach (self::GABARITS as $gabarit) {
            $contenu = (string) file_get_contents($racine . '/' . $gabarit);
            self::assertDoesNotMatchRegularExpression(
                '/set renewalLabels\s*=\s*\{/',
                $contenu,
                sprintf(
                    "Le gabarit « %s » redéclare les libellés de statut. Ils viennent de "
                    . 'SoaContextBuilder::STATUTS_DE_POLICE, via le contexte.',
                    $gabarit,
                ),
            );
        }

        // Le jeu du relevé est bien celui attendu (vocabulaire adressé au client).
        self::assertSame('En cours', SoaContextBuilder::STATUTS_DE_POLICE[4] ?? null);
        self::assertSame('Résilié', SoaContextBuilder::STATUTS_DE_POLICE[6] ?? null);
    }
}
