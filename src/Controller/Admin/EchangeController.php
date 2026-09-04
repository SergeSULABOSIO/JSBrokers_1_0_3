<?php

namespace App\Controller\Admin;

use App\Echange\Canevas\CanevasDEchange;
use App\Echange\Service\CompteurDOccurrences;
use App\Echange\Service\ExportateurJsbx;
use App\Echange\Service\FluxNdjson;
use App\Echange\Service\Progression;
use App\Echange\Service\ImportImpossibleException;
use App\Echange\Service\ImportateurJsbx;
use App\Entity\EchangeImportRun;
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
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
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
        private readonly ImportateurJsbx $importateur,
        private readonly EchangeOccurrenceRepository $occurrences,
        private readonly EchangeImportRunRepository $importRuns,
        private readonly EntrepriseRepository $entrepriseRepository,
        private readonly WorkspaceAccessResolver $accessResolver,
        private readonly TokenAccountService $tokenAccountService,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
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
     * EXPORTATION DIFFUSÉE : la progression RÉELLE, puis un jeton de téléchargement.
     *
     * ⚠ POURQUOI DIFFUSER PLUTÔT QUE FAIRE SONDER. La façon habituelle d'animer une
     * barre est de lancer le travail d'un côté et d'en interroger l'état de l'autre.
     * Cela suppose deux requêtes servies EN MÊME TEMPS ; le serveur de développement de
     * ce projet n'a qu'UN processus PHP. Le sondage attendrait donc la fin de ce qu'il
     * interroge, et la barre resterait figée jusqu'à ce qu'elle n'ait plus rien à dire.
     *
     * On envoie donc la progression DANS la requête qui travaille : une ligne JSON par
     * étape, puis une dernière ligne portant le jeton. Aucune infrastructure nouvelle,
     * et cela vaut aussi bien pour un processus que pour cent.
     *
     * Le classeur est déposé sur disque et retiré au téléchargement : on ne peut pas
     * mêler des octets binaires à un flux de lignes JSON sans les encoder, et encoder un
     * classeur de plusieurs mégaoctets en base64 coûterait plus cher que de l'écrire.
     */
    #[Route('/exporter/{idEntreprise}', name: 'exporter_diffuse', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['POST'])]
    public function exporterDiffuse(int $idEntreprise, Request $request): StreamedResponse
    {
        [$entreprise, $invite] = $this->resolveWorkspace($idEntreprise);

        $demandes = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $request->request->get('donnees', '')),
        ), static fn (string $code) => $code !== ''));

        $graine = (string) $request->request->get('op', '');
        $acteur = $this->getUser();

        $reponse = new StreamedResponse(function () use ($entreprise, $invite, $acteur, $demandes, $graine): void {
            // Le contrôle de droits est DANS le flux : une réponse diffusée a déjà envoyé
            // son code 200 quand on découvre le refus, et une exception n'aurait plus
            // aucun moyen de le dire. On l'écrit donc comme une ligne de résultat.
            FluxNdjson::demarrer();

            if ($invite === null || !$this->accessResolver->canRead($invite, self::ENTITY_SHORT_NAME)) {
                FluxNdjson::ligne([
                    'type'    => 'erreur',
                    'message' => sprintf('« %s » est hors de votre périmètre d\'accès.', self::LIBELLE),
                ]);

                return;
            }

            $progression = new Progression(0, static fn (array $etat) => FluxNdjson::ligne($etat));

            try {
                $reponseFichier = $this->exportateur->exporter(
                    $entreprise,
                    $invite,
                    $acteur,
                    $demandes,
                    $graine !== '' ? $graine : null,
                    $progression,
                );

                // Le classeur est déjà produit et l'occurrence enregistrée : il ne reste
                // qu'à le poser où le téléchargement ira le chercher.
                [$jeton, $nom] = $this->deposerExport($reponseFichier, $entreprise);

                $progression->terminer();
                FluxNdjson::ligne(['type' => 'pret', 'jeton' => $jeton, 'nom' => $nom]);
            } catch (InsufficientTokensException $e) {
                FluxNdjson::ligne([
                    'type'    => 'erreur',
                    'message' => sprintf(
                        'Solde de tokens insuffisant : cette exportation coûte %d tokens, il en reste %d.',
                        $e->required,
                        $e->available,
                    ),
                ]);
            } catch (\Throwable $e) {
                FluxNdjson::ligne(['type' => 'erreur', 'message' => $e->getMessage()]);
            }
        });

        foreach (FluxNdjson::entetes() as $nom => $valeur) {
            $reponse->headers->set($nom, $valeur);
        }

        return $reponse;
    }

    /**
     * TÉLÉCHARGEMENT du classeur préparé, puis effacement.
     *
     * Le jeton est un nom de fichier aléatoire dans un répertoire propre au cabinet : il
     * ne se devine pas, et il ne survit pas à sa lecture — un export contient des
     * données personnelles, il n'a rien à faire sur le disque une fois remis.
     */
    #[Route('/telecharger/{idEntreprise}/{jeton}', name: 'telecharger', requirements: ['idEntreprise' => Requirement::DIGITS, 'jeton' => '[a-f0-9]{32}'], methods: ['GET'])]
    public function telecharger(int $idEntreprise, string $jeton): Response
    {
        [$entreprise, $invite] = $this->resolveWorkspace($idEntreprise);

        if ($invite === null || !$this->accessResolver->canRead($invite, self::ENTITY_SHORT_NAME)) {
            throw $this->createAccessDeniedException(sprintf('« %s » est hors de votre périmètre d\'accès.', self::LIBELLE));
        }

        $chemin = $this->repertoireExports($entreprise) . '/' . $jeton . '.xlsx';
        if (!is_file($chemin)) {
            throw $this->createNotFoundException('Ce fichier a expiré ou a déjà été téléchargé.');
        }

        $nom = $this->nomExport($entreprise);
        $reponse = new StreamedResponse(static function () use ($chemin): void {
            readfile($chemin);
            @unlink($chemin);
        });
        $reponse->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $reponse->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $nom));
        $reponse->headers->set('Content-Length', (string) filesize($chemin));
        $reponse->headers->set('Cache-Control', 'no-store, private');

        return $reponse;
    }

    /**
     * Écrit le classeur produit dans le dépôt d'exports et rend son jeton.
     *
     * @return array{0: string, 1: string} jeton, nom de fichier proposé
     */
    private function deposerExport(Response $reponseFichier, Entreprise $entreprise): array
    {
        $repertoire = $this->repertoireExports($entreprise);
        if (!is_dir($repertoire) && !mkdir($repertoire, 0775, true) && !is_dir($repertoire)) {
            throw new \RuntimeException('Impossible de préparer le dépôt de l\'export.');
        }

        $jeton = bin2hex(random_bytes(16));

        // La réponse produite par l'exportateur écrit dans le flux de sortie : on la
        // capture pour l'écrire sur disque, sans la mêler au flux de progression.
        ob_start();
        $reponseFichier->sendContent();
        file_put_contents($repertoire . '/' . $jeton . '.xlsx', (string) ob_get_clean());

        return [$jeton, $this->nomExport($entreprise)];
    }

    private function repertoireExports(Entreprise $entreprise): string
    {
        return sprintf('%s/var/echange/exports/%d', $this->projectDir, $entreprise->getId());
    }

    private function nomExport(Entreprise $entreprise): string
    {
        $slug = preg_replace('/[^A-Za-z0-9_-]+/', '_', $entreprise->getNom() ?? 'cabinet');

        return sprintf('jsbrokers_%s_%s.xlsx', trim((string) $slug, '_') ?: 'cabinet', date('Ymd-Hi'));
    }

    /**
     * DÉPÔT + CONTRÔLE À BLANC (passes 1 et 2). Gratuit, et n'écrit aucune donnée métier.
     *
     * Le fichier est stocké HORS de `public/` : il contient les données personnelles du
     * cabinet, et rien de ce qui est déposé ne doit être servi par une URL devinable.
     */
    #[Route('/importer/{idEntreprise}', name: 'controler', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['POST'])]
    public function controlerImport(int $idEntreprise, Request $request): Response
    {
        [$entreprise, $invite] = $this->resolveWorkspace($idEntreprise);

        // Fail-closed : importer exige l'ÉCRITURE, pas la simple lecture de la rubrique.
        if ($invite === null || !$this->accessResolver->can($invite, self::ENTITY_SHORT_NAME, Invite::ACCESS_ECRITURE)) {
            return $this->json(['message' => sprintf('L\'importation de données est hors de votre périmètre d\'accès.')], Response::HTTP_FORBIDDEN);
        }

        $fichier = $request->files->get('fichier');
        if (!$fichier instanceof UploadedFile) {
            return $this->json(['message' => 'Aucun fichier n\'a été déposé.'], Response::HTTP_BAD_REQUEST);
        }
        if (mb_strtolower((string) $fichier->getClientOriginalExtension()) !== 'xlsx') {
            return $this->json(
                ['message' => 'Seuls les classeurs Excel (.xlsx) produits par cette rubrique sont acceptés.'],
                Response::HTTP_UNSUPPORTED_MEDIA_TYPE,
            );
        }

        // Un contrôle déjà en attente est remplacé : garder les deux ferait deux
        // rapports concurrents pour un même utilisateur, dont un obsolète.
        $enCours = $this->importRuns->enAttentePour($entreprise, $invite);
        if ($enCours !== null) {
            $this->importateur->annuler($enCours);
        }

        $nomOriginal = $fichier->getClientOriginalName() ?: 'import.xlsx';
        $chemin = $this->deposer($fichier, $entreprise);

        $suppressions = $request->request->getBoolean('suppressions');
        $autreCabinet = $request->request->getBoolean('autreCabinet');

        $reponse = new StreamedResponse(function () use ($chemin, $nomOriginal, $entreprise, $invite, $suppressions, $autreCabinet): void {
            FluxNdjson::demarrer();
            $progression = new Progression(0, static fn (array $etat) => FluxNdjson::ligne($etat));

            try {
                $run = $this->importateur->controler(
                    $chemin,
                    $nomOriginal,
                    $entreprise,
                    $invite,
                    $suppressions,
                    $autreCabinet,
                    $progression,
                );

                $progression->terminer();
                FluxNdjson::ligne([
                    'type'        => 'resultat',
                    'idRun'       => $run->getId(),
                    'statut'      => $run->getStatut(),
                    'confirmable' => $run->estConfirmable(),
                    'rapport'     => $run->getRapport(),
                ]);
            } catch (\Throwable $e) {
                FluxNdjson::ligne(['type' => 'erreur', 'message' => $e->getMessage()]);
            }
        });

        foreach (FluxNdjson::entetes() as $nom => $valeur) {
            $reponse->headers->set($nom, $valeur);
        }

        return $reponse;
    }

    /**
     * PASSE 3 — ÉCRITURE, sur confirmation explicite.
     *
     * POST, et sans reprise de paramètres : tout ce qui gouverne l'écriture (périmètre,
     * suppressions autorisées, fichier) a été fixé au dépôt. Ce geste-ci ne dit qu'une
     * chose, « oui », et c'est l'utilisateur qui le pose.
     */
    #[Route('/importer/{idEntreprise}/{idRun}/confirmer', name: 'confirmer', requirements: ['idEntreprise' => Requirement::DIGITS, 'idRun' => Requirement::DIGITS], methods: ['POST'])]
    public function confirmerImport(int $idEntreprise, int $idRun): Response
    {
        [$run, $refus] = $this->resoudreRun($idEntreprise, $idRun);
        if ($refus !== null) {
            return $refus;
        }

        $acteur = $this->getUser();

        $reponse = new StreamedResponse(function () use ($run, $acteur): void {
            FluxNdjson::demarrer();
            $progression = new Progression(0, static fn (array $etat) => FluxNdjson::ligne($etat));

            try {
                $run = $this->importateur->executer($run, $acteur, $progression);
                $abouti = $run->getStatut() === EchangeImportRun::STATUT_TERMINE;

                $progression->terminer();
                FluxNdjson::ligne([
                    'type'    => 'resultat',
                    'success' => $abouti,
                    'statut'  => $run->getStatut(),
                    'rapport' => $run->getRapport(),
                    'message' => $abouti
                        ? 'Importation terminée.'
                        : 'L\'importation n\'a pas abouti : aucune modification n\'a été conservée.',
                ]);
            } catch (ImportImpossibleException $e) {
                FluxNdjson::ligne(['type' => 'erreur', 'message' => $e->getMessage()]);
            } catch (\Throwable $e) {
                // La transaction a été annulée : on le DIT, plutôt que de laisser la
                // barre à quatre-vingt-dix pour cent sur un import qui n'existe plus.
                FluxNdjson::ligne([
                    'type'    => 'erreur',
                    'message' => 'L\'importation a été interrompue et aucune modification n\'a été conservée : ' . $e->getMessage(),
                ]);
            }
        });

        foreach (FluxNdjson::entetes() as $nom => $valeur) {
            $reponse->headers->set($nom, $valeur);
        }

        return $reponse;
    }

    /** Annulation d'un contrôle en attente. Gratuite, sans effet sur les données. */
    #[Route('/importer/{idEntreprise}/{idRun}/annuler', name: 'annuler', requirements: ['idEntreprise' => Requirement::DIGITS, 'idRun' => Requirement::DIGITS], methods: ['POST'])]
    public function annulerImport(int $idEntreprise, int $idRun): JsonResponse
    {
        [$run, $refus] = $this->resoudreRun($idEntreprise, $idRun);
        if ($refus !== null) {
            return $refus;
        }

        $this->importateur->annuler($run);

        return $this->json(['success' => true, 'message' => 'Contrôle annulé.']);
    }

    /**
     * Charge un contrôle en s'assurant qu'il appartient bien à CET invité de CE cabinet.
     *
     * Le rapport porte le détail d'un fichier déposé, avec ses données : le scoping au
     * seul cabinet ne suffirait pas, deux collègues pouvant préparer des imports
     * différents en parallèle.
     *
     * @return array{0: ?EchangeImportRun, 1: ?JsonResponse}
     */
    private function resoudreRun(int $idEntreprise, int $idRun): array
    {
        [$entreprise, $invite] = $this->resolveWorkspace($idEntreprise);

        if ($invite === null || !$this->accessResolver->can($invite, self::ENTITY_SHORT_NAME, Invite::ACCESS_ECRITURE)) {
            return [null, $this->json(['message' => 'Action hors de votre périmètre d\'accès.'], Response::HTTP_FORBIDDEN)];
        }

        $run = $this->importRuns->find($idRun);
        if ($run === null
            || $run->getEntreprise()?->getId() !== $entreprise->getId()
            || $run->getInvite()?->getId() !== $invite->getId()) {
            return [null, $this->json(['message' => 'Ce contrôle est introuvable.'], Response::HTTP_NOT_FOUND)];
        }

        return [$run, null];
    }

    /**
     * Stocke le dépôt hors de `public/`, sous un nom qui ne révèle rien.
     *
     * Le nom d'origine est conservé dans l'entité pour l'affichage, jamais sur le
     * disque : un fichier nommé d'après le cabinet trahirait son contenu à qui
     * parcourrait le répertoire.
     */
    private function deposer(UploadedFile $fichier, Entreprise $entreprise): string
    {
        $repertoire = sprintf('%s/var/echange/%d', $this->projectDir, $entreprise->getId());
        if (!is_dir($repertoire) && !mkdir($repertoire, 0775, true) && !is_dir($repertoire)) {
            throw new \RuntimeException('Impossible de préparer le dépôt du fichier.');
        }

        $nom = bin2hex(random_bytes(16)) . '.xlsx';
        $fichier->move($repertoire, $nom);

        return $repertoire . '/' . $nom;
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
