<?php

namespace App\Tests\Email;

use App\DTO\DemandeContactDTO;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mime\BodyRendererInterface;

/**
 * UNE DONNÉE SAISIE N'EST JAMAIS DU HTML DANS UN E-MAIL.
 *
 * Les macros `paragraph` et `note` (emails/_macros.html.twig) rendent leur argument en
 * `|raw` : leurs appelants y composent du balisage (`<strong>`, `<br>`). Une donnée
 * CONCATÉNÉE à ce balisage n'est donc échappée par personne — sauf par l'appelant.
 * Avant ce test, un visiteur anonyme saisissait un nom `<a href=…>` dans le formulaire de
 * contact, et Joseara l'envoyait, en HTML, à l'adresse de son choix.
 *
 * Chaque gabarit est rendu avec la charge dans TOUTES ses données saisies : elle doit
 * ressortir échappée, jamais telle quelle.
 */
class EmailEchappementTest extends KernelTestCase
{
    private const CHARGE = '<img src=x onerror=alert(1)>';

    private function rendre(string $gabarit, array $contexte): string
    {
        $email = (new TemplatedEmail())->htmlTemplate($gabarit)->context($contexte + [
            'logoPath' => '@images/entreprises/logofav.png',
            'senderEmail' => 'contact@joseara.com',
        ]);
        static::getContainer()->get('twig.mime_body_renderer')->render($email);

        return (string) $email->getHtmlBody();
    }

    private function exigerEchappe(string $html, string $gabarit): void
    {
        self::assertStringNotContainsString('<img src=x', $html, $gabarit . ' : une donnée saisie sort en HTML.');
        self::assertStringContainsString('&lt;img src=x', $html, $gabarit . ' : la donnée doit rester lisible, échappée.');
    }

    private function contact(): DemandeContactDTO
    {
        $data = new DemandeContactDTO();
        $data->name = 'Nom ' . self::CHARGE;
        $data->email = 'visiteur@example.test';
        $data->objet = 'Objet ' . self::CHARGE;
        $data->message = 'Message ' . self::CHARGE;
        $data->wantsPhone = true;
        $data->phone = '+243 ' . self::CHARGE;

        return $data;
    }

    public function testLesEMailsDeContact(): void
    {
        self::bootKernel();
        foreach (['home/mail/message_demande_de_contact.html.twig', 'home/mail/accuse_reception_contact.html.twig'] as $gabarit) {
            $html = $this->rendre($gabarit, ['data' => $this->contact(), 'wantsCallback' => true]);
            $this->exigerEchappe($html, $gabarit);
        }
    }

    public function testLesEMailsDeCompte(): void
    {
        self::bootKernel();
        foreach (['registration/confirmation_email.html.twig', 'emails/reset_password_email.html.twig'] as $gabarit) {
            $html = $this->rendre($gabarit, [
                'signedUrl' => 'https://example.test/v?id=1&token=abc',
                'expiresAtMessageKey' => '1 heure',
                'expiresAtMessageData' => [],
                'recipientName' => 'Nom ' . self::CHARGE,
            ]);
            $this->exigerEchappe($html, $gabarit);
        }
    }

    public function testLInvitation(): void
    {
        self::bootKernel();
        foreach ([true, false] as $enAttente) {
            $html = $this->rendre('home/mail/message_invitation.html.twig', [
                'entreprise' => ['nom' => 'Cabinet ' . self::CHARGE],
                'invite' => ['nom' => 'Invité ' . self::CHARGE, 'getEmail' => 'invite@example.test'],
                'enAttente' => $enAttente,
                'registerUrl' => 'https://example.test/inscription',
                'loginUrl' => 'https://example.test/connexion',
            ]);
            $this->exigerEchappe($html, 'message_invitation');
        }
    }

    public function testLeLienDuRelevé(): void
    {
        self::bootKernel();
        $html = $this->rendre('emails/soa_lien_client.html.twig', [
            'recipientName' => 'Nom ' . self::CHARGE,
            'entrepriseNom' => 'Cabinet ' . self::CHARGE,
            'clientNom' => 'Client ' . self::CHARGE,
            'entrepriseTel' => '+243 ' . self::CHARGE,
            'messageCourtier' => 'Message ' . self::CHARGE,
            'soaUrl' => 'https://example.test/soa/abc',
            'expiresAt' => new \DateTimeImmutable('+30 days'),
        ]);
        $this->exigerEchappe($html, 'soa_lien_client');
    }

    public function testLAvisAuxAgents(): void
    {
        self::bootKernel();
        $html = $this->rendre('emails/agent_notification.html.twig', [
            'titre' => 'Nouveau ticket support',
            'intro' => 'Intro ' . self::CHARGE,
            'icone' => 'feedback',
            'details' => ['Sujet ' . self::CHARGE => 'Valeur ' . self::CHARGE],
            'piedNote' => 'Pied ' . self::CHARGE,
        ]);
        $this->exigerEchappe($html, 'agent_notification');
    }

