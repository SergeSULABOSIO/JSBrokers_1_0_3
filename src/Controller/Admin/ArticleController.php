<?php

namespace App\Controller\Admin;

use App\Entity\Article;
use App\Entity\Invite;
use App\Constantes\Constante;
use App\Form\ArticleType;
use App\Repository\ArticleRepository;
use App\Repository\InviteRepository;
use App\Repository\EntrepriseRepository;
use App\Services\CanvasBuilder;
use Doctrine\ORM\EntityManagerInterface;
use App\Services\JSBDynamicSearchService;
use Symfony\Component\HttpFoundation\Request;
use App\Controller\Admin\ControllerUtilsTrait;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use App\Entity\Traits\HandleChildAssociationTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

#[Route("/admin/article", name: 'admin.article.')]
#[IsGranted('ROLE_USER')]
class ArticleController extends AbstractController
{
    use HandleChildAssociationTrait;
    use ControllerUtilsTrait;

    public function __construct(
        private EntityManagerInterface $em,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private ArticleRepository $articleRepository,
        private Constante $constante,
        private JSBDynamicSearchService $searchService,
        private SerializerInterface $serializer,
        CanvasBuilder $canvasBuilder
    ) {
        // Assignation du CanvasBuilder à la propriété déclarée dans le trait
        $this->canvasBuilder = $canvasBuilder;
    }

    protected function getCollectionMap(): array
    {
        return $this->buildCollectionMapFromEntity(Article::class);
    }

    protected function getParentAssociationMap(): array
    {
        return $this->buildParentAssociationMapFromEntity(Article::class);
    }

    #[Route('/test', name: 'test')]
    public function edit(): Response
    {
        return $this->render('components/article/editor.html.twig', []);
    }

    #[Route('/index/{idInvite}/{idEntreprise}', name: 'index', requirements: ['idEntreprise' => Requirement::DIGITS, 'idInvite' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        return $this->renderViewOrListComponent(Article::class, $request);
    }

    #[Route('/api/get-form/{id?}', name: 'api.get_form', methods: ['GET'])]
    public function getFormApi(?Article $article, Request $request): Response
    {
        return $this->renderFormCanvas(
            $request,
            Article::class,
            ArticleType::class,
            $article,
            function (Article $article, Invite $invite) {
                // Fonction d'initialisation pour une nouvelle entité Article
                // Tu peux définir ici des valeurs par défaut si besoin
            }
        );
    }

    #[Route('/api/submit', name: 'api.submit', methods: ['POST'])]
    public function submitApi(Request $request): JsonResponse
    {
        $inviteConnecte = $this->getInvite();

        return $this->handleFormSubmission(
            $request,
            Article::class,
            ArticleType::class,
            function (Article $article) use ($inviteConnecte) {
                // On peut ici forcer des liaisons si nécessaire (ex: lier la note via le parent_id)
            }
        );
    }

    #[Route('/api/delete/{id}', name: 'api.delete', methods: ['DELETE'])]
    public function deleteApi(Article $article): Response
    {
        return $this->handleDeleteApi($article);
    }

    #[Route('/api/dynamic-query/{idInvite}/{idEntreprise}', name: 'app_dynamic_query', requirements: ['idEntreprise' => Requirement::DIGITS, 'idInvite' => Requirement::DIGITS], methods: ['POST'])]
    public function query(Request $request): Response
    {
        return $this->renderViewOrListComponent(Article::class, $request, true);
    }

    #[Route('/api/{id}/{collectionName}/{usage}', name: 'api.get_collection', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    public function getCollectionListApi(int $id, string $collectionName, ?string $usage = "generic"): Response
    {
        return $this->handleCollectionApiRequest($id, $collectionName, Article::class, $usage);
    }

    /**
     * Vérifie si un article similaire existe déjà dans la note.
     * Un doublon est défini par la même combinaison de [revenuFacture, tranche].
     *
     * @param Article $article L'entité Article à vérifier.
     * @return array|null Un tableau avec le message d'erreur si un doublon est trouvé, sinon null.
     */
    private function checkForDuplicates(Article $article): ?array
    {
        $note = $article->getNote();
        $revenu = $article->getRevenuFacture();
        $tranche = $article->getTranche();

        // Si les éléments clés ne sont pas définis, on ne peut pas vérifier.
        if (!$note || !$revenu) {
            return null;
        }

        // On construit la requête pour trouver un article existant avec la même combinaison.
        $qb = $this->articleRepository->createQueryBuilder('a');
        $qb->select('count(a.id)')
            ->where('a.note = :note')
            ->andWhere('a.revenuFacture = :revenu')
            ->setParameter('note', $note)
            ->setParameter('revenu', $revenu);

        // La tranche est optionnelle, on ajuste la requête en conséquence.
        if ($tranche) {
            $qb->andWhere('a.tranche = :tranche')->setParameter('tranche', $tranche);
        } else {
            $qb->andWhere('a.tranche IS NULL');
        }

        // Si on est en mode édition, il faut exclure l'article lui-même de la recherche.
        if ($article->getId()) {
            $qb->andWhere('a.id != :articleId')->setParameter('articleId', $article->getId());
        }

        if ($qb->getQuery()->getSingleScalarResult() > 0) {
            // Message d'erreur concis. Le client (dialog-instance) ajoutera le contexte (timestamp, etc.).
            $message = "Cet article existe déjà.";
            return ['message' => $message];
        }

        return null;
    }
}