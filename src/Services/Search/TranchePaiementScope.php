<?php

namespace App\Services\Search;

/**
 * Périmètre « Paiement » des tranches : QUATRE axes indépendants et COMPLÉMENTAIRES,
 * portés par la barre de recherche (badges + dialogue avancé) et par les groupes de chips
 * de la liste Tranches, cumulables en ET.
 *
 * POURQUOI QUATRE AXES ET NON UN STATUT UNIQUE. Une tranche porte TROIS dettes qui ont des
 * DÉBITEURS DIFFÉRENTS et ne se compensent JAMAIS :
 *   • la PRIME est due par l'ASSURÉ (au profit de l'assureur) ;
 *   • la COMMISSION est due par l'ASSUREUR (au profit du courtier) ;
 *   • la RÉTROCOMMISSION est due par le COURTIER au partenaire (flux inverse).
 * Un statut unique les mélangeait : « impayées » signifiait « prime ET/OU commission », si
 * bien qu'une tranche à prime soldée mais commission due y figurait avec un solde de prime
 * de 0. L'assistant a annoncé tour à tour 5 puis 1 puis 5 lignes pour la MÊME question
 * (incident du 2026-08-05) : les deux réponses étaient « vraies » sous deux lectures du même
 * mot. Un axe par dette rend l'ambiguïté INEXPRIMABLE — c'est la seule garantie durable.
 *
 * L'échéance est un QUATRIÈME axe, orthogonal aux trois : une prime impayée peut être échue
 * ou non, et le retard se croise avec n'importe quelle dette.
 *
 * COMPOSITION : les combinaisons remplacent sans perte les anciens statuts composites —
 *   commission exigible (à collecter maintenant) = prime PAYÉE + commission IMPAYÉE ;
 *   rétro à payer maintenant                     = rétro IMPAYÉE + commission PAYÉE ;
 *   tout soldé                                   = prime PAYÉE + commission PAYÉE ;
 *   relances en retard                           = prime IMPAYÉE + ÉCHUES.
 * Seule l'ancienne union « prime OU commission » n'est pas exprimable en ET : c'est
 * délibéré, c'est elle qu'on supprime.
 *
 * Aucun de ces axes n'est stocké en base : ils dérivent des indicateurs calculés à la volée
 * par TrancheIndicatorStrategy. Ces critères sont donc interceptés par le moteur de recherche
 * (JSBDynamicSearchService) qui bascule sur un filtrage/tri en mémoire assuré par
 * TranchePaiementService, au lieu du chemin SQL standard.
 */
final class TranchePaiementScope
{
    /** Clés de critère synthétiques, une par axe. */
    public const AXE_PRIME = '__paiement_prime__';
    public const AXE_COMMISSION = '__paiement_commission__';
    public const AXE_RETRO = '__paiement_retro__';
    public const AXE_ECHEANCE = '__echeance_tranche__';

    /**
     * Valeurs des trois axes de dette.
     *
     * PAYEE et IMPAYEE PARTITIONNENT l'axe (disjointes, exhaustives). PARTIELLE est un
     * SOUS-ENSEMBLE d'IMPAYEE — « il reste dû, mais de l'argent est déjà rentré » —, pas
     * une troisième valeur disjointe, et c'est délibéré : six appelants (boussole,
     * programme du jour, vigie, suivi_impayes…) ont besoin de « toute dette restante » en
     * UN filtre. Découper IMPAYEE en deux valeurs disjointes les obligerait à réunir deux
     * requêtes, et cette union serait le retour d'un mot recouvrant plusieurs sens.
     *
     * Ce n'est pas l'ambiguïté qui a causé l'incident du 2026-08-05 : là, un seul mot
     * désignait deux dettes de DÉBITEURS DIFFÉRENTS. Ici les trois valeurs parlent de la
     * même dette, du même débiteur, à trois stades de son règlement.
     */
    public const PAYEE = 'payee';
    public const PARTIELLE = 'partielle';
    public const IMPAYEE = 'impayee';

    /** Valeurs de l'axe d'échéance. */
    public const ECHUE = 'echue';
    public const A_ECHOIR = 'a_echoir';