    public function testLaSyntheseDeConfiguration(): void
    {
        self::bootKernel();
        foreach ([true, false] as $complet) {
            $html = $this->rendre('emails/onboarding_synthese.html.twig', [
                'recipientName' => 'Nom ' . self::CHARGE,
                'entrepriseNom' => 'Cabinet ' . self::CHARGE,
                'bilan' => ['complet' => $complet, 'score' => 50, 'restantes' => [], 'etapes' => []],
                'etapesCitees' => [],
                'guideUrl' => null,
            ]);
            $this->exigerEchappe($html, 'onboarding_synthese');
        }
    }

    public function testLaCampagneCrmEstDuTexte(): void
    {
        self::bootKernel();
        $html = $this->rendre('emails/crm_campagne.html.twig', [
            'objet' => 'Nouveautés',
            'clientNom' => 'Client ' . self::CHARGE,
            'messageLignes' => ['Ligne ' . self::CHARGE, '<a href="https://piege.example">clic</a>'],
            'lien' => 'https://example.test',
        ]);
        $this->exigerEchappe($html, 'crm_campagne');
        self::assertStringNotContainsString('<a href="https://piege.example">', $html, 'Une campagne est du texte seul.');
    }

    public function testLesConfirmationsDAchatDeJetons(): void
    {
        self::bootKernel();
        $achat = [
            'utilisateur' => ['nom' => 'Acheteur ' . self::CHARGE],
            'pack' => 'pack ' . self::CHARGE,
            'tokens' => 1000,
            'montantUsd' => 10,
            'reference' => 'REF-' . self::CHARGE,
            'invoiceNumber' => 'FAC-' . self::CHARGE,
            'cardLast4' => '4242',
        ];
        foreach (['emails/token_purchase_confirmation.html.twig', 'emails/token_refund_confirmation.html.twig'] as $gabarit) {
            $html = $this->rendre($gabarit, ['purchase' => $achat, 'accountUrl' => 'https://example.test/compte']);
            $this->exigerEchappe($html, $gabarit);
        }
    }

    public function testLaNotificationDeConge(): void
    {
        self::bootKernel();
        $type = new class () {
            public bool $decompte = true;

            public function __toString(): string
            {
                return 'Congé ' . '<img src=x onerror=alert(1)>';
            }
        };
        $nom = static fn (string $prefixe) => (object) ['nom' => $prefixe . ' ' . self::CHARGE];
        $demande = (object) [
            'id' => 7,
            'agent' => $nom('Agent'),
            'valideur' => $nom('Valideur'),
            'typeAbsence' => $type,
            'dateDebut' => new \DateTimeImmutable('2026-11-02'),
            'dateFin' => new \DateTimeImmutable('2026-11-06'),
            'demiJourneeDebut' => false,
            'demiJourneeFin' => false,
            'nbJours' => 5,
            'motif' => '<p>Motif ' . self::CHARGE . '</p>',
            'documents' => [],
            'createdAt' => new \DateTimeImmutable('2026-10-16'),
            'controlesContournes' => 'x',
            'contournements' => ['Règle ' . self::CHARGE],
        ];
        $ctx = (object) [
            'titre' => 'Congé approuvé',
            'intro' => 'Votre congé a été <strong>approuvé</strong>.',
            'icone' => 'conge',
            'viaAssistant' => false,
            'demande' => $demande,
            'solde' => (object) ['acquis' => 25, 'dontReport' => 0, 'consomme' => 3, 'engage' => 0, 'exercice' => 2026],
            'disponibleAvant' => 22,
            'disponibleApres' => 17,
            'collegues' => [(object) ['nom' => 'Collègue ' . self::CHARGE, 'periode' => 'du 2 au 6 novembre']],
            'transition' => (object) [
                'statutApres' => 'APPROUVEE',
                'auteur' => $nom('Auteur'),
                'createdAt' => new \DateTimeImmutable('2026-10-16'),
                'commentaire' => '<p>Commentaire ' . self::CHARGE . '</p>',
            ],
            'estAutoApprouvee' => false,
            'lienApplication' => null,
            'instantaneLe' => new \DateTimeImmutable('2026-10-16'),
        ];
        $html = $this->rendre('emails/conge_notification.html.twig', ['ctx' => $ctx]);
        $this->exigerEchappe($html, 'conge_notification');
        self::assertStringNotContainsString('&lt;p&gt;', $html, 'Un texte riche (motif, commentaire) se rend en texte, sans balises visibles.');
        self::assertStringContainsString('<strong>approuvé</strong>', $html, 'Le balisage voulu de l\'accroche reste du HTML.');
    }

    public function testLAccrocheDuCongeEchappeLesNoms(): void
    {
        self::bootKernel();
        $source = (string) file_get_contents(static::getContainer()->getParameter('kernel.project_dir') . '/src/Service/Conge/CongeMailer.php');
        // L'accroche porte du balisage voulu (<strong>) : les NOMS y sont échappés à la source.
        self::assertMatchesRegularExpression('/\$nom\s*=\s*htmlspecialchars\(/', $source);
        self::assertMatchesRegularExpression('/\$auteur\s*=\s*htmlspecialchars\(/', $source);
    }
}
