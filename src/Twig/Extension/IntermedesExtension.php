<?php

namespace App\Twig\Extension;

use App\Ai\Live\IntermedesDeKet;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Expose au mode Live le catalogue des intermèdes de Ket (`intermedes_de_ket()`) :
 * les petites phrases qu'elle dit pendant qu'elle réfléchit, clé PUIS texte.
 *
 * LE TEXTE NE SERVAIT À RIEN AU NAVIGATEUR — jusqu'à ce qu'il doive reconnaître la voix
 * de Ket dans ce qu'il entend. Le 2026-09-19, un intermède est entré dans la bulle de
 * l'utilisateur, collé au début de sa propre phrase : « aucun avenant ne répertorié avec
 * une date VA VOIR AUSSI DANS LES PROCHAINS 90 JOURS ». Le micro avait raison — une
 * personne parlait bien tout près —, mais la reconnaissance avait fondu les deux voix en
 * une seule phrase. Pour retrancher ce que Ket vient de dire, encore faut-il le
 * connaître (cf. ket-live-tri.js).
 *
 * La source reste unique (IntermedesDeKet) : une phrase reformulée ne demande toujours
 * aucune modification du JavaScript.
 */
class IntermedesExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('intermedes_de_ket', [$this, 'cles']),
        ];
    }

    /** @return array<string, array<string, string>> moment => clé => phrase */
    public function cles(): array
    {
        return IntermedesDeKet::catalogue();
    }
}
