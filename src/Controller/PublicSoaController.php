<?php

/**
 * @file Ce fichier contient le contrôleur PublicSoaController.
 * @description Accès PUBLIC (aucune authentification requise) au relevé de
 * compte (SOA) d'un client via un lien tokenisé envoyé par son courtier.
 * Sécurité : jeton opaque de 256 bits en URL (aucune énumération possible),
 * expiration fixe, réponse d'échec strictement uniforme quel que soit le cas
 * (jeton inconnu, expiré ou révoqué) pour ne fournir aucun oracle.
 */

namespace App\Controller;

use App\Repository\SoaAccesTokenRepository;
use App\Service\Soa\SoaContextBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class PublicSoaController extends AbstractController
{
    public function __construct(
        private readonly SoaAccesTokenRepository $tokenRepository,
        private readonly SoaContextBuilder $soaContextBuilder,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/soa/{token}', name: 'public.soa.view', methods: ['GET'], requirements: ['token' => '[a-f0-9]{64}'])]
    public function view(string $token): Response
    {
        $acces = $this->tokenRepository->findOneByToken($token);

        if ($acces === null || !$acces->isActif(new \DateTimeImmutable())) {
            return $this->render('public/soa/soa_lien_invalide.html.twig', [], new Response('', Response::HTTP_NOT_FOUND));
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

        return $this->render('public/soa/soa_client_public.html.twig', $context);
    }
}
