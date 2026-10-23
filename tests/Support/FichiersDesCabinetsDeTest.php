<?php

namespace App\Tests\Support;

use App\Services\ServiceSuppressionEntreprise;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * UN TEST EFFACE LES FICHIERS DE SES CABINETS D'ESSAI comme leurs lignes (Serge, 2026-10-22).
 *
 * À appeler en TÊTE du nettoyage, avant tout DELETE : la liste se lit en base
 * (`ServiceSuppressionEntreprise::fichiersDuCabinet`, la même source que la suppression d'un
 * cabinet). Sans quoi les pièces, fichiers de Ket et dossiers d'échange s'accumulaient sur le
 * poste — l'extension FichiersLaissesParLesTests désigne tout test qui l'oublie.
 */
final class FichiersDesCabinetsDeTest
{
    public static function effacer(ContainerInterface $conteneur, string ...$nomsDeCabinet): void
    {
        $service = $conteneur->get(ServiceSuppressionEntreprise::class);
        $connexion = $conteneur->get('doctrine')->getConnection();
        $fs = new Filesystem();
        foreach ($nomsDeCabinet as $nom) {
            foreach ($connexion->fetchFirstColumn('SELECT id FROM entreprise WHERE nom = :n', ['n' => $nom]) as $id) {
                foreach ($service->fichiersDuCabinet((int) $id) as $chemin) {
                    if ($fs->exists($chemin)) {
                        $fs->remove($chemin);
                    }
                }
            }
        }
    }
}
