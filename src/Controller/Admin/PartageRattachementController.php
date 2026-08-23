<?php

/**
 * @file Rattacher — ou détacher — la condition de partage d'un agent interne, depuis
 * N'IMPORTE OÙ dans l'arbre d'une affaire.
 *
 * ── LE POINT D'ENTRÉE S'ÉLARGIT, LE POINT D'ÉCRITURE NE BOUGE PAS ───────────────────
 * On travaille depuis la liste des avenants, des propositions ou des tranches — presque
 * jamais depuis la piste. Devoir remonter l'arbre, retrouver l'affaire et ouvrir sa fiche
 * pour reconnaître l'effort d'un agent, c'est un geste qu'on finit par ne plus faire : et
 * l'agent perd sa rétrocommission sans que personne le voie.
 *
 * Ces routes acceptent donc les quatre entités de l'arbre, et remontent elles-mêmes à la
 * PISTE (`EffortCommercialAgent::piste`), où la condition s'écrit — comme avant, au même
 * endroit, avec les mêmes conséquences sur le décompte.
 *
 * ── UNE SEULE ROUTE POUR UN LOT COMME POUR UNE LIGNE ────────────────────────────────
 * Une sélection unique est un lot d'un élément. Deux chemins pour le même geste finiraient
 * par diverger sur le refus — et c'est le refus qui compte ici.
 */

namespace App\Controller\Admin;

use App\Entity\Avenant;
use App\Entity\ConditionPartage;
use App\Entity\Cotation;
use App\Entity\Invite;
use App\Entity\Piste;
use App\Entity\Tranche;
use App\Repository\ConditionPartageRepository;
use App\Repository\EntrepriseRepository;
use App\Repository\InviteRepository;
use App\Service\Partage\EffortCommercialAgent;
use App\Services\CanvasBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/partage', name: 'admin.partage.')]
#[IsGranted('ROLE_USER')]
class PartageRattachementController extends AbstractController
{
    use ControllerUtilsTrait;

    /**
     * Les quatre entités depuis lesquelles on peut ordonner le geste.
     *
     * Une table EXPLICITE, jamais un nom de classe reconstruit depuis l'URL : `{entite}`
     * vient du navigateur, et ne devient une classe qu'après confrontation à cette liste.
     */
    private const ENTITES = [
        'piste'    => Piste::class,
        'cotation' => Cotation::class,
        'avenant'  => Avenant::class,
        'tranche'  => Tranche::class,
    ];

    public function __construct(
        private EntityManagerInterface $em,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private ConditionPartageRepository $conditionRepository,
        private EffortCommercialAgent $effortCommercial,
        CanvasBuilder $canvasBuilder,
    ) {
        $this->canvasBuilder = $canvasBuilder;
    }

    // Aucune collection éditable ni parent : ce contrôleur n'est pas un CRUD, il ne
    // fait que déplacer un rattachement vers l'affaire. Le trait les exige tout de même.
    protected function getCollectionMap(): array
    {
        return [];
    }

    protected function getParentAssociationMap(): array
    {
        return [];
    }

    /**
     * LE PICKER DES CONDITIONS D'AGENT.
     *
     * Fragment HTML, jamais une enveloppe JSON : l'ouvreur commun (`picker-open.js`) lit la
     * réponse en TEXTE et insère son premier élément. Une chaîne JSON n'en contient aucun,
     * et le picker s'ouvrirait vide.
     */
    #[Route('/{entite}/conditions-picker', name: 'conditions_picker', methods: ['GET'])]
    public function conditionsPicker(string $entite, Request $request): Response
    {
        $classe = $this->classeDe($entite);
        if (!$this->mayAccessEntity($classe, Invite::ACCESS_ECRITURE)) {
            throw $this->createAccessDeniedException('Accès refusé.');
        }

        $pistes = $this->pistesDeLaSelection($classe, $this->idsDemandes($request));
        $entreprise = $this->getInvite()->getEntreprise();

        return $this->render('components/partage/_conditions_picker.html.twig', [
            'entite'     => $entite,
            'ids'        => $this->idsDemandes($request),
            'pistes'     => $pistes,
            // Le motif s'il y en a un : le picker le montre AVANT de laisser cliquer, plutôt
            // que de faire découvrir le refus après coup.
            'refus'      => $this->effortCommercial->refusDuLot($pistes),
            'conditions' => $this->conditionsDAgent($entreprise),
            'submitUrl'  => $this->generateUrl('admin.partage.rattacher', ['entite' => $entite]),
            'standalone' => true,
        ]);
    }

