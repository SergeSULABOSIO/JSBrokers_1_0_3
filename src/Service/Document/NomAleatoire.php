<?php

namespace App\Service\Document;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Vich\UploaderBundle\Mapping\PropertyMapping;
use Vich\UploaderBundle\Naming\NamerInterface;

/**
 * LE NOM D'UN FICHIER DÉPOSÉ SUR LE DISQUE : 32 caractères hexadécimaux aléatoires (128 bits,
 * `random_bytes`) et l'extension — rien d'autre (chantier 18, décision de Serge).
 *
 * Le nommeur précédent (SmartUniqueNamer) gardait le nom d'origine, qui fuyait, suivi d'un
 * `uniqid` tiré de l'HORLOGE : en partie devinable. Le nom lisible, lui, vit en base
 * (`Document::nom`, `AssistantFichier::nomOriginal`) et sert au téléchargement.
 *
 * L'extension est celle DÉCLARÉE (sinon devinée), réduite à [a-z0-9] : elle ne sert qu'à
 * reconnaître la famille du fichier (un bordereau .csv, .xlsx…), jamais à décider de son
 * service — le fichier est hors de la racine web, et ReponseDePiece lit son type RÉEL.
 */
final class NomAleatoire implements NamerInterface
{
    /** Les seules extensions d'un logo PUBLIC — tirées du CONTENU, jamais du nom annoncé. */
    public const EXTENSIONS_DES_LOGOS = ['png', 'jpg', 'gif', 'webp'];

    public function name(object $object, PropertyMapping $mapping): string
    {
        $fichier = $mapping->getFile($object);
        if ($mapping->getMappingName() === 'entreprises') {
            return self::pourUnLogo($fichier);
        }
        $extension = '';
        if ($fichier instanceof UploadedFile) {
            $extension = $fichier->getClientOriginalExtension() !== '' ? $fichier->getClientOriginalExtension() : (string) $fichier->guessExtension();
        } elseif ($fichier instanceof \SplFileInfo) {
            $extension = $fichier->getExtension();
        }

        return self::nouveau($extension);
    }

    /**
     * Le logo est servi par Apache : une image valide nommée « x.php » ne doit pas devenir
     * « <hex>.php ». L'extension vient du CONTENU, dans une liste fermée — sinon aucune.
     */
    public static function pourUnLogo(mixed $fichier): string
    {
        $devinee = $fichier instanceof \Symfony\Component\HttpFoundation\File\File ? (string) $fichier->guessExtension() : '';
        $devinee = $devinee === 'jpeg' ? 'jpg' : $devinee;

        return self::nouveau(\in_array($devinee, self::EXTENSIONS_DES_LOGOS, true) ? $devinee : '');
    }

    public static function nouveau(string $extension = ''): string
    {
        $extension = substr(preg_replace('/[^a-z0-9]/', '', strtolower($extension)) ?? '', 0, 10);

        return bin2hex(random_bytes(16)) . ($extension !== '' ? '.' . $extension : '');
    }
}
