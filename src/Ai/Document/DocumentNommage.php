<?php

namespace App\Ai\Document;

use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Le NOM DE TÉLÉCHARGEMENT, assaini.
 *
 * L'export de bulle contournait le problème en ne mettant AUCUNE donnée utilisateur
 * dans le nom (« message-ia-42-20260811.pdf ») : rien à assainir, donc aucune faille
 * d'en-tête Content-Disposition. Ici le propriétaire veut un nom parlant, puisque le
 * fichier va vivre dans un dossier Téléchargements parmi d'autres — il faut donc
 * assainir pour de bon, et le titre vient du modèle.
 *
 * Trois barrières cumulées : on retire d'abord les caractères dangereux pour un
 * en-tête HTTP et un chemin, on passe ensuite par un slug ASCII strict, et le nom
 * final ne peut contenir que [a-z0-9-]. La longueur est bornée pour rester sous les
 * limites de chemin de Windows.
 */
final class DocumentNommage
{
    private const LONGUEUR_MAX_SLUG = 60;

    public function nomFichier(string $titre, DocumentFormat $format, \DateTimeImmutable $le): string
    {
        // Défense en profondeur : ces caractères ne doivent jamais atteindre le
        // slugger, quoi qu'il en fasse.
        $propre = str_replace(["\r", "\n", '"', ';', '/', '\\', '%', ':'], ' ', $titre);

        $slug = (new AsciiSlugger('fr'))->slug($propre)->lower()->toString();
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', $slug), '-');
        $slug = mb_substr($slug, 0, self::LONGUEUR_MAX_SLUG);
        $slug = trim($slug, '-');

        return sprintf('%s-%s.%s', $slug !== '' ? $slug : 'document', $le->format('Ymd'), $format->extension());
    }
}
