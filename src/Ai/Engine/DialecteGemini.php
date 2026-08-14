<?php

namespace App\Ai\Engine;

use App\Ai\Scope\AiScope;
use App\Ai\Trousse\Trousse;
use App\Ai\Trousse\TrousseCatalogue;

/**
 * LES PARTICULARITÉS DU PROTO GEMINI, réunies en un seul endroit.
 *
 * Deux appelants les partagent désormais — le moteur et la phase de compréhension —
 * et ce ne sont pas des détails de confort : chacune de ces règles a été payée par
 * une panne en production.
 *
 *  - `assainir()` : le proto Schema de Gemini ne connaît qu'un SOUS-ENSEMBLE de
 *    JSON-Schema. Un mot-clé inconnu (vécu : `additionalProperties`, posé par le
 *    pré-remplissage d'ouvrir_dialogue) fait rejeter TOUTE la requête en 400
 *    INVALID_ARGUMENT — donc chaque message du chat, pas seulement celui qui aurait
 *    appelé cet outil. Les schémas restent du JSON-Schema standard pour les autres
 *    moteurs (Claude les accepte) : c'est le dialecte Gemini qui s'adapte.
 *
 *  - `preserverArgsObjets()` : PHP décode « args: {} » (objet JSON vide) en TABLEAU
 *    vide ; ré-encodé tel quel dans l'écho du tour model, il redevient `[]` — une
 *    liste, que le proto refuse (400 « Proto field is not repeating, cannot start
 *    list »). Signature typique : le premier appel passe, la boucle casse au renvoi
 *    du functionResponse.
 *
 * Une seconde copie de l'une de ces règles serait la panne suivante : elle ne se
 * verrait qu'au moment où un outil sans paramètre, ou un schéma un peu riche,
 * traverserait le chemin qu'on aurait oublié de corriger.
 */
final class DialecteGemini
{
    public function __construct(
        // Source unique des outils déclarés : le prompt et les déclarations DOIVENT
        // être dérivés du même tableau, sinon une consigne peut nommer un outil absent.
        private readonly TrousseCatalogue $catalogue,
    ) {
    }

    /**
     * Déclarations de fonctions au format Gemini (name/description/parameters),
     * restreintes à ce qui a un sens dans cette trousse et ce périmètre.
     *
     * Elles repartent à CHAQUE tour, l'API étant sans mémoire. Décrire un outil que
     * l'invité n'a pas le droit d'exécuter, c'est payer plusieurs fois par message
     * pour un refus certain. Ce filtrage n'est PAS une sécurité (elle reste dans
     * execute(), fail-closed) mais une économie de débit — cf. AiToolConditionnel.
     *
     * @return list<array{name: string, description: string, parameters: array}>
     */
    public function declarations(Trousse $trousse, AiScope $scope): array
    {
        $declarations = [];
        foreach ($this->catalogue->outilsDe($trousse, $scope) as $outil) {
            $declarations[] = [
                'name'        => $outil->name(),
                'description' => $outil->description(),
                'parameters'  => self::assainir($outil->schema()),
            ];
        }

        return $declarations;
    }

    /** @param array<string, mixed> $schema */
    public static function assainir(array $schema): array
    {
        unset($schema['additionalProperties']);
        foreach ($schema as $cle => $valeur) {
            if (\is_array($valeur)) {
                $schema[$cle] = self::assainir($valeur);
            }
        }

        return $schema;
    }

    /**
     * @param array<int, array> $parts
     *
     * @return array<int, array>
     */
    public static function preserverArgsObjets(array $parts): array
    {
        foreach ($parts as $i => $part) {
            if (isset($part['functionCall']) && ($part['functionCall']['args'] ?? null) === []) {
                $parts[$i]['functionCall']['args'] = new \stdClass();
            }
        }

        return $parts;
    }
}
