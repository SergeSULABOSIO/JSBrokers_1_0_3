<?php

namespace App\Service\Workspace;

/**
 * D'OÙ VIENT UNE ÉCRITURE, quand cela change la règle à lui appliquer.
 *
 * Un seul cas aujourd'hui : la REPRISE de données, qui reconstitue l'historique d'un
 * portefeuille réel — une police souscrite sans prime chiffrée dans l'outil d'origine doit
 * pouvoir y être reprise telle quelle. Les autres écrivains (l'écran, Ket) n'en déclarent pas.
 *
 * ⚠ CE N'EST JAMAIS UNE DONNÉE. L'origine se passe en PARAMÈTRE PHP au service d'écriture
 * (WorkspaceMutationService::analyserOperation / executer), par le seul moteur de reprise
 * (AvanceurDImport, qui exécute les opérations de ReconstitueurDeTranche). Aucun champ d'une
 * opération, d'une requête HTTP ou d'un appel de Ket ne peut la fournir.
 */
enum OrigineEcriture
{
    case Reprise;
}
