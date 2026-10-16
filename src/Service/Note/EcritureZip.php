<?php

namespace App\Service\Note;

/**
 * L'ÉCRITURE DU ZIP DE L'ARCHIVE COMPTABLE — un composant à part, pour qu'un test puisse
 * simuler ce que le disque fait en vrai : une fermeture qui échoue (justificatif supprimé
 * pendant la construction, disque plein). libzip n'écrit qu'à la FERMETURE ; c'est donc
 * elle qui dit si l'archive existe ({@see ArchiveComptableDuCabinet}).
 */
class EcritureZip
{
    private ?\ZipArchive $zip = null;

    public function ouvrir(string $chemin): bool
    {
        $this->zip = new \ZipArchive();

        return $this->zip->open($chemin, \ZipArchive::OVERWRITE) === true;
    }

    public function ajouterContenu(string $nom, string $contenu): void
    {
        $this->zip?->addFromString($nom, $contenu);
    }

    /** Le fichier n'est lu qu'à la fermeture : il doit encore exister à ce moment-là. */
    public function ajouterFichier(string $nom, string $source): void
    {
        $this->zip?->addFile($source, $nom);
    }

    /** Vrai si l'archive a bien été écrite. */
    public function fermer(): bool
    {
        $ok = $this->zip?->close() === true;
        $this->zip = null;

        return $ok;
    }
}
