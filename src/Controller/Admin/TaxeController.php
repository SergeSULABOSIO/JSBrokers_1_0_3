<?php

namespace App\Controller\Admin;

use App\Entity\Taxe;
use App\Form\TaxeType;
use App\Entity\Entreprise;
use App\Constantes\Constante;
use App\Services\ServiceTaxes;
use App\Constantes\MenuActivator;
use App\Services\ServiceMonnaies;
use App\Repository\TaxeRepository;
use App\Repository\InviteRepository;
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


#[Route("/admin/taxe", name: 'admin.taxe.')]
#[IsGranted('ROLE_USER')]
class TaxeController extends AbstractController
{
    public MenuActivator $activator;

    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private EntityManagerInterface $manager,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private TaxeRepository $taxeRepository,
        private Constante $constante,
        private ServiceMonnaies $serviceMonnaies,
        private ServiceTaxes $serviceTaxes,
    ) {
        $this->activator = new MenuActivator(MenuActivator::GROUPE_FINANCE);
    }


    #[Route('/index/{idEntreprise}', name: 'index', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function index($idEntreprise, Request $request)
    {
        $page = $request->query->getInt("page", 1);

        return $this->render('admin/taxe/index.html.twig', [
            'pageName' => $this->translator->trans("taxe_page_name_new"),
            'utilisateur' => $this->getUser(),
            'entreprise' => $this->entrepriseRepository->find($idEntreprise),
            'taxes' => $this->taxeRepository->paginateForEntreprise($idEntreprise, $page),
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

        /** @var Taxe */
        $taxe = new Taxe();
        //Paramètres par défaut
        $taxe->setEntreprise($entreprise);
        $taxe->setCode("");
        $taxe->setDescription("");
        // $taxe->setOrganisation("");
        $taxe->setRedevable(Taxe::REDEVABLE_COURTIER);
        $taxe->setTauxIARD(0);
        $taxe->setTauxVIE(0);

        $form = $this->createForm(TaxeType::class, $taxe);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->manager->persist($taxe);
            $this->manager->flush();
            
            return new Response("Ok");

        }
        return $this->render('admin/taxe/create.html.twig', [
            'pageName' => $this->translator->trans("taxe_page_name_new"),
            'utilisateur' => $user,
            'entreprise' => $entreprise,
            'activator' => $this->activator,
            'form' => $form,
        ]);
    }


    #[Route('/edit/{idEntreprise}/{idTaxe}', name: 'edit', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function edit($idEntreprise, $idTaxe, Request $request)
    {
        /** @var Entreprise $entreprise */
        $entreprise = $this->entrepriseRepository->find($idEntreprise);

        /** @var Utilisateur $user */
        $user = $this->getUser();

        /** @var Taxe */
        $taxe = $this->taxeRepository->find($idTaxe);

        $form = $this->createForm(TaxeType::class, $taxe);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->manager->persist($taxe); //On peut ignorer cette instruction car la fonction flush suffit.
            $this->manager->flush();
            
            return new Response("Ok");

        }
        return $this->render('admin/taxe/edit.html.twig', [
            'pageName' => $this->translator->trans("taxe_page_name_update", [
                ":tax" => $taxe->getCode(),
            ]),
            'utilisateur' => $user,
            'taxe' => $taxe,
            'entreprise' => $entreprise,
            'activator' => $this->activator,
            'form' => $form,
        ]);
    }

    #[Route('/remove/{idEntreprise}/{idTaxe}', name: 'remove', requirements: ['idTaxe' => Requirement::DIGITS, 'idEntreprise' => Requirement::DIGITS], methods: ['DELETE'])]
    public function remove($idEntreprise, $idTaxe, Request $request)
    {
        /** @var Taxe $taxe */
        $taxe = $this->taxeRepository->find($idTaxe);

        $message = $this->translator->trans("taxe_deletion_ok", [
            ":tax" => $taxe->getCode(),
        ]);;
        
        $this->manager->remove($taxe);
        $this->manager->flush();

        $this->addFlash("success", $message);
        return $this->redirectToRoute("admin.taxe.index", [
            'idEntreprise' => $idEntreprise,
        ]);
    }
}
