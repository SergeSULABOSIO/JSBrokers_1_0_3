<?php

namespace App\Ai\Mutation;

use App\Ai\Tool\AiToolResult;

/**
 * POURQUOI UN OUTIL DE PLAN N'A PAS PRODUIT DE PLAN, dit en une phrase lisible —
 * et tirée de ce que l'outil a RÉELLEMENT répondu, jamais d'une formule générique.
 *
 * Cette traduction existait déjà, enfouie en privé dans ProgrammeRunner pour le
 * rapport de fin de mission. Elle sert maintenant à deux endroits — le rapport, et
 * l'avertissement affiché à l'utilisateur quand la prose du modèle décrit un plan
 * que l'outil a refusé de préparer. Deux implémentations diraient tôt ou tard deux
 * choses différentes du même refus : celui qu'on lit dans le rapport et celui qu'on
 * lit dans le fil.
 *
 * RÈGLE DE LECTURE (déjà payée par un incident) : un refus d'outil de plan est un
 * `AiToolResult::ok()` portant `pret: false`. Tester le seul statut ne l'intercepte
 * PAS — c'est `pret !== true` qui fait autorité.
 */
final class MotifDeRefus
{
    /** Même plafond que RepliPrecis::MAX_VALEURS : au-delà, une liste cesse d'aider. */
    private const MAX_CANDIDATS = 8;

    /** Un outil de plan a-t-il refusé de produire un plan ? */
    public static function estUnRefus(AiToolResult $resultat): bool
    {
        if ($resultat->status !== AiToolResult::STATUS_OK) {
            return true;
        }

        return ($resultat->data['pret'] ?? null) !== true;
    }

