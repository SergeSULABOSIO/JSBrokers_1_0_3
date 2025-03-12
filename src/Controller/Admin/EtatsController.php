<?php

namespace App\Controller\Admin;

// namespace App\Controller;

use App\Entity\Note;
use DateTimeImmutable;
use App\Entity\Entreprise;
use App\Entity\Utilisateur;
use App\Constantes\Constante;
use App\Services\ServiceTaxes;
use App\Services\ServiceMonnaies;
use App\Repository\NoteRepository;
use App\Repository\InviteRepository;
use App\Repository\EntrepriseRepository;
use App\Services\ServiceTcpdf;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;


#[Route("/admin/etats", name: 'admin.etats.')]
#[IsGranted('ROLE_USER')]
class EtatsController extends AbstractController
{
    public function __construct(
        private MailerInterface $mailer,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private NoteRepository $noteRepository,
        private TranslatorInterface $translator,
        private EntityManagerInterface $manager,
        private Constante $constante,
        private ServiceMonnaies $serviceMonnaies,
        private ServiceTaxes $serviceTaxes,
    ) {}


    #[Route(
        '/imprimerNote/{idEntreprise}/{idNote}/{currentURL}',
        name: 'imprimer_note',
        requirements: [
            'idEntreprise' => Requirement::DIGITS,
            'idNote' => Requirement::DIGITS,
            'currentURL' => Requirement::CATCH_ALL
        ]
    )]
    public function imprimerNote($currentURL, $idEntreprise, $idNote, Request $request, ServiceTcpdf $serviceTcpdf): Response
    {
        /** @var Utilisateur $utilisateur */
        $utilisateur = $this->getUser();

        /** @var Entreprise $entreprise */
        $entreprise = $this->entrepriseRepository->find($idEntreprise);

        /** @var Note $note */
        $note = $this->noteRepository->find($idNote);

        if ($note != null) {
            return $this->executerNoteTCPDF($serviceTcpdf, $entreprise, $utilisateur, $note);
        } else {
            $this->addFlash("danger", "Désolé " . $utilisateur->getNom() . ", la note est introuvable dans la base de données.");
            return $this->redirect($currentURL);
        }
    }

    #[Route(
        '/imprimerBordereauNote/{idEntreprise}/{idNote}/{currentURL}',
        name: 'imprimer_bordereau_note',
        requirements: [
            'idEntreprise' => Requirement::DIGITS,
            'idNote' => Requirement::DIGITS,
            'currentURL' => Requirement::CATCH_ALL
        ]
    )]
    public function imprimerBordereauNote($currentURL, $idEntreprise, $idNote, Request $request): Response
    {
        /** @var Utilisateur $utilisateur */
        $utilisateur = $this->getUser();

        /** @var Entreprise $entreprise */
        $entreprise = $this->entrepriseRepository->find($idEntreprise);

        /** @var Note $note */
        $note = $this->noteRepository->find($idNote);

        if ($note != null) {
            // return $this->executerBordereauDomPDF($entreprise, $utilisateur, $note);
            return new Response();
        } else {
            $this->addFlash("danger", "Désolé " . $utilisateur->getNom() . ", la note est introuvable dans la base de données.");
            return $this->redirect($currentURL);
        }
    }

    public function executerNoteTCPDF(ServiceTcpdf $serviceTcpdf, Entreprise $entreprise, Utilisateur $utilisateur, Note $note): Response
    {
        $html = $this->renderView(
            'admin/etats/note/tcpdf_index.html.twig',
            [
                'entreprise' => $entreprise,
                'utilisateur' => $utilisateur,
                'note' => $note,
                'constante' => $this->constante,
                'serviceMonnaie' => $this->serviceMonnaies,
                'serviceTaxe' => $this->serviceTaxes,
                'date' => new DateTimeImmutable("now"),
            ]
        );
        $pdf = $serviceTcpdf->getTcpdf("P", $note->getNom(), true, true);
        $pdf->writeHTML($html, true, false, true, false, '');
        $fileName = "Note-" . $note->getId() . ".pdf";
        $pdfData = $pdf->Output($fileName, 'S'); // 'S' pour récupérer le contenu du PDF

        return new Response($pdfData, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $fileName . '"',
        ]);
    }
}
