<?php

namespace App\Controller\Admin;

use App\Entity\Piste;
use App\Entity\Tache;
use App\Entity\Invite;
use DateTimeImmutable;
use App\Entity\Avenant;
use App\Form\PisteType;
use App\Form\TacheType;
use App\Entity\Entreprise;
use App\Entity\Utilisateur;
use App\Constantes\Constante;
use App\Services\ServiceTaxes;
use App\Constantes\MenuActivator;
use App\Entity\Chargement;
use App\Entity\ChargementPourPrime;
use App\Entity\Cotation;
use App\Entity\RevenuPourCourtier;
use App\Entity\Tranche;
use App\Services\ServiceMonnaies;
use App\Repository\PisteRepository;
use App\Repository\TacheRepository;
use App\Repository\InviteRepository;
use App\Repository\AvenantRepository;
use App\Repository\EntrepriseRepository;
use Doctrine\ORM\EntityManagerInterface;
use Proxies\__CG__\App\Entity\Revenu;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Twig\Node\SetNode;

#[Route("/admin/piste", name: 'admin.piste.')]
#[IsGranted('ROLE_USER')]
class PisteController extends AbstractController
{
    public MenuActivator $activator;

    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private EntityManagerInterface $manager,
        private EntrepriseRepository $entrepriseRepository,
        private AvenantRepository $avenantRepository,
        private InviteRepository $inviteRepository,
        private PisteRepository $pisteRepository,
        private Constante $constante,
        private ServiceMonnaies $serviceMonnaies,
        private ServiceTaxes $serviceTaxes,
    ) {
        $this->activator = new MenuActivator(MenuActivator::GROUPE_MARKETING);
    }


    #[Route('/index/{idEntreprise}', name: 'index', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function index($idEntreprise, Request $request)
    {
        $page = $request->query->getInt("page", 1);

        return $this->render('admin/piste/index.html.twig', [
            'pageName' => $this->translator->trans("piste_page_name_new"),
            'utilisateur' => $this->getUser(),
            'entreprise' => $this->entrepriseRepository->find($idEntreprise),
            'pistes' => $this->pisteRepository->paginateForEntreprise($idEntreprise, $page),
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

        /** @var Invite $invite */
        $invite = $this->inviteRepository->findOneByEmail($user->getEmail());

        /** @var Piste $piste */
        $piste = new Piste();
        //Paramètres par défaut
        $piste->setTypeAvenant(Piste::AVENANT_SOUSCRIPTION);
        $piste->setInvite($invite);
        $piste->setExercice((new DateTimeImmutable("now"))->format('Y'));

        $form = $this->createForm(PisteType::class, $piste);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->manager->persist($piste);
            $this->manager->flush();
            $this->addFlash("success", $this->translator->trans("piste_creation_ok", [
                ":piste" => $piste->getNom(),
            ]));
            return $this->redirectToRoute("admin.piste.index", [
                'idEntreprise' => $idEntreprise,
            ]);
        }
        return $this->render('admin/piste/create.html.twig', [
            'pageName' => $this->translator->trans("piste_page_name_new"),
            'utilisateur' => $user,
            'entreprise' => $entreprise,
            'activator' => $this->activator,
            'form' => $form,
        ]);
    }


    #[Route('/edit/{idEntreprise}/{idPiste}', name: 'edit', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function edit($idEntreprise, $idPiste, Request $request)
    {
        /** @var Entreprise $entreprise */
        $entreprise = $this->entrepriseRepository->find($idEntreprise);

        /** @var Utilisateur $user */
        $user = $this->getUser();

        /** @var Piste $piste */
        $piste = $this->pisteRepository->find($idPiste);

        $form = $this->createForm(PisteType::class, $piste);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->manager->persist($piste); //On peut ignorer cette instruction car la fonction flush suffit.
            $this->manager->flush();
            $this->addFlash("success", $this->translator->trans("piste_edition_ok", [
                ":piste" => $piste->getNom(),
            ]));

            //On doit rester sur la page d'édition
            // return $this->redirectToRoute("admin.piste.index", [
            //     'idEntreprise' => $idEntreprise,
            // ]);
        }
        return $this->render('admin/piste/edit.html.twig', [
            'pageName' => $this->translator->trans("piste_page_name_update", [
                ":piste" => $piste->getNom(),
            ]),
            'utilisateur' => $user,
            'piste' => $piste,
            'entreprise' => $entreprise,
            'activator' => $this->activator,
            'form' => $form,
        ]);
    }

    #[Route('/remove/{idEntreprise}/{idPiste}', name: 'remove', requirements: ['idPiste' => Requirement::DIGITS, 'idEntreprise' => Requirement::DIGITS], methods: ['DELETE'])]
    public function remove($idEntreprise, $idPiste, Request $request)
    {
        /** @var Piste $piste */
        $piste = $this->pisteRepository->find($idPiste);

        $message = $this->translator->trans("piste_deletion_ok", [
            ":piste" => $piste->getNom(),
        ]);;

        $this->manager->remove($piste);
        $this->manager->flush();

        $this->addFlash("success", $message);
        return $this->redirectToRoute("admin.piste.index", [
            'idEntreprise' => $idEntreprise,
        ]);
    }

    #[Route('/endorse/{idEntreprise}/{idAvenant}/{mouvement}', name: 'endorse', requirements: ['idAvenant' => Requirement::DIGITS, 'mouvement' => Requirement::DIGITS, 'idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function endorse($idEntreprise, $idAvenant, $mouvement, Request $request)
    {
        /** @var Avenant $avenant */
        $avenant = $this->avenantRepository->find($idAvenant);
        if ($avenant != null) {

            /** @var Utilisateur $user */
            $user = $this->getUser();

            /** @var Invite $invite */
            $invite = $this->inviteRepository->findOneByEmail($user->getEmail());

            /** @var Cotation $cotation */
            $cotationAvenant = $avenant->getCotation();

            /** @var Piste $piste */
            $pisteAvenant = $cotationAvenant->getPiste();

            $referencePolice = $avenant->getReferencePolice();

            $nomPiste = "Avenant n°" . ($avenant->getNumero() + 1) . " • " . $this->constante->getTypeAvenant($mouvement) . " • Pol.:" . $referencePolice . " • " . $pisteAvenant->getClient() . " • " . $pisteAvenant->getRisque()->getCode();

            /** @var Piste $piste */
            $piste = new Piste();
            //Paramètres par défaut
            $piste->setNom($nomPiste);
            $piste->setPrimePotentielle($pisteAvenant->getPrimePotentielle());
            $piste->setCommissionPotentielle($pisteAvenant->getCommissionPotentielle());
            $piste->setDescriptionDuRisque($nomPiste);
            $piste->setClient($pisteAvenant->getClient());
            $piste->setRisque($pisteAvenant->getRisque());
            //Chargements des partenaires éventuels
            foreach ($pisteAvenant->getPartenaires() as $partenaire) {
                $piste->addPartenaire($partenaire);
            }
            //Chargements des conditions de partage éventuelles
            foreach ($pisteAvenant->getConditionsPartageExceptionnelles() as $condition) {
                $piste->addConditionsPartageExceptionnelle($condition);
            }
            $piste->setTypeAvenant($mouvement);
            $piste->setInvite($invite);
            $piste->setExercice((new DateTimeImmutable("now"))->format('Y'));

            /** @var Cotation $newCotation */
            $newCotation = new Cotation();
            $newCotation->setDuree($cotationAvenant->getDuree());
            $newCotation->setAssureur($cotationAvenant->getAssureur());
            $newCotation->setNom("Proposition • " . $cotationAvenant->getAssureur()->getNom() . " • " . $this->constante->getTypeAvenant($mouvement) . " • Av. n°" . ($avenant->getNumero() + 1) . " • " . $pisteAvenant->getRisque()->getCode() . " • " . $pisteAvenant->getClient()->getNom());
            //On défini les chargements par défaut
            foreach ($cotationAvenant->getChargements() as $chargement) {
                $newCotation->addChargement(
                    (new ChargementPourPrime())
                        ->setNom($chargement->getNom())
                        ->setCreatedAt(new DateTimeImmutable("now"))
                        ->setUpdatedAt(new DateTimeImmutable("now"))
                        ->setType($chargement->getType())
                        ->setMontantFlatExceptionel($chargement->getMontantFlatExceptionel())
                );
            }
            //On défini les tranches par défaut
            foreach ($cotationAvenant->getTranches() as $tranche) {
                $newCotation->addTranch(
                    (new Tranche())
                        ->setNom($tranche->getNom())
                        ->setPayableAt($tranche->getPayableAt())
                        ->setEcheanceAt($tranche->getEcheanceAt())
                        ->setMontantFlat($tranche->getMontantFlat())
                        ->setCreatedAt($tranche->getCreatedAt())
                        ->setUpdatedAt($tranche->getUpdatedAt())
                        ->setPourcentage($tranche->getPourcentage())
                );
            }
            //On défini les revenus par défaut
            foreach ($cotationAvenant->getRevenus() as $revenu) {
                $newCotation->addRevenu(
                    (new RevenuPourCourtier)
                        ->setNom($revenu->getNom())
                        ->setTypeRevenu($revenu->getTypeRevenu())
                        ->setCreatedAt(new DateTimeImmutable("now"))
                        ->setUpdatedAt(new DateTimeImmutable("now"))
                        ->setMontantFlatExceptionel($revenu->getMontantFlatExceptionel())
                );
            }

            //Défini la cotation
            $piste->addCotation($newCotation);

            // dd("Je suis ici", $avenant, $mouvement);

            $form = $this->createForm(PisteType::class, $piste);
            // $form->handleRequest($request);
            return $this->render('admin/piste/create.html.twig', [
                'pageName' => $this->translator->trans("piste_page_name_new"),
                'utilisateur' => $user,
                'entreprise' => $user->getConnectedTo(),
                'activator' => $this->activator,
                'form' => $form,
            ]);
        } else {
            return $this->redirectToRoute("admin.avenant.index", [
                'idEntreprise' => $idEntreprise,
            ]);
        }
    }
}
