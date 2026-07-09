<?php

/**
 * @file Ce fichier contient le contrôleur PublicSoaController.
 * @description Accès PUBLIC (aucune authentification requise) au relevé de
 * compte (SOA) d'un client via un lien tokenisé envoyé par son courtier.
 * Sécurité : jeton opaque de 256 bits en URL (aucune énumération possible),
 * expiration fixe, réponse d'échec strictement uniforme quel que soit le cas
 * (jeton inconnu, expiré ou révoqué) pour ne fournir aucun oracle. Les routes
 * annexes (documents d'une police, téléchargement) sont gardées par le MÊME
 * jeton et vérifient l'appartenance de la ressource au pipe du client.
 */

namespace App\Controller;

use App\Entity\SoaAccesToken;
use App\Repository\AvenantRepository;
use App\Repository\DocumentRepository;
use App\Repository\SoaAccesTokenRepository;
use App\Service\Soa\SoaContextBuilder;
use App\Service\Soa\SoaPoliceDocumentsCollector;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Vich\UploaderBundle\Handler\DownloadHandler;

class PublicSoaController extends AbstractController
{
    public function __construct(
        private readonly SoaAccesTokenRepository $tokenRepository,
        private readonly SoaContextBuilder $soaContextBuilder,
        private readonly SoaPoliceDocumentsCollector $documentsCollector,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/soa/{token}', name: 'public.soa.view', methods: ['GET'], requirements: ['token' => '[a-f0-9]{64}'])]
    public function view(string $token): Response
    {
        $acces = $this->resolveToken($token);
        if ($acces === null) {
            return $this->invalidResponse();
        }

        // Traçabilité de consultation (le courtier saura que son client a lu le relevé).
        $acces->setLastAccessedAt(new \DateTimeImmutable());
        $acces->incrementAccessCount();
        $this->em->flush();

        $context = $this->soaContextBuilder->build(
            $acces->getClient(),
            $acces->getEntreprise(),
            null,
            vueClient: true,
        );
        $context['lienExpireAt'] = $acces->getExpiresAt();
        // Bouton « Documents » des polices : picker et téléchargements gardés par le jeton.
        $context['soaDocsUrlPattern'] = '/soa/' . $token . '/police/%aid%/documents';

        return $this->render('public/soa/soa_client_public.html.twig', $context);
    }

    /**
     * Picker « Documents de la police » côté client : mêmes niveaux d'attache que la
     * vue courtier (piste, cotation, police, client). La police doit appartenir au
     * client du jeton — sinon réponse uniforme, comme tout échec du SOA public.
     */
    #[Route('/soa/{token}/police/{avenantId}/documents', name: 'public.soa.police_documents', methods: ['GET'], requirements: ['token' => '[a-f0-9]{64}', 'avenantId' => '\d+'])]
    public function policeDocuments(string $token, int $avenantId, AvenantRepository $avenantRepository): Response
    {
        $acces = $this->resolveToken($token);
        if ($acces === null) {
            return $this->invalidResponse();
        }

        $avenant = $avenantRepository->find($avenantId);
        $client  = $avenant !== null ? $this->documentsCollector->clientDeLaPolice($avenant) : null;
        if ($client === null || $client->getId() !== $acces->getClient()->getId()) {
            return $this->invalidResponse();
        }

        return $this->render('components/soa/_documents_picker.html.twig', [
            'avenant'            => $avenant,
            'client'             => $client,
            'items'              => $this->documentsCollector->collect($avenant),
            'downloadUrlPattern' => '/soa/' . $token . '/document/%did%/telecharger',
        ]);
    }

    /**
     * Téléchargement public d'un document, gardé par le jeton : seul un document
     * attaché au pipe du client du jeton (client, piste, cotation, police) est servi.
     */
    #[Route('/soa/{token}/document/{documentId}/telecharger', name: 'public.soa.document_download', methods: ['GET'], requirements: ['token' => '[a-f0-9]{64}', 'documentId' => '\d+'])]
    public function documentDownload(string $token, int $documentId, DocumentRepository $documentRepository, DownloadHandler $downloadHandler): Response
    {
        $acces = $this->resolveToken($token);
        if ($acces === null) {
            return $this->invalidResponse();
        }

        $document = $documentRepository->find($documentId);
        if ($document === null || !$this->documentsCollector->documentAppartientAuClient($document, $acces->getClient())) {
            return $this->invalidResponse();
        }

        return $downloadHandler->downloadObject($document, 'fichier');
    }

    /** Jeton actif, ou null pour TOUT cas d'échec (inconnu, expiré, révoqué). */
    private function resolveToken(string $token): ?SoaAccesToken
    {
        $acces = $this->tokenRepository->findOneByToken($token);

        return ($acces !== null && $acces->isActif(new \DateTimeImmutable())) ? $acces : null;
    }

    /** Réponse d'échec STRICTEMENT uniforme : aucune énumération possible. */
    private function invalidResponse(): Response
    {
        return $this->render('public/soa/soa_lien_invalide.html.twig', [], new Response('', Response::HTTP_NOT_FOUND));
    }
}
