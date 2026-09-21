<?php

namespace App\Twig\Extension;

use App\Ai\Live\IntermedesDeKet;
use App\Ai\Voix\VoixDeKet;
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
    public function __construct(private readonly VoixDeKet $voixDeKet)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('intermedes_de_ket', [$this, 'cles']),
            new TwigFunction('voix_serveur_disponible', [$this, 'voixServeurDisponible']),
        ];
    }

    /**
     * RESTE-T-IL UNE VOIX DE SERVEUR QUI PARLERA ? La page a besoin de le savoir À
     * L'OUVERTURE.
     *
     * Quand la réponse est « non », le navigateur lit avec sa PROPRE synthèse sans
     * demander d'abord au serveur un refus qu'on connaît déjà — un aller-retour de moins
     * avant le premier mot, sur chaque lecture d'une page fraîche. Relevé le 2026-09-21 :
     * crédits ElevenLabs du mois épuisés depuis le 17/09, les trois modèles Gemini
     * épuisés depuis le 19/09. Toutes les lectures se faisaient donc déjà par l'API
     * native du navigateur, mais chacune commençait par attendre ce « non ».
     *
     * ⚠ CE N'EST QU'UNE AVANCE, JAMAIS UNE CERTITUDE : un quota peut se libérer entre le
     * rendu de la page et la lecture. Le client garde donc son chemin de repli complet —
     * ceci ne fait que lui éviter la question quand la réponse est connue.
     */
    public function voixServeurDisponible(): bool
    {
        return $this->voixDeKet->uneVoixPeutParler();
    }

    /** @return array<string, array<string, string>> moment => clé => phrase */
    public function cles(): array
    {
        return IntermedesDeKet::catalogue();
    }
}
