<?php

namespace App\Ai\Resolution;

/**
 * Le résultat d'une résolution « nom dicté → identifiant ».
 *
 * Quatre états, et pas un de plus : résolue, absente (rien n'a été dit), introuvable,
 * ambiguë. Les trois derniers ne sont PAS des erreurs techniques : ce sont des
 * QUESTIONS à poser à l'utilisateur, avec de quoi y répondre en un clic — d'où les
 * « candidats » qui voyagent avec.
 *
 * On ne devine jamais une donnée du courtier : c'est la règle de la maison, et une
 * référence non résolue s'arrête ici plutôt que de devenir un identifiant au jugé.
 */
final class Reference
{
    public const RESOLUE = 'resolue';
    public const ABSENTE = 'absente';
    public const INTROUVABLE = 'introuvable';
    public const AMBIGUE = 'ambigu';

    /** @param array<int, string> $candidats id => libellé */
    private function __construct(
        public readonly string $etat,
        public readonly string $entite,
        public readonly string $libelleEntite,
        public readonly ?int $id,
        public readonly ?string $libelle,
        public readonly string $terme,
        public readonly array $candidats,
    ) {
    }

    public static function resolue(string $entite, string $libelleEntite, int $id, string $libelle, string $terme): self
    {
        return new self(self::RESOLUE, $entite, $libelleEntite, $id, $libelle, $terme, []);
    }

    public static function absente(string $entite, string $libelleEntite): self
    {
        return new self(self::ABSENTE, $entite, $libelleEntite, null, null, '', []);
    }

    /** @param array<int, string> $apercu */
    public static function introuvable(string $entite, string $libelleEntite, string $terme, array $apercu): self
    {
        return new self(self::INTROUVABLE, $entite, $libelleEntite, null, null, $terme, $apercu);
    }

    /** @param array<int, string> $candidats */
    public static function ambigue(string $entite, string $libelleEntite, string $terme, array $candidats): self
    {
        return new self(self::AMBIGUE, $entite, $libelleEntite, null, null, $terme, $candidats);
    }

    public function estResolue(): bool
    {
        return $this->etat === self::RESOLUE;
    }

    /**
     * La question à poser, dans la forme attendue par les outils (« aDemander »).
     *
     * @return array<string, mixed>
     */
    public function question(): array
    {
        return array_filter([
            'champ'    => $this->entite,
            'libelle'  => $this->libelleEntite,
            'probleme' => $this->etat,
            'terme'    => $this->terme !== '' ? $this->terme : null,
            'valeurs'  => $this->candidats !== [] ? $this->candidats : null,
        ], static fn ($v) => $v !== null);
    }
}