    /**
     * RATTACHE, SUR LES PISTES, ET TOUT OU RIEN.
     *
     * Si une seule affaire du lot est déjà prise, rien n'est écrit et le refus la nomme.
     * Appliquer le reste serait pire qu'un refus : l'utilisateur croirait avoir tout
     * couvert, et l'affaire oubliée ne se signalerait jamais.
     */
    #[Route('/{entite}/rattacher', name: 'rattacher', methods: ['POST'])]
    public function rattacher(string $entite, Request $request): JsonResponse
    {
        $classe = $this->classeDe($entite);
        if (!$this->mayAccessEntity($classe, Invite::ACCESS_ECRITURE)) {
            throw $this->createAccessDeniedException('Accès refusé.');
        }

        $donnees = json_decode($request->getContent(), true);
        $ids = array_values(array_filter(array_map('intval', (array) ($donnees['ids'] ?? []))));
        $condition = $this->conditionDAgent((int) ($donnees['conditionId'] ?? 0));

        if ($condition === null) {
            return $this->json(
                ['message' => 'Cette condition de partage n\'existe pas, ou ne désigne aucun agent interne.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $pistes = $this->pistesDeLaSelection($classe, $ids);
        $refus = $this->effortCommercial->refusDuLot($pistes);
        if ($refus !== null) {
            return $this->json(['message' => $refus], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        foreach ($pistes as $piste) {
            $piste->addConditionsPartageAgent($condition);
        }
        $this->em->flush();

        return $this->json([
            'message' => sprintf(
                '%s bénéficie désormais de %d affaire%s (condition « %s »).',
                $condition->getAgent()?->getNom() ?: 'L\'agent',
                count($pistes),
                count($pistes) > 1 ? 's' : '',
                $condition->getNom(),
            ),
            'affaires' => count($pistes),
        ]);
    }

    /**
     * DÉTACHE — si la rétrocommission n'a pas encore été versée.
     *
     * Le refus est rendu tel quel : c'est ainsi que l'utilisateur apprend POURQUOI c'est
     * trop tard, et qu'il comprend du même coup pourquoi il ne peut pas changer d'agent.
     */
    #[Route('/{entite}/{id}/detacher', name: 'detacher', requirements: ['id' => Requirement::DIGITS], methods: ['DELETE'])]
    public function detacher(string $entite, int $id): JsonResponse
    {
        $classe = $this->classeDe($entite);
        if (!$this->mayAccessEntity($classe, Invite::ACCESS_ECRITURE)) {
            throw $this->createAccessDeniedException('Accès refusé.');
        }

        $pistes = $this->pistesDeLaSelection($classe, [$id]);
        $piste = $pistes[0] ?? null;

        $refus = $this->effortCommercial->refusDeDetachement($piste);
        if ($refus !== null) {
            return $this->json(['message' => $refus], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $condition = $this->effortCommercial->condition($piste);
        $agentNom = $condition?->getAgent()?->getNom() ?: 'l\'agent';

        // LES DEUX CANAUX. Une condition exceptionnelle doit pouvoir être défaite comme une
        // réutilisable, sinon le voyant resterait allumé sans qu'on puisse l'éteindre. Elle
        // est DISSOCIÉE, jamais supprimée : c'est peut-être une règle qui sert ailleurs.
        $piste->removeConditionsPartageAgent($condition);
        $piste->removeConditionsPartageExceptionnelle($condition);
        $this->em->flush();

        return $this->json([
            'message' => sprintf(
                'L\'affaire « %s » ne revient plus à %s : elle redevient un effort du cabinet seul.',
                $piste->getNom() ?: ('#' . $piste->getId()),
                $agentNom,
            ),
        ]);
    }

    // ===================== Résolution =====================

    /** @return class-string */
    private function classeDe(string $entite): string
    {
        return self::ENTITES[$entite] ?? throw $this->createNotFoundException('Entité inconnue.');
    }

    /** @return int[] */
    private function idsDemandes(Request $request): array
    {
        $brut = (string) $request->query->get('ids', '');

        return array_values(array_filter(array_map('intval', explode(',', $brut))));
    }

    /**
     * Les AFFAIRES visées par une sélection, dédoublonnées et scopées au cabinet.
     *
     * Deux avenants d'une même police ne font qu'un rattachement : sans ce dédoublonnage,
     * le compte annoncé à l'utilisateur (« 3 affaires ») serait faux, et l'on écrirait deux
     * fois la même chose.
     *
     * @param class-string $classe
     * @param int[]        $ids
     *
     * @return Piste[]
     */
    private function pistesDeLaSelection(string $classe, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $entreprise = $this->getInvite()->getEntreprise();
        $pistes = [];

        foreach ($this->em->getRepository($classe)->findBy(['id' => $ids]) as $objet) {
            // Le cloisonnement par cabinet : sans lui, un identifiant dicté au hasard
            // rattacherait une condition à l'affaire d'une autre entreprise.
            if (method_exists($objet, 'getEntreprise')
                && $objet->getEntreprise()?->getId() !== $entreprise?->getId()) {
                continue;
            }

            $piste = $this->effortCommercial->piste($objet);
            if ($piste !== null && $piste->getId() !== null) {
                $pistes[$piste->getId()] = $piste;
            }
        }

        return array_values($pistes);
    }

    /** Les conditions RÉUTILISABLES d'agents du cabinet — jamais celles d'un partenaire. */
    private function conditionsDAgent($entreprise): array
    {
        $conditions = $this->conditionRepository->createQueryBuilder('c')
            ->join('c.agent', 'a')->addSelect('a')
            ->where('c.entreprise = :entreprise')
            ->setParameter('entreprise', $entreprise)
            ->orderBy('a.nom', 'ASC')
            ->addOrderBy('c.nom', 'ASC')
            ->getQuery()
            ->getResult();

        return $conditions;
    }

    /** La condition dictée, à condition qu'elle soit du cabinet ET pour un agent. */
    private function conditionDAgent(int $id): ?ConditionPartage
    {
        if ($id <= 0) {
            return null;
        }

        $condition = $this->conditionRepository->findOneBy([
            'id' => $id,
            'entreprise' => $this->getInvite()->getEntreprise(),
        ]);

        return $condition?->estPourAgent() === true ? $condition : null;
    }
}
