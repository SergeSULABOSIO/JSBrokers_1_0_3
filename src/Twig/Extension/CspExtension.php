<?php

namespace App\Twig\Extension;

use App\Security\Csp\NonceCsp;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `csp_nonce()` : le nonce de la réponse, à poser sur tout `<script>` inline
 * (`<script nonce="{{ csp_nonce() }}">`) et sur `importmap('app', {nonce: csp_nonce()})`.
 * Un `<script>` inline sans lui est refusé par le navigateur (`PolitiqueCspTest`).
 */
final class CspExtension extends AbstractExtension
{
    public function __construct(private readonly NonceCsp $nonce)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('csp_nonce', $this->nonce->valeur(...))];
    }
}
