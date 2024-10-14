<?php

namespace App\Controller\Admin;


use App\DTO\ContactDTO;
use App\Form\ContactType;
use Symfony\Component\Mime\Email;
use App\Repository\InviteRepository;
use App\Repository\EntrepriseRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

#[Route("/admin/demande_contact", name: 'admin.demande.contact.')]
class DemandeContactController extends AbstractController
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private MailerInterface $mailer,
        private EntityManagerInterface $manager,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
    ) {}

    #[Route(name: 'index')]
    public function index(Request $request, MailerInterface $mailer): Response
    {
        /** @var ContactDTO */
        $data = new ContactDTO();
        $form = $this->createForm(ContactType::class, $data);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            # C'est ici qu'on va gérer l'envoie de l'email de l'utilisateur
            $email = (new TemplatedEmail())
                ->to('contact@demo.fr')
                ->from($data->email)
                //->cc('cc@example.com')
                //->bcc('bcc@example.com')
                //->replyTo('fabien@example.com')
                ->priority(Email::PRIORITY_HIGH)
                ->subject('Demande de contact')
                // ->text($data->message)
                // ->html('<p>' . $data->message . '</p>');
                ->htmlTemplate("home/mail/message_demande_de_contact.html.twig")
                ->context(["data" => $data]);
            $mailer->send($email);
            $this->addFlash("success", "L'email a bien été envoyé. Nous vous reviendrons au plus vite.");
            return $this->redirectToRoute('admin.demande.contact.index');
            // return $this->redirectToRoute('app_contact');
        }
        return $this->render('admin/demande_contact/index.html.twig', [
            'pageName' => 'Formulaire de contact',
            'form' => $form,
        ]);
    }

}
