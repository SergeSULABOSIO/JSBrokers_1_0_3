<?php

namespace App\Controller\Admin;

use App\Echange\Canevas\CanevasDEchange;
use App\Echange\Service\CompteurDOccurrences;
use App\Echange\Service\ExportateurJsbx;
use App\Entity\EchangeOccurrence;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Utilisateur;
use App\Repository\EchangeImportRunRepository;
use App\Repository\EchangeOccurrenceRepository;
use App\Repository\EntrepriseRepository;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Token\InsufficientTokensException;
use App\Token\TokenAccountService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * @file Rubrique « Importation / Exportation des données » (espace de travail).
 * @description Aller-retour du portefeuille d'un cabinet vers un classeur Excel unique,
 * qui sert à la fois de fichier d'export et de gabarit d'import.
 *
 * Sécurité : forwardToComponent ne valide que l'accès au workspace — ce contrôleur
 * re-vérifie lui-même le périmètre (fail-closed) via WorkspaceAccessResolver sur la
 * pseudo-entité « Echange » (RolesEnAdministration::accessEchange).
 *
 * ⚠ CE DROIT N'EST QU'UNE PORTE. Il ouvre la rubrique ; il n'élargit jamais le
 * périmètre. Ce qui sort réellement du cabinet est refiltré ENTITÉ PAR ENTITÉ par
 * CanevasDEchange, à partir des droits ordinaires du collaborateur. Sans ce second
 * filtrage, un invité au périmètre restreint extrairait tout le cabinet en un clic —
 * un contournement propre de toute la matrice de droits de l'application.
 *
 * Le niveau LECTURE ouvre la consultation ET l'exportation (l'export ne mute rien —
 * même décision que pour les documents comptables) ; l'ÉCRITURE ouvre l'importation.
 */
#[Route('/admin/echange', name: 'admin.echange.')]
#[IsGranted('ROLE_USER')]
class EchangeController extends AbstractController
{
    /** Nom court de la pseudo-entité gouvernant l'accès et le métrage. */
    private const ENTITY_SHORT_NAME = 'Echange';

    private const LIBELLE = 'Importation / Exportation';

    public function __construct(
        private readonly CanevasDEchange $canevas,
        private readonly CompteurDOccurrences $compteur,
        private readonly ExportateurJsbx $exportateur,
        private readonly EchangeOccurrenceRepository $occurrences,
        private readonly EchangeImportRunRepository $importRuns,
        private readonly EntrepriseRepository $entrepriseRepository,
        private readonly WorkspaceAccessResolver $accessResolver,
        private readonly TokenAccountService $tokenAccountService,
    ) {
    }

    /**
     * Rend le composant dans l'espace de travail. Atteint par le « Cerveau » via
     * forwardToComponent (chargement initial), puis rechargé en AJAX au changement
     * d'onglet.
     */
    #[Route('/workspace/{idEntreprise}', name: 'workspace', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function loadWorkspaceComponent(int $idEntreprise, Request $request): Response
    {
        [$entreprise, $invite] = $this->resolveWorkspace($idEntreprise);

        // Fail-closed : le menu n'est que cosmétique, la porte est ici.
        if ($invite === null || !$this->accessResolver->canRead($invite, self::ENTITY_SHORT_NAME)) {
            return $this->render('components/_access_denied.html.twig', [
                'entiteNom' => self::LIBELLE,
            ]);
        }

        // MÉTRAGE TOKENS (lecture) : une consultation = une unité de lecture, comme
        // toute rubrique. À ne pas confondre avec le forfait d'exportation, qui n'est
        // dû qu'au moment de produire un fichier.
        try {
            $this->tokenAccountService->meterRead(self::ENTITY_SHORT_NAME, 1, $entreprise, $this->getUser());
        } catch (InsufficientTokensException $e) {
            return $this->render('components/_tokens_blocked.html.twig', [
                'nextRenewalAt' => $e->nextRenewalAt,
                'required'      => $e->required,
                'available'     => $e->available,
            ]);
        }

        $peutImporter = $this->accessResolver->can($invite, self::ENTITY_SHORT_NAME, Invite::ACCESS_ECRITURE);

        return $this->render('components/_echange_component.html.twig', [
            'idEntreprise'  => $idEntreprise,
            'ongletActif'   => $this->ongletDemande($request, $peutImporter),
            'ressources'    => $this->canevas->ressourcesLisibles($invite),
            'peutImporter'  => $peutImporter,
            // Ce que l'invité peut réellement ÉCRIRE : afficher le périmètre d'import
            // comme s'il était celui de lecture promettrait une importation qui
            // échouerait ligne à ligne au contrôle.
            'ecrivables'    => $peutImporter ? array_keys($this->canevas->ressourcesEcrivables($invite)) : [],
            'facturation'   => $this->compteur->etat($entreprise),
            'controleEnCours' => $this->importRuns->enAttentePour($entreprise, $invite),
            'historique'    => $this->occurrences->historiquePour($entreprise, 50),
            'typeExport'    => EchangeOccurrence::TYPE_EXPORT,
        ]);
    }

