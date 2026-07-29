<?php

namespace App\Ai\Fichier;

use App\Ai\Mutation\ConversationFichierRef;
use App\Ai\Scope\AiScope;
use App\Entity\AssistantConversationFichier;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Vich\UploaderBundle\Storage\StorageInterface;

/**
 * Résout un marqueur « @fichier:<id> » d'un plan de Ket en un UploadedFile réel,
 * FAIL-CLOSED au périmètre de la conversation en cours (AiScope) : seul un
 * fichier RÉELLEMENT attaché à cette conversation peut être injecté dans un
 * formulaire d'entité (ex. le Document d'un Avenant). Point de passage unique,
 * partagé par le dry-run et l'exécution du WorkspaceMutationService.
 */
final class ConversationFichierResolver
{
    public function __construct(
        private readonly StorageInterface $storage,
    ) {
    }

    /** Le fichier de conversation désigné par l'id, uniquement s'il appartient au scope. */
    public function trouver(int $id, AiScope $scope): ?AssistantConversationFichier
    {
        $conversation = $scope->conversation;
        if ($conversation === null) {
            return null; // Hors conversation (test, exécution différée) : aucun fichier joignable.
        }
        foreach ($conversation->getFichiers() as $fichier) {
            if ($fichier->getId() === $id) {
                return $fichier;
            }
        }

        return null;
    }

    /**
     * UploadedFile prêt à soumettre pour un marqueur « @fichier:<id> », ou null
     * si le marqueur est invalide / le fichier introuvable / hors périmètre.
     *
     * @param bool $pourExecution true à l'EXÉCUTION (Vich déplacera le binaire au
     *   flush) : on remet une COPIE temporaire pour préserver l'original attaché
     *   à la conversation. En dry-run, on valide sur l'original (aucun déplacement).
     */
    public function uploadPourMarqueur(string $marqueur, AiScope $scope, bool $pourExecution): ?UploadedFile
    {
        $id = ConversationFichierRef::id($marqueur);
        if ($id === null) {
            return null;
        }
        $fichier = $this->trouver($id, $scope);
        if ($fichier === null) {
            return null;
        }

        $chemin = $this->storage->resolvePath($fichier, 'fichier');
        if ($chemin === null || !is_file($chemin)) {
            return null;
        }

        $source = $chemin;
        if ($pourExecution) {
            $tmp = tempnam(sys_get_temp_dir(), 'ket_pj_');
            if ($tmp === false || !@copy($chemin, $tmp)) {
                return null;
            }
            $source = $tmp;
        }

        return new UploadedFile(
            $source,
            (string) $fichier->getNomOriginal(),
            $fichier->getMimeType(),
            null,
            true, // test=true : fichier non issu d'un upload HTTP direct.
        );
    }
}
