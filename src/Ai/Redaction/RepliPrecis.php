<?php

namespace App\Ai\Redaction;

/**
 * CE QUE KET DIT QUAND LA RÉDACTION N'A RIEN PRODUIT — et pourquoi ce n'est plus
 * une phrase toute faite.
 *
 * LE SYMPTÔME. Un message part en planification, le modèle appelle un outil, puis,
 * au tour de rédaction — où plus aucun outil ne lui est déclaré —, il en réclame un
 * de plus au lieu d'écrire. Le moteur n'a alors aucun texte à rendre. Il servait
 * jusqu'ici une phrase générique : « il me manque un élément pour conclure,
 * reformulez ». Mesuré le 2026-08-10 sur deux messages consécutifs d'un même
 * courtier : les deux fois, l'outil avait pourtant TROUVÉ quelque chose. Ce n'est
 * pas l'information qui manquait, c'est la restitution.
 *
 * POURQUOI CETTE PHRASE ÉTAIT LE PIRE DES REPLIS. Elle rejette sur l'utilisateur un
 * travail déjà fait — « reformulez » —, alors que le serveur tient les résultats
 * dans ses mains ; elle ne dit ni ce qui a été cherché, ni ce qui a été trouvé, ni
 * ce qui manque VRAIMENT ; et elle demande « le client, la police ou la période »
 * même quand l'utilisateur vient de les donner. Deux tours facturés pour une
 * non-réponse, et un utilisateur qui recommence à l'identique.
 *
 * CE QU'ON FAIT À LA PLACE. On rédige nous-mêmes, en PHP, à partir des résultats
 * d'outils du tour : ils portent déjà tout le nécessaire — la question à poser
 * (« aDemander »), le blocage rencontré (« bloquant »), les candidats à départager
 * (« ambigu »), ou la liste obtenue. Coût : ZÉRO token, puisque rien n'est demandé
 * au fournisseur. Précision : totale, puisque rien n'est deviné.
 *
 * La phrase générique subsiste, mais comme dernier recours seulement : le cas où
 * aucun outil n'a tourné, donc où l'on n'a réellement rien à raconter.
 */
final class RepliPrecis
{
    /** Au-delà, une énumération cesse d'aider à choisir et devient un mur. */
    private const MAX_VALEURS = 8;

    /**
     * Dernier recours : aucun outil n'a tourné, il n'y a rien à restituer. On dit la
     * vérité — nous n'avons pas conclu — sans en faire porter la faute à
     * l'utilisateur, et on indique la sortie.
     */
    public const GENERIQUE = "Je n'ai pas pu aboutir sur cette demande en une seule fois. "
        . 'Redites-la-moi en nommant le point précis qui vous intéresse (le client, la police ou la '
        . 'période), et je la traite entièrement.';

