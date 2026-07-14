<?php

namespace App\Services\Canvas;
use App\Entity\Utilisateur;
use App\Repository\EntrepriseRepository;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Services\Canvas\Provider\Form\FormCanvasProviderInterface;
use App\Token\TokenAccountService;
use Doctrine\Persistence\Proxy;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

class FormCanvasProvider
{
    /**
     * @var FormCanvasProviderInterface[]
     */
    private iterable $providers;

    /** Mémoïsation par entreprise (le service vit le temps d'une requête). */
    private array $assistantDispo = [];

    public function __construct(
        #[TaggedIterator('app.form_canvas_provider')] iterable $providers,
        private readonly Security $security,
        private readonly WorkspaceAccessResolver $accessResolver,
        private readonly TokenAccountService $tokenAccountService,
        private readonly EntrepriseRepository $entrepriseRepository,
    ) {
        $this->providers = $providers;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        // Proxy-safe : une association lazy (ex. Avenant::pisteDeRenouvellement) est un
        // proxy Doctrine dont get_class() renvoie « Proxies\__CG__\… », qui ne matche
        // aucun supports(). On remonte à la classe réelle (le proxy étend l'entité).
        $entityClassName = $object instanceof Proxy ? get_parent_class($object) : get_class($object);

        foreach ($this->providers as $provider) {
            if ($provider->supports($entityClassName)) {
                $canvas = $provider->getCanvas($object, $idEntreprise);
                $this->injecterActionAssistant($canvas, $entityClassName, $idEntreprise);

                return $canvas;
            }
        }

        // If no specific provider is found, return an empty array.
        return [];
    }

    /**
     * Action transverse « Ajouter au chat avec l'assistant IA » : injectée
     * centralement pour TOUTES les entités des listes du workspace (une seule
     * déclaration → toolbar + menu contextuel, drapeau multi = multi-sélection).
     * Ce gating n'est que cosmétique : les endpoints contexte re-valident tout
     * (fail-closed). L'URL reste vide, le cerveau construit la charge depuis la
     * sélection.
     */
    private function injecterActionAssistant(array &$canvas, string $fqcn, ?int $idEntreprise): void
    {
        $shortName = substr($fqcn, (int) strrpos($fqcn, '\\') + 1);
        if ($idEntreprise === null
            || !isset($this->accessResolver->libellesEntites()[$shortName])
            || !$this->assistantDisponible($idEntreprise)) {
            return;
        }

        $canvas['parametres']['attribute_actions'][] = [
            'label' => "Ajouter au chat avec l'assistant IA",
            'icon'  => 'assistant-ia',
            'event' => 'ui:assistant.add-to-chat',
            'url'   => '',
            'multi' => true,
        ];
    }

    /**
     * L'assistant IA est-il disponible pour l'utilisateur courant dans cette
     * entreprise ? Compte payant + accès au MODULE (pseudo-entité AssistantIa).
     * Fail-closed sans utilisateur (CLI, worker) ou hors du workspace demandé.
     */
    private function assistantDisponible(int $idEntreprise): bool
    {
        return $this->assistantDispo[$idEntreprise] ??= (function () use ($idEntreprise): bool {
            $user = $this->security->getUser();
            if (!$user instanceof Utilisateur) {
                return false;
            }
            $invite = $this->accessResolver->resolveConnectedInvite($user);
            if ($invite === null || $invite->getEntreprise()?->getId() !== $idEntreprise) {
                return false;
            }
            if (!$this->accessResolver->canRead($invite, 'AssistantIa')) {
                return false;
            }
            $entreprise = $this->entrepriseRepository->find($idEntreprise);

            return $entreprise !== null && $this->tokenAccountService->estComptePayant($entreprise);
        })();
    }
}
