<?php

namespace App\Controller\Admin;

use App\Entity\Piste;
use App\Entity\Tache;
use App\Entity\Invite;
use DateTimeImmutable;
use Twig\Node\SetNode;
use App\Entity\Avenant;
use App\Entity\Tranche;
use App\Form\PisteType;
use App\Form\TacheType;
use App\Entity\Cotation;
use App\Entity\Chargement;
use App\Entity\Entreprise;
use App\Entity\Utilisateur;
use App\Constantes\Constante;
use App\Services\ServiceDates;
use App\Services\ServiceTaxes;
use App\Constantes\MenuActivator;
use App\Services\ServiceMonnaies;
use App\Entity\RevenuPourCourtier;
use App\Entity\ChargementPourPrime;
use App\Repository\PisteRepository;
use App\Repository\TacheRepository;
use App\Repository\InviteRepository;
use App\Repository\AvenantRepository;
use Proxies\__CG__\App\Entity\Revenu;
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
        private ServiceDates $serviceDates,
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
            $this->manager->persist($piste); //On peut ignorer cette instruction car la fonction flush suffit.
            $this->manager->flush();

            return new Response(
                count($piste->getCotations()) . "__1986__" . 
                count($piste->getTaches()) . "__1986__" . 
                count($piste->getDocuments()) . "__1986__" . 
                count($piste->getConditionsPartageExceptionnelles())
            );
        }
        return $this->render('admin/piste/create.html.twig', [
            'pageName' => $this->translator->trans("piste_page_name_new"),
            'utilisateur' => $user,
            'entreprise' => $entreprise,
            'activator' => $this->activator,
            'piste' => $piste,
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
            // return new Response("Ok");
            return new Response(
                count($piste->getCotations()) . "__1986__" . 
                count($piste->getTaches()) . "__1986__" . 
                count($piste->getDocuments()) . "__1986__" . 
                count($piste->getConditionsPartageExceptionnelles())
            );
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
        /** @var Utilisateur $user */
        $user = $this->getUser();

        /** @var Avenant $avenant */
        $avenantDeBase = $this->avenantRepository->find($idAvenant);
        // dd($avenantDeBase);

        if ($avenantDeBase != null) {

            $newPiste = $this->buildNewPisteFromAvenant($user, $avenantDeBase, $mouvement);

            $form = $this->createForm(PisteType::class, $newPiste);
            $form->handleRequest($request);

            if ($form->isSubmitted() && $form->isValid()) {
                $this->manager->persist($newPiste); //On peut ignorer cette instruction car la fonction flush suffit.
                $this->manager->flush();
                return new Response("Ok");
            }
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

    private function buildNewPisteFromAvenant(Utilisateur $user, Avenant $avenantDeBase, $mouvement): Piste
    {
        /** @var Invite $invite */
        $invite = $this->inviteRepository->findOneByEmail($user->getEmail());

        /** @var Cotation $cotation */
        $cotationAvenantDeBase = $avenantDeBase->getCotation();

        /** @var Piste $piste */
        $pisteAvenant = $cotationAvenantDeBase->getPiste();

        $referencePolice = $avenantDeBase->getReferencePolice();

        $newCalculatedPeriod = $this->constante->calculerPeriodeCouverture($mouvement, $avenantDeBase);

        $nomPiste = "Avenant n°" . $newCalculatedPeriod['New Numero Avenant'] . " • " . $this->constante->getTypeAvenant($mouvement) . " • Pol.:" . $referencePolice . " • " . $pisteAvenant->getClient() . " • " . $pisteAvenant->getRisque()->getCode();

        /** @var Piste $piste */
        $piste = new Piste();
        //Paramètres par défaut
        $piste->setAvenantDeBase($avenantDeBase);
        $piste->setRenewalCondition($avenantDeBase->getCotation()->getPiste()->getRenewalCondition());
        $piste->setNom($nomPiste);
        $piste->setDescriptionDuRisque($this->constante->getTypeAvenant($mouvement) . " • " . $avenantDeBase->getDescription());
        $piste->setPrimePotentielle($pisteAvenant->getPrimePotentielle());
        $piste->setCommissionPotentielle($pisteAvenant->getCommissionPotentielle());
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
        $newCotation->setDuree($cotationAvenantDeBase->getDuree());
        $newCotation->setAssureur($cotationAvenantDeBase->getAssureur());
        $newCotation->setNom("Proposition • " . $cotationAvenantDeBase->getAssureur()->getNom() . " • " . $this->constante->getTypeAvenant($mouvement) . " • Av. n°" . $newCalculatedPeriod['New Numero Avenant'] . " • " . $pisteAvenant->getRisque()->getCode() . " • " . $pisteAvenant->getClient()->getNom());
        //On défini les chargements par défaut
        foreach ($cotationAvenantDeBase->getChargements() as $chargement) {
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
        foreach ($cotationAvenantDeBase->getTranches() as $tranche) {
            $newCotation->addTranch(
                (new Tranche())
                    ->setNom($tranche->getNom())
                    ->setPayableAt($newCalculatedPeriod['Effect Date'])
                    ->setEcheanceAt($newCalculatedPeriod['Expiry Date'])
                    ->setMontantFlat($tranche->getMontantFlat())
                    ->setCreatedAt($tranche->getCreatedAt())
                    ->setUpdatedAt($tranche->getUpdatedAt())
                    ->setPourcentage($tranche->getPourcentage())
            );
        }
        //On défini les revenus par défaut
        foreach ($cotationAvenantDeBase->getRevenus() as $revenu) {
            $newCotation->addRevenu(
                (new RevenuPourCourtier)
                    ->setNom($revenu->getNom())
                    ->setTypeRevenu($revenu->getTypeRevenu())
                    ->setCreatedAt(new DateTimeImmutable("now"))
                    ->setUpdatedAt(new DateTimeImmutable("now"))
                    ->setMontantFlatExceptionel($revenu->getMontantFlatExceptionel())
            );
        }

        //On ne charge pas encore l'avenant car à ce stade on n'est pas encore sur que c'est cofirmé.
        $newCotation->addAvenant(
            (new Avenant())
                ->setNumero($newCalculatedPeriod['New Numero Avenant'])
                ->setStartingAt($newCalculatedPeriod['Effect Date'])
                ->setEndingAt($newCalculatedPeriod['Expiry Date'])
                ->setReferencePolice($referencePolice)
                ->setDescription($this->constante->getTypeAvenant($mouvement) . " • Av. n°" . $newCalculatedPeriod['New Numero Avenant'] . " • " . $pisteAvenant->getRisque()->getCode() . " • " . $pisteAvenant->getClient()->getNom())
        );

        //Défini la cotation
        $piste->addCotation($newCotation);
        return $piste;
    }
}
