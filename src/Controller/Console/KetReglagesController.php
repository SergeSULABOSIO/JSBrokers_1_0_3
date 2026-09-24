<?php

namespace App\Controller\Console;

use App\Ai\Reglage\ApplicationDesReglages;
use App\Ai\Reglage\CatalogueDesReglages;
use App\Ai\Reglage\Classe;
use App\Ai\Reglage\ManifesteDesRegles;
use App\Ai\Reglage\ReglagesDeKet;
use App\Entity\Utilisateur;
use App\Repository\KetReglageJournalRepository;
use App\Repository\EntrepriseRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * CE QUE KET SAIT FAIRE, CE QUE CELA COÛTE, ET CE QU'ON PEUT EN COUPER.
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
    public function __construct(
        private CatalogueDesReglages $catalogue,
        private EntrepriseRepository $entrepriseRepository,
        private ReglagesDeKet $reglages,
        private ApplicationDesReglages $application,
        private KetReglageJournalRepository $journal,
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

        // L'ONGLET QUI S'OUVRE, DÉCIDÉ ICI ET NON DANS LE NAVIGATEUR.
        //
        // Le contrôleur Stimulus sait ouvrir un onglet depuis le fragment d'URL, et
        // c'est par là que reviennent les actions POST. Mais un FILTRE est un GET :
        // il ne peut pas porter de fragment. Sans cette ligne, chercher « tranche »
        // dans les outils renverrait l'agent sur l'onglet des déclarations, devant
        // un résultat qu'il ne voit pas — le pire des retours possibles.
        $ongletActif = ($classe !== null && $classe !== '') || $recherche !== ''
            ? 'outils'
            : 'mesures';

        return $this->render('console/ket_reglages/index.html.twig', [
            'pageName'      => 'Réglages de Ket',
            'pageIcon'      => 'assistant-ia-parametres',
            'outils'        => array_values($filtres),
            'total'         => \count($outils),
            'classes'       => Classe::cases(),
            'classeActive'  => $classe,
            'recherche'     => $recherche,
            'poids'         => $this->catalogue->poidsDesTrousses(),
            'cabinets'      => $cabinets,
            'cabinetsAvecKet' => $this->entrepriseRepository->countAvecSoldePayant(),
            'coupes'        => $this->reglages->outilsCoupes(),
            'parametres'    => ReglagesDeKet::PARAMETRES,
            'valeurs'       => $this->reglages->parametres(),
            'peutRegler'    => $this->isGranted('ROLE_SUPER_ADMIN'),
            'vierge'        => $this->reglages->estVierge(),
            'dernierChangement' => $this->journal->dernierParElement(),
            'historique'    => $this->journal->derniers(),
            'gainCoupe'     => $this->catalogue->gainDesOutilsCoupes($this->reglages->outilsCoupes()),
            'familles'      => ManifesteDesRegles::familles(),
            'reglesTotal'   => \count(ManifesteDesRegles::CODE)
                + \count(ManifesteDesRegles::RESTITUTION)
                + \count(ManifesteDesRegles::BOUSSOLE),
            'ongletActif'   => $ongletActif,
        ]);
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
        return $this->redirectToRoute('console.ket.reglages.index', ['_fragment' => 'tab-outils']);
    }

    #[Route('/parametre/{clef}', name: 'parametre', requirements: ['clef' => '[a-z_.]+'], methods: ['POST'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function reglerParametre(string $clef, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('ket_reglage', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        try {
            $this->application->reglerParametre(
                $clef,
                $request->request->getInt('valeur'),
                (string) $request->request->get('motif'),
                $this->utilisateurCourant(),
            );
            $this->addFlash('success', 'Seuil enregistré : il s’applique au prochain message de chaque cabinet.');
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('console.ket.reglages.index', ['_fragment' => 'tab-seuils']);
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
        return $this->redirectToRoute('console.ket.reglages.index', ['_fragment' => 'tab-historique']);
    }

    private function utilisateurCourant(): ?Utilisateur
    {
        $user = $this->getUser();

        return $user instanceof Utilisateur ? $user : null;
    }
}
