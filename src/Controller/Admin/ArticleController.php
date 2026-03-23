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
        // --- HYDRATATION EXPLICITE ROBUSTE ---
        // En mode édition, on force le chargement de toutes les données nécessaires aux calculs financiers
        // (HT, TTC, Taxes, Primes) pour éviter les valeurs à 0.00 USD dues aux Proxies non initialisés.
        if ($article && $article->getId()) {
            $article = $this->em->createQueryBuilder()
                ->select('a', 'r', 'tr', 'c', 'char', 'chart', 't', 'p', 'risk', 'i', 'e', 'tc', 'tc_char', 'tc_chart', 'tc_p', 'tc_i', 'tc_e')
                ->from(Article::class, 'a')
                
                // --- BRANCHE REVENU (Pour calculs Commissions & Taxes) ---
                ->leftJoin('a.revenuFacture', 'r')
                ->leftJoin('r.typeRevenu', 'tr')
                ->leftJoin('r.cotation', 'c')
                ->leftJoin('c.chargements', 'char') 
                ->leftJoin('char.type', 'chart')    // INDISPENSABLE: Pour identifier "Prime Nette" vs autres
                ->leftJoin('c.piste', 'p')
                ->leftJoin('p.risque', 'risk')      // INDISPENSABLE: Pour isIARD() -> Taux de Taxe correct
                ->leftJoin('p.invite', 'i')
                ->leftJoin('i.entreprise', 'e')     // INDISPENSABLE: Pour trouver la Taxe configurée
                
                // --- BRANCHE TRANCHE (Pour calculs Primes & Soldes) ---
                ->leftJoin('a.tranche', 't')
                ->leftJoin('t.cotation', 'tc')      // Relation distincte dans Doctrine
                ->leftJoin('tc.chargements', 'tc_char') // INDISPENSABLE: Pour calculer la Prime Totale de la Tranche
                ->leftJoin('tc_char.type', 'tc_chart')
                ->leftJoin('tc.piste', 'tc_p')
                ->leftJoin('tc_p.invite', 'tc_i')
                ->leftJoin('tc_i.entreprise', 'tc_e') // Pour les taxes de la tranche
                
                ->where('a.id = :id')
                ->setParameter('id', $article->getId())
                ->getQuery()
                ->getOneOrNullResult();
        }

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
        return $this->handleFormSubmission(
            $request,
            Article::class,
            ArticleType::class
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
}