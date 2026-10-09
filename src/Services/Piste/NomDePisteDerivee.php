<?php

namespace App\Services\Piste;

use App\Form\PisteType;

/**
 * NOM D'UNE OPPORTUNITÉ DÉRIVÉE : « <type de mouvement> — <nom de l'affaire> ».
 *
 * RAISON D'ÊTRE. Chaque mouvement préfixait le nom de l'opportunité de base, qui
 * portait souvent déjà un préfixe : la deuxième année donnait « Renouvellement —
 * Renouvellement — EASTCASTLE… », la troisième un triple préfixe. Le préfixe dit le
 * type du DERNIER mouvement, rien de plus : on retire donc TOUS les préfixes connus
 * avant d'en poser un seul.
 *
 * MÊME RÈGLE QUE LE NAVIGATEUR. Le contrôleur Stimulus « piste-name-sync » reconnaît un
 * préfixe aux mêmes conditions : un libellé de PisteType::TYPE_AVENANT_LABELS suivi du
 * séparateur « — » (tiret cadratin U+2014 entouré d'espaces). Il reçoit ces libellés de
 * la même constante, par l'attribut du formulaire ; un test garantit l'identité.
 * Un nom personnalisé (sans préfixe connu) est laissé intact.
 */
final class NomDePisteDerivee
{
    /** Séparateur préfixe / nom, identique à celui de piste-name-sync. */
    public const SEPARATEUR = ' — ';

    /** Longueur de la colonne Piste::nom. */
    private const LONGUEUR_MAX = 255;

    /** @return string[] libellés reconnus comme préfixes de type */
    public static function libellesConnus(): array
    {
        return array_values(PisteType::TYPE_AVENANT_LABELS);
    }

    /** « Prorogation — Renouvellement — X » → « X ». Un nom sans préfixe connu revient tel quel. */
    public static function sansPrefixe(string $nom): string
    {
        $nom = trim($nom);
        $libelles = self::libellesConnus();

        do {
            $retire = false;
            foreach ($libelles as $libelle) {
                $prefixe = $libelle . self::SEPARATEUR;
                if (str_starts_with($nom, $prefixe)) {
                    $nom = trim(mb_substr($nom, mb_strlen($prefixe)));
                    $retire = true;
                }
            }
        } while ($retire);

        return $nom;
    }

    /** Nom de l'opportunité dérivée : UN préfixe, celui du mouvement, devant le nom nu. */
    public static function nommer(string $libelleMouvement, ?string $nomDeBase): string
    {
        $nu = self::sansPrefixe((string) $nomDeBase);

        return mb_substr($nu === '' ? $libelleMouvement : $libelleMouvement . self::SEPARATEUR . $nu, 0, self::LONGUEUR_MAX);
    }
}
