<?php

namespace App\Controller\Admin;

use App\Entity\Entreprise;
use App\Constantes\Constante;
use App\Constantes\MenuActivator;
use App\Entity\Document;
use App\Entity\Risque;
use App\Form\DocumentType;
use App\Form\RisqueType;
use App\Repository\DocumentRepository;
use App\Repository\InviteRepository;
use App\Repository\EntrepriseRepository;
use App\Repository\RisqueRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;


#[Route("/admin/document", name: 'admin.document.')]
#[IsGranted('ROLE_USER')]
class DocumentController extends AbstractController
{
    public MenuActivator $activator;

    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private EntityManagerInterface $manager,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private DocumentRepository $documentRepository,
        private Constante $constante,
    ) {
        $this->activator = new MenuActivator(MenuActivator::GROUPE_ADMINISTRATION);
    }


    #[Route('/index/{idEntreprise}', name: 'index', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function index($idEntreprise, Request $request)
    {
        $page = $request->query->getInt("page", 1);

        return $this->render('admin/document/index.html.twig', [
            'pageName' => $this->translator->trans("document_page_name_new"),
            'utilisateur' => $this->getUser(),
            'entreprise' => $this->entrepriseRepository->find($idEntreprise),
            'documents' => $this->documentRepository->paginate($idEntreprise, $page),
            'page' => $page,
            'constante' => $this->constante,
            'activator' => $this->activator,
        ]);
    }


    #[Route('/create/{idEntreprise}', name: 'create')]
    public function create($idEntreprise, Request $request)
    {
        /** @var Entreprise $entreprise */
        $entreprise = $this->entrepriseRepository->find($idEntreprise);

        /** @var Utilisateur $user */
        $user = $this->getUser();

        /** @var Document $document */
        $document = new Document();
        //Paramètres par défaut
        $document->setEntreprise($entreprise);

        $form = $this->createForm(DocumentType::class, $document);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->manager->persist($document);
            $this->manager->flush();
            $this->addFlash("success", $this->translator->trans("document_creation_ok", [
                ":document" => $document->getNom(),
            ]));
            return $this->redirectToRoute("admin.document.index", [
                'idEntreprise' => $idEntreprise,
            ]);
        }
        return $this->render('admin/document/create.html.twig', [
            'pageName' => $this->translator->trans("document_page_name_new"),
            'utilisateur' => $user,
            'entreprise' => $entreprise,
            'activator' => $this->activator,
            'form' => $form,
        ]);
    }


    #[Route('/edit/{idEntreprise}/{idDocument}', name: 'edit', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function edit($idEntreprise, $idDocument, Request $request)
    {
        /** @var Entreprise $entreprise */
        $entreprise = $this->entrepriseRepository->find($idEntreprise);

        /** @var Utilisateur $user */
        $user = $this->getUser();

        /** @var Document $document */
        $document = $this->documentRepository->find($idDocument);

        $form = $this->createForm(DocumentType::class, $document);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->manager->persist($document); //On peut ignorer cette instruction car la fonction flush suffit.
            $this->manager->flush();
            $this->addFlash("success", $this->translator->trans("document_edition_ok", [
                ":document" => $document->getNom(),
            ]));
            return $this->redirectToRoute("admin.document.index", [
                'idEntreprise' => $idEntreprise,
            ]);
        }
        return $this->render('admin/document/edit.html.twig', [
            'pageName' => $this->translator->trans("document_page_name_update", [
                ":document" => $document->getNom(),
            ]),
            'utilisateur' => $user,
            'document' => $document,
            'entreprise' => $entreprise,
            'activator' => $this->activator,
            'form' => $form,
        ]);
    }

    #[Route('/remove/{idEntreprise}/{idDocument}', name: 'remove', requirements: ['idDocument' => Requirement::DIGITS, 'idEntreprise' => Requirement::DIGITS], methods: ['DELETE'])]
    public function remove($idEntreprise, $idDocument, Request $request)
    {
        /** @var Document $document */
        $document = $this->documentRepository->find($idDocument);

        $message = $this->translator->trans("document_deletion_ok", [
            ":document" => $document->getNom(),
        ]);;
        
        $this->manager->remove($document);
        $this->manager->flush();

        $this->addFlash("success", $message);
        return $this->redirectToRoute("admin.document.index", [
            'idEntreprise' => $idEntreprise,
        ]);
    }
}
