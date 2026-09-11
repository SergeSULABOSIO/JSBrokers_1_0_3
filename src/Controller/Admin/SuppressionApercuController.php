<?php

namespace App\Controller\Admin;

use App\Service\Workspace\WorkspaceAccessResolver;
use App\Service\Workspace\WorkspaceMutationService;
use App\Entity\Invite;
use App\Repository\EntrepriseRepository;
use App\Repository\InviteRepository;
use App\Services\JSBDynamicSearchService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * CE QUI VA PARTIR, DIT AVANT DE LE FAIRE.
 *
 * On ne peut pas demander à quelqu'un de valider ce qu'on lui cache : effacer une
 * opportunité emporte ses propositions, ses polices, ses échéances, ses commissions, sa
 * facture et son règlement. La boîte de confirmation annonçait « 1 élément ».
 *
 * ⚠ UNE SEULE ROUTE, GÉNÉRIQUE, ET C'EST DÉLIBÉRÉ. La déclarer dans chacun des 48
 * contrôleurs de rubrique serait 48 fois la même dizaine de lignes, avec la certitude
 * qu'une rubrique l'oublierait. L'entité est donc nommée dans l'URL, et résolue par la
 * même carte d'accès qui gouverne tout le reste.
 *
 * ⚠ ET CE N'EST PAS UNE PORTE DÉROBÉE. L'aperçu exige le droit de SUPPRESSION sur
 * l'entité, et la cible est cherchée DANS l'entreprise ouverte (scoping du moteur de
 * recherche) : on ne peut pas s'en servir pour apprendre l'existence d'une donnée qu'on
 * n'aurait pas le droit d'effacer.
 */
#[Route('/admin/suppression', name: 'admin.suppression.')]
#[IsGranted('ROLE_USER')]
class SuppressionApercuController extends AbstractController
{
    use ControllerUtilsTrait;

    public function __construct(
        private EntityManagerInterface $em,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private JSBDynamicSearchService $searchService,
        private WorkspaceAccessResolver $accessResolver,
        private WorkspaceMutationService $mutationService,
    ) {
    }

    protected function getCollectionMap(): array
    {
        return [];
    }

    protected function getParentAssociationMap(): array
    {
        return [];
    }

    /**
     * @param string $rubrique segment d'URL de la rubrique (« piste », « chargementpourprime »),
     *                         tel que l'écran le connaît déjà par `endpoint_delete_url`
     */
    #[Route('/apercu/{rubrique}/{id}', name: 'apercu', requirements: ['rubrique' => '[A-Za-z_]+', 'id' => Requirement::DIGITS], methods: ['GET'])]
    public function apercu(string $rubrique, int $id): JsonResponse
    {
        $fqcn = $this->classeDeLaRubrique($rubrique);
        if ($fqcn === null) {
            return $this->json(['message' => 'Élément inconnu.'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->mayAccessEntity($fqcn, Invite::ACCESS_SUPPRESSION)) {
            return $this->accessDeniedJson();
        }

        // La cible est cherchée DANS l'entreprise ouverte, jamais globalement.
        $resultat = $this->searchService->search($fqcn, ['id' => $id], $this->getEntreprise(), null, 1, 1);
        $cible = $resultat['data'][0] ?? null;
        if ($cible === null) {
            return $this->json(['message' => 'Élément introuvable dans cet espace de travail.'], Response::HTTP_NOT_FOUND);
        }

        $plan = $this->mutationService->planifierSuppression($cible);

        return $this->json([
            'total'         => $plan->total(),
            'portee'        => $plan->portee(),
            'conservations' => $plan->conservations,
            'refus'         => $plan->refus,
        ]);
    }

    /**
     * La classe d'entité derrière un segment d'URL de rubrique, si elle est GOUVERNÉE.
     *
     * Le segment est le nom court en minuscules — c'est la convention de toutes les
     * rubriques de l'espace de travail, et l'écran n'a donc rien de nouveau à connaître.
     * Une entité non gouvernée n'a pas d'aperçu : elle n'a pas non plus de droit, et
     * répondre reviendrait à confirmer l'existence d'une donnée sans contrôle.
     */
    private function classeDeLaRubrique(string $rubrique): ?string
    {
        $cherche = strtolower(str_replace('_', '', $rubrique));

        try {
            $toutes = $this->em->getMetadataFactory()->getAllMetadata();
        } catch (\Throwable) {
            return null;
        }

        foreach ($toutes as $meta) {
            $nom = $meta->getName();
            $court = substr(strrchr('\\' . $nom, '\\') ?: '', 1);
            if (strtolower($court) === $cherche && $this->accessResolver->estGouvernee($court)) {
                return $nom;
            }
        }

        return null;
    }
}
