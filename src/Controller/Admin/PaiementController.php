<?php

namespace App\Controller\Admin;

use DateTimeImmutable;
use App\Entity\Classeur;
use App\Entity\Document;
use App\Entity\Paiement;
use App\Entity\Entreprise;
use App\Form\PaiementType;
use App\Constantes\Constante;
use App\Constantes\MenuActivator;
use App\Entity\OffreIndemnisationSinistre;
use App\Services\ServiceMonnaies;
use App\Repository\NoteRepository;
use App\Repository\InviteRepository;
use App\Repository\ClasseurRepository;
use App\Repository\PaiementRepository;
use App\Repository\EntrepriseRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

#[Route("/admin/paiement", name: 'admin.paiement.')]
#[IsGranted('ROLE_USER')]
class PaiementController extends AbstractController
{
    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private EntityManagerInterface $manager,
        private EntrepriseRepository $entrepriseRepository,
        private NoteRepository $noteRepository,
        private InviteRepository $inviteRepository,
        private PaiementRepository $paiementRepository,
        private ClasseurRepository $classeurRepository,
        private ServiceMonnaies $serviceMonnaies,
        private Constante $constante,
    ) {
    }


    #[Route('/index/{idEntreprise}', name: 'index', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function index($idEntreprise, Request $request)
    {
        $page = $request->query->getInt("page", 1);

        return $this->render('admin/paiement/index.html.twig', [
            'pageName' => $this->translator->trans("paiement_page_name_new"),
            'utilisateur' => $this->getUser(),
            'entreprise' => $this->entrepriseRepository->find($idEntreprise),
            'paiements' => $this->paiementRepository->paginateForEntreprise($idEntreprise, $page),
            'page' => $page,
            'serviceMonnaie' => $this->serviceMonnaies,
            'constante' => $this->constante,
        ]);
    }

    private function loadClasseurPreuvesDesPaiements(Entreprise $entreprise): Classeur
    {
        /** @var Classeur $classeurPOP */
        $classeurPOP = $this->classeurRepository->findOneByNom(Classeur::NOM_CLASSEUR_POP, $entreprise->getId());
        // $classeurPOP = $this->loadClasseurPreuvesDesPaiements($idEntreprise);
        if ($classeurPOP == null) {
            $classeurPOP = (new Classeur())
                ->setNom(Classeur::NOM_CLASSEUR_POP)
                ->setDescription("Classeur des preuves des paiements")
                ->setEntreprise($entreprise);
            $this->manager->persist($classeurPOP);
            $this->manager->flush();
            // dd("Classeur POP", $classeurPOP, "Crée.");
        }
        return $classeurPOP;
    }


    private function loadDataOnNote(Paiement $paiement): string
    {
        $refNote = "";
        $montPayable = 0;
        $montPaye = 0;
        $montSolde = 0;
        if ($paiement->getNote() != null) {
            $note = $paiement->getNote();
            $refNote = $note->getReference();
            $montPayable = number_format($this->constante->Note_getMontant_payable($note), 2, ",", " ");
            $montPaye = number_format($this->constante->Note_getMontant_paye($note), 2, ",", " ");
            $montSolde = number_format($this->constante->Note_getMontant_solde($note), 2, ",", " ");
        }
        return $refNote . "__1986__" . $montPayable . "__1986__" . $montPaye . "__1986__" . $montSolde;
    }

    /**
     * Fournit le formulaire HTML pour une pièce.
     */
    #[Route('/api/get-form/{id?}', name: 'api.get_form', methods: ['GET'])]
    public function getFormApi(?Paiement $paiement, Constante $constante): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        /** @var Invite $invite */
        $invite = $this->inviteRepository->findOneByEmail($user->getEmail());

        /** @var Entreprise $entreprise */
        $entreprise = $invite->getEntreprise();

        if (!$paiement) {
            $paiement = new Paiement();
        }
        $form = $this->createForm(PaiementType::class, $paiement);

        return $this->render('components/_form_canvas.html.twig', [
            'form' => $form->createView(),
            'entityFormCanvas' => $constante->getEntityFormCanvas($paiement, $entreprise->getId()) // ID entreprise à adapter
        ]);
    }

    /**
     * Traite la soumission du formulaire.
     */
    #[Route('/api/submit', name: 'api.submit', methods: ['POST'])]
    public function submitApi(Request $request, EntityManagerInterface $em): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        /** @var Invite $invite */
        $invite = $this->inviteRepository->findOneByEmail($user->getEmail());

        /** @var Entreprise $entreprise */
        $entreprise = $invite->getEntreprise();

        $data = $request->request->all();
        $files = $request->files->all();
        $submittedData = array_merge($data, $files);

        /** @var Paiement $paiement */
        $paiement = isset($data['id']) ? $em->getRepository(Paiement::class)->find($data['id']) : new Paiement();

        if (isset($data['offreIndemnisationSinistre'])) {
            $offreIndemnisationSInistre = $em->getReference(OffreIndemnisationSinistre::class, $data['offreIndemnisationSinistre']);
            if ($offreIndemnisationSInistre) $paiement->setOffreIndemnisationSinistre($offreIndemnisationSInistre);
        }
        if (isset($data['offreIndemnisation'])) {
            $offreIndemnisation = $em->getReference(OffreIndemnisationSinistre::class, $data['offreIndemnisation']);
            if ($offreIndemnisation) $paiement->setOffreIndemnisationSinistre($offreIndemnisation);
        }

        $form = $this->createForm(PaiementType::class, $paiement);
        $form->submit($submittedData, false);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($paiement);
            $em->flush();
            return $this->json(['message' => 'Paiement enregistrée avec succès!']);
        }

        // --- CORRECTION : GESTION DES ERREURS DE VALIDATION ---
        $errors = [];
        // On parcourt toutes les erreurs du formulaire (y compris celles des champs enfants)
        foreach ($form->getErrors(true) as $error) {
            $errors[$error->getOrigin()->getName()][] = $error->getMessage();
        }

        return $this->json([
            'success' => false,
            'message' => 'Veuillez corriger les erreurs ci-dessous.',
            'errors'  => $errors // On envoie le tableau détaillé des erreurs au client
        ], 422); // 422 = Unprocessable Entity
    }

    /**
     * Supprime une pièce.
     */
    #[Route('/api/delete/{id}', name: 'api.delete', methods: ['DELETE'])]
    public function deleteApi(Paiement $paiement, EntityManagerInterface $em): Response
    {
        try {
            $em->remove($paiement);
            $em->flush();
            return $this->json(['message' => 'Paiement supprimée avec succès.']);
        } catch (\Exception $e) {
            return $this->json(['message' => 'Erreur lors de la suppression.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