    /**
     * SOURCE UNIQUE de la description des axes : alimente les groupes de chips, le dialogue
     * de recherche avancée, les enum des outils de l'assistant IA et les tests. L'ordre est
     * celui de présentation.
     *
     * `nom` est le nom court employé par les outils IA (objet `axes: {prime, commission…}`),
     * `libelle` titre le groupe de chips, `valeurs` mappe valeur => libellé affiché.
     *
     * @var array<string, array{nom: string, libelle: string, icone: string, valeurs: array<string, string>}>
     */
    public const AXES = [
        self::AXE_PRIME => [
            'nom' => 'prime',
            'libelle' => 'Prime (due par l\'assuré)',
            'icone' => 'action:alert',
            'valeurs' => [
                self::PAYEE => 'Prime payée',
                self::PARTIELLE => 'Prime partiellement payée',
                self::IMPAYEE => 'Prime impayée',
            ],
        ],
        self::AXE_COMMISSION => [
            'nom' => 'commission',
            'libelle' => 'Commission (due par l\'assureur)',
            'icone' => 'paiement',
            'valeurs' => [
                self::PAYEE => 'Commission payée',
                self::PARTIELLE => 'Commission partiellement encaissée',
                self::IMPAYEE => 'Commission impayée',
            ],
        ],
        self::AXE_RETRO => [
            'nom' => 'retro',
            'libelle' => 'Rétrocommission (due au partenaire)',
            'icone' => 'depense',
            'valeurs' => [
                self::PAYEE => 'Rétro payée',
                self::PARTIELLE => 'Rétro partiellement reversée',
                self::IMPAYEE => 'Rétro à payer',
            ],
        ],
        self::AXE_ECHEANCE => [
            'nom' => 'echeance',
            'libelle' => 'Échéance',
            'icone' => 'action:calendar',
            'valeurs' => [
                self::ECHUE => 'Échues (en retard)',
                self::A_ECHOIR => 'À échoir',
            ],
        ],
    ];

    /**
     * Fragment de JSON-Schema décrivant l'argument `axes` des outils de l'assistant IA.
     * SOURCE UNIQUE : suivi_impayes, rechercher_entites et compter_entites décrivent les
     * axes AU MOT PRÈS de la même façon, sinon le modèle croit à trois filtres différents.
     *
     * $note préfixe la description (les outils génériques précisent que l'argument ne vaut
     * que pour Tranche). Elle passe par ici, et non par une clé maison ajoutée après coup :
     * les dialectes de tool-calling rejettent les extensions hors JSON-Schema standard.
     *
     * @return array<string, mixed>
     */
    public static function proprieteSchema(?string $note = null): array
    {
        $proprietes = [];
        foreach (self::AXES as $axe) {
            $proprietes[$axe['nom']] = [
                'type' => 'string',
                'enum' => array_keys($axe['valeurs']),
                'description' => $axe['libelle'] . ' : ' . implode(' / ', array_map(
                    static fn (string $v, string $l): string => $v . ' = ' . $l,
                    array_keys($axe['valeurs']),
                    array_values($axe['valeurs']),
                )) . '. Omettre = ne pas filtrer sur cet axe.',
            ];
        }

        return [
            'type' => 'object',
            'description' => ($note === null ? '' : $note . ' ')
                . 'Filtre par DETTE. Chaque axe est indépendant et se CUMULE en ET avec '
                . 'les autres. Les trois dettes ont des débiteurs DIFFÉRENTS et ne se compensent '
                . 'jamais : la prime est due par l\'assuré, la commission par l\'assureur, la rétro '
                . 'par le courtier au partenaire. Compositions utiles : commission à collecter '
                . 'maintenant = {prime: payee, commission: impayee} ; rétro à verser maintenant = '
                . '{retro: impayee, commission: payee} ; relances en retard = {prime: impayee, '
                . 'echeance: echue} ; tout soldé = {prime: payee, commission: payee}. Aucun axe = '
                . 'toutes les tranches suivies. ATTENTION : sur chaque dette, « partielle » est un '
                . 'SOUS-ENSEMBLE de « impayee » (il reste dû, ET de l\'argent est déjà rentré), pas '
                . 'une catégorie à part : ne les additionne JAMAIS, et n\'annonce pas « impayee » '
                . 'comme « rien reçu ».',
            'properties' => $proprietes,
            'required' => [],
        ];
    }

    /**
     * Clé de critère d'un axe désigné par son nom court (« prime », « commission »…).
     */
    public static function cle(string $nom): ?string
    {
        foreach (self::AXES as $cle => $axe) {
            if ($axe['nom'] === $nom) {
                return $cle;
            }
        }

        return null;
    }

    public static function estValide(string $cleAxe, ?string $valeur): bool
    {
        return $valeur !== null && isset(self::AXES[$cleAxe]['valeurs'][$valeur]);
    }

    public static function libelle(string $cleAxe, string $valeur): string
    {
        return self::AXES[$cleAxe]['valeurs'][$valeur] ?? $valeur;
    }

