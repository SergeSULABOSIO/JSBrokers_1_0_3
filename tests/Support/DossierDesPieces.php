<?php

namespace App\Tests\Support;

/**
 * LE DOSSIER DES PIÈCES, pour les tests qui y déposent un fichier à la main : celui que
 * l'application lit (`app.dossier_des_pieces`, hors de public/ depuis le chantier 18) — jamais
 * un chemin recopié. Créé s'il manque (var/ n'est pas versionné : un clone neuf ne l'a pas).
 */
final class DossierDesPieces
{
    public static function chemin(\Symfony\Component\DependencyInjection\ContainerInterface $conteneur): string
    {
        $dossier = (string) $conteneur->getParameter('app.dossier_des_pieces');
        if (!is_dir($dossier)) {
            mkdir($dossier, 0777, true);
        }

        return $dossier;
    }
}