    public static function depuis(AiToolResult $resultat): string
    {
        if ($resultat->status === AiToolResult::STATUS_HORS_PERIMETRE) {
            return sprintf('Hors de votre périmètre d’accès (%s).', (string) ($resultat->data['libelle'] ?? 'données'));
        }
        if ($resultat->status === AiToolResult::STATUS_INTROUVABLE) {
            $precision = trim((string) ($resultat->data['precision'] ?? ''));

            return $precision !== '' ? sprintf('Cible introuvable : %s.', $precision) : 'Cible introuvable.';
        }

        $manquants = $resultat->data['manquants'] ?? [];
        if (is_array($manquants) && $manquants !== []) {
            $phrase = 'Informations manquantes : ' . implode(' ; ', array_map('strval', $manquants));

            // ET CE QUI A ÉTÉ ÉCARTÉ, quand il y en a. C'est le motif que le courtier
            // LIT — dans le rapport d'un programme, et dans le fil quand la prose du
            // modèle décrit un plan qui n'existe pas. Le 2026-09-08, il y a lu « numéro
            // d'impôt manquant » pour sept assureurs dont il venait de dicter le numéro :
            // la valeur avait bien été transmise, sous un nom que le formulaire ne
            // reconnaissait pas. Taire cette moitié-là, c'est lui affirmer qu'il n'a pas
            // donné ce qu'il a donné — et le laisser redonner indéfiniment la même chose.
            $ignores = $resultat->data['champsIgnores'] ?? [];
            if (is_array($ignores) && $ignores !== []) {
                $phrase .= ' — et ceci a été écarté : ' . implode(' ', array_map('strval', $ignores));
            }

            return $phrase;
        }
        // Un nom dicté qui n'a pas pu être identifié : on nomme le terme cherché, sinon
        // l'utilisateur ne peut pas savoir lequel de ses mots n'a pas été compris.
        $aDemander = $resultat->data['aDemander'] ?? [];
        if (is_array($aDemander) && $aDemander !== []) {
            $termes = [];
            foreach ($aDemander as $question) {
                if (!is_array($question)) {
                    continue;
                }
                $libelle = trim((string) ($question['libelle'] ?? $question['champ'] ?? ''));
                $terme = trim((string) ($question['terme'] ?? ''));
                if ($libelle === '') {
                    continue;
                }
                $termes[] = $terme !== '' ? sprintf('%s « %s »', $libelle, $terme) : $libelle;
            }
            if ($termes !== []) {
                return 'Références à préciser : ' . implode(' ; ', $termes) . '.';
            }
        }
        $blocages = $resultat->data['blocages'] ?? [];
        if (is_array($blocages) && $blocages !== []) {
            return 'Blocage : ' . implode(' ; ', array_map('strval', $blocages));
        }
        if (($resultat->data['planEnAttente'] ?? false) === true) {
            return 'Un autre plan attendait déjà une décision.';
        }
        if (($resultat->data['dejaAJour'] ?? false) === true) {
            return 'Rien à écrire : les données étaient déjà à jour.';
        }
        // « bloquant » est, par contrat, la phrase écrite POUR L'UTILISATEUR (c'est
        // aussi celle que RepliPrecis restitue). Elle passe donc avant « note », qui
        // s'adresse au modèle : le 2026-08-13, faute de cette branche, le courtier a
        // lu « reprends le nom exact et rappelle preparer_operations ».
        $bloquant = trim((string) ($resultat->data['bloquant'] ?? ''));
        if ($bloquant !== '') {
            return $bloquant;
        }
        // PLUSIEURS CANDIDATS. Cinq refus du catalogue ne portent que « ambigu » —
        // deux homonymes, deux polices concurrentes. RepliPrecis sait les restituer
        // depuis toujours ; ici, faute de cette branche, ils retombaient sur la note
        // et le courtier lisait « Demande LEQUEL puis ARRÊTE-TOI » — une consigne
        // adressée à quelqu'un d'autre que lui, qui ne nommait même pas les candidats
        // entre lesquels on lui demandait de choisir.
        $candidats = self::candidats($resultat->data['ambigu'] ?? null);
        if ($candidats !== []) {
            return 'Plusieurs enregistrements correspondent — lequel visez-vous ? ' . implode(' ; ', $candidats);
        }

        // LA FRONTIÈRE, ET ELLE NE S'OUVRE PLUS. « note » est le brouillon adressé au
        // modèle : elle le tutoie, lui nomme des outils et, pour les outils de
        // programme, lui récite le CATALOGUE ENTIER avec les arguments de chacun.
        // Servie ici, elle finissait dans la bulle du courtier — et, persistée en
        // meta, se réaffichait à chaque rechargement. Constaté le 2026-08-12
        // (conversation 40) puis le 2026-09-14, après qu'un premier correctif eut
        // traité UN site sur vingt-deux en laissant la retombée en place.
        //
        // Un refus que nous n'avons pas su rédiger pour l'utilisateur donne donc une
        // phrase neutre. Elle est pauvre, et c'est voulu : elle doit se remarquer, et
        // le test de contrat pousse chaque outil à écrire son « bloquant ».
        return 'Je n’ai pas pu préparer ce plan, et rien n’a été enregistré. '
            . 'Reformulez votre demande ou précisez l’enregistrement visé.';
    }

    /**
     * Les candidats d'un refus « ambigu », en une ligne chacun.
     *
     * Deux formes coexistent selon l'outil, et les deux doivent se lire : une liste
     * de noms (CongesTool) ou une liste de résumés structurés (`resumer()` des outils
     * d'avenant, qui portent police, numéro, client, assureur, période).
     *
     * @return list<string>
     */
    private static function candidats(mixed $ambigu): array
    {
        if (!is_array($ambigu)) {
            return [];
        }

        $lignes = [];
        foreach (array_slice($ambigu, 0, self::MAX_CANDIDATS) as $candidat) {
            if (is_scalar($candidat)) {
                $texte = trim((string) $candidat);
                if ($texte !== '') {
                    $lignes[] = $texte;
                }
                continue;
            }
            if (!is_array($candidat)) {
                continue;
            }
            // Mêmes clés, et dans le même ordre, que RepliPrecis::candidat() : deux
            // rendus divergents du même candidat dérouteraient plus qu'ils n'aident.
            $parts = [];
            foreach (['police', 'libelle', 'nom', 'client', 'assureur', 'periode'] as $cle) {
                $valeur = trim((string) ($candidat[$cle] ?? ''));
                if ($valeur !== '' && !in_array($valeur, $parts, true)) {
                    $parts[] = $valeur;
                }
            }
            if ($parts !== []) {
                $lignes[] = implode(' · ', $parts);
            }
        }

        return $lignes;
    }
}
