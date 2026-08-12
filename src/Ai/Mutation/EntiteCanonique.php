<?php

namespace App\Ai\Mutation;

use App\Ai\AiText;
use App\Ai\Tool\EntiteLexique;

/**
 * SOURCE UNIQUE de « le terme d'entité dicté par le modèle » => « nom court de
 * l'allowlist ».
 *
 * POURQUOI CETTE CLASSE EXISTE (incident du 2026-08-12). Ket a préparé un dossier
 * complet en désignant les entités par les LIBELLÉS DE L'ÉCRAN, au pluriel :
 * « Risques », « Clients », « Pistes & Propositions », « Paiements de prime ».
 * C'est le vocabulaire que nous lui montrons partout — le menu, les fiches, ses
 * propres tableaux —, et c'est aussi celui de l'utilisateur. L'allowlist, elle,
 * ne connaît que « Risque », « Client », « Cotation », « PaiementPrime » : chaque
 * étape a donc été refusée, et le courtier n'a rien enregistré.
 *
 * ON NE RECOPIE AUCUNE TABLE. Les libellés vivent déjà dans
 * WorkspaceAccessResolver::libellesEntites() (carte de permissions + sous-entités
 * gouvernées), et EntiteLexique en dérive déjà « nom court => mots-clés
 * normalisés », variante singulier/pluriel comprise. On se contente de renverser
 * ce lexique. Une entité ajoutée demain à la carte est donc couverte sans que
 * personne ait à y penser.
 *
 * CE QUI RESTE FAIL-CLOSED, ET C'EST L'ESSENTIEL. La tolérance porte sur
 * l'ORTHOGRAPHE du nom, JAMAIS sur le PÉRIMÈTRE : le lexique renversé est filtré
 * par MutationAllowlist, si bien qu'un libellé ne peut pas ouvrir une porte que
 * l'allowlist ferme (« Invités » reste irrésoluble). Et un mot-clé revendiqué par
 * DEUX entités est RETIRÉ plutôt qu'attribué au premier venu : écrire dans la
 * mauvaise entité serait bien pire qu'un refus.
 */
final class EntiteCanonique
{
    /** @var array<string, string>|null mot-clé normalisé => nom court, sans ambiguïté */
    private ?array $inverse = null;

    public function __construct(
        private readonly EntiteLexique $lexique,
    ) {
    }

    /**
     * Nom court d'allowlist correspondant au terme dicté, ou null (fail-closed).
     */
    public function resoudre(?string $terme): ?string
    {
        $terme = trim((string) $terme);
        if ($terme === '') {
            return null;
        }

        // (1) Chemin nominal : le modèle a écrit le nom court exact. Coût nul.
        if (MutationAllowlist::autorise($terme)) {
            return $terme;
        }

        $normalise = AiText::normalize($terme);

        // (2) Le nom court, à la casse ou aux accents près (« cotation », « COTATION »).
        foreach (MutationAllowlist::membres() as $membre) {
            if (AiText::normalize($membre) === $normalise) {
                return $membre;
            }
        }

        // (3) Le libellé de l'écran, singulier ou pluriel (« Risques », « Propositions »).
        return $this->inverse()[$normalise] ?? null;
    }

    /**
     * Ce que le modèle a le droit d'écrire, nommé des DEUX façons : le nom court
     * (le contrat) et le libellé de l'écran (ce qu'il a sous les yeux). Sert au
     * message de refus — sans 3e appel au moteur, cela ne sauve pas le tour
     * courant, mais cela rend le tour suivant auto-correctif.
     *
     * @return list<string>
     */
    public function nomsAcceptes(): array
    {
        $libelles = $this->lexique->libelles();

        $noms = [];
        foreach (MutationAllowlist::membres() as $membre) {
            $libelle = $libelles[$membre] ?? null;
            $noms[] = ($libelle === null || AiText::normalize($libelle) === AiText::normalize($membre))
                ? $membre
                : sprintf('%s (« %s »)', $membre, $libelle);
        }

        return $noms;
    }

    /**
     * Le lexique RENVERSÉ, dédoublonné des ambiguïtés.
     *
     * @return array<string, string>
     */
    private function inverse(): array
    {
        if ($this->inverse !== null) {
            return $this->inverse;
        }

        $revendications = [];
        foreach ($this->lexique->lexique() as $court => $motsCles) {
            // FAIL-CLOSED sur le périmètre : le lexique couvre tout ce qui est
            // LISIBLE ; l'écriture, elle, s'arrête à l'allowlist.
            if (!MutationAllowlist::autorise($court)) {
                continue;
            }
            foreach ($motsCles as $motCle) {
                $revendications[$motCle][$court] = true;
            }
        }

        $inverse = [];
        foreach ($revendications as $motCle => $courts) {
            // Un mot-clé revendiqué par deux entités ne tranche rien : on le retire.
            if (count($courts) === 1) {
                $inverse[(string) $motCle] = (string) array_key_first($courts);
            }
        }

        // Un nom court exact ne doit JAMAIS être détourné par le libellé d'une
        // autre entité (ex. le libellé « Types Chargements » et l'entité
        // « Chargement ») : on réaffirme les noms courts en dernier.
        foreach (MutationAllowlist::membres() as $membre) {
            $inverse[AiText::normalize($membre)] = $membre;
        }

        return $this->inverse = $inverse;
    }
}
