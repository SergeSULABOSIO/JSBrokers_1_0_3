<?php

namespace App\Controller\Admin;

use App\Entity\Entreprise;
use App\Constantes\Constante;
use App\Constantes\MenuActivator;
use App\Entity\Assureur;
use App\Entity\Contact;
use App\Entity\Cotation;
use App\Entity\Piste;
use App\Entity\Risque;
use App\Entity\Taxe;
use App\Form\AssureurType;
use App\Form\ContactType;
use App\Form\CotationType;
use App\Repository\AssureurRepository;
use App\Repository\ContactRepository;
use App\Repository\CotationRepository;
use App\Repository\InviteRepository;
use App\Repository\EntrepriseRepository;
use App\Services\ServiceTaxes;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;


#[Route("/admin/cotation", name: 'admin.cotation.')]
#[IsGranted('ROLE_USER')]
class CotationController extends AbstractController
{
    public MenuActivator $activator;

    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private EntityManagerInterface $manager,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private CotationRepository $cotationRepository,
        private Constante $constante,
        private ServiceTaxes $serviceTaxes,
    ) {
        $this->activator = new MenuActivator(MenuActivator::GROUPE_PRODUCTION);
    }


    #[Route('/index/{idEntreprise}', name: 'index', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function index($idEntreprise, Request $request)
    {
        $page = $request->query->getInt("page", 1);

        return $this->render('admin/cotation/index.html.twig', [
            'pageName' => $this->translator->trans("cotation_page_name_new"),
            'utilisateur' => $this->getUser(),
            'entreprise' => $this->entrepriseRepository->find($idEntreprise),
            'cotations' => $this->cotationRepository->paginateForEntreprise($idEntreprise, $page),
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

        /** @var Cotation $cotation */
        $cotation = new Cotation();
        //Paramètres par défaut
        // $contact->setEntreprise($entreprise);

        $form = $this->createForm(CotationType::class, $cotation);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->manager->persist($cotation);
            $this->manager->flush();
            $this->addFlash("success", $this->translator->trans("cotation_creation_ok", [
                ":cotation" => $cotation->getNom(),
            ]));
            return $this->redirectToRoute("admin.cotation.index", [
                'idEntreprise' => $idEntreprise,
            ]);
        }
        return $this->render('admin/cotation/create.html.twig', [
            'pageName' => $this->translator->trans("cotation_page_name_new"),
            'utilisateur' => $user,
            'entreprise' => $entreprise,
            'activator' => $this->activator,
            'form' => $form,
        ]);
    }


    #[Route('/edit/{idEntreprise}/{idCotation}', name: 'edit', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function edit($idEntreprise, $idCotation, Request $request)
    {
        /** @var Entreprise $entreprise */
        $entreprise = $this->entrepriseRepository->find($idEntreprise);

        /** @var Utilisateur $user */
        $user = $this->getUser();

        /** @var Cotation $cotation */
        $cotation = $this->cotationRepository->find($idCotation);

        $form = $this->createForm(CotationType::class, $cotation, [
            "cotation" => $cotation
        ]);
        if ($cotation) {
            $form->get('prime')->setData($this->constante->Cotation_getMontant_prime_payable_par_client($cotation));

            $comNette = $this->constante->Cotation_getMontant_commission_payable_par_assureur($cotation) + $this->constante->Cotation_getMontant_commission_payable_par_client($cotation);
            $tvaCom = 0;

            foreach ($this->serviceTaxes->getTaxesPayableParAssureur() as $taxeAss) {
                /** @var Taxe $taxeAssureur */
                $taxeAssureur = $taxeAss;
                // dd($taxeAssureur, $cotation);
                if ($cotation->getPiste()) {
                    /** @var Piste $piste */
                    $piste = $cotation->getPiste();
                    if ($piste->getRisque()) {
                        /** @var Risque $risque */
                        $risque = $piste->getRisque();
                        if ($risque->getBranche() == Risque::BRANCHE_IARD_OU_NON_VIE) {
                            $tvaCom += $comNette * $taxeAssureur->getTauxIARD();
                        }else{
                            $tvaCom += $comNette * $taxeAssureur->getTauxVIE();
                        }
                    }
                }
            }

            $comTTC = $comNette + $tvaCom;
            $form->get('commissionNette')->setData($comNette);
            $form->get('commissionNetteTva')->setData($tvaCom);
            $form->get('commissionTTC')->setData($comTTC);

            // dd($this->serviceTaxes->getTaxesPayableParCourtier());
        }
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->manager->persist($cotation); //On peut ignorer cette instruction car la fonction flush suffit.
            $this->manager->flush();
            $this->addFlash("success", $this->translator->trans("cotation_edition_ok", [
                ":cotation" => $cotation->getNom(),
            ]));
            // return $this->redirectToRoute("admin.cotation.index", [
            //     'idEntreprise' => $idEntreprise,
            // ]);
        }
        return $this->render('admin/cotation/edit.html.twig', [
            'pageName' => $this->translator->trans("cotation_page_name_update", [
                ":cotation" => $cotation->getNom(),
            ]),
            'utilisateur' => $user,
            'cotation' => $cotation,
            'entreprise' => $entreprise,
            'activator' => $this->activator,
            'form' => $form,
        ]);
    }

    #[Route('/remove/{idEntreprise}/{idCotation}', name: 'remove', requirements: ['idCotation' => Requirement::DIGITS, 'idEntreprise' => Requirement::DIGITS], methods: ['DELETE'])]
    public function remove($idEntreprise, $idCotation, Request $request)
    {
        /** @var Cotation $cotation */
        $cotation = $this->cotationRepository->find($idCotation);

        $message = $this->translator->trans("cotation_deletion_ok", [
            ":cotation" => $cotation->getNom(),
        ]);

        $this->manager->remove($cotation);
        $this->manager->flush();

        $this->addFlash("success", $message);
        return $this->redirectToRoute("admin.cotation.index", [
            'idEntreprise' => $idEntreprise,
        ]);
    }
}
