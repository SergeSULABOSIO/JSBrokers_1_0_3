<?php

namespace App\Controller\Admin;

use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Utilisateur;
use App\Repository\EntrepriseRepository;
use App\Repository\InviteRepository;
use App\Service\Onboarding\OnboardingCatalogue;
use App\Service\Onboarding\OnboardingCompletude;
use App\Services\CanvasBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * LE GUIDE DE DÉMARRAGE DU CABINET.
 *
 * ── UN ATELIER, PAS UN COULOIR ──────────────────────────────────────────────────────
 * Le courtier reste ici du début à la fin. Chaque carte montre ce qui est déjà enregistré
 * et ouvre, par-dessus le panneau, LE SEUL dialogue dont il a besoin. On n'ouvre jamais la
 * rubrique complète : quinze allers-retours lui feraient perdre le fil de sa progression,
 * et c'est précisément ce fil qui fait le guide.
 *
 * ── AUCUN FORMULAIRE N'EST RECRÉÉ ───────────────────────────────────────────────────
 * Ce contrôleur ne rend pas de formulaire : il rend les CANEVAS des dialogues existants,
 * que le contrôleur Stimulus remet au gestionnaire de dialogues. Un canevas suffit à ouvrir
 * une fiche — ni liste, ni onglet actif — et le formulaire, sa validation et son
 * enregistrement restent ceux de la rubrique, à l'identique.
 *
 * ── LE GATING TIENT EN UNE PHRASE ───────────────────────────────────────────────────
 * Configurer le cabinet est l'affaire de son PROPRIÉTAIRE. Rien à déclarer dans
 * WorkspaceAccessResolver::MAP, aucune rubrique dans menu.yaml : le voyant de la colonne 1
 * est la porte d'entrée, et `acces()` ci-dessous refuse tout le reste.
 *
 * Les canevas ne passent volontairement PAS par `applyPermissionToFormCanvas` : ce filtre
 * retire les URL d'écriture à qui n'a pas le droit, et le propriétaire les a toutes — il
 * ne retirerait donc jamais rien ici. Le filet de sécurité réel est ailleurs, et il est
 * déjà tendu : `renderFormCanvas` exige l'Écriture en création et la Modification en
 * édition, à chaque ouverture de dialogue comme à chaque soumission.
 */
#[Route('/admin/onboarding', name: 'admin.onboarding.')]
#[IsGranted('ROLE_USER')]
class OnboardingWorkspaceController extends AbstractController
{
    public function __construct(
        private OnboardingCompletude $completude,
        private OnboardingCatalogue $catalogue,
        private CanvasBuilder $canvasBuilder,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
    ) {
    }

    /**
     * Rend le guide dans l'espace de travail. Atteint par le « Cerveau » via
     * forwardToComponent, qui a déjà validé l'accès au workspace en amont.
     */
    #[Route('/workspace/{idEntreprise}/{idInvite}', name: 'workspace', requirements: ['idEntreprise' => Requirement::DIGITS, 'idInvite' => Requirement::DIGITS], methods: ['GET'])]
    public function loadWorkspaceComponent(int $idEntreprise, int $idInvite): Response
    {
        [$entreprise, $invite] = $this->acces($idEntreprise, $idInvite);

        return $this->render('components/_onboarding_component.html.twig', $this->parametres($entreprise, $invite));
    }

    /**
     * L'état après un enregistrement : le panneau ET le voyant, rendus par les mêmes
     * partiels que le premier affichage.
     *
     * Deux fragments dans une seule réponse, parce qu'un seul aurait menti : créer un
     * assureur fait bouger la carte du panneau ET le pourcentage de la colonne 1. Les
     * rafraîchir en deux requêtes aurait laissé une fenêtre où l'un contredit l'autre.
     */
    #[Route('/api/etat/{idEntreprise}/{idInvite}', name: 'api.etat', requirements: ['idEntreprise' => Requirement::DIGITS, 'idInvite' => Requirement::DIGITS], methods: ['GET'])]
    public function etat(int $idEntreprise, int $idInvite): JsonResponse
    {
        [$entreprise, $invite] = $this->acces($idEntreprise, $idInvite);
        $parametres = $this->parametres($entreprise, $invite);

        return $this->json([
            'score' => $parametres['bilan']['score'],
            'complet' => $parametres['bilan']['complet'],
            'panneau' => $this->renderView('components/_onboarding_etapes.html.twig', $parametres),
            'voyant' => $this->renderView('components/_onboarding_rappel.html.twig', $parametres + ['variante' => 'voyant']),
        ]);
    }

    /**
     * Le bilan, les canevas de dialogue et le contexte du workspace.
     *
     * @return array<string, mixed>
     */
    private function parametres(Entreprise $entreprise, Invite $invite): array
    {
        $bilan = $this->completude->pour($entreprise);

        return [
            'bilan' => $bilan,
            // Le découpage en sections est déclaré avec les poids qui le définissent, pas
            // recopié dans le gabarit : deux endroits qui décident du même regroupement
            // finissent toujours par ne plus dire la même chose.
            'sections' => $this->catalogue->sections(),
            'canvasParEtape' => $this->canvasParEtape($bilan['etapes'], (int) $entreprise->getId()),
            'idEntreprise' => $entreprise->getId(),
            'idInvite' => $invite->getId(),
            'entrepriseNom' => $entreprise->getNom(),
        ];
    }

    /**
     * Un canevas de dialogue par étape, indexé par sa clé.
     *
     * Ils partent tous d'un coup, dans un attribut unique du panneau : quinze attributs
     * — un par carte — auraient répété quinze fois le même contexte d'entreprise.
     *
     * @param array<int, array<string, mixed>> $etapes
     *
     * @return array<string, array<string, mixed>>
     */
    private function canvasParEtape(array $etapes, int $idEntreprise): array
    {
        $canvas = [];

        foreach ($etapes as $etape) {
            $classe = $etape['entite'];

            try {
                $canvas[$etape['cle']] = $this->canvasBuilder->getEntityFormCanvas(new $classe(), $idEntreprise);
            } catch (\Throwable) {
                // Une entité sans provider de formulaire ne doit pas emporter tout le
                // guide : sa carte s'affichera sans bouton d'ouverture, ce qui se voit —
                // là où une page blanche ne dirait rien de la cause.
                $canvas[$etape['cle']] = [];
            }
        }

        return $canvas;
    }

    /**
     * L'invité doit être celui de l'utilisateur connecté, appartenir à cette entreprise,
     * et en être le PROPRIÉTAIRE. Les trois conditions, et pas deux : sans la première,
     * un identifiant d'invité deviné suffirait à lire la configuration d'un autre cabinet.
     *
     * @return array{0: Entreprise, 1: Invite}
     */
    private function acces(int $idEntreprise, int $idInvite): array
    {
        $entreprise = $this->entrepriseRepository->find($idEntreprise)
            ?? throw $this->createNotFoundException("L'entreprise n'a pas été trouvée.");
        $invite = $this->inviteRepository->find($idInvite)
            ?? throw $this->createNotFoundException("L'invité n'a pas été trouvé.");

        /** @var Utilisateur|null $user */
        $user = $this->getUser();

        if ($user === null
            || $invite->getUtilisateur()?->getId() !== $user->getId()
            || $invite->getEntreprise()?->getId() !== $entreprise->getId()
            || $invite->isProprietaire() !== true
        ) {
            throw $this->createAccessDeniedException('La configuration du cabinet est réservée à son propriétaire.');
        }

        return [$entreprise, $invite];
    }
}
