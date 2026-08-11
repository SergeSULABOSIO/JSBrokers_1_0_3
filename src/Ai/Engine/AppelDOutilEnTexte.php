<?php

namespace App\Ai\Engine;

use App\Ai\Tool\AiToolInterface;

/**
 * RATTRAPE UN APPEL D'OUTIL QUE LE MODÈLE A ÉCRIT AU LIEU DE L'ÉMETTRE.
 *
 * L'INCIDENT (2026-08-11, conversation 35, message 1630). L'utilisateur demande
 * « J'ai une dépense à enregistrer, que faire ? ». Ket répond, en tout et pour
 * tout : `consulter_guide(parcours-de-saisie)`. Le journal est sans ambiguïté —
 * un seul tour, `outils: []`, 13 tokens de sortie : le modèle n'a émis AUCUN
 * appel de fonction, il a écrit l'appel comme du texte. Le serveur a donc servi
 * cette ligne telle quelle à l'utilisateur, qui a répondu « Je ne comprends
 * rien !! ». La fiche demandée existait pourtant, et le tour suivant l'a bien
 * restituée : rien ne manquait, ni l'outil, ni le droit, ni l'information.
 *
 * POURQUOI CELA ARRIVE, ET POURQUOI ON NE PEUT PAS L'INTERDIRE PAR CONSIGNE. Les
 * modèles entraînés à écrire du code émettent parfois l'appel en texte plutôt
 * que dans le canal prévu ; c'est un défaut de FORME, indépendant de la qualité
 * du raisonnement — le bon outil et le bon argument étaient là. Une consigne de
 * prompt supplémentaire ne ferait qu'ajouter une règle à respecter là où le
 * serveur peut simplement comprendre ce qui lui est dit.
 *
 * CE QUE ÇA NE FAIT PAS : un appel de plus au fournisseur. On lit la réponse
 * DÉJÀ payée du tour de planification. La règle des deux appels par message
 * reste intacte.
 *
 * PRÉCISION AVANT TOUT. Le texte doit se RÉDUIRE à l'appel (une fois retirés les
 * habillages de code habituels) et nommer un outil RÉELLEMENT déclaré ce
 * tour-ci. Une phrase qui mentionne un outil pour l'expliquer (« il me faudrait
 * preparer_operations, mais la référence manque ») est un comportement légitime :
 * elle ne doit jamais déclencher d'exécution.
 */
final class AppelDOutilEnTexte
{
    /**
     * Habillages que les modèles ajoutent autour de l'appel : bloc de code
     * (```tool_code, ```python, ```), appel enveloppé dans print(), points-virgules.
     */
    private const ENVELOPPES = [
        '/^```[a-z_]*\s*(.*?)\s*```$/isu',
        '/^print\s*\(\s*(.*?)\s*\)$/isu',
        '/^(.*?);$/su',
    ];

    /**
     * Les appels reconnus dans le texte, prêts à être exécutés.
     *
     * @param iterable<AiToolInterface> $outilsDeclares les outils du tour — et EUX
     *                                  SEULS : rattraper l'appel d'un outil non
     *                                  déclaré, ce serait contourner la trousse
     *
     * @return list<array{name: string, args: array<string, mixed>}>
     */
    public function extraire(string $texte, iterable $outilsDeclares): array
    {
        $noyau = $this->deshabiller($texte);
        if ($noyau === '') {
            return [];
        }

        if (preg_match('/^([a-z][a-z0-9_]{2,})\s*\((.*)\)$/isu', $noyau, $m) !== 1) {
            return [];
        }

        $nom = mb_strtolower($m[1]);
        $outil = null;
        foreach ($outilsDeclares as $candidat) {
            if ($candidat->name() === $nom) {
                $outil = $candidat;
                break;
            }
        }
        if ($outil === null) {
            return [];
        }

        $args = $this->arguments(trim($m[2]), $outil);

        return $args === null ? [] : [['name' => $outil->name(), 'args' => $args]];
    }

    /**
     * Le texte se réduit-il à quelque chose qui RESSEMBLE à un appel d'outil, sans
     * qu'on se soucie de savoir si l'outil existe ?
     *
     * Sert de FILET DE SÛRETÉ à l'affichage : `chercher_client(nom="X")` — un outil
     * qui n'existe pas — n'est pas rattrapable, mais ce n'est pas une réponse à
     * montrer à un courtier. Dans ce cas on restitue plutôt ce que les outils du
     * tour ont réellement rapporté.
     */
    public function ressembleAUnAppel(string $texte): bool
    {
        $noyau = $this->deshabiller($texte);

        return $noyau !== '' && preg_match('/^[a-z][a-z0-9_]{2,}\s*\(.*\)$/isu', $noyau) === 1;
    }

    /** Retire, en boucle, les habillages de code autour de l'appel. */
    private function deshabiller(string $texte): string
    {
        $noyau = trim($texte);
        // Plusieurs couches sont possibles (```tool_code + print(...)) : on répète
        // jusqu'à ce que plus rien ne tombe, avec un plafond pour rester borné.
        for ($passe = 0; $passe < 4; $passe++) {
            $avant = $noyau;
            foreach (self::ENVELOPPES as $motif) {
                $noyau = trim((string) preg_replace($motif, '$1', $noyau));
            }
            if ($noyau === $avant) {
                break;
            }
        }

        return $noyau;
    }

