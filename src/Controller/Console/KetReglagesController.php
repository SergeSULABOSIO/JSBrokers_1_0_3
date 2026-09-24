<?php

namespace App\Controller\Console;

use App\Ai\Fournisseur\EtatDesFournisseurs;
use App\Ai\Fournisseur\PolitiqueDesFournisseurs;
use App\Ai\Reglage\ApplicationDesReglages;
use App\Ai\Reglage\CatalogueDesReglages;
use App\Ai\Reglage\Classe;
use App\Ai\Reglage\ManifesteDesRegles;
use App\Ai\Reglage\ReglagesDeKet;
use App\Entity\Utilisateur;
use App\Form\KetFournisseursType;
use Symfony\Component\Form\FormInterface;
use App\Repository\KetReglageJournalRepository;
use App\Repository\EntrepriseRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * TOUT CE QUI SE RÈGLE SUR KET, DANS UN SEUL ÉCRAN.
 *
 * ── LA FUSION, ET POURQUOI ──────────────────────────────────────────────────
 * « Réglages de Ket » et « Fournisseurs de Ket » étaient deux rubriques voisines dans
 * le même menu. Un agent qui cherchait pourquoi Ket répond mal devait deviner
 * laquelle ouvrir — et la réponse dépendait de la cause, qu'il ignorait justement.
 * Qui répond pour Ket est un RÉGLAGE comme les autres : c'est désormais un onglet.
 *
 * Le gabarit du formulaire n'a pas été recopié mais EXTRAIT
 * (`ket_fournisseurs/_corps.html.twig`) : deux écrans qui rendent le même formulaire
 * finissent par diverger sur le point qui compte.
 *
 * ── POURQUOI CET ÉCRAN EXISTE ───────────────────────────────────────────────
 * Cinquante-deux outils partent au fournisseur à chaque tour, et personne dans
 * l'équipe ne disposait de leur liste ailleurs que dans le code. Le support répond
 * pourtant tous les jours à « Ket sait-elle faire X ? », et le commercial construit
 * son argumentaire sur la réponse. Aller la chercher dans `src/Ai/Tool` n'est pas un
 * chemin praticable pour eux.
 *
 * ── ROLE_ADMIN, ET NON SUPER-ADMIN — À LA DIFFÉRENCE DES FOURNISSEURS ───────
 * L'écran voisin (`console.ket.fournisseurs`) décide de ce que la plateforme DÉPENSE :
 * il est super-admin. Celui-ci ne fait que LIRE, et ce qu'il montre est le catalogue
 * du produit — aucune donnée de cabinet, aucun chiffre d'affaires, aucun secret. Le
 * fermer au support reviendrait à lui demander de deviner.
 *
 * Le filtrage par département (ConsoleAccessSubscriber) reste au-dessus : seuls la
 * Direction, le Commercial et la Relation client portent le préfixe
 * `console.ket.reglages.` dans leur périmètre. Finance et RH n'ont rien à y faire.
 *
 * ── DEUX NIVEAUX DE DROIT SUR LE MÊME ÉCRAN ─────────────────────────────────
 * Consulter : ROLE_ADMIN, filtré par département. Régler : ROLE_SUPER_ADMIN, posé
 * méthode par méthode. Un agent du support voit donc l'inventaire entier et
 * l'historique des changements, sans aucun interrupteur — ce qui est exactement ce
 * dont il a besoin pour répondre « Ket ne le fait plus depuis le 12 ».
 *
 * ── LES RÈGLES SONT LÀ, MAIS EN LECTURE SEULE ───────────────────────────────
 * Les trois familles (R = appliquées par le code, K = de restitution, B = boussole)
 * s'affichent avec leur source. Aucune n'est modifiable, et ce n'est pas une
 * précaution : une règle d'intégrité comptable ou de cloisonnement ne se règle pas
 * depuis un écran. Ce que l'écran apporte, c'est de pouvoir les CITER — elles
 * n'avaient jusqu'ici aucun identifiant stable.
 */
#[Route('/console/ket/reglages', name: 'console.ket.reglages.')]
#[IsGranted('ROLE_ADMIN')]
class KetReglagesController extends AbstractConsoleController
{
    /** Les volets de l'écran, dans l'ordre. Sert à valider ce qui arrive par l'URL. */
    private const ONGLETS = ['mesures', 'outils', 'seuils', 'regles', 'historique', 'fournisseurs'];

