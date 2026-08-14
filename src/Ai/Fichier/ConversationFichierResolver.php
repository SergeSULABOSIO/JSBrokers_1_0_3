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
     * Le fichier prêt à soumettre pour un marqueur « @fichier:<id> », ou le MOTIF
     * pour lequel il ne peut pas l'être.
     *
     * CHAQUE ÉCHEC SE NOMME. Cette méthode rendait `null` pour cinq causes
     * différentes et l'appelant retirait le champ sans rien dire : c'est ainsi
     * qu'un contrat a disparu dans un Document vide présenté comme enregistré
     * (2026-08-14). Le motif nomme ce qui manque ET ce qui est disponible — sans
     * quoi l'utilisateur ne peut pas corriger, et Ket recommencera la même erreur.
     *
     * @param bool $pourExecution true à l'EXÉCUTION (Vich déplacera le binaire au
     *   flush) : on remet une COPIE temporaire pour préserver l'original attaché
     *   à la conversation. En dry-run, on valide sur l'original (aucun déplacement).
     */
    public function resoudre(string $marqueur, AiScope $scope, bool $pourExecution): PieceResolue
    {
        $id = ConversationFichierRef::id($marqueur);
        if ($id === null) {
            return PieceResolue::refus(sprintf(
                'La référence de pièce jointe « %s » est illisible. Attendu : @fichier:<id>.%s',
                $marqueur,
                $this->piecesDisponibles($scope),
            ));
        }

        $fichier = $this->trouver($id, $scope);
        if ($fichier === null) {
            return PieceResolue::refus(sprintf(
                'La pièce jointe #%d n’est pas attachée à cette conversation, le fichier n’a donc pas pu être joint.%s',
                $id,
                $this->piecesDisponibles($scope),
            ));
        }

        $chemin = $this->storage->resolvePath($fichier, 'fichier');
        if ($chemin === null || !is_file($chemin)) {
            return PieceResolue::refus(sprintf(
                'Le fichier « %s » (#%d) est introuvable sur le stockage : il ne peut pas être joint.',
                (string) $fichier->getNomOriginal(),
                $id,
            ));
        }

        $source = $chemin;
        if ($pourExecution) {
            $tmp = tempnam(sys_get_temp_dir(), 'ket_pj_');
            if ($tmp === false || !@copy($chemin, $tmp)) {
                return PieceResolue::refus(sprintf(
                    'Le fichier « %s » (#%d) n’a pas pu être préparé pour l’enregistrement.',
                    (string) $fichier->getNomOriginal(),
                    $id,
                ));
            }
            $source = $tmp;
        }

        return PieceResolue::ok(new UploadedFile(
            $source,
            (string) $fichier->getNomOriginal(),
            $fichier->getMimeType(),
            null,
            true, // test=true : fichier non issu d'un upload HTTP direct.
        ));
    }

    /**
     * Les pièces réellement attachées, listées dans le motif d'un refus.
     *
     * « La pièce #19 n'existe pas » laisse l'utilisateur — et Ket — sans recours.
     * « Pièces disponibles : #18 CONTRACT-….pdf » se corrige en un message. C'est la
     * règle de QUALITÉ DE LA QUESTION du prompt, appliquée à une erreur machine.
     */
    private function piecesDisponibles(AiScope $scope): string
    {
        $items = [];
        foreach ($scope->conversation?->getFichiers() ?? [] as $fichier) {
            $items[] = sprintf('#%d %s', (int) $fichier->getId(), (string) $fichier->getNomOriginal());
        }

        return $items === []
            ? ' Aucune pièce n’est attachée à cette conversation : demande à l’utilisateur d’en joindre une.'
            : ' Pièces disponibles : ' . implode(', ', $items) . '.';
    }
}