    /**
     * Les arguments de l'appel, nommés d'après le SCHÉMA de l'outil.
     *
     * Trois formes vues en production, dans cet ordre de fiabilité :
     *  - nommée : `consulter_guide(sujet="parcours-de-saisie")` ;
     *  - positionnelle unique : `consulter_guide(parcours-de-saisie)` — rattachée au
     *    premier paramètre REQUIS scalaire du schéma, jamais à un paramètre deviné ;
     *  - vide : `solde_tokens()`.
     *
     * Renvoie null quand la forme n'est pas comprise : mieux vaut ne rien exécuter
     * que d'exécuter un outil avec des arguments arrangés.
     *
     * @return array<string, mixed>|null
     */
    private function arguments(string $brut, AiToolInterface $outil): ?array
    {
        if ($brut === '') {
            return [];
        }

        $schema = $outil->schema();
        $proprietes = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];

        // Forme nommée : cle=valeur, séparées par des virgules. On n'accepte que des
        // clés que l'outil déclare (fail-closed : pas de paramètre fantôme).
        if (preg_match('/^[a-z_][a-z0-9_]*\s*[=:]/iu', $brut) === 1) {
            $args = [];
            foreach ($this->decouper($brut) as $morceau) {
                if (preg_match('/^([a-z_][a-z0-9_]*)\s*[=:]\s*(.*)$/isu', trim($morceau), $m) !== 1) {
                    return null;
                }
                $cle = $m[1];
                if (!isset($proprietes[$cle])) {
                    return null;
                }
                $args[$cle] = $this->typer($this->deciter(trim($m[2])), (string) ($proprietes[$cle]['type'] ?? 'string'));
            }

            return $args === [] ? null : $args;
        }

        // Forme positionnelle : une seule valeur, et un seul paramètre pour l'accueillir.
        if (count($this->decouper($brut)) > 1) {
            return null;
        }
        $cible = $this->parametreUnique($schema, $proprietes);
        if ($cible === null) {
            return null;
        }

        return [$cible => $this->typer($this->deciter($brut), (string) ($proprietes[$cible]['type'] ?? 'string'))];
    }

    /**
     * Le paramètre auquel rattacher une valeur positionnelle : l'unique REQUIS
     * scalaire du schéma. Deux requis, ou aucun, et l'on renonce — deviner l'ordre
     * des paramètres d'après un texte serait exactement la sorte d'arrangement que
     * ce service refuse de faire.
     *
     * @param array<string, mixed> $schema
     * @param array<string, array> $proprietes
     */
    private function parametreUnique(array $schema, array $proprietes): ?string
    {
        $requis = is_array($schema['required'] ?? null) ? $schema['required'] : [];
        $candidats = [];
        foreach ($requis as $cle) {
            if (!is_string($cle) || !isset($proprietes[$cle])) {
                continue;
            }
            if (in_array($proprietes[$cle]['type'] ?? null, ['string', 'integer', 'number', 'boolean'], true)) {
                $candidats[] = $cle;
            }
        }

        return count($candidats) === 1 ? $candidats[0] : null;
    }

    /**
     * Découpe sur les virgules de premier niveau (une valeur peut contenir une
     * virgule entre guillemets).
     *
     * @return list<string>
     */
    private function decouper(string $brut): array
    {
        $morceaux = [];
        $courant = '';
        $quote = null;
        $longueur = mb_strlen($brut);
        for ($i = 0; $i < $longueur; $i++) {
            $c = mb_substr($brut, $i, 1);
            if ($quote !== null) {
                if ($c === $quote) {
                    $quote = null;
                }
                $courant .= $c;
                continue;
            }
            if ($c === '"' || $c === "'") {
                $quote = $c;
                $courant .= $c;
                continue;
            }
            if ($c === ',') {
                $morceaux[] = $courant;
                $courant = '';
                continue;
            }
            $courant .= $c;
        }
        if (trim($courant) !== '') {
            $morceaux[] = $courant;
        }

        return array_values(array_filter(array_map('trim', $morceaux), static fn (string $m) => $m !== ''));
    }

    private function deciter(string $valeur): string
    {
        if (mb_strlen($valeur) >= 2) {
            $premier = mb_substr($valeur, 0, 1);
            if (($premier === '"' || $premier === "'") && mb_substr($valeur, -1) === $premier) {
                return mb_substr($valeur, 1, -1);
            }
        }

        return $valeur;
    }

    private function typer(string $valeur, string $type): mixed
    {
        return match ($type) {
            'integer' => (int) $valeur,
            'number'  => (float) str_replace([' ', ','], ['', '.'], $valeur),
            'boolean' => filter_var($valeur, FILTER_VALIDATE_BOOL),
            default   => $valeur,
        };
    }
}
