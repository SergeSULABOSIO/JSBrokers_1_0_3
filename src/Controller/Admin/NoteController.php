<?php

namespace App\Controller\Admin;

use Dompdf\Dompdf;
use Dompdf\Options;
use App\Entity\Assureur;
use App\Entity\Client;
use App\Entity\RevenuPourCourtier;
use App\Entity\Entreprise;
use App\Entity\CompteBancaire;
use App\Entity\Article;
use App\Entity\Bordereau;
use App\Entity\Note;
use App\Entity\Paiement;
use App\Entity\Tranche;
use App\Entity\Invite;
use App\Services\Note\SourceDeFacturation;
use App\Constantes\Constante;
use App\Form\NoteType;
use App\Repository\NoteRepository;
use App\Repository\InviteRepository;
use App\Repository\EntrepriseRepository;
use App\Services\BordereauAnalysisPdfService;
use App\Services\CanvasBuilder;
use App\Services\ServiceMonnaies;
use App\Services\Canvas\CalculationProvider;
use setasign\Fpdi\Tcpdf\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;
use Doctrine\ORM\EntityManagerInterface;
use App\Service\Workspace\ValeursDeNaissance;
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

#[Route("/admin/note", name: 'admin.note.')]
#[IsGranted('ROLE_USER')]
class NoteController extends AbstractController
{
    use HandleChildAssociationTrait;
    use ControllerUtilsTrait;

    public function __construct(
        private EntityManagerInterface $em,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private NoteRepository $noteRepository,
        private Constante $constante,
        private JSBDynamicSearchService $searchService,
        private SerializerInterface $serializer, // Ajout de SerializerInterface
        private CalculationProvider $calculationProvider,
        private ServiceMonnaies $serviceMonnaies, // NOUVEAU : Injection du service des monnaies
        private BordereauAnalysisPdfService $bordereauPdfService,
        // Ce qu'une note porte en naissant — partagé avec le circuit d'écriture commun,
        // pour qu'une note importée soit en tout point une note créée d'un clic.
        private ValeursDeNaissance $valeursDeNaissance,
        // La règle de facturation, partagée avec l'assistant : l'en-tête d'une note
        // déduite d'une échéance se calcule au même endroit pour les deux surfaces.
        private SourceDeFacturation $sourceDeFacturation,
        CanvasBuilder $canvasBuilder
    ) {
        // Assign the injected CanvasBuilder to the property declared in the trait
        $this->canvasBuilder = $canvasBuilder;
    }

    protected function getCollectionMap(): array
    {
        return $this->buildCollectionMapFromEntity(Note::class);
    }

    protected function getParentAssociationMap(): array
    {
        return $this->buildParentAssociationMapFromEntity(Note::class);
    }

    #[Route('/test', name: 'test')]
    public function edit(): Response
    {
        return $this->render('components/note/editor.html.twig', []);
    }