    /**
     * EXPORTATION : produit le classeur et le renvoie en téléchargement.
     *
     * GET, et non POST, pour rester un simple lien de téléchargement comme les autres
     * exports de l'application. L'idempotence n'en souffre pas : la clé calculée par le
     * compteur absorbe le rejeu (double clic, requête relancée), si bien qu'un second
     * appel identique dans la même minute ne produit ni seconde occurrence ni second
     * débit.
     *
     * `donnees` liste les codes de ressources demandés, séparés par des virgules ;
     * absent, l'export couvre tout ce que l'invité peut lire.
     */
    #[Route('/export/{idEntreprise}', name: 'export', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET'])]
    public function exporter(int $idEntreprise, Request $request): Response
    {
        [$entreprise, $invite] = $this->resolveWorkspace($idEntreprise);

        // Fail-closed, comme à l'entrée de la rubrique : le lien peut être appelé
        // directement, sans passer par l'écran qui l'a affiché.
        if ($invite === null || !$this->accessResolver->canRead($invite, self::ENTITY_SHORT_NAME)) {
            throw $this->createAccessDeniedException(sprintf('« %s » est hors de votre périmètre d\'accès.', self::LIBELLE));
        }

        $demandes = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $request->query->get('donnees', '')),
        ), static fn (string $code) => $code !== ''));

        try {
            return $this->exportateur->exporter(
                $entreprise,
                $invite,
                $this->getUser(),
                $demandes,
                // Graine d'idempotence : la minute courante, sauf si l'appelant en
                // fournit une (l'assistant passe la sienne pour que sa proposition et
                // le clic de l'utilisateur ne comptent qu'une fois).
                $request->query->get('op'),
            );
        } catch (InsufficientTokensException $e) {
            return new Response(
                sprintf(
                    'Solde de tokens insuffisant : cette exportation coûte %d tokens, il en reste %d. '
                    . 'Rechargez votre solde ou attendez le renouvellement de votre allocation gratuite.',
                    $e->required,
                    $e->available,
                ),
                Response::HTTP_PAYMENT_REQUIRED,
            );
        } catch (\RuntimeException $e) {
            return new Response($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    /**
     * Onglet demandé, ramené à ceux que l'invité a le droit de voir : arriver sur
     * « Importer » sans droit d'écriture afficherait un écran mort.
     */
    private function ongletDemande(Request $request, bool $peutImporter): string
    {
        $onglet = (string) $request->query->get('onglet', 'exporter');
        if ($onglet === 'importer' && !$peutImporter) {
            return 'exporter';
        }

        return in_array($onglet, ['exporter', 'importer', 'historique'], true) ? $onglet : 'exporter';
    }

    /**
     * Charge l'entreprise (404 sinon) et résout l'invité connecté, en refusant tout
     * invité rattaché à une AUTRE entreprise que celle demandée.
     *
     * @return array{0: Entreprise, 1: ?Invite}
     */
    private function resolveWorkspace(int $idEntreprise): array
    {
        $entreprise = $this->entrepriseRepository->find($idEntreprise);
        if (!$entreprise instanceof Entreprise) {
            throw $this->createNotFoundException('Entreprise introuvable.');
        }

        /** @var Utilisateur $user */
        $user = $this->getUser();
        $invite = $this->accessResolver->resolveConnectedInvite($user);
        if ($invite !== null && $invite->getEntreprise()?->getId() !== $entreprise->getId()) {
            $invite = null;
        }

        return [$entreprise, $invite];
    }
}