    /**
     * Libellé lisible d'une combinaison d'axes (« Prime impayée · Échues »), pour que
     * l'assistant puisse dire à l'utilisateur EXACTEMENT quel filtre il a appliqué.
     *
     * @param array<string, string> $axes clé de critère => valeur
     */
    public static function libelleCombinaison(array $axes): string
    {
        $parts = [];
        foreach (self::AXES as $cle => $_) {
            if (isset($axes[$cle]) && self::estValide($cle, $axes[$cle])) {
                $parts[] = self::libelle($cle, $axes[$cle]);
            }
        }

        return implode(' · ', $parts);
    }

    /**
     * Normalise une combinaison venue des outils IA (noms courts : {prime: 'impayee'}) ou
     * déjà exprimée en clés de critère. Les entrées inconnues ou invalides sont ignorées.
     *
     * @param array<string, string|null> $axes
     * @return array<string, string> clé de critère => valeur, dans l'ordre de AXES
     */
    public static function normaliserAxes(array $axes): array
    {
        $normalises = [];
        foreach (self::AXES as $cle => $axe) {
            $valeur = $axes[$cle] ?? $axes[$axe['nom']] ?? null;
            if (is_string($valeur) && self::estValide($cle, $valeur)) {
                $normalises[$cle] = $valeur;
            }
        }

        return $normalises;
    }

    /**
     * Traduit une combinaison en NOMS COURTS, la forme attendue par l'argument `axes` des
     * outils IA. Passe par normaliserAxes(), donc l'ordre est canonique quelle que soit la
     * branche de détection qui a produit la combinaison — sans quoi la sortie du moteur
     * simulé varierait d'une formulation à l'autre.
     *
     * @param array<string, string|null> $axes
     * @return array<string, string> nom court => valeur
     */
    public static function versNomsCourts(array $axes): array
    {
        $courts = [];
        foreach (self::normaliserAxes($axes) as $cle => $valeur) {
            $courts[self::AXES[$cle]['nom']] = $valeur;
        }

        return $courts;
    }

    /**
     * Fragment de critères à passer au moteur de recherche pour restreindre aux axes
     * demandés. SOURCE UNIQUE partagée par les chips de la rubrique et les outils génériques
     * de l'assistant IA (compter_entites / rechercher_entites) : les mêmes critères traversent
     * la même interception (filtrage/tri en mémoire par TranchePaiementService), donc Ket et
     * la barre de chips donnent EXACTEMENT le même résultat. Retourne un tableau vide si
     * l'entité n'est pas Tranche ou si aucun axe valide n'est fourni (filtre ignoré).
     *
     * @param array<string, string|null> $axes noms courts ou clés de critère
     * @return array<string, array{operator: string, value: string, label: string}>
     */
    public static function critereRecherche(string $entityShortName, array $axes): array
    {
        if ($entityShortName !== 'Tranche') {
            return [];
        }

        $criteres = [];
        foreach (self::normaliserAxes($axes) as $cle => $valeur) {
            $criteres[$cle] = [
                'operator' => '=',
                'value' => $valeur,
                'label' => self::libelle($cle, $valeur),
            ];
        }

        return $criteres;
    }

    /**
     * Extrait les axes présents dans un jeu de critères de recherche (valeurs brutes ou
     * enveloppées dans ['value' => …]), en ignorant les valeurs inconnues.
     *
     * @param array<string, mixed> $criteria
     * @return array<string, string> clé de critère => valeur
     */
    public static function extraireAxes(array $criteria): array
    {
        $axes = [];
        foreach (array_keys(self::AXES) as $cle) {
            if (!array_key_exists($cle, $criteria)) {
                continue;
            }
            $raw = $criteria[$cle];
            $valeur = is_array($raw) ? (string) ($raw['value'] ?? '') : (string) $raw;
            if (self::estValide($cle, $valeur)) {
                $axes[$cle] = $valeur;
            }
        }

        return $axes;
    }

