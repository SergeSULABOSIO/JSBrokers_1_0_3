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
 * PISTE (`RattachementDuPartage::piste`), où la condition s'écrit — comme avant, au même
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
use App\Service\Partage\RattachementDuPartage;
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
        private RattachementDuPartage $rattachement,
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
            // CE QUE CHAQUE AFFAIRE PORTE DÉJÀ. Le refus du lot dépend de la condition
            // choisie — sa famille décide de la place qu'elle prétend occuper — et ne peut
            // donc pas être calculé d'avance. Montrer l'occupation laisse voir le conflit
            // sans prétendre le deviner.
            'occupations' => $this->occupationsDesAffaires($pistes),
            // CHAQUE LIGNE PORTE SON PROPRE VERBE, et c'est ce qui fait disparaître le mode
            // « détacher » : le picker n'a plus à savoir ce qu'on est venu faire, chaque
            // condition le dit d'elle-même selon qu'elle est déjà posée ou non.
            'conditions' => $this->conditionsAvecLeurEtat($entreprise, $pistes),
            'urlRattacher' => $this->generateUrl('admin.partage.rattacher', ['entite' => $entite]),
            'urlDetacher'  => $this->generateUrl('admin.partage.detacher', ['entite' => $entite]),
            'standalone' => true,
        ]);
    }

    /**
     * LES CONDITIONS DU CABINET, CHACUNE AVEC SON ÉTAT SUR LA SÉLECTION.
     *
     * Le picker proposait « Rattacher ici » sur une condition DÉJÀ rattachée à l'affaire.
     * Le clic partait au serveur pour revenir avec un refus qui nommait cette même
     * condition — « détachez la condition en place avant d'en rattacher une autre », où la
     * condition en place ÉTAIT celle qu'on venait de choisir. Le geste ne doit pas être
     * offert.
     *
     * Trois états, donc trois verbes. Le troisième — rattachée à une PARTIE de la
     * sélection — est le seul qui demandait une décision : compléter est le geste utile, et
     * c'est déjà ce que fait le serveur, le rattachement étant idempotent affaire par
     * affaire.
     *
     * @param Piste[] $pistes
     *
     * @return array<int, array{condition: ConditionPartage, couvertes: int, total: int, etat: string}>
     */
    private function conditionsAvecLeurEtat($entreprise, array $pistes): array
    {
        $total = count($pistes);

        // Les identifiants rattachés, par affaire — UNE traversée, pas une par condition.
        $rattachees = [];
        foreach ($pistes as $piste) {
            foreach ($this->rattachement->conditions($piste) as $condition) {
                $rattachees[(int) $condition->getId()] = ($rattachees[(int) $condition->getId()] ?? 0) + 1;
            }
        }

        $lignes = [];
        foreach ($this->conditionsRattachables($entreprise) as $condition) {
            $couvertes = $rattachees[(int) $condition->getId()] ?? 0;
            $lignes[] = [
                'condition' => $condition,
                'couvertes' => $couvertes,
                'total'     => $total,
                'etat'      => match (true) {
                    $couvertes === 0 => 'libre',
                    $couvertes >= $total => 'rattachee',
                    default => 'partielle',
                },
            ];
        }

        return $lignes;
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
        $condition = $this->conditionDuCabinet((int) ($donnees['conditionId'] ?? 0));

        if ($condition === null) {
            return $this->json(
                ['message' => 'Cette condition de partage n\'existe pas dans votre espace de travail.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $pistes = $this->pistesDeLaSelection($classe, $ids);
        $refus = $this->rattachement->refusDuLot($pistes, $condition);
        if ($refus !== null) {
            return $this->json(['message' => $refus], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $designees = [];
        foreach ($pistes as $piste) {
            $piste->addConditionsPartageAgent($condition);
            // LA DÉSIGNATION D'INTERMÉDIAIRE, quand l'affaire n'en a pas. Sans elle, une
            // condition de partenaire serait écrite sans jamais rien produire : le calcul
            // ne retient que les conditions de l'apporteur désigné.
            if ($this->rattachement->designerIntermediaire($piste, $condition)) {
                $designees[] = $piste->getNom() ?: ('#' . $piste->getId());
            }
        }
        $this->em->flush();

        $estAgent = $condition->estPourAgent();
        $nom = ($estAgent ? $condition->getAgent()?->getNom() : $condition->getPartenaire()?->getNom())
            ?: ($estAgent ? 'L\'agent' : 'L\'intermédiaire');

        return $this->json([
            'message' => sprintf(
                '%s bénéficie désormais de %d affaire%s (condition « %s »).%s',
                $nom,
                count($pistes),
                count($pistes) > 1 ? 's' : '',
                $condition->getNom(),
                // L'ÉCRITURE IMPLICITE SE DIT. Poser l'apporteur change qui touche
                // l'argent : la laisser découvrir serait pire que de la refuser.
                $designees === [] ? '' : sprintf(
                    ' %s devient aussi l\'intermédiaire de %s, qui n\'en avai%s aucun.',
                    $nom,
                    count($designees) > 1 ? count($designees) . ' de ces affaires' : '« ' . $designees[0] . ' »',
                    count($designees) > 1 ? 'ent' : 't',
                ),
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
    #[Route('/{entite}/detacher', name: 'detacher', methods: ['POST'])]
    public function detacher(string $entite, Request $request): JsonResponse
    {
        $classe = $this->classeDe($entite);
        if (!$this->mayAccessEntity($classe, Invite::ACCESS_ECRITURE)) {
            throw $this->createAccessDeniedException('Accès refusé.');
        }

        $donnees = json_decode($request->getContent(), true);
        $ids = array_values(array_filter(array_map('intval', (array) ($donnees['ids'] ?? []))));
        $condition = $this->conditionDuCabinet((int) ($donnees['conditionId'] ?? 0));

        if ($condition === null) {
            return $this->json(
                ['message' => 'Cette condition de partage n\'existe pas dans votre espace de travail.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $pistes = $this->pistesDeLaSelection($classe, $ids);

        // TOUT OU RIEN, comme au rattachement : si une seule affaire est scellée par un
        // versement, on n'en détache aucune. Défaire la moitié d'un lot laisserait
        // l'utilisateur croire que tout est fait.
        foreach ($pistes as $piste) {
            $refus = $this->rattachement->refusDeDetachement($piste, $condition);
            if ($refus !== null) {
                return $this->json(['message' => $refus], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }
        if ($pistes === []) {
            return $this->json(
                ['message' => 'Aucune affaire à détacher : la sélection n\'a rien donné.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $estAgent = $condition->estPourAgent();
        $nom = ($estAgent ? $condition->getAgent()?->getNom() : $condition->getPartenaire()?->getNom())
            ?: ($estAgent ? 'l\'agent' : 'l\'intermédiaire');

        // LES DEUX CANAUX. Une condition exceptionnelle doit pouvoir être défaite comme une
        // réutilisable, sinon le voyant resterait allumé sans qu'on puisse l'éteindre. Elle
        // est DISSOCIÉE, jamais supprimée : c'est peut-être une règle qui sert ailleurs.
        //
        // ⚠ ON NE RETIRE PAS `Piste::partenaire`. Détacher une condition défait la RÈGLE de
        // rémunération, pas le fait que cette affaire a été apportée : l'apporteur reste, et
        // sa part habituelle reprend ses droits. Effacer la désignation supprimerait une
        // donnée saisie sans le dire — c'est déjà la règle de `setPartenaire(null)`.
        foreach ($pistes as $piste) {
            $piste->removeConditionsPartageAgent($condition);
            $piste->removeConditionsPartageExceptionnelle($condition);
        }
        $this->em->flush();

        return $this->json([
            'message' => sprintf(
                '%d affaire%s ne revien%s plus à %s (condition « %s »).',
                count($pistes),
                count($pistes) > 1 ? 's' : '',
                count($pistes) > 1 ? 'nent' : 't',
                $nom,
                $condition->getNom(),
            ),
            'affaires' => count($pistes),
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

            $piste = $this->rattachement->piste($objet);
            if ($piste !== null && $piste->getId() !== null) {
                $pistes[$piste->getId()] = $piste;
            }
        }

        return array_values($pistes);
    }

    /** Les conditions RÉUTILISABLES d'agents du cabinet — jamais celles d'un partenaire. */
    private function conditionsRattachables($entreprise): array
    {
        // LES DEUX FAMILLES, et donc des jointures EXTERNES : une jointure interne sur
        // l'agent écartait toutes les conditions de partenaire — c'était le filtre qui
        // rendait le geste agent-only. On garde aussi celles dont le bénéficiaire a été
        // supprimé : on veut pouvoir les voir pour les corriger.
        return $this->conditionRepository->createQueryBuilder('c')
            ->leftJoin('c.agent', 'a')->addSelect('a')
            ->leftJoin('c.partenaire', 'p')->addSelect('p')
            ->where('c.entreprise = :entreprise')
            ->setParameter('entreprise', $entreprise)
            ->orderBy('c.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }


    /**
     * CE QUE CHAQUE AFFAIRE PORTE DÉJÀ, pour que le picker le montre AVANT le clic.
     *
     * Le refus dépend maintenant de la condition choisie — sa famille décide de la place
     * qu'elle prétend occuper — et ne peut donc plus être calculé d'avance. Montrer les
     * occupations vaut mieux : l'utilisateur voit le conflit au lieu de le découvrir.
     *
     * @param Piste[] $pistes
     *
     * @return array<int, array{affaire: string, partage: ?string, apporteur: ?string}>
     */
    private function occupationsDesAffaires(array $pistes): array
    {
        $occupations = [];
        foreach ($pistes as $piste) {
            $occupations[] = [
                'affaire'   => $piste->getNom() ?: ('#' . $piste->getId()),
                'partage'   => $this->rattachement->libelle($piste),
                'apporteur' => $piste->getPartenaire()?->getNom(),
            ];
        }

        return $occupations;
    }

    /** La condition dictée, à condition qu'elle soit du cabinet. La FAMILLE ne filtre plus. */
    private function conditionDuCabinet(int $id): ?ConditionPartage
    {
        if ($id <= 0) {
            return null;
        }

        return $this->conditionRepository->findOneBy([
            'id' => $id,
            'entreprise' => $this->getInvite()->getEntreprise(),
        ]);
    }
}
