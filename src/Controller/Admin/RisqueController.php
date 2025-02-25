<?php

namespace App\Controller\Admin;

use App\Entity\Revenu;
use App\Entity\Risque;
use App\Form\RevenuType;
use App\Form\RisqueType;
use App\Entity\Entreprise;
use App\Constantes\Constante;
use App\Services\ServiceTaxes;
use App\Constantes\MenuActivator;
use App\Services\ServiceMonnaies;
use App\Repository\InviteRepository;
use App\Repository\RevenuRepository;
use App\Repository\RisqueRepository;
use App\Repository\EntrepriseRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;


#[Route("/admin/risque", name: 'admin.risque.')]
#[IsGranted('ROLE_USER')]
class RisqueController extends AbstractController
{
    public MenuActivator $activator;

    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private EntityManagerInterface $manager,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private RisqueRepository $risqueRepository,
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

        return $this->render('admin/risque/index.html.twig', [
            'pageName' => $this->translator->trans("risque_page_name_new"),
            'utilisateur' => $this->getUser(),
            'entreprise' => $this->entrepriseRepository->find($idEntreprise),
            'risques' => $this->risqueRepository->paginateForEntreprise($idEntreprise, $page),
            'page' => $page,
            'constante' => $this->constante,
            'activator' => $this->activator,
            'serviceMonnaie' => $this->serviceMonnaies,
            'serviceTaxe' => $this->serviceTaxes,
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

        /** @var Risque $risque */
        $risque = new Risque();
        //Paramètres par défaut
        $risque->setCode("RSK" . (rand(0, 100)));
        $risque->setNomComplet("RISQUE" . (rand(2000, 3000)));
        $risque->setPourcentageCommissionSpecifiqueHT(0.1);
        $risque->setBranche(Risque::BRANCHE_IARD_OU_NON_VIE);
        $risque->setImposable(true);
        $risque->setEntreprise($entreprise);

        $form = $this->createForm(RisqueType::class, $risque);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->manager->persist($risque);
            $this->manager->flush();
            $this->addFlash("success", $this->translator->trans("risque_creation_ok", [
                ":risque" => $risque->getNomComplet(),
            ]));
            return $this->redirectToRoute("admin.risque.index", [
                'idEntreprise' => $idEntreprise,
            ]);
        }
        return $this->render('admin/risque/create.html.twig', [
            'pageName' => $this->translator->trans("risque_page_name_new"),
            'utilisateur' => $user,
            'entreprise' => $entreprise,
            'activator' => $this->activator,
            'form' => $form,
        ]);
    }


    #[Route('/edit/{idEntreprise}/{idRisque}', name: 'edit', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function edit($idEntreprise, $idRisque, Request $request)
    {
        /** @var Entreprise $entreprise */
        $entreprise = $this->entrepriseRepository->find($idEntreprise);

        /** @var Utilisateur $user */
        $user = $this->getUser();

        /** @var Risque $risque */
        $risque = $this->risqueRepository->find($idRisque);

        $form = $this->createForm(RisqueType::class, $risque);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->manager->persist($risque); //On peut ignorer cette instruction car la fonction flush suffit.
            $this->manager->flush();
            $this->addFlash("success", $this->translator->trans("risque_edition_ok", [
                ":risque" => $risque->getNomComplet(),
            ]));
            return $this->redirectToRoute("admin.risque.index", [
                'idEntreprise' => $idEntreprise,
            ]);
        }
        return $this->render('admin/risque/edit.html.twig', [
            'pageName' => $this->translator->trans("risque_page_name_update", [
                ":risque" => $risque->getNomComplet(),
            ]),
            'utilisateur' => $user,
            'risque' => $risque,
            'entreprise' => $entreprise,
            'activator' => $this->activator,
            'form' => $form,
        ]);
    }

    #[Route('/remove/{idEntreprise}/{idRisque}', name: 'remove', requirements: ['idRisque' => Requirement::DIGITS, 'idEntreprise' => Requirement::DIGITS], methods: ['DELETE'])]
    public function remove($idEntreprise, $idRisque, Request $request)
    {
        /** @var Risque $risque */
        $risque = $this->risqueRepository->find($idRisque);

        $message = $this->translator->trans("risque_deletion_ok", [
            ":risque" => $risque->getNomComplet(),
        ]);;
        
        $this->manager->remove($risque);
        $this->manager->flush();

        $this->addFlash("success", $message);
        return $this->redirectToRoute("admin.risque.index", [
            'idEntreprise' => $idEntreprise,
        ]);
    }
}