    /**
     * @param array<int, array{outil: string, data: array<string, mixed>}> $resultats
     *        résultats des outils exécutés pendant la planification, dans l'ordre
     */
    public function depuis(array $resultats): string
    {
        $morceaux = [];
        foreach ($resultats as $resultat) {
            $texte = $this->restituer((array) ($resultat['data'] ?? []));
            if ($texte !== null && !in_array($texte, $morceaux, true)) {
                $morceaux[] = $texte;
            }
        }

        if ($morceaux === []) {
            return self::GENERIQUE;
        }

        // Une invitation à répondre, une seule fois et à la fin : sans elle, un
        // constat reste un constat et l'utilisateur ne sait pas ce qu'on attend de lui.
        return implode("\n\n", $morceaux);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function restituer(array $data): ?string
    {
        // 1. Une QUESTION déjà formulée par l'outil : c'est le cas le plus précieux,
        //    et c'est exactement celui que la phrase générique écrasait.
        if (isset($data['aDemander']) && is_array($data['aDemander']) && $data['aDemander'] !== []) {
            $lignes = [];
            foreach ($data['aDemander'] as $question) {
                $ligne = $this->question((array) $question);
                if ($ligne !== null) {
                    $lignes[] = '- ' . $ligne;
                }
            }
            if ($lignes !== []) {
                return count($lignes) === 1
                    ? 'Il me manque une précision pour aller au bout :' . "\n" . implode("\n", $lignes)
                    : 'Il me manque quelques précisions pour aller au bout :' . "\n" . implode("\n", $lignes);
            }
        }

        // 2. Un BLOCAGE nommé : l'utilisateur a droit à la raison, pas à « reformulez ».
        if (isset($data['bloquant']) && is_string($data['bloquant']) && trim($data['bloquant']) !== '') {
            $texte = trim($data['bloquant']);
            $cherche = $this->liste($data['cherchePar'] ?? null);
            if ($cherche !== []) {
                $texte .= "\n\nJ’ai cherché sur : " . implode(' ; ', $cherche) . '.';
            }

            return $texte;
        }

        // 3. Plusieurs CANDIDATS : on les montre, c'est une question à un clic.
        if (isset($data['ambigu']) && is_array($data['ambigu']) && $data['ambigu'] !== []) {
            $lignes = [];
            foreach (array_slice($data['ambigu'], 0, self::MAX_VALEURS) as $candidat) {
                $ligne = $this->candidat($candidat);
                if ($ligne !== null) {
                    $lignes[] = '- ' . $ligne;
                }
            }
            if ($lignes !== []) {
                return 'Plusieurs enregistrements correspondent — lequel visez-vous ?' . "\n"
                    . implode("\n", $lignes);
            }
        }

        // 4. Une RECHERCHE qui n'a rien ramené : dire ce qui a été cherché vaut mieux
        //    que laisser croire que la donnée n'existe pas.
        if (array_key_exists('totalItems', $data) && (int) $data['totalItems'] === 0) {
            $quoi = (string) ($data['libelle'] ?? $data['entite'] ?? 'enregistrement');
            $texte = sprintf('Je n’ai trouvé aucun élément dans « %s »', $quoi);
            $precisions = [];
            if (($filtre = trim((string) ($data['filtre'] ?? ''))) !== '') {
                $precisions[] = sprintf('filtre « %s »', $filtre);
            }
            if (($rubrique = trim((string) ($data['filtreRubrique'] ?? ''))) !== '') {
                $precisions[] = $rubrique;
            }
            if (($perimetre = trim((string) ($data['perimetre'] ?? ''))) !== '') {
                $precisions[] = $perimetre;
            }
            if ($precisions !== []) {
                $texte .= ' avec ' . implode(', ', $precisions);
            }

            return $texte . '. Précisez le nom exact recherché et je reprends.';
        }

        return null;
    }

    /**
     * Une question d'outil rendue lisible : ce qui manque, sur quel terme, et les
     * réponses possibles quand l'outil les connaît.
     *
     * @param array<string, mixed> $question
     */
    private function question(array $question): ?string
    {
        // Certaines questions sont déjà écrites en toutes lettres par l'outil
        // (« À quelle date cette résiliation prend-elle effet ? ») : on ne les
        // reformule pas, on les sert telles quelles.
        if (($texte = trim((string) ($question['question'] ?? ''))) !== '') {
            return $texte;
        }

        $libelle = trim((string) ($question['libelle'] ?? $question['champ'] ?? ''));
        if ($libelle === '') {
            return null;
        }

        $terme = trim((string) ($question['terme'] ?? ''));
        $ligne = match ((string) ($question['probleme'] ?? '')) {
            'introuvable' => $terme !== ''
                ? sprintf('%s : « %s » ne correspond à rien dans cet espace de travail.', $libelle, $terme)
                : sprintf('%s : introuvable dans cet espace de travail.', $libelle),
            'ambigu' => $terme !== ''
                ? sprintf('%s : « %s » désigne plusieurs enregistrements.', $libelle, $terme)
                : sprintf('%s : plusieurs enregistrements correspondent.', $libelle),
            default => sprintf('%s : information manquante.', $libelle),
        };

        $valeurs = $this->liste($question['valeurs'] ?? null);
        if ($valeurs !== []) {
            $ligne .= ' Lequel : ' . implode(', ', array_slice($valeurs, 0, self::MAX_VALEURS)) . ' ?';
        }

        return $ligne;
    }

    /**
     * Un candidat d'ambiguïté rendu par ce qui permet de CHOISIR — sa période, son
     * client, son statut —, jamais par son identifiant interne : l'utilisateur ne
     * connaît pas les identifiants, les lui montrer ne l'aide pas à trancher.
     */
    private function candidat(mixed $candidat): ?string
    {
        if (is_string($candidat)) {
            return trim($candidat) !== '' ? trim($candidat) : null;
        }
        if (!is_array($candidat)) {
            return null;
        }

        $parts = [];
        foreach (['police', 'libelle', 'nom', 'client', 'assureur', 'periode', 'statutRenouvellement'] as $cle) {
            $valeur = trim((string) ($candidat[$cle] ?? ''));
            if ($valeur !== '' && !in_array($valeur, $parts, true)) {
                $parts[] = $valeur;
            }
        }
        if ($parts === []) {
            return null;
        }
        if (($candidat['enVigueur'] ?? false) === true) {
            $parts[] = 'en vigueur aujourd’hui';
        }

        return implode(' · ', $parts);
    }

    /**
     * @return array<int, string>
     */
    private function liste(mixed $valeurs): array
    {
        if (!is_array($valeurs)) {
            return [];
        }

        $lignes = [];
        foreach ($valeurs as $valeur) {
            if (is_scalar($valeur) && trim((string) $valeur) !== '') {
                $lignes[] = trim((string) $valeur);
            }
        }

        return array_values(array_unique($lignes));
    }
}
