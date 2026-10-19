<?php

namespace App\Security\Csp;

use Symfony\Contracts\Service\ResetInterface;

/**
 * LE NONCE DE LA REQUÊTE — un aléa par réponse, jamais deux fois le même.
 *
 * Le gabarit le pose sur chaque `<script>` inline (`csp_nonce()`), l'écouteur le recopie
 * dans l'en-tête : le navigateur n'exécute que les scripts qui portent le nonce de la
 * réponse qui les a apportés. Tiré au premier usage, oublié entre deux requêtes
 * (`ResetInterface`) — un nonce réutilisé ne protège plus rien.
 */
final class NonceCsp implements ResetInterface
{
    private ?string $valeur = null;

    public function valeur(): string
    {
        // 128 bits : la recommandation du W3C. Base64, que l'en-tête accepte tel quel.
        return $this->valeur ??= base64_encode(random_bytes(16));
    }

    public function reset(): void
    {
        $this->valeur = null;
    }
}
