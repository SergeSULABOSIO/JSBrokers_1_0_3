<?php

namespace App\Controller\Admin;

use App\Echange\Service\FluxNdjson;
use App\Service\Workspace\ArbreDeSuppression;
use App\Service\Workspace\ExclusionsDeSuppression;
use App\Service\Workspace\ExecutionDuDossier;
use App\Service\Workspace\WorkspaceAccessResolver;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
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

    /**
     * Nombre maximal d'éléments dont on ouvre l'arbre d'un coup.
     *
     * ⚠ CE N'EST PAS UNE LIMITE DE SUPPRESSION. Au-delà, l'arbre ne se lit plus : personne
     * n'arbitre cinquante dossiers branche par branche, et chaque cible coûte son propre
     * plan. La borne protège la lecture, pas la base.
     */
    private const CIBLES_MAX = 20;

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
     * LE MÊME PLAN, MAIS REPLIÉ EN ARBRE — de quoi MONTRER la chaîne au lieu de la compter.
     *
     * L'aperçu ci-dessus sert la corbeille des listes : trois phrases avant de confirmer,
     * c'est ce qu'il lui faut. Pour arbitrer un dossier entier, il faut voir quelle échéance
     * pend de quelle proposition, et pouvoir en épargner une. Même plan, même moteur, même
     * garde d'accès : seule la projection change.
     */
    #[Route('/arbre/{rubrique}/{id}', name: 'arbre', requirements: ['rubrique' => '[A-Za-z_]+', 'id' => Requirement::DIGITS], methods: ['GET'])]
    public function arbre(string $rubrique, int $id, ArbreDeSuppression $arbre): JsonResponse
    {
        $fqcn = $this->classeDeLaRubrique($rubrique);
        if ($fqcn === null) {
            return $this->json(['message' => 'Élément inconnu.'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->mayAccessEntity($fqcn, Invite::ACCESS_SUPPRESSION)) {
            return $this->accessDeniedJson();
        }

        $resultat = $this->searchService->search($fqcn, ['id' => $id], $this->getEntreprise(), null, 1, 1);
        $cible = $resultat['data'][0] ?? null;
        if ($cible === null) {
            return $this->json(['message' => 'Élément introuvable dans cet espace de travail.'], Response::HTTP_NOT_FOUND);
        }

        return $this->json($arbre->depuis($this->mutationService->planifierSuppression($cible)));
    }

    /**
     * LA COQUE DE LA BOÎTE — rendue tout de suite, remplie ensuite.
     *
     * Planifier un dossier coûte une vingtaine de requêtes : les attendre avant d'afficher
     * quoi que ce soit donnerait une seconde de vide après le clic, pendant laquelle
     * l'utilisateur reclique. La coque part donc immédiatement avec sa barre de
     * progression, et l'arbre la rejoint.
     */
    #[Route('/dossier/{rubrique}', name: 'dossier', requirements: ['rubrique' => '[A-Za-z_]+'], methods: ['GET'])]
    public function dossier(string $rubrique, Request $request): Response
    {
        $fqcn = $this->classeDeLaRubrique($rubrique);
        if ($fqcn === null) {
            return $this->json(['message' => 'Élément inconnu.'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->mayAccessEntity($fqcn, Invite::ACCESS_SUPPRESSION)) {
            return $this->accessDeniedJson();
        }

        // ⚠ LA SÉLECTION PEUT PORTER SUR PLUSIEURS LIGNES : c'est le geste de suppression
        // ordinaire, et il a toujours accepté une sélection multiple. La boîte montre alors
        // une forêt — un arbre par élément retenu — plutôt que de refuser le geste.
        $ids = array_values(array_unique(array_filter(
            array_map('intval', explode(',', (string) $request->query->get('ids', ''))),
            static fn (int $id): bool => $id > 0,
        )));
        if ($ids === []) {
            return $this->json(['message' => 'Aucun élément à supprimer.'], Response::HTTP_BAD_REQUEST);
        }
        $ids = array_slice($ids, 0, self::CIBLES_MAX);

        // Chaque cible est cherchée DANS l'entreprise ouverte : une liste d'identifiants
        // forgée ne peut pas faire apparaître le dossier d'un autre cabinet.
        $cibles = [];
        foreach ($ids as $id) {
            $resultat = $this->searchService->search($fqcn, ['id' => $id], $this->getEntreprise(), null, 1, 1);
            $cible = $resultat['data'][0] ?? null;
            if ($cible !== null) {
                $cibles[$id] = $cible;
            }
        }
        if ($cibles === []) {
            return $this->json(['message' => 'Élément introuvable dans cet espace de travail.'], Response::HTTP_NOT_FOUND);
        }

        return $this->renderBoite($rubrique, $cibles, $fqcn);
    }

    /**
     * EXÉCUTE LE TRI : les branches retenues partent, les pièces épargnées sont détachées.
     *
     * ⚠ UNE ROUTE À ELLE, ET NON `handleDeleteApi()`. Le socle des 48 routes prend une
     * entité et planifie lui-même : il n'a nulle part où recevoir des exclusions, et lui en
     * ajouter toucherait les quarante-huit. Les gardes, elles, sont exactement les mêmes.
     *
     * ⚠ ET LA DIFFUSION EST NÉGOCIÉE, comme pour les paliers d'import : sans l'en-tête
     * `Accept`, la réponse reste un objet JSON unique. Les commandes et les tests qui
     * appellent cette route n'ont pas à savoir lire un flux.
     */
    #[Route('/executer/{rubrique}/{id}', name: 'executer', requirements: ['rubrique' => '[A-Za-z_]+', 'id' => Requirement::DIGITS], methods: ['POST'])]
    public function executer(string $rubrique, int $id, Request $request, ExecutionDuDossier $execution): Response
    {
        $fqcn = $this->classeDeLaRubrique($rubrique);
        if ($fqcn === null) {
            return $this->json(['message' => 'Élément inconnu.'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->mayAccessEntity($fqcn, Invite::ACCESS_SUPPRESSION)) {
            return $this->accessDeniedJson();
        }

        $resultat = $this->searchService->search($fqcn, ['id' => $id], $this->getEntreprise(), null, 1, 1);
        $cible = $resultat['data'][0] ?? null;
        if ($cible === null) {
            return $this->json(['message' => 'Élément introuvable dans cet espace de travail.'], Response::HTTP_NOT_FOUND);
        }

        $charge = json_decode((string) $request->getContent(), true) ?: [];
        $lots = array_values(array_filter(
            (array) ($charge['lots'] ?? []),
            static fn ($cle): bool => is_string($cle) && preg_match('/^[A-Za-z]+#\d+$/', $cle) === 1,
        ));
        $exclusions = ExclusionsDeSuppression::depuis($charge['conserver'] ?? []);

        if ($lots === []) {
            return $this->json(['message' => 'Rien n’a été retenu pour suppression.'], Response::HTTP_BAD_REQUEST);
        }

        if (!str_contains((string) $request->headers->get('Accept', ''), 'application/x-ndjson')) {
            return $this->json(['type' => 'resultat'] + $execution->executer($cible, $lots, $exclusions));
        }

        return new StreamedResponse(function () use ($execution, $cible, $lots, $exclusions): void {
            FluxNdjson::demarrer();
            try {
                $rapport = $execution->executer($cible, $lots, $exclusions, static fn (array $ligne) => FluxNdjson::ligne($ligne));
                FluxNdjson::ligne(['type' => 'resultat'] + $rapport);
            } catch (\Throwable $erreur) {
                // ⚠ LE FLUX A DÉJÀ ENVOYÉ SON 200 : un refus ne peut plus voyager dans un
                // code HTTP, il voyage dans la dernière ligne.
                FluxNdjson::ligne([
                    'type'    => 'erreur',
                    'message' => 'La suppression s’est interrompue : ' . $erreur->getMessage(),
                ]);
            }
        }, Response::HTTP_OK, FluxNdjson::entetes());
    }

    /**
     * @param array<int, object> $cibles identifiant => entité
     * @param class-string       $fqcn
     */
    private function renderBoite(string $rubrique, array $cibles, string $fqcn): Response
    {
        $libelle = $this->accessResolver->libellesEntites()[$this->getEntityName($fqcn)] ?? 'Élément';
        $noms = [];
        foreach ($cibles as $id => $cible) {
            $noms[$id] = method_exists($cible, 'getNom') && trim((string) $cible->getNom()) !== ''
                ? (string) $cible->getNom()
                : sprintf('%s n° %d', $libelle, $id);
        }

        return $this->render('components/suppression/_dossier_picker.html.twig', [
            'rubrique' => $rubrique,
            'ids'      => array_keys($cibles),
            'noms'     => $noms,
            'titre'    => count($noms) === 1
                ? sprintf('Supprimer « %s »', reset($noms))
                : sprintf('Supprimer %d éléments', count($noms)),
            'libelle'  => $libelle,
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
