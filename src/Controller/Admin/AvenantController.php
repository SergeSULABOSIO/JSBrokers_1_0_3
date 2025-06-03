<?php

namespace App\Controller\Admin;

use App\Entity\Avenant;
use App\Entity\Assureur;
use App\Form\AvenantType;
use App\Entity\Entreprise;
use App\Form\AssureurType;
use App\Constantes\Constante;
use App\Services\ServiceTaxes;
use App\Constantes\MenuActivator;
use App\Services\ServiceMonnaies;
use App\Repository\InviteRepository;
use App\Repository\AvenantRepository;
use App\Repository\AssureurRepository;
use App\Repository\EntrepriseRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;

#[Route("/admin/avenant", name: 'admin.avenant.')]
#[IsGranted('ROLE_USER')]
class AvenantController extends AbstractController
{
    public MenuActivator $activator;

    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private EntityManagerInterface $manager,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private AvenantRepository $avenantRepository,
        private Constante $constante,
        private ServiceMonnaies $serviceMonnaies,
        private ServiceTaxes $serviceTaxes,
    ) {
        $this->activator = new MenuActivator(MenuActivator::GROUPE_PRODUCTION);
    }


    #[Route('/index/{idEntreprise}', name: 'index', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function index($idEntreprise, Request $request)
    {
        $page = $request->query->getInt("page", 1);

        return $this->render('admin/avenant/index.html.twig', [
            'pageName' => $this->translator->trans("avenant_page_name_new"),
            'utilisateur' => $this->getUser(),
            'entreprise' => $this->entrepriseRepository->find($idEntreprise),
            'avenants' => $this->avenantRepository->paginateForEntreprise($idEntreprise, $page),
            'page' => $page,
            'constante' => $this->constante,
            'activator' => $this->activator,
            'serviceMonnaie' => $this->serviceMonnaies,
            'serviceTaxe' => $this->serviceTaxes,
        ]);
    }


    #[Route('/create/{idEntreprise}', name: 'create')]
    public function create($idEntreprise, Request $request)
    {
        /** @var Entreprise $entreprise */
        $entreprise = $this->entrepriseRepository->find($idEntreprise);

        /** @var Utilisateur $user */
        $user = $this->getUser();

        /** @var Avenant $avenant */
        $avenant = new Avenant();
        //Paramètres par défaut

        $form = $this->createForm(AvenantType::class, $avenant);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->manager->persist($avenant);
            $this->manager->flush();
            
            return new Response("Ok__1986__" . count($avenant->getDocuments()));

        }
        return $this->render('admin/avenant/create.html.twig', [
            'pageName' => $this->translator->trans("avenant_page_name_new"),
            'utilisateur' => $user,
            'entreprise' => $entreprise,
            'avenant' => $avenant,
            'activator' => $this->activator,
            'form' => $form,
        ]);
    }


    #[Route('/edit/{idEntreprise}/{idAvenant}', name: 'edit', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function edit($idEntreprise, $idAvenant, Request $request)
    {
        /** @var Entreprise $entreprise */
        $entreprise = $this->entrepriseRepository->find($idEntreprise);

        /** @var Utilisateur $user */
        $user = $this->getUser();

        /** @var Avenant $avenant */
        $avenant = $this->avenantRepository->find($idAvenant);

        $form = $this->createForm(AvenantType::class, $avenant);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->manager->persist($avenant); //On peut ignorer cette instruction car la fonction flush suffit.
            $this->manager->flush();
            
            return new Response("Ok__1986__" . count($avenant->getDocuments()));
        }
        return $this->render('admin/avenant/edit.html.twig', [
            'pageName' => $this->translator->trans("avenant_page_name_update", [
                ":avenant" => $avenant->getReferencePolice(),
            ]),
            'utilisateur' => $user,
            'avenant' => $avenant,
            'entreprise' => $entreprise,
            'activator' => $this->activator,
            'form' => $form,
        ]);
    }

    #[Route('/remove/{idEntreprise}/{idAvenant}', name: 'remove', requirements: ['idAvenant' => Requirement::DIGITS, 'idEntreprise' => Requirement::DIGITS], methods: ['DELETE'])]
    public function remove($idEntreprise, $idAvenant, Request $request)
    {
        /** @var Avenant $avenant */
        $avenant = $this->avenantRepository->find($idAvenant);

        $message = $this->translator->trans("avenant_deletion_ok", [
            ":avenant" => $avenant->getReferencePolice(),
        ]);;
        
        $this->manager->remove($avenant);
        $this->manager->flush();

        $this->addFlash("success", $message);
        return $this->redirectToRoute("admin.avenant.index", [
            'idEntreprise' => $idEntreprise,
        ]);
    }


    #[Route('/viewAvenantsByReferencePolice/{referencepolice}', name: 'viewAvenantsByReferencePolice', methods: ['GET', 'POST'])]
    public function viewAvenant($referencepolice)
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        /** @var Avenant[] $avenants */
        $avenants = $this->constante->Entreprise_getAvenantsByReference($referencepolice);
        // dd($avenants);

        return $this->render('admin/avenant/view/simpleinfos.html.twig', [
            'utilisateur' => $user,
            'avenants' => $avenants,
            'constante' => $this->constante,
            'serviceMonnaie' => $this->serviceMonnaies,
            'serviceTaxe' => $this->serviceTaxes,
            'activator' => $this->activator,
        ]);
    }
}