    /**
     * Indique si un jeu de critères porte au moins une clé d'axe (même vide ou invalide) :
     * le moteur doit alors emprunter le chemin en mémoire et retirer ces clés.
     *
     * @param array<string, mixed> $criteria
     */
    public static function porteUnAxe(array $criteria): bool
    {
        foreach (array_keys(self::AXES) as $cle) {
            if (array_key_exists($cle, $criteria)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Retire toutes les clés d'axe d'un jeu de critères (elles ne sont pas filtrables en SQL).
     *
     * @param array<string, mixed> $criteria
     * @return array<string, mixed>
     */
    public static function retirerAxes(array $criteria): array
    {
        foreach (array_keys(self::AXES) as $cle) {
            unset($criteria[$cle]);
        }

        return $criteria;
    }

    /**
     * Détecte une COMBINAISON d'axes dans une question en langage naturel déjà normalisée
     * (AiText::normalize : minuscules, sans accents). Sert au moteur simulé pour que
     * « quelles primes sont dues ? » applique le MÊME filtre que le chip correspondant.
     *
     * @return array<string, string> clé de critère => valeur (vide si rien de détecté)
     */
    public static function detecterAxesDepuisTexte(string $texteNormalise): array
    {
        $axes = [];

        $mentionnePrime = (bool) preg_match('/\bprimes?\b/', $texteNormalise);
        $mentionneCommission = (bool) preg_match('/\bcommissions?\b/', $texteNormalise);
        $mentionneRetro = (bool) preg_match('/\bretro(commissions?)?\b/', $texteNormalise);
        $du = (bool) preg_match('/\b(dues?|dois|impayees?|impayes?|arrieres?|en retard|a collecter|a encaisser|a payer|a verser|a reverser|exigibles?|soldes? dus?|reste|restants?)\b/', $texteNormalise);
        $soldee = (bool) preg_match('/\b(payees?|soldees?|reglees?|encaissees?)\b/', $texteNormalise);
        // « partiellement », « en partie », « acompte » : la dette est entamée sans être
        // soldée. Testé AVANT « du » et « soldee », qui l'accompagnent presque toujours
        // (« primes partiellement payées » porte les deux).
        $partielle = (bool) preg_match('/\b(partiel\w*|en partie|acomptes?|partiellement)\b/', $texteNormalise);

        // « partiellement » qualifie la dette nommée, et prime sur tout le reste : la
        // formulation la porte presque toujours avec « payée » ou « due », qui seuls
        // enverraient sur les valeurs extrêmes.
        if ($partielle) {
            if ($mentionneRetro) {
                return [self::AXE_RETRO => self::PARTIELLE];
            }
            if ($mentionneCommission) {
                return [self::AXE_COMMISSION => self::PARTIELLE];
            }
            if ($mentionnePrime) {
                return [self::AXE_PRIME => self::PARTIELLE];
            }
        }

        // Ordre volontaire : la RÉTRO d'abord (elle mentionne aussi « payer »), puis la
        // commission EXIGIBLE — qui n'est pas un axe mais la COMBINAISON « prime payée +
        // commission impayée » : sa définition même.
        if ($mentionneRetro && $du) {
            $axes[self::AXE_RETRO] = self::IMPAYEE;
            // « à payer maintenant » : la dette rétro n'est née que si la commission
            // partageable a été encaissée.
            $axes[self::AXE_COMMISSION] = self::PAYEE;

            return $axes;
        }
        if ($mentionneRetro && $soldee) {
            return [self::AXE_RETRO => self::PAYEE];
        }

        // « collecter » / « encaisser » sans préposition : la formulation courante est
        // « quelles commissions puis-je collecter ? », pas « commissions à collecter ».
        if ($mentionneCommission && preg_match('/\bexigibles?\b|\bcollecter\b|\bencaisser\b/', $texteNormalise)) {
            return [self::AXE_PRIME => self::PAYEE, self::AXE_COMMISSION => self::IMPAYEE];
        }

        if ($mentionnePrime && $du) {
            $axes[self::AXE_PRIME] = self::IMPAYEE;
        } elseif ($mentionnePrime && $soldee) {
            $axes[self::AXE_PRIME] = self::PAYEE;
        }

        if ($mentionneCommission && $du) {
            $axes[self::AXE_COMMISSION] = self::IMPAYEE;
        } elseif ($mentionneCommission && $soldee) {
            $axes[self::AXE_COMMISSION] = self::PAYEE;
        }

        // Retard : axe d'échéance, cumulable avec ce qui précède.
        if (preg_match('/\b(echues?|en retard)\b/', $texteNormalise)) {
            $axes[self::AXE_ECHEANCE] = self::ECHUE;
        } elseif (preg_match('/\ba echoir\b/', $texteNormalise)) {
            $axes[self::AXE_ECHEANCE] = self::A_ECHOIR;
        }

        // « impayés » / « arriérés » sans dette nommée : l'union n'existe plus (c'était
        // l'ambiguïté à supprimer). On retient la dette du CLIENT, la plus courante en
        // relance, et l'outil restitue la répartition des deux dettes pour que la réponse
        // puisse nommer l'autre.
        if ($axes === [] && preg_match('/\bimpayees?\b|\bimpayes?\b|\barrieres?\b|\brelances?\b/', $texteNormalise)) {
            $axes[self::AXE_PRIME] = self::IMPAYEE;
        }

        return $axes;
    }
}