    public function __construct(
        private CatalogueDesReglages $catalogue,
        private EntrepriseRepository $entrepriseRepository,
        private ReglagesDeKet $reglages,
        private ApplicationDesReglages $application,
        private KetReglageJournalRepository $journal,
        private PolitiqueDesFournisseurs $politique,
        private EtatDesFournisseurs $etatFournisseurs,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request, LocaleSwitcher $localeSwitcher): Response
    {
        $this->applyLangPreference($request, $localeSwitcher);

        $outils = $this->catalogue->outils();
        $classe = $request->query->get('classe');
        $recherche = trim((string) $request->query->get('q'));

        // FILTRES EN PHP, ET C'EST ASSUMÉ. Cinquante-trois lignes tiennent en mémoire
        // et viennent du conteneur, pas de la base : une requête paginée coûterait un
        // aller-retour serveur pour trier ce que l'on a déjà entièrement sous la main.
        $filtres = array_filter($outils, static function (array $o) use ($classe, $recherche): bool {
            if ($classe !== null && $classe !== '' && $o['classe']->value !== $classe) {
                return false;
            }
            if ($recherche === '') {
                return true;
            }

            $foin = mb_strtolower($o['libelle'] . ' ' . $o['nom'] . ' ' . $o['resume']);

            return str_contains($foin, mb_strtolower($recherche));
        });

        $cabinets = $this->entrepriseRepository->countAllGlobal();

        // L'ONGLET QUI S'OUVRE EST DIT PAR L'URL, PLUS DEVINÉ.
        //
        // Un FILTRE est un GET : il ne peut pas porter de fragment, et le contrôleur
        // Stimulus — qui sait lire `#tab-…` — n'a donc rien à lire au retour. La
        // première version le DÉDUISAIT : « s'il y a un filtre, ouvre les outils ».
        // Elle échouait sur le cas le plus banal, celui d'un filtre VIDÉ : on clique
        // « Filtrer » après avoir effacé sa recherche, il n'y a plus de filtre à
        // déduire, et l'agent est renvoyé sur le premier onglet alors qu'il n'a
        // jamais quitté les outils.
        //
        // Le formulaire porte donc son onglet en clair. La déduction reste en repli
        // pour un lien construit à la main, et la valeur est validée : un onglet
        // inconnu dans l'URL ouvrirait un volet vide.
        $ongletDemande = (string) $request->query->get('onglet', '');
        $ongletActif = \in_array($ongletDemande, self::ONGLETS, true)
            ? $ongletDemande
            : ((($classe !== null && $classe !== '') || $recherche !== '') ? 'outils' : 'mesures');

        // LE FORMULAIRE DES FOURNISSEURS N'EST CONSTRUIT QUE POUR QUI PEUT LE VOIR.
        // Il décide de ce que la plateforme DÉPENSE : il reste super-admin, comme
        // avant la fusion. Un agent du support voit donc les cinq autres onglets et
        // pas celui-là — et la route qui l'enregistre le refuse de toute façon.
        $peutRegler = $this->isGranted('ROLE_SUPER_ADMIN');

        // UNE SAISIE REFUSÉE SE RÉAFFICHE, ELLE NE SE PERD PAS.
        //
        // KetFournisseursController nous passe son formulaire par `forward()` quand
        // il l'a refusé : l'agent retrouve alors sa saisie ET la raison du refus,
        // au lieu de devoir retaper le JSON qu'il venait d'écrire. C'est aussi ce qui
        // rend le 422 automatique — `render()` le pose dès qu'une vue porte un
        // formulaire soumis et invalide.
        $refuse = $request->attributes->get('formFournisseursInvalide');
        $formFournisseurs = match (true) {
            $refuse instanceof FormInterface => $refuse->createView(),
            $peutRegler => $this->createForm(KetFournisseursType::class, null, ['politique' => $this->politique->tout()])->createView(),
            default => null,
        };
        if ($refuse instanceof FormInterface) {
            $ongletActif = 'fournisseurs';
        }

        // 422 SUR UNE SAISIE REFUSÉE, et posé à la main : le code que Symfony pose
        // tout seul quand un formulaire invalide est rendu ne traverse pas un
        // `forward()`. Sans lui, le navigateur et les tests liraient « 200 OK » sur
        // un enregistrement qui n'a pas eu lieu.
        $reponse = $refuse instanceof FormInterface
            ? new Response(null, Response::HTTP_UNPROCESSABLE_ENTITY)
            : null;

        return $this->render('console/ket_reglages/index.html.twig', [
            'pageName'      => 'Configuration de Ket',
            'pageIcon'      => 'assistant-ia-parametres',
            'outils'        => array_values($filtres),
            'total'         => \count($outils),
            // ON N'OFFRE QUE CE QUI EXISTE. La liste proposait les quatre classes, dont
            // « Paramètre » — qu'aucun outil ne porte, puisqu'elle désigne les seuils.
            // Un filtre qui ne peut rendre que zéro résultat n'est pas un filtre, c'est
            // une impasse : l'utilisateur conclut que la recherche est cassée
            // (Bastien & Scapin > Gestion des erreurs, par la PRÉVENTION).
            'classes'       => $this->classesPresentes($outils),
            'classeActive'  => $classe,
            'recherche'     => $recherche,
            'poids'         => $this->catalogue->poidsDesTrousses(),
            'cabinets'      => $cabinets,
            'cabinetsAvecKet' => $this->entrepriseRepository->countAvecSoldePayant(),
            'coupes'        => $this->reglages->outilsCoupes(),
            'parametres'    => ReglagesDeKet::PARAMETRES,
            'valeurs'       => $this->reglages->parametres(),
            'peutRegler'    => $peutRegler,
            'vierge'        => $this->reglages->estVierge(),
            'dernierChangement' => $this->journal->dernierParElement(),
            'historique'    => $this->journal->derniers(),
            'gainCoupe'     => $this->catalogue->gainDesOutilsCoupes($this->reglages->outilsCoupes()),
            'familles'      => ManifesteDesRegles::familles(),
            'reglesTotal'   => \count(ManifesteDesRegles::CODE)
                + \count(ManifesteDesRegles::RESTITUTION)
                + \count(ManifesteDesRegles::BOUSSOLE),
            'ongletActif'   => $ongletActif,
            'formFournisseurs' => $formFournisseurs,
            'etatFournisseurs' => $formFournisseurs !== null ? $this->etatFournisseurs->tout() : [],
        ], $reponse);
    }

