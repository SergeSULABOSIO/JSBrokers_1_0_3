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
use App\Services\ServiceMonnaies;
use App\Services\ServiceTaxes;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Util\Json;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

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
        private ServiceMonnaies $serviceMonnaies,
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

        /** @var Cotation $cotation */
        $cotation = new Cotation();
        //Paramètres par défaut
        // $contact->setEntreprise($entreprise);

        $form = $this->createForm(CotationType::class, $cotation, [
            "cotation" => $cotation
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->manager->persist($cotation);
            $this->manager->flush();

            return new Response(
                "Ok__1986__" .
                    count($cotation->getChargements()) . "__1986__" .
                    count($cotation->getRevenus()) . "__1986__" .
                    count($cotation->getAvenants()) . "__1986__" .
                    count($cotation->getTranches()) . "__1986__" .
                    count($cotation->getTaches()) . "__1986__" .
                    count($cotation->getDocuments())
            );
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

            $comNette = $this->constante->Cotation_getMontant_commission_ht($cotation, -1, false);
            $tvaCom = 0;

            if ($cotation->getPiste()) {
                /** @var Piste $piste */
                $piste = $cotation->getPiste();
                if ($piste->getRisque()) {
                    /** @var Risque $risque */
                    $tvaCom = $this->serviceTaxes->getMontantTaxe(
                        $comNette,
                        $piste->getRisque()->getBranche() == Risque::BRANCHE_IARD_OU_NON_VIE,
                        true
                    );
                }
            }

            $form->get('commissionNette')->setData($comNette);
            $form->get('commissionNetteTva')->setData($tvaCom);
            $form->get('commissionTTC')->setData($comNette + $tvaCom);

            // dd($this->serviceTaxes->getTaxesPayableParCourtier());
        }
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->manager->persist($cotation); //On peut ignorer cette instruction car la fonction flush suffit.
            $this->manager->flush();

            //Le serveur renvoie un objet JSON
            return $this->getJsonData($cotation);
        }
        return $this->render('admin/cotation/edit.html.twig', [
            'pageName' => $this->translator->trans("cotation_page_name_update", [
                ":cotation" => $cotation->getNom(),
            ]),
            'utilisateur' => $user,
            'cotation' => $cotation,
            'entreprise' => $entreprise,
            'constante' => $this->constante,
            'serviceMonnaie' => $this->serviceMonnaies,
            'serviceTaxe' => $this->serviceTaxes,
            'activator' => $this->activator,
            'form' => $form,
        ]);
    }


    #[Route('/viewRevenu/{idCotation}', name: 'viewRevenu', requirements: ['idCotation' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function viewRevenu($idCotation)
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        /** @var Cotation $cotation */
        $cotation = $this->cotationRepository->find($idCotation);

        return $this->render('admin/cotation/view/revenucourtier.html.twig', [
            'utilisateur' => $user,
            'cotation' => $cotation,
            'constante' => $this->constante,
            'serviceMonnaie' => $this->serviceMonnaies,
            'serviceTaxe' => $this->serviceTaxes,
            'activator' => $this->activator,
        ]);
    }

    private function getJsonData(?Cotation $cotation)
    {
        return $this->json(json_encode([
            "reponse" => "Ok",
            "nbChargements" => count($cotation->getChargements()),
            "idcotation" => $cotation->getId(),
            "nbRevenus" => count($cotation->getRevenus()),
            "nbAvenants" => count($cotation->getAvenants()),
            "nbTranches" => count($cotation->getTranches()),
            "nbTaches" => count($cotation->getTaches()),
            "nbDocuments" => count($cotation->getDocuments()),
            "primeTTC" => "" . number_format($this->constante->Cotation_getMontant_prime_payable_par_client($cotation), 2, ",", " "),
            "commissionHT" => "" . number_format($this->constante->Cotation_getMontant_commission_ht($cotation, -1, false), 2, ",", " "),
            "commissionTaxe" => "" . number_format($this->constante->Cotation_getMontant_taxe_payable_par_assureur($cotation, -1, false), 2, ",", " "),
            "commissionTTC" => "" . number_format($this->constante->Cotation_getMontant_commission_ttc($cotation, -1, false), 2, ",", " "),
        ]));
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
