<?php

namespace App\Controller\Console;

use App\Entity\ErreurApplicative;
use App\Entity\Utilisateur;
use App\Repository\ErreurApplicativeRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * @file La rubrique où l'équipe Joseara voit ce qui casse.
 *
 * @description
 * Les erreurs de Joseara arrivaient jusqu'ici par e-mail, et nulle part
 * ailleurs. Or une boîte aux lettres ne se priorise pas : elle se subit, dans
 * l'ordre d'arrivée, et le défaut rencontré deux cents fois par des courtiers y
 * occupe exactement la même place que celui qui n'est arrivé qu'une fois.
 *
 * Cette rubrique montre l'inverse : une ligne par DÉFAUT, avec le nombre de
 * fois qu'il s'est produit. C'est ce nombre qui dit par quoi commencer.
 *
 * ── CHAQUE LIGNE DIT D'OÙ ELLE VIENT ────────────────────────────────────────
 * Deux informations de source, parce qu'elles appellent deux gestes différents :
 *   · le CÔTÉ (serveur / navigateur) dit dans quel langage aller chercher ;
 *   · la BRANCHE (portail / espace de travail / Console) dit QUI est touché —
 *     un prospect qui n'arrive pas à s'inscrire, un cabinet qui travaille, ou
 *     nous. Les trois n'ont pas la même urgence.
 *
 * Les deux sont déduits côté serveur par {@see \App\Supervision\OrigineErreur},
 * jamais déclarés par l'appelant.
 *
 * ── L'ACCÈS ─────────────────────────────────────────────────────────────────
 * ROLE_ADMIN au niveau de la classe, et le préfixe « console.supervision. »
 * relève de la Direction Générale (qui couvre « console. » en entier). Pour
 * l'ouvrir à un autre département, une ligne à ajouter dans
 * {@see \App\Enum\Departement::routePrefixes()}.
 */
#[Route('/console/supervision', name: 'console.supervision.')]
#[IsGranted('ROLE_ADMIN')]
class SupervisionController extends AbstractConsoleController
{
    public function __construct(
        private ErreurApplicativeRepository $depot,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request, LocaleSwitcher $localeSwitcher): Response
    {
        $this->applyLangPreference($request, $localeSwitcher);

        // Filtres en query string (motif des listes de la Console : formulaire
        // GET, pas de FormType), pour qu'un lien vers une vue filtrée puisse
        // être collé dans un message d'équipe et rouvrir la même chose.
        $filtres = [
            'cote'    => $request->query->get('cote'),
            'branche' => $request->query->get('branche'),
            'statut'  => $request->query->get('statut'),
            'q'       => $request->query->get('q'),
        ];

        return $this->render('console/supervision/index.html.twig', [
            'pageName' => 'Supervision des erreurs',
            'pageIcon' => 'action:alert',
            'erreurs'  => $this->depot->paginateFiltered($filtres, $request->query->getInt('page', 1)),
            'totaux'   => $this->depot->totaux($filtres),
            'filtres'  => $filtres,
            'cotes'    => ErreurApplicative::COTES,
            'branches' => ErreurApplicative::BRANCHES,
            'statuts'  => ErreurApplicative::STATUTS,
        ]);
    }

    /**
     * Le détail : la trace, et le contexte de la dernière occurrence.
     *
     * C'est la page qu'on ouvre pour REPRODUIRE. D'où le choix de garder, à
     * chaque occurrence, le contexte de la dernière et non celui de la
     * première : c'est le plus récent qu'on a une chance de retrouver.
     */
    #[Route('/{id}', name: 'detail', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function detail(Request $request, ErreurApplicative $erreur, LocaleSwitcher $localeSwitcher): Response
    {
        $this->applyLangPreference($request, $localeSwitcher);

        return $this->render('console/supervision/detail.html.twig', [
            'pageName' => 'Défaut nº' . $erreur->getId(),
            'pageIcon' => 'action:alert',
            'erreur'   => $erreur,
            'statuts'  => ErreurApplicative::STATUTS,
        ]);
    }

    /**
     * Trancher : en cours, résolue, ignorée — ou rouvrir.
     *
     * ⚠ Le statut n'est PAS qu'un affichage. Il commande deux mécanismes :
     *   · la PURGE n'efface que ce qui est tranché (résolu ou ignoré), jamais
     *     ce qui est ouvert ;
     *   · la RÉGRESSION ne se détecte que sur un défaut marqué résolu — c'est
     *     sa réapparition qui prouve que le correctif n'a pas tenu, et elle
     *     part alors par e-mail.
     * Marquer « résolue » une erreur qu'on n'a pas corrigée revient donc à
     * armer une fausse alerte, et à autoriser l'effacement de sa trace.
     */
    #[Route('/{id}/statut', name: 'statut', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function changerStatut(Request $request, ErreurApplicative $erreur): Response
    {
        $this->verifierJeton($request, $erreur);

        $statut = (string) $request->request->get('statut');
        if (!in_array($statut, ErreurApplicative::STATUTS, true)) {
            throw $this->createNotFoundException('Statut inconnu.');
        }

        $erreur->setStatut($statut);

        // « En cours » sans personne en face n'engage personne : celui qui
        // ouvre le dossier le prend, sauf s'il appartient déjà à quelqu'un.
        $agent = $this->getUser();
        if (ErreurApplicative::STATUT_EN_COURS === $statut
            && null === $erreur->getAssigneA()
            && $agent instanceof Utilisateur) {
            $erreur->setAssigneA($agent);
        }

        $this->em->flush();
        $this->addFlash('success', sprintf('Défaut nº%d : %s.', $erreur->getId(), $statut));

        return $this->retour($request);
    }

    /** Prendre en charge, ou rendre le défaut à la pile commune. */
    #[Route('/{id}/assignation', name: 'assignation', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function assigner(Request $request, ErreurApplicative $erreur): Response
    {
        $this->verifierJeton($request, $erreur);

        $agent = $this->getUser();
        $prendre = '1' === (string) $request->request->get('prendre');

        $erreur->setAssigneA($prendre && $agent instanceof Utilisateur ? $agent : null);
        $this->em->flush();

        $this->addFlash('success', $prendre ? 'Défaut pris en charge.' : 'Défaut libéré.');

        return $this->retour($request);
    }

    private function verifierJeton(Request $request, ErreurApplicative $erreur): void
    {
        if (!$this->isCsrfTokenValid('supervision-' . $erreur->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
    }

    /**
     * Retour à la vue d'où l'on vient, filtres et page compris.
     *
     * L'URL de retour vient du formulaire, donc du client : on vérifie qu'elle
     * désigne bien cette rubrique avant de l'utiliser. Une redirection dont la
     * cible est dictée par l'extérieur est une redirection ouverte, et celle-ci
     * est atteignable par un agent authentifié qu'on aurait hameçonné.
     */
    private function retour(Request $request): Response
    {
        $retour = (string) $request->request->get('retour', '');

        if (str_starts_with($retour, '/console/supervision')) {
            return $this->redirect($retour);
        }

        return $this->redirectToRoute('console.supervision.index');
    }
}
