<?php

namespace App\Echange\Service;

use App\Entity\Entreprise;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * OÙ SE RANGE UN CLASSEUR DÉPOSÉ POUR L'IMPORT — la source unique, pour l'écran ET pour Ket.
 *
 * Dans le dossier du cabinet (`var/echange/<id>/`), sous un nom aléatoire : le nom d'origine
 * reste dans l'entité, jamais sur le disque — un fichier nommé d'après le cabinet trahirait son
 * contenu à qui parcourrait le répertoire. Ce dossier part entier avec le cabinet
 * (`ServiceSuppressionEntreprise::fichiersDuCabinet`).
 *
 * ⚠ Ket copiait le classeur joint dans le dossier temporaire du SYSTÈME (`tempnam(…) . '.xlsx'`) :
 * supprimer le cabinet effaçait la ligne d'import, pas le classeur — et chaque import laissait
 * un fichier vide de plus (2026-10-23).
 */
final class DepotDeLEchange
{
    public function __construct(
        #[Autowire('%app.dossier_de_l_echange%')]
        private readonly string $dossier,
    ) {
    }

    /** Un chemin neuf, libre, dans le dépôt du cabinet (le dossier est créé au besoin). */
    public function nouveauChemin(Entreprise $entreprise): string
    {
        $id = $entreprise->getId() ?? throw new \LogicException('Un dépôt appartient à un cabinet enregistré.');
        $repertoire = sprintf('%s/%d', $this->dossier, $id);
        if (!is_dir($repertoire) && !mkdir($repertoire, 0775, true) && !is_dir($repertoire)) {
            throw new \RuntimeException('Impossible de préparer le dépôt du fichier.');
        }

        return $repertoire . '/' . bin2hex(random_bytes(16)) . '.xlsx';
    }
}
