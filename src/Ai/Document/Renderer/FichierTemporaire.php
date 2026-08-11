<?php

namespace App\Ai\Document\Renderer;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Le détour par un fichier temporaire, fait une seule fois pour tous ceux qui en
 * ont besoin.
 *
 * PHPWord et PhpSpreadsheet assemblent des archives ZIP : ils écrivent dans un
 * FICHIER, jamais dans une chaîne. Il faut donc leur en fournir un, relire les
 * octets, puis nettoyer — y compris quand l'écriture échoue, d'où le `finally`.
 *
 * `var/tmp` du projet plutôt que `sys_get_temp_dir()` : sous Windows, un service
 * web tourne sous un compte dont le dossier temporaire n'est ni garanti ni
 * accessible en écriture, et l'échec se manifesterait par un 500 sans message.
 * Le dossier est créé à la volée — un déploiement neuf ne doit pas échouer au
 * premier document.
 */
final class FichierTemporaire
{
    private readonly string $dossier;

    public function __construct(ParameterBagInterface $params)
    {
        $this->dossier = $params->get('kernel.project_dir') . '/var/tmp';
    }

    /**
     * Appelle $ecrire avec un chemin de fichier neuf, puis renvoie les octets écrits.
     *
     * @param callable(string): void $ecrire
     */
    public function avec(string $extension, callable $ecrire): string
    {
        $chemin = $this->chemin($extension);

        try {
            $ecrire($chemin);

            return is_file($chemin) ? (string) file_get_contents($chemin) : '';
        } finally {
            (new Filesystem())->remove($chemin);
        }
    }

    /**
     * Un chemin de fichier neuf, dont l'appelant garde la charge.
     *
     * Sert le dépôt Vich : le binaire y est écrit puis DÉPLACÉ au flush. Le
     * supprimer ici ferait disparaître le document avant même qu'il soit rangé —
     * d'où une méthode distincte de avec(), qui, elle, nettoie toujours.
     */
    public function chemin(string $extension): string
    {
        (new Filesystem())->mkdir($this->dossier);

        return sprintf('%s/rapport-%s.%s', $this->dossier, bin2hex(random_bytes(8)), $extension);
    }
}
