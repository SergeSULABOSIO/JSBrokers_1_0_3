<?php

namespace App\Service\Fichier;

use App\Entity\Entreprise;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Service\ResetInterface;

/**
 * LES FICHIERS DE PASSAGE D'UN CABINET — jamais dans le dossier temporaire du système (chantier 19 bis).
 *
 * Certains fichiers ne vivent que le temps d'un travail : la copie d'une pièce jointe remise à
 * Vich à l'exécution d'un plan de Ket, le rapport que Ket vient de produire. Vich les DÉPLACE au
 * flush ; si l'enregistrement échoue, ils restaient — le fichier d'un client, dans
 * `sys_get_temp_dir()` ou `var/tmp`, hors de portée de la suppression du cabinet.
 *
 * Trois filets, du plus fin au plus large :
 *  1. ce que Vich n'a pas emporté est effacé à la remise à zéro du service (entre deux messages
 *     du worker) et à sa destruction (fin de requête) ;
 *  2. le dossier est rangé PAR CABINET (`var/tmp/cabinets/<id>/`) : il part avec lui
 *     (`ServiceSuppressionEntreprise::fichiersDuCabinet`) ;
 *  3. la purge quotidienne (`app:echange:purger`) efface ce qui y dort depuis plus d'une heure —
 *     le cas d'un PHP tué net, où ni destructeur ni remise à zéro ne passent.
 */
final class TemporairesDuCabinet implements ResetInterface
{
    /** @var list<string> fichiers remis, à effacer s'ils sont encore là à la fin du travail */
    private array $remis = [];

    public function __construct(
        #[Autowire('%app.dossier_des_temporaires%')]
        private readonly string $dossier,
    ) {
    }

    /** Le dossier de passage du cabinet. */
    public function dossierDe(int $idCabinet): string
    {
        return $this->dossier . '/' . $idCabinet;
    }

    /**
     * Un chemin neuf dans le dossier de passage du cabinet, retenu pour être effacé à la fin du
     * travail s'il n'a pas été emporté.
     */
    public function nouveau(Entreprise $entreprise, string $extension): string
    {
        $id = $entreprise->getId() ?? throw new \LogicException('Un fichier de passage appartient à un cabinet enregistré.');
        $repertoire = $this->dossierDe($id);
        if (!is_dir($repertoire) && !mkdir($repertoire, 0775, true) && !is_dir($repertoire)) {
            throw new \RuntimeException('Impossible de préparer le fichier de passage.');
        }

        $extension = preg_replace('/[^a-z0-9]/', '', strtolower($extension)) ?: 'bin';
        $chemin = $repertoire . '/' . bin2hex(random_bytes(16)) . '.' . $extension;
        $this->remis[] = $chemin;

        return $chemin;
    }

    public function reset(): void
    {
        foreach ($this->remis as $chemin) {
            if (is_file($chemin)) {
                @unlink($chemin);
            }
        }
        $this->remis = [];
    }

    public function __destruct()
    {
        $this->reset();
    }
}
