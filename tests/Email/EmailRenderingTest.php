<?php

namespace App\Tests\Email;

use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mime\BodyRendererInterface;

/**
 * Valide que les e-mails sortants se rendent réellement en HTML riche de marque.
 *
 * En env de test les e-mails sont mis en file (transport messenger) et non rendus
 * pendant la requête ; on force donc le rendu via le BodyRenderer pour exercer le
 * layout commun, les macros (titre + icône, bouton, encart), l'icône SVG inline et
 * l'embarquement du logo (email.image → cid:).
 */
class EmailRenderingTest extends KernelTestCase
{
    private function render(string $template, array $context): string
    {
        $email = (new TemplatedEmail())
            ->htmlTemplate($template)
            ->context($context);

        /** @var BodyRendererInterface $renderer */
        $renderer = static::getContainer()->get('twig.mime_body_renderer');
        $renderer->render($email);

        return (string) $email->getHtmlBody();
    }

    private function logoPath(): string
    {
        // Référence Twig (namespace « images ») résolue par email.image().
        return '@images/entreprises/logofav.png';
    }

    public function testConfirmationEmailRendersBrandedHtml(): void
    {
        self::bootKernel();

        $html = $this->render('registration/confirmation_email.html.twig', [
            'signedUrl' => 'https://example.test/verify/email?id=1&expires=9&token=abc',
            'expiresAtMessageKey' => '1 heure',
            'expiresAtMessageData' => [],
            'logoPath' => $this->logoPath(),
            'senderEmail' => 'contact@jsbrokers.com',
            'recipientName' => 'Mr. Modogo',
        ]);

        // Marque + en-tête + signature.
        $this->assertStringContainsString('JS Brokers', $html);
        // Appel à l'action.
        $this->assertStringContainsString('Confirmer mon adresse e-mail', $html);
        $this->assertStringContainsString('https://example.test/verify/email', $html);
        // Icône d'illustration rendue en SVG inline.
        $this->assertStringContainsString('<svg', $html);
        // Logo embarqué en inline (CID) par email.image() — header + signature.
        $this->assertStringContainsString('cid:', $html);
    }

    public function testContactEmailRendersBrandedHtml(): void
    {
        self::bootKernel();

        $data = new \App\DTO\DemandeContactDTO();
        $data->name = 'Victor ESAFE';
        $data->email = 'victor@example.test';
        $data->message = "Bonjour,\nJe souhaite un devis.";

        $html = $this->render('home/mail/message_demande_de_contact.html.twig', [
            'data' => $data,
            'logoPath' => $this->logoPath(),
            'senderEmail' => 'contact@jsbrokers.com',
        ]);

        $this->assertStringContainsString('Nouvelle demande de contact', $html);
        $this->assertStringContainsString('victor@example.test', $html);
        // Le saut de ligne du message est converti en <br> (filtre nl2br).
        $this->assertStringContainsString('<br', $html);
        $this->assertStringContainsString('<svg', $html);
        $this->assertStringContainsString('cid:', $html);
    }
}
