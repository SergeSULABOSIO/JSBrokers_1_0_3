<?php

namespace App\Controller\Admin;


use App\DTO\DemandeContactDTO;
use App\Form\DemandeContactType;
use Symfony\Component\Mime\Email;
use App\Event\DemandeContactEvent;
use App\Repository\InviteRepository;
use App\Repository\EntrepriseRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route("/admin/demande_contact", name: 'admin.demande.contact.')]
class DemandeContactController extends AbstractController
{
    public function __construct(
        private TranslatorInterface $translator,
        private UrlGeneratorInterface $urlGenerator,
        private MailerInterface $mailer,
        private EntityManagerInterface $manager,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
    ) {}

    #[Route(name: 'index')]
    public function index(Request $request, EventDispatcherInterface $dispatcher): Response
    {
        /** @var DemandeContactDTO $data */
        $data = new DemandeContactDTO();
        $form = $this->createForm(DemandeContactType::class, $data);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                //Lancer un évènement
                $dispatcher->dispatch(new DemandeContactEvent($data));
                $this->addFlash("success", $this->translator->trans("contact_email_sent_ok"));
            } catch (\Throwable $th) {
                //throw $th;
                $this->addFlash("danger", $this->translator->trans("contact_email_sent_error"));
            }
            return $this->redirectToRoute('admin.demande.contact.index');
        }
        return $this->render('admin/demande_contact/index.html.twig', [
            'pageName' => $this->translator->trans("contact_email_title"),
            'form' => $form,
        ]);
    }
}