    /**
     * BASCULER UN OUTIL, pour toute la plateforme.
     *
     * SUPER-ADMIN AU NIVEAU MÉTHODE, et non de la classe : la consultation reste
     * ouverte au support et au commercial (cf. docblock de classe), seule l'écriture
     * est réservée. La garde de fond — un INVARIANT ou un INDISPENSABLE ne se coupe
     * pas — vit dans ApplicationDesReglages, sur le chemin d'écriture : un appel
     * direct à cette route échoue donc exactement comme un clic.
     */
    #[Route('/outil/{nom}', name: 'outil', requirements: ['nom' => '[a-z_]+'], methods: ['POST'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function basculerOutil(string $nom, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('ket_reglage', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $actif = $request->request->getBoolean('actif');

        try {
            $this->application->basculerOutil(
                $nom,
                $actif,
                (string) $request->request->get('motif'),
                $this->utilisateurCourant(),
            );
            $this->addFlash('success', sprintf(
                '« %s » est désormais %s pour tous les cabinets.',
                $nom,
                $actif ? 'actif' : 'coupé',
            ));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        // ON REVIENT D'OÙ L'ON VIENT. Le fragment rouvre l'onglet des outils : sans
        // lui, l'agent qui vient de couper un outil atterrit sur les déclarations et
        // doit retrouver sa ligne parmi cinquante-deux.
        return $this->redirectToRoute('console.ket.reglages.index', ['onglet' => 'outils', '_fragment' => 'tab-outils']);
    }

    /**
     * LES QUATRE SEUILS EN UN SEUL GESTE.
     *
     * Quatre formulaires côte à côte, chacun avec son motif et son bouton, faisaient
     * quatre fois le même travail à l'écran : celui qui déplace deux seuils le fait
     * pour une seule raison, et il ne devrait avoir à l'écrire qu'une fois.
     */
    #[Route('/seuils', name: 'seuils', methods: ['POST'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function reglerSeuils(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('ket_reglage', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $soumis = (array) $request->request->all('valeurs');
        $valeurs = [];
        foreach (array_keys(ReglagesDeKet::PARAMETRES) as $clef) {
            if (isset($soumis[$clef]) && $soumis[$clef] !== '') {
                $valeurs[$clef] = (int) $soumis[$clef];
            }
        }

        try {
            $this->application->reglerSeuils($valeurs, (string) $request->request->get('motif'), $this->utilisateurCourant());
            $this->addFlash('success', 'Seuils enregistrés : ils s’appliquent au prochain message de chaque cabinet.');
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('console.ket.reglages.index', ['onglet' => 'seuils', '_fragment' => 'tab-seuils']);
    }

    #[Route('/reinitialiser', name: 'reinitialiser', methods: ['POST'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function reinitialiser(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('ket_reglage', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        try {
            $this->application->reinitialiser((string) $request->request->get('motif'), $this->utilisateurCourant());
            $this->addFlash('success', 'Tous les réglages sont revenus aux valeurs d’origine.');
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        // Vers l'HISTORIQUE : c'est là que se lit ce qui vient d'être annulé.
        return $this->redirectToRoute('console.ket.reglages.index', ['onglet' => 'historique', '_fragment' => 'tab-historique']);
    }

    /**
     * Les classes réellement portées par au moins un outil, dans l'ordre de l'enum.
     *
     * @param list<array{classe: Classe}> $outils
     *
     * @return list<Classe>
     */
    private function classesPresentes(array $outils): array
    {
        $vues = [];
        foreach ($outils as $outil) {
            $vues[$outil['classe']->value] = true;
        }

        return array_values(array_filter(
            Classe::cases(),
            static fn (Classe $c): bool => isset($vues[$c->value]),
        ));
    }

    private function utilisateurCourant(): ?Utilisateur
    {
        $user = $this->getUser();

        return $user instanceof Utilisateur ? $user : null;
    }
}