    #[Route('/index/{idInvite}/{idEntreprise}', name: 'index', requirements: ['idEntreprise' => Requirement::DIGITS, 'idInvite' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        return $this->renderViewOrListComponent(Note::class, $request);
    }

    #[Route('/api/get-form/{id?}', name: 'api.get_form', methods: ['GET'])]
    public function getFormApi(?Note $note, Request $request): Response
    {
        return $this->renderFormCanvas(
            $request,
            Note::class,
            NoteType::class,
            $note,
            // « type » (débit/crédit) et « addressedTo » n'ont VOLONTAIREMENT plus de
            // défaut inconditionnel : ce sont des discriminants comptables — un débit
            // réclame un paiement, un crédit accorde un avoir — et les préremplir
            // revenait à trancher en silence à la place du courtier
            // (ChampsObligatoiresInspector::CHOIX_METIER_REQUIS les exige désormais).
            // Ils restent posés dans la branche BORDEREAU, où ils ne sont pas devinés
            // mais DÉDUITS : facturer un bordereau validé, c'est émettre une note de
            // débit à l'assureur.
            function (Note $note, Invite $invite) use ($request) {
                // Même règle qu'à la soumission, et qu'à l'écriture par le circuit commun :
                // signature, validation et date d'émission ont un seul endroit.
                $this->valeursDeNaissance->poser($note);
                $note->setInvite($invite);

                // Pré-remplissage depuis un parent (parentContext du dialog-instance)
                $parentId = $request->query->get('parent_id');
                $parentField = $request->query->get('parent_field_name');

                if ($parentField === 'bordereau' && $parentId) {
                    $bordereau = $this->em->find(Bordereau::class, (int)$parentId);
                    if ($bordereau) {
                        $note->setBordereau($bordereau);
                        $note->setType(Note::TYPE_NOTE_DE_DEBIT);
                        $note->setAddressedTo(Note::TO_ASSUREUR);
                        $note->setAssureur($bordereau->getAssureur());
                        $note->setNom('Commission — Bordereau ' . $bordereau->getReference());
                        $note->setReference('FACT-' . $bordereau->getReference());
                        return; // référence déjà définie, on sort
                    }
                }

                // ⚠ IL N'Y A PAS DE BRANCHE « TRANCHE » ICI, ET C'EST VOULU.
                //
                // Une première version préremplissait ce formulaire depuis une échéance.
                // Elle ne pouvait poser que l'EN-TÊTE : la collection `articles` est
                // `mapped: false`, remplacée par un widget dont les enfants passent par
                // le tampon du navigateur — rien, côté serveur, ne sait la semer. Le
                // courtier obtenait donc une note vide dont il composait les lignes une
                // à une, là où l'assistant posait les deux en un seul plan.
                //
                // La facturation depuis une échéance passe désormais par son PICKER
                // (`/admin/note/facturation-picker`), qui écrit la note ET ses lignes en
                // un seul POST. Ce formulaire garde son rôle : composer une note à la
                // main, depuis la rubrique.
                $note->setReference('N' . time());
            }
        );
    }

    /**
     * LA FENÊTRE DE FACTURATION — ce que le bouton « Facturer la commission » de la
     * rubrique Tranches ouvre, déjà remplie.
     *
     * ⚠ DU HTML, PAS DU JSON. L'ouvreur de pickers autonomes (`picker-open.js`, partagé
     * avec le portefeuille, le partage et les reversements) lit la réponse en TEXTE et
     * l'insère telle quelle. Une enveloppe JSON lui donne une chaîne sans aucun élément
     * — « Contenu du picker vide » — et le bouton ne fait rien d'autre qu'une
     * notification d'erreur. Le piège est déjà commenté deux fois dans
     * `RetroAgentController` : il a été payé une fois, il ne le sera pas deux.
     */
    #[Route('/facturation-picker', name: 'facturation_picker', methods: ['GET'])]
    public function facturationPicker(Request $request): Response
    {
        // Mutation à venir (création d'une note) : Écriture sur Note, fail-closed.
        if (!$this->mayAccessEntity(Note::class, Invite::ACCESS_ECRITURE)) {
            return $this->accessDeniedJson();
        }

        $entreprise = $this->getEntreprise();
        $addressedTo = $request->query->get('destinataire') === 'client'
            ? Note::TO_CLIENT
            : Note::TO_ASSUREUR;

        $tranches = $this->tranchesDuPerimetre(
            explode(',', (string) $request->query->get('ids', '')),
            $entreprise,
        );
        $groupes = $this->sourceDeFacturation->facturableDansLaSelection($entreprise, $tranches, $addressedTo);

        // UNE NOTE A UN DESTINATAIRE. On propose donc le premier groupe, et l'on ANNONCE
        // ce qui reste : mêler deux assureurs produirait une pièce que personne ne peut
        // ni payer ni comptabiliser.
        $groupe = $groupes[0] ?? null;
        $suivants = array_slice($groupes, 1);

        return $this->render('components/note/_facturation_picker.html.twig', [
            'groupe' => $groupe,
            'suivants' => array_map(
                static fn (array $g): array => [
                    'nom' => $g['nom'],
                    'compte' => count($g['lignes']),
                    'ids' => array_values(array_unique(array_column($g['lignes'], 'trancheId'))),
                ],
                $suivants,
            ),
            'destinataire' => $addressedTo === Note::TO_CLIENT ? 'client' : 'assureur',
            'idsDemandes' => array_map(static fn (Tranche $t): int => (int) $t->getId(), $tranches),
            'objet' => $groupe !== null ? $this->objetPropose($tranches, $addressedTo) : '',
            'monnaie' => $this->serviceMonnaies->getCodeMonnaieAffichage(),
            'comptes' => $this->em->getRepository(CompteBancaire::class)
                ->findBy(['entreprise' => $entreprise], ['intitule' => 'ASC']),
            'signataire' => $this->getInvite()?->getNom() ?? '',
            'submitUrl' => $this->generateUrl('admin.note.facturation_submit'),
            'apercuUrlPattern' => $this->generateUrl('admin.note.api.get_preview_url', ['id' => 0]),
        ]);
    }

    /**
     * LES ÉCHÉANCES COCHÉES, RÉDUITES À CELLES DU CABINET.
     *
     * Ce qui n'en relève pas est ignoré EN SILENCE, comme le fait le reversement : un
     * identifiant étranger n'est pas une erreur de l'utilisateur, c'est une tentative
     * ou une liste périmée — dans les deux cas, on n'en parle pas, on n'en fait rien.
     *
     * @param list<mixed> $ids les identifiants bruts, tels que reçus
     *
     * @return list<Tranche>
     */
    private function tranchesDuPerimetre(array $ids, Entreprise $entreprise): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            return [];
        }

        return $this->em->getRepository(Tranche::class)
            ->createQueryBuilder('t')
            ->andWhere('t.id IN (:ids)')
            ->andWhere('t.entreprise = :entreprise')
            ->setParameter('ids', $ids)
            ->setParameter('entreprise', $entreprise)
            ->orderBy('t.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** L'objet proposé, déduit de la première échéance — la règle de l'assistant. */
    private function objetPropose(array $tranches, int $addressedTo): string
    {
        $premiere = $tranches[0] ?? null;

        return $premiere instanceof Tranche
            ? (string) $this->sourceDeFacturation->entetePour($premiere, Note::TYPE_NOTE_DE_DEBIT, $addressedTo)['nom']
            : 'Commission';
    }

    /**
     * SIGNALER LE RÈGLEMENT D'UNE NOTE — le contexte dont le formulaire a besoin.
     *
     * ── POURQUOI CE GESTE EST ICI ───────────────────────────────────────────────
     * Émettre une note n'est que la moitié du geste : elle part à l'assureur pour être
     * PAYÉE. Jusqu'ici, l'encaissement ne se saisissait qu'en rouvrant la note et en
     * descendant dans sa collection de paiements — deux détours, et seulement si l'on y
     * pensait. La fenêtre de facturation le propose désormais dans la foulée.
     *
     * ── AUCUN FORMULAIRE N'EST RÉÉCRIT ──────────────────────────────────────────
     * `PaiementType` existe, avec sa date, sa référence, son compte et ses pièces
     * justificatives. On rend son canevas et on laisse le dialogue ordinaire faire le
     * reste : le rattachement à la note passe par le `parentContext` du cerveau, et le
     * montant par défaut par `?default_montant=`, que ce formulaire lit déjà.
     *
     * C'est le calque exact de {@see TrancheController::getPaiementPrimeContext()}.
     *
     * ── LE DROIT REGARDÉ EST CELUI DE LA NOTE ───────────────────────────────────
     * Un paiement est une sous-entité structurelle gouvernée par sa note — c'est déjà
     * ainsi qu'il se saisit dans le dialogue de la note, par sa collection.
     */
    #[Route('/api/{id}/paiement-context', name: 'api.paiement_context', requirements: ['id' => Requirement::DIGITS], methods: ['GET'], priority: 1)]
    public function getPaiementContext(Note $note, Request $request): JsonResponse
    {
        if (!$this->mayAccessEntity(Note::class, Invite::ACCESS_ECRITURE)) {
            return $this->accessDeniedJson();
        }

        $entreprise = $this->getEntreprise();
        if ($entreprise === null || $note->getEntreprise()?->getId() !== $entreprise->getId()) {
            return $this->json(
                ['message' => 'Note introuvable dans cet espace de travail.'],
                Response::HTTP_NOT_FOUND,
            );
        }

        // LE SOLDE SE LIT, IL NE SE RECALCULE PAS. C'est la valeur calculée que la
        // rubrique Notes affiche déjà : montant payable − montant payé. En refaire ici
        // une seconde soustraction mettrait deux chiffres en présence.
        $this->canvasBuilder->loadAllCalculatedValues($note);
        $solde = round((float) ($note->solde ?? 0.0), 2);

        return $this->json([
            'noteId'        => $note->getId(),
            'noteReference' => $note->getReference(),
            // Un solde négatif ou nul n'a rien à proposer : on ne prérenseigne alors rien
            // plutôt que d'inscrire un montant que personne ne doit.
            'solde'         => $solde > 0.0 ? $solde : null,
            // LA COLLECTION QUI ATTEND CE PAIEMENT. Le dialogue de la note porte un
            // widget « Paiements liés » ; quand il est ouvert, le paiement enregistré
            // doit s'y ranger plutôt que de n'apparaître qu'au prochain chargement.
            //
            // Le nom vient d'ici, et non d'une chaîne écrite dans le navigateur : c'est
            // le même fournisseur de canevas qui déclare l'action ET cette collection.
            'collection'    => 'paiements',
            'formCanvas'    => $this->canvasBuilder->getEntityFormCanvas(new Paiement(), $entreprise->getId()),
        ]);
    }

    /**
     * ÉMETTRE LA NOTE ET SES LIGNES, en une seule écriture.
     *
     * ── POURQUOI ICI, ET PAS PAR LE FORMULAIRE ──────────────────────────────────
     * `NoteType` déclare `articles` en `mapped: false` : la collection est remplacée
     * par un widget dont les enfants naissent dans leur propre dialogue, après que le
     * parent existe. Passer par lui obligerait le courtier à composer ses lignes une
     * à une — exactement ce que ce chantier supprime.
     *
     * ── ÉMETTRE, C'EST VALIDER ──────────────────────────────────────────────────
     * `ValeursDeNaissance` pose `validated = false`, ce qui est juste pour une note
     * composée à la main et relue avant envoi. Facturer est un acte achevé : la pièce
     * part à l'assureur. Sans ce drapeau, la note n'entrerait jamais au suivi du
     * recouvrement, qui ne compte que les notes validées.
     */
    #[Route('/facturation', name: 'facturation_submit', methods: ['POST'])]
    public function facturationSubmit(Request $request): JsonResponse
    {
        if (!$this->mayAccessEntity(Note::class, Invite::ACCESS_ECRITURE)) {
            return $this->accessDeniedJson();
        }

        $entreprise = $this->getEntreprise();
        $donnees = json_decode($request->getContent(), true);
        $lignes = is_array($donnees['lignes'] ?? null) ? $donnees['lignes'] : [];
        if ($lignes === []) {
            return $this->json(
                ['message' => 'Cochez au moins une commission à facturer.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $addressedTo = ($donnees['destinataire'] ?? 'assureur') === 'client'
            ? Note::TO_CLIENT
            : Note::TO_ASSUREUR;

        $note = (new Note())
            ->setType(Note::TYPE_NOTE_DE_DEBIT)
            ->setAddressedTo($addressedTo)
            ->setNom(trim((string) ($donnees['objet'] ?? '')) ?: 'Commission')
            ->setValidated(true);
        // Référence, signature et date d'émission : un seul endroit, partagé avec le
        // formulaire et avec l'écriture de l'assistant.
        $this->valeursDeNaissance->poser($note);
        $note->setEntreprise($entreprise)->setInvite($this->getInvite());

        if (($donnees['description'] ?? null) !== null && $donnees['description'] !== '') {
            $note->setDescription((string) $donnees['description']);
        }
        $note->setSignedBy($this->texteOuNull($donnees['signataire'] ?? null));
        $note->setTitleSignedBy($this->texteOuNull($donnees['titreSignataire'] ?? null));

        // OÙ SE FAIRE PAYER : sans compte, le PDF ne dit pas à l'assureur où virer.
        foreach ($this->comptesDuPerimetre($donnees['comptes'] ?? [], $entreprise) as $compte) {
            $note->addCompte($compte);
        }

        // ── CE QUE LE SERVEUR ACCEPTE DE FACTURER, REPESÉ ICI ───────────────────
        // Le navigateur envoie des identifiants et des montants ; aucun des deux n'est
        // cru sur parole. On refait la MÊME pesée que celle qui a rempli la fenêtre :
        // elle dit ce qui relève du cabinet, ce qu'il reste à facturer, et le montant
        // d'une quantité de 1. Une ligne absente de cette carte a été facturée entre
        // l'ouverture et l'envoi — on l'ignore, comme un identifiant étranger.
        $tranchesPostees = $this->tranchesDuPerimetre(
            array_map(static fn (mixed $l): mixed => $l['trancheId'] ?? 0, $lignes),
            $entreprise,
        );
        $parId = [];
        foreach ($tranchesPostees as $t) {
            $parId[(int) $t->getId()] = $t;
        }
        $facturable = [];
        foreach ($this->sourceDeFacturation->facturableDansLaSelection($entreprise, $tranchesPostees, $addressedTo) as $groupe) {
            foreach ($groupe['lignes'] as $l) {
                $facturable[$l['trancheId'] . ':' . $l['revenuId']] = $l;
            }
        }

        $ecrites = 0;
        foreach ($lignes as $ligne) {
            $pesee = $facturable[((int) ($ligne['trancheId'] ?? 0)) . ':' . ((int) ($ligne['revenuId'] ?? 0))] ?? null;
            if ($pesee === null) {
                continue; // hors périmètre ou plus rien à facturer : ignoré en silence.
            }

            // LE MONTANT DEMANDÉ EST BORNÉ AU RESTE, et converti en quantité : `Article`
            // n'a pas de champ montant, sa valeur se dérive de la quantité. Facturer une
            // part laisse donc le reliquat facturable, sans une règle de plus.
            $quantite = $this->sourceDeFacturation->quantitePour(
                (float) $pesee['montant'],
                (float) $pesee['unitaire'],
                isset($ligne['montant']) ? (float) $ligne['montant'] : null,
            );
            if ($quantite <= 0.0) {
                continue;
            }

            $revenu = $this->em->getRepository(RevenuPourCourtier::class)->findOneBy([
                'id' => (int) $ligne['revenuId'],
                'entreprise' => $entreprise,
            ]);
            if ($revenu === null) {
                continue;
            }
            $tranche = $parId[(int) $ligne['trancheId']] ?? null;

            // QUI DOIT L'ARGENT — déduit de la police, comme le fait l'assistant. Sans
            // ce rattachement, la note n'aurait pas de débiteur : elle ne serait
            // adressée à personne, n'entrerait dans aucun suivi, et le PDF ne porterait
            // aucun nom. Posé sur la PREMIÈRE ligne : la fenêtre a déjà garanti qu'elles
            // relèvent toutes du même destinataire.
            if ($note->getAssureur() === null && $note->getClient() === null) {
                $this->rattacherLeDestinataire($note, $tranche ?? $revenu->getCotation()?->getTranches()->first(), $addressedTo);
            }

            $article = (new Article())->setQuantite($quantite);
            $article->setNote($note)->setRevenuFacture($revenu)->setTranche($tranche);
            $article->setEntreprise($entreprise)->setInvite($this->getInvite());
            $this->em->persist($article);
            $note->addArticle($article);
            ++$ecrites;
        }

        if ($ecrites === 0) {
            return $this->json(
                ['message' => 'Aucune ligne exploitable : ces commissions ne relèvent pas de cet espace de travail, ou il n’y reste plus rien à facturer.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $this->em->persist($note);
        $this->em->flush();

        return $this->json([
            'message' => sprintf(
                '%s %s émise, portant %d commission%s.',
                $addressedTo === Note::TO_CLIENT ? 'Note de débit au client' : 'Note de débit à l’assureur',
                $note->getReference(),
                $ecrites,
                $ecrites > 1 ? 's' : '',
            ),
            'noteId' => $note->getId(),
            // L'URL que le cerveau sait déjà ouvrir : sa branche « download » fait un
            // window.open sur un PDF servi `inline`. Aucun code d'impression ici.
            'pdfUrl' => $this->generateUrl('admin.note.api.get_preview_url', ['id' => $note->getId()]) . '?download=1',
        ]);
    }

    /**
     * Rattache la note à celui qui doit l'argent, par la MÊME règle que l'assistant :
     * `entetePour()` lit l'assureur — ou le client — de la police de l'échéance.
     */
    private function rattacherLeDestinataire(Note $note, mixed $source, int $addressedTo): void
    {
        if (!$source instanceof Tranche) {
            return;
        }

        $cible = $this->sourceDeFacturation->entetePour($source, Note::TYPE_NOTE_DE_DEBIT, $addressedTo)['cible'];
        if ($cible === null) {
            return;
        }

        $addressedTo === Note::TO_CLIENT
            ? $note->setClient($this->em->getRepository(Client::class)->find($cible))
            : $note->setAssureur($this->em->getRepository(Assureur::class)->find($cible));
    }

    /** @return list<CompteBancaire> les comptes cochés qui relèvent bien du cabinet */
    private function comptesDuPerimetre(mixed $ids, Entreprise $entreprise): array
    {
        $ids = array_values(array_filter(array_map('intval', is_array($ids) ? $ids : [])));
        if ($ids === []) {
            return [];
        }

        return $this->em->getRepository(CompteBancaire::class)
            ->createQueryBuilder('c')
            ->andWhere('c.id IN (:ids)')
            ->andWhere('c.entreprise = :entreprise')
            ->setParameter('ids', $ids)
            ->setParameter('entreprise', $entreprise)
            ->getQuery()
            ->getResult();
    }

    private function texteOuNull(mixed $valeur): ?string
    {
        $texte = trim((string) $valeur);

        return $texte === '' ? null : $texte;
    }

    #[Route('/api/get-preview-url/{id}', name: 'api.get_preview_url', methods: ['GET'])]
    public function getPreviewUrlApi(Note $note, Request $request): JsonResponse
    {
        // NOUVEAU : On vérifie si on doit télécharger directement ou juste afficher/imprimer.
        if ($request->query->get('download')) {
            // Si le paramètre 'download' est présent, on génère l'URL de téléchargement PDF.
            $finalUrl = $this->generateUrl('admin.note.download_pdf', ['id' => $note->getId()], \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL);
        } else {
            // Sinon, on génère l'URL de l'aperçu, en conservant les autres paramètres (comme 'print').
            $params = ['id' => $note->getId()];
            if ($request->query->get('print')) {
                $params['print'] = 1;
            }
            $finalUrl = $this->generateUrl('admin.note.show_preview', $params, \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL);
        }

        return $this->json(['previewUrl' => $finalUrl]);
    }

    #[Route('/apercu/{id}', name: 'show_preview', methods: ['GET'])]
    public function showPreview(Note $note): Response
    {
        // CORRECTION : On charge les valeurs calculées pour la note ET pour chaque article.
        
        // 1. On charge les valeurs de la note (montantTotal, montantTaxe, etc.)
        $this->canvasBuilder->loadAllCalculatedValues($note);

        // 2. On charge les valeurs pour chaque article (montantArticleHT, valeurUnitaireHT, etc.)
        foreach ($note->getArticles() as $article) {
            $this->canvasBuilder->loadAllCalculatedValues($article);
        }

        // 3. On récupère l'entreprise et les autres données nécessaires pour le template.
        $entreprise = $this->getEntreprise();
        $entityCanvas = $this->canvasBuilder->getEntityCanvas(Note::class);

        return $this->render('admin/note/note_preview.html.twig', [
            'note' => $note,
            'entreprise' => $entreprise,
            'entityCanvas' => $entityCanvas,
            // On passe la monnaie d'affichage pour l'utiliser dans le template
            'monnaie' => $this->serviceMonnaies->getCodeMonnaieAffichage()
        ]);
    }

    #[Route('/workspace-apercu/{id}', name: 'workspace_content', methods: ['GET'])]
    public function showWorkspaceContent(Note $note): JsonResponse
    {
        $this->canvasBuilder->loadAllCalculatedValues($note);
        foreach ($note->getArticles() as $article) {
            $this->canvasBuilder->loadAllCalculatedValues($article);
        }
        $entreprise = $this->getEntreprise();
        $entityCanvas = $this->canvasBuilder->getEntityCanvas(Note::class);

        $html = $this->renderView('admin/note/note_preview_workspace.html.twig', [
            'note'         => $note,
            'entreprise'   => $entreprise,
            'entityCanvas' => $entityCanvas,
            'monnaie'      => $this->serviceMonnaies->getCodeMonnaieAffichage(),
            'previewUrl'   => $this->generateUrl('admin.note.show_preview', ['id' => $note->getId()]),
        ]);

        return $this->json([
            'html'  => $html,
            'title' => $note->getTypeString() . ' — ' . $note->getReference(),
        ]);
    }

    #[Route('/download-pdf/{id}', name: 'download_pdf', methods: ['GET'])]
    public function downloadPdf(Note $note): Response
    {
        // 1. On configure DomPDF pour qu'il respecte les styles d'impression (@media print)
        $pdfOptions = new Options();
        $pdfOptions->set('defaultFont', 'Arial');
        $pdfOptions->set('isHtml5ParserEnabled', true);
        // AUCUN accès distant. Le gabarit incorpore désormais son CSS (voir
        // note_preview.html.twig) : laisser cette option à « true » ferait sortir
        // une requête HTTPS SYNCHRONE du serveur à chaque PDF — le rendu de la
        // note se mettrait alors à dépendre de la disponibilité d'un CDN, et à
        // expirer quand l'hébergeur filtre le trafic sortant.
        // Elle offrait au passage la lecture de ressources arbitraires à qui
        // contrôlerait le contenu d'une note.
        // Les quatre autres usages de DomPDF du projet (TokenInvoicePdfService,
        // BordereauAnalysisPdfService, PdfRapportRenderer, MessageExporter) sont
        // déjà dans ce régime : on aligne le cinquième.
        $pdfOptions->set('isRemoteEnabled', false);
        // La ligne la plus importante : elle demande à DomPDF de simuler le rendu d'impression
        // NOUVEAU : Améliore la compatibilité avec les CSS modernes (Flexbox, etc.)
        $pdfOptions->set('chroot', $this->getParameter('kernel.project_dir') . '/public');


        $pdfOptions->setDefaultMediaType('print');

        $dompdf = new Dompdf($pdfOptions);

        // 2. On récupère le contexte nécessaire pour le template, comme dans showPreview
        $this->canvasBuilder->loadAllCalculatedValues($note);
        foreach ($note->getArticles() as $article) {
            $this->canvasBuilder->loadAllCalculatedValues($article);
        }
        $entreprise = $this->getEntreprise();
        $entityCanvas = $this->canvasBuilder->getEntityCanvas(Note::class);
        $monnaie = $this->serviceMonnaies->getCodeMonnaieAffichage();

        // NOUVEAU : On prépare les chemins absolus pour les images
        $logoPath = null;
        if ($entreprise && $entreprise->getThumbnail()) {
            $logoPath = $this->getParameter('kernel.project_dir') . '/public/images/entreprises/' . $entreprise->getThumbnail();
        } else {
            $logoPath = $this->getParameter('kernel.project_dir') . '/public/images/entreprises/logofav.png';
        }

        // 3. On génère le HTML en utilisant la méthode renderView
        $html = $this->renderView('admin/note/note_preview.html.twig', [
            'note' => $note,
            'entreprise' => $entreprise,
            'entityCanvas' => $entityCanvas,
            'monnaie' => $monnaie,
            // On passe le chemin absolu du logo au template
            'logo_path_for_pdf' => $logoPath,
        ]);

        // 4. On charge le HTML dans DomPDF, on génère le PDF et on le propose au téléchargement
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $notePdfString = $dompdf->output();

        // 5. Si la note est liée à un bordereau, on annexe les lignes conformes en paysage
        $bordereau = $note->getBordereau();
        if ($bordereau !== null) {
            $bordereauPdfString = $this->bordereauPdfService->generatePdfString($bordereau, matchOnly: true);
            if ($bordereauPdfString !== null) {
                $notePdfString = $this->mergePdfs($notePdfString, $bordereauPdfString);
            }
        }

        return new Response($notePdfString, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="note-' . $note->getReference() . '.pdf"',
        ]);
    }

    private function mergePdfs(string $notePdf, string $bordereauPdf): string
    {
        $fpdi = new Fpdi('P', 'mm', 'A4');
        $fpdi->setPrintHeader(false);
        $fpdi->setPrintFooter(false);

        $count = $fpdi->setSourceFile(StreamReader::createByString($notePdf));
        for ($i = 1; $i <= $count; $i++) {
            $tpl  = $fpdi->importPage($i);
            $size = $fpdi->getTemplateSize($tpl);
            $fpdi->AddPage($size['width'] > $size['height'] ? 'L' : 'P', [$size['width'], $size['height']]);
            $fpdi->useTemplate($tpl, 0, 0, $size['width'], $size['height'], true);
        }

        $count = $fpdi->setSourceFile(StreamReader::createByString($bordereauPdf));
        for ($i = 1; $i <= $count; $i++) {
            $tpl  = $fpdi->importPage($i);
            $size = $fpdi->getTemplateSize($tpl);
            $fpdi->AddPage($size['width'] > $size['height'] ? 'L' : 'P', [$size['width'], $size['height']]);
            $fpdi->useTemplate($tpl, 0, 0, $size['width'], $size['height'], true);
        }

        return $fpdi->Output('', 'S');
    }

    #[Route('/api/submit', name: 'api.submit', methods: ['POST'])]
    public function submitApi(Request $request): JsonResponse
    {
        $inviteConnecte = $this->getInvite();

        return $this->handleFormSubmission(
            $request,
            Note::class,
            NoteType::class,
            function (Note $note) use ($inviteConnecte, $request) {
                if (!$note->getId()) {
                    if (!$note->getReference()) {
                        $note->setReference("N" . time());
                    }
                    // ⚠ SIGNATURE, VALIDATION ET DATE D'ÉMISSION VIENNENT D'UN SEUL ENDROIT.
                    //
                    // Ces trois valeurs étaient posées ici, et une seconde fois plus haut
                    // dans ce contrôleur — donc nulle part ailleurs. Tout ce qui écrit
                    // AUTREMENT que par cet écran (l'assistant, la reprise de données)
                    // butait alors sur des colonnes NOT NULL qu'aucun formulaire ne
                    // propose : la reprise renonçait à enregistrer les commissions déjà
                    // encaissées, une par échéance.
                    //
                    // Elles vivent désormais dans `ValeursDeNaissance`, que le circuit
                    // d'écriture commun applique aussi. Une note née d'un import est en
                    // tout point une note née d'un clic.
                    $this->valeursDeNaissance->poser($note);
                    $note->setInvite($inviteConnecte);

                    // The 'bordereau' field is suppressed from the form layout (to avoid Twig double-render),
                    // so the form binding sets it to null. Restore it from the parentContext sent by dialog-instance.
                    if ($note->getBordereau() === null) {
                        $bordereauId = $request->request->get('parent_id');
                        $parentField = $request->request->get('parent_field_name');
                        if ($parentField === 'bordereau' && $bordereauId) {
                            $bordereau = $this->em->find(Bordereau::class, (int)$bordereauId);
                            if ($bordereau) {
                                $note->setBordereau($bordereau);
                            }
                        }
                    }

                    // Bordereau → passe au statut "Facturé" dans la même transaction
                    if ($note->getBordereau() !== null) {
                        $bordereau = $note->getBordereau();
                        $bordereau->setCurrentAnalysisStep(Bordereau::STEP_NOTE_EMISE);
                        $bordereau->setUpdatedAt(new \DateTimeImmutable());
                    }
                }
            }
        );
    }

    #[Route('/api/delete/{id}', name: 'api.delete', methods: ['DELETE'])]
    public function deleteApi(Note $note): Response
    {
        return $this->handleDeleteApi($note);
    }

    #[Route('/api/dynamic-query/{idInvite}/{idEntreprise}', name: 'app_dynamic_query', requirements: ['idEntreprise' => Requirement::DIGITS, 'idInvite' => Requirement::DIGITS], methods: ['POST'])]
    public function query(Request $request): Response
    {
        return $this->renderViewOrListComponent(Note::class, $request, true);
    }

    #[Route('/api/{id}/{collectionName}/{usage}', name: 'api.get_collection', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    public function getCollectionListApi(int $id, string $collectionName, ?string $usage = "generic"): Response
    {
        return $this->handleCollectionApiRequest($id, $collectionName, Note::class, $usage);
    }
}