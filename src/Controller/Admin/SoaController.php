<?php

namespace App\Controller\Admin;

use App\Entity\Avenant;
use App\Entity\Client;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\SoaAccesToken;
use App\Entity\SoaEnvoi;
use App\Services\CanvasBuilder;
use App\Service\Soa\SoaClientNotifier;
use App\Service\Soa\SoaContextBuilder;
use App\Service\Soa\SoaPoliceDocumentsCollector;
use App\Repository\EntrepriseRepository;
use App\Repository\InviteRepository;
use App\Repository\SoaAccesTokenRepository;
use App\Repository\SoaEnvoiRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/soa', name: 'admin.soa.')]
#[IsGranted('ROLE_USER')]
class SoaController extends AbstractController
{
    use ControllerUtilsTrait;

    /** Libellés des types de contact (Contact::TYPE_CONTACT_*), pour le choix du destinataire. */
    private const CONTACT_TYPE_LABELS = [0 => 'Production', 1 => 'Sinistre', 2 => 'Administration', 3 => 'Autres'];

    protected function getCollectionMap(): array
    {
        return [];
    }

    protected function getParentAssociationMap(): array
    {
        return [];
    }

    public function __construct(
        private EntityManagerInterface $em,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private SoaContextBuilder $soaContextBuilder,
        private SoaAccesTokenRepository $soaAccesTokenRepository,
        private SoaEnvoiRepository $soaEnvoiRepository,
        private SoaClientNotifier $soaClientNotifier,
        private SoaPoliceDocumentsCollector $documentsCollector,
        CanvasBuilder $canvasBuilder,
    ) {
        $this->canvasBuilder = $canvasBuilder;
    }

    #[Route('/client/{id}/workspace', name: 'client_workspace', methods: ['GET'])]
    public function clientWorkspace(Client $client): JsonResponse
    {
        $context = $this->buildSoaContext($client);

        $html = $this->renderView('admin/soa/soa_client_workspace.html.twig', $context);

        return $this->json([
            'html'  => $html,
            'title' => 'SOA — ' . $client->getNom(),
        ]);
    }

    #[Route('/client/{id}/apercu', name: 'client_apercu', methods: ['GET'])]
    public function clientApercu(Client $client): Response
    {
        $context = $this->buildSoaContext($client);
        // Bouton « Documents » des polices (picker ouvert par le JS inline de la page).
        $context['soaDocsUrlPattern'] = '/admin/soa/api/police/%aid%/documents';

        return $this->render('admin/soa/soa_client_standalone.html.twig', $context);
    }

    /**
     * Boîte de choix du destinataire pour l'envoi du SOA par e-mail (action « Envoyer
     * le SOA par e-mail » de la rubrique Clients). Liste l'e-mail du client et ceux de
     * ses contacts ; l'envoi effectif passe par la route `api.client_envoyer`.
     */
    #[Route('/client/{id}/envoi-picker', name: 'client_envoi_picker', methods: ['GET'])]
    public function envoiPicker(Client $client): Response
    {
        if (!$this->mayAccessEntity(Client::class, Invite::ACCESS_LECTURE)) {
            throw $this->createAccessDeniedException("L'envoi du relevé de compte est hors de votre périmètre d'accès.");
        }
        $this->assertClientDansEspace($client);

        return $this->render('components/soa/_envoi_picker.html.twig', [
            'client'        => $client,
            'destinataires' => $this->collecterDestinataires($client),
            'validiteJours' => SoaAccesToken::VALIDITE_JOURS,
            'historique'    => $this->soaEnvoiRepository->findDerniersPourClient($client, $this->getEntreprise()),
        ]);
    }

    /**
     * Picker « Documents de la police » : tous les documents enregistrés concernant
     * cet avenant, quel que soit le niveau d'attache (piste, cotation, police, client).
     * Téléchargements via la route admin existante admin.document.api.download.
     */
    #[Route('/api/police/{id}/documents', name: 'api.police_documents', methods: ['GET'])]
    public function policeDocuments(Avenant $avenant): Response
    {
        if (!$this->mayAccessEntity(Client::class, Invite::ACCESS_LECTURE)) {
            throw $this->createAccessDeniedException("Les documents de la police sont hors de votre périmètre d'accès.");
        }
        $client = $this->documentsCollector->clientDeLaPolice($avenant);
        if ($client === null) {
            throw $this->createNotFoundException("Police sans client rattaché.");
        }
        $this->assertClientDansEspace($client);

        return $this->render('components/soa/_documents_picker.html.twig', [
            'avenant'            => $avenant,
            'client'             => $client,
            'items'              => $this->documentsCollector->collect($avenant),
            'downloadUrlPattern' => '/admin/document/api/%did%/download',
        ]);
    }

    /**
     * Retourne (en la créant/prolongeant au besoin) l'URL publique du SOA du client,
     * pour copie dans le presse-papiers côté front. Toute action de partage redonne
     * 30 jours de validité au lien — même règle que l'envoi par e-mail.
     */
    #[Route('/api/client/{id}/lien-public', name: 'api.client_lien_public', methods: ['POST'])]
    public function lienPublic(Client $client): JsonResponse
    {
        if (!$this->mayAccessEntity(Client::class, Invite::ACCESS_LECTURE)) {
            return $this->accessDeniedJson();
        }
        $entreprise = $this->getEntreprise();
        if ($entreprise === null || $client->getEntreprise()?->getId() !== $entreprise->getId()) {
            return $this->json(['success' => false, 'message' => "Client introuvable dans cet espace de travail."], Response::HTTP_NOT_FOUND);
        }

        $token = $this->obtenirOuProlongerToken($client, $entreprise);
        $this->em->flush();

        return $this->json([
            'success' => true,
            'url'     => $this->generateUrl('public.soa.view', ['token' => $token->getToken()], UrlGeneratorInterface::ABSOLUTE_URL),
            'message' => sprintf('Lien client copié. Valable jusqu\'au %s.', $token->getExpiresAt()->format('d/m/Y')),
        ]);
    }

    /**
     * Révoque le lien d'accès public du client : tout jeton actif est invalidé
     * immédiatement (les e-mails déjà envoyés pointent alors sur la page
     * « lien invalide »). Un prochain envoi/copie créera un nouveau lien.
     */
    #[Route('/api/client/{id}/revoquer-lien', name: 'api.client_revoquer_lien', methods: ['DELETE'])]
    public function revoquerLien(Client $client): JsonResponse
    {
        // Révoquer modifie l'état de partage du client : niveau Modification (fail-closed).
        if (!$this->mayAccessEntity(Client::class, Invite::ACCESS_MODIFICATION)) {
            return $this->accessDeniedJson();
        }
        $entreprise = $this->getEntreprise();
        if ($entreprise === null || $client->getEntreprise()?->getId() !== $entreprise->getId()) {
            return $this->json(['success' => false, 'message' => "Client introuvable dans cet espace de travail."], Response::HTTP_NOT_FOUND);
        }

        $revoques = 0;
        $maintenant = new \DateTimeImmutable();
        // Boucle défensive : la règle « un seul jeton actif » est garantie par le code,
        // pas par une contrainte BD — on révoque donc tout actif résiduel.
        while (($token = $this->soaAccesTokenRepository->findActifPourClient($client, $entreprise)) !== null) {
            $token->setRevokedAt($maintenant);
            $this->em->flush();
            $revoques++;
        }

        if ($revoques === 0) {
            return $this->json(['success' => false, 'message' => "Aucun lien d'accès actif pour ce client : rien à révoquer."], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'success' => true,
            'message' => sprintf('Lien d\'accès de « %s » révoqué : le relevé n\'est plus consultable en ligne.', $client->getNom()),
        ]);
    }

    /**
     * Envoie au destinataire choisi le lien public du SOA. Le jeton actif du client est
     * PROLONGÉ (expiresAt = maintenant + 30 j) pour que les destinataires successifs
     * partagent le même lien ; à défaut un jeton est créé. Le jeton est persisté AVANT
     * l'envoi : un échec SMTP ne l'annule pas (un renvoi réutilisera le même lien).
     */
    #[Route('/api/client/{id}/envoyer', name: 'api.client_envoyer', methods: ['POST'])]
    public function envoyer(Client $client, Request $request): JsonResponse
    {
        if (!$this->mayAccessEntity(Client::class, Invite::ACCESS_LECTURE)) {
            return $this->accessDeniedJson();
        }
        $entreprise = $this->getEntreprise();
        if ($entreprise === null || $client->getEntreprise()?->getId() !== $entreprise->getId()) {
            return $this->json(['success' => false, 'message' => "Client introuvable dans cet espace de travail."], Response::HTTP_NOT_FOUND);
        }

        $payload = json_decode($request->getContent(), true) ?: [];
        $email   = mb_strtolower(trim((string) ($payload['email'] ?? '')));
        $message = isset($payload['message']) ? (string) $payload['message'] : null;

        // L'adresse doit appartenir STRICTEMENT à l'ensemble proposé (client + contacts) :
        // l'endpoint ne doit pas pouvoir servir de relais d'e-mail vers une adresse arbitraire.
        $destinataire = null;
        foreach ($this->collecterDestinataires($client) as $candidat) {
            if (mb_strtolower($candidat['email']) === $email) {
                $destinataire = $candidat;
                break;
            }
        }
        if ($destinataire === null) {
            return $this->json(['success' => false, 'message' => "Ce destinataire ne fait pas partie des adresses connues du client."], Response::HTTP_BAD_REQUEST);
        }

        $token = $this->obtenirOuProlongerToken($client, $entreprise);

        // Journal des envois : trace figée (destinataire, expéditeur via AuditableTrait,
        // validité annoncée), affichée dans la boîte d'envoi comme contexte.
        $envoi = (new SoaEnvoi())
            ->setClient($client)
            ->setEmailDestinataire($destinataire['email'])
            ->setNomDestinataire($destinataire['nom'])
            ->setMessage($message !== null && trim($message) !== '' ? trim($message) : null)
            ->setLienExpireAt($token->getExpiresAt());
        $envoi->setEntreprise($entreprise);
        $envoi->setInvite($this->getInvite());
        $this->em->persist($envoi);
        $this->em->flush();

        $envoye = $this->soaClientNotifier->envoyerLien(
            $token,
            $destinataire['email'],
            $destinataire['nom'],
            $message,
        );

        if (!$envoye) {
            return $this->json([
                'success' => false,
                'message' => "L'e-mail n'a pas pu être envoyé. Le lien d'accès reste valable : réessayez dans quelques instants.",
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->json([
            'success' => true,
            'message' => sprintf(
                'Relevé de compte envoyé à %s (%s). Lien valable jusqu\'au %s.',
                $destinataire['nom'],
                $destinataire['email'],
                $token->getExpiresAt()->format('d/m/Y'),
            ),
        ]);
    }

    private function buildSoaContext(Client $client): array
    {
        $this->assertClientDansEspace($client);

        return $this->soaContextBuilder->build($client, $this->getEntreprise(), $this->getInvite());
    }

    /**
     * Jeton actif du client, PROLONGÉ à +30 jours ; créé s'il n'en existe pas.
     * Règle unique de toutes les actions de partage (envoi e-mail, copie du lien).
     * L'appelant flush() lui-même (avant tout envoi : un échec SMTP n'annule rien).
     */
    private function obtenirOuProlongerToken(Client $client, Entreprise $entreprise): SoaAccesToken
    {
        $token = $this->soaAccesTokenRepository->findActifPourClient($client, $entreprise);
        if ($token === null) {
            $token = (new SoaAccesToken())
                ->setToken(bin2hex(random_bytes(32)))
                ->setClient($client);
            $token->setEntreprise($entreprise);
            $token->setInvite($this->getInvite());
            $this->em->persist($token);
        }
        $token->setExpiresAt(new \DateTimeImmutable('+' . SoaAccesToken::VALIDITE_JOURS . ' days'));

        return $token;
    }

    /** Scoping : le client doit appartenir à l'espace de travail courant (404 sinon). */
    private function assertClientDansEspace(Client $client): void
    {
        $entreprise = $this->getEntreprise();
        if ($entreprise === null || $client->getEntreprise()?->getId() !== $entreprise->getId()) {
            throw $this->createNotFoundException("Client introuvable dans cet espace de travail.");
        }
    }

    /**
     * Adresses e-mail exploitables pour l'envoi du SOA : celle du client puis celles de
     * ses contacts, dédoublonnées (insensible à la casse).
     * @return array<int, array{email: string, nom: string, detail: string}>
     */
    private function collecterDestinataires(Client $client): array
    {
        $destinataires = [];
        $vus           = [];

        $emailClient = trim((string) $client->getEmail());
        if ($emailClient !== '') {
            $destinataires[] = ['email' => $emailClient, 'nom' => (string) $client->getNom(), 'detail' => 'Client'];
            $vus[mb_strtolower($emailClient)] = true;
        }

        foreach ($client->getContacts() as $contact) {
            $email = trim((string) $contact->getEmail());
            if ($email === '' || isset($vus[mb_strtolower($email)])) {
                continue;
            }
            $vus[mb_strtolower($email)] = true;

            $details = array_filter([
                $contact->getFonction(),
                self::CONTACT_TYPE_LABELS[$contact->getType()] ?? null,
            ]);
            $destinataires[] = [
                'email'  => $email,
                'nom'    => (string) $contact->getNom(),
                'detail' => $details !== [] ? implode(' — ', $details) : 'Contact',
            ];
        }

        return $destinataires;
    }
}
