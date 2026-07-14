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
use App\Service\Workspace\WorkspaceAccessResolver;
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
    //Note (débit ou crédit)
    public const TYPE_OUTPUT_NOTE = 0;
    //Note (débit ou crédit)
    public const TYPE_OUTPUT_BORDEREAU = 1;   


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
        private WorkspaceAccessResolver $accessResolver,
    ) {}

    /**
     * FAIL-CLOSED : imprimer une note = lire la rubrique Notes. L'invité doit
     * appartenir à l'entreprise demandée et avoir la lecture sur Note ; la note
     * doit appartenir à cette entreprise (sinon « introuvable », sans fuite).
     */
    private function resolveNoteImprimable(?Entreprise $entreprise, $idNote): ?Note
    {
        if ($entreprise === null) {
            return null;
        }

        /** @var Utilisateur $utilisateur */
        $utilisateur = $this->getUser();
        $invite = $this->accessResolver->resolveConnectedInvite($utilisateur);
        if ($invite === null || $invite->getEntreprise()?->getId() !== $entreprise->getId()
            || !$this->accessResolver->canRead($invite, 'Note')) {
            throw $this->createAccessDeniedException("L'impression des notes est hors de votre périmètre d'accès.");
        }

        /** @var ?Note $note */
        $note = $this->noteRepository->find($idNote);
        if ($note === null || $note->getEntreprise()?->getId() !== $entreprise->getId()) {
            return null;
        }

        return $note;
    }


    #[Route(
        '/imprimerNote/{idEntreprise}/{idNote}/{currentURL}',
        name: 'imprimer_note',
        requirements: [
            'idEntreprise' => Requirement::DIGITS,
            'idNote' => Requirement::DIGITS,
            'currentURL' => Requirement::CATCH_ALL
        ]
    )]
    public function imprimerNote($currentURL, $idEntreprise, $idNote, ServiceTcpdf $serviceTcpdf): Response
    {
        /** @var Utilisateur $utilisateur */
        $utilisateur = $this->getUser();

        /** @var Entreprise $entreprise */
        $entreprise = $this->entrepriseRepository->find($idEntreprise);

        $note = $this->resolveNoteImprimable($entreprise, $idNote);

        if ($note != null) {
            return $this->produirePDF($serviceTcpdf, $entreprise, $utilisateur, $note, self::TYPE_OUTPUT_NOTE);
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
    public function imprimerBordereauNote($currentURL, $idEntreprise, $idNote, ServiceTcpdf $serviceTcpdf): Response
    {
        /** @var Utilisateur $utilisateur */
        $utilisateur = $this->getUser();

        /** @var Entreprise $entreprise */
        $entreprise = $this->entrepriseRepository->find($idEntreprise);

        $note = $this->resolveNoteImprimable($entreprise, $idNote);

        if ($note != null) {
            return $this->produirePDF($serviceTcpdf, $entreprise, $utilisateur, $note, self::TYPE_OUTPUT_BORDEREAU);
        } else {
            $this->addFlash("danger", "Désolé " . $utilisateur->getNom() . ", la note est introuvable dans la base de données.");
            return $this->redirect($currentURL);
        }
    }

    public function produirePDF(ServiceTcpdf $serviceTcpdf, Entreprise $entreprise, Utilisateur $utilisateur, Note $note, $typeOutPut): Response
    {
        $twig_note = 'admin/etats/note/tcpdf_note.html.twig';
        $twig_bordereau = 'admin/etats/note/tcpdf_bordereau.html.twig';
        $html = $this->renderView(
            $typeOutPut == self::TYPE_OUTPUT_NOTE ? $twig_note : $twig_bordereau,
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

        // dd($note);

        $withHeader = false;
        $withFooter = true;
        $pdf = $serviceTcpdf->getTcpdf($typeOutPut == self::TYPE_OUTPUT_NOTE ? "P" : "L", $note->getNom(), $withHeader, $withFooter);
        $pdf->writeHTML($html, true, false, true, false, '');

        $fileName = ($typeOutPut == self::TYPE_OUTPUT_NOTE ? "Note-" : "Bordereau-") . $note->getId() . ".pdf";

        $pdfData = $pdf->Output($fileName, 'S'); // 'S' pour récupérer le contenu du PDF

        return new Response($pdfData, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $fileName . '"',
        ]);
    }
}
