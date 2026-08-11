<?php

namespace App\Ai\Document;

/**
 * LE THÈME DU RENDU — clair ou sombre, comme le chat.
 *
 * Ket s'affiche déjà en clair ou en sombre (jetons `--aic-*`, cf. le gabarit du
 * chat) et l'export d'une bulle en image emporte déjà ce choix. Un document
 * fabriqué depuis une bulle sombre sortait pourtant toujours blanc : la barre de
 * décision porte désormais l'option, préréglée sur le thème du chat.
 *
 * PALETTE POSÉE ICI, ET NULLE PART AILLEURS. Le gabarit HTML, le PDF qui le
 * réutilise et les tests lisent la même table : c'est ce qui garantit qu'un
 * document sombre n'aura jamais du texte sombre oublié sur son fond sombre.
 *
 * QUELS FORMATS ? Ceux qui PEIGNENT leur fond — la page web et le PDF
 * ({@see DocumentFormat::supporteTheme()}). Un .docx n'a pas de fond de page en
 * OOXML tel que PHPWord l'écrit : y appliquer une encre claire produirait un
 * document blanc au texte illisible, et une feuille Excel ne teinte que ses
 * cellules remplies. Un .txt et un .md n'ont pas de couleurs du tout. Mieux vaut
 * ne pas offrir l'option que de la trahir : le sélecteur disparaît pour ces
 * formats.
 */
enum ThemeDocument: string
{
    case Clair  = 'clair';
    case Sombre = 'sombre';

    public function libelle(): string
    {
        return match ($this) {
            self::Clair  => 'Clair',
            self::Sombre => 'Sombre',
        };
    }

    public function estSombre(): bool
    {
        return $this === self::Sombre;
    }

    /**
     * Le thème par défaut : clair. Un document circule, s'imprime et s'archive —
     * le sombre est un choix, jamais une conséquence d'un oubli.
     */
    public static function defaut(): self
    {
        return self::Clair;
    }

    /**
     * Lecture TOLÉRANTE, même esprit que {@see DocumentFormat::depuis()}. Elle
     * accepte notamment `light` et `dark` : ce sont les valeurs que le chat
     * emploie déjà côté navigateur (assistant-theme.js), et les recopier ici
     * évite une table de traduction de plus entre les deux mondes.
     */
    public static function depuis(mixed $valeur): self
    {
        if ($valeur instanceof self) {
            return $valeur;
        }
        if (!is_string($valeur)) {
            return self::defaut();
        }

        $normalise = mb_strtolower(trim($valeur));

        return self::tryFrom($normalise)
            ?? match ($normalise) {
                'dark', 'sombre', 'nuit', 'night'  => self::Sombre,
                'light', 'clair', 'jour', 'day'    => self::Clair,
                default                            => self::defaut(),
            };
    }

    /** @return list<string> */
    public static function valeurs(): array
    {
        return array_map(static fn (self $t) => $t->value, self::cases());
    }

    /**
     * LA PALETTE, source unique des deux rendus qui la consomment.
     *
     * Les valeurs sombres sont celles du chat (`--aic-bg`, `--aic-text`,
     * `--aic-accent`…) : un document sombre doit ressembler à la bulle dont il
     * sort. Le cobalt de la charte cède la place au bleu clair `#6ea8fe` sur fond
     * sombre — le cobalt n'y ferait que 1,90:1, sous le seuil WCAG, exactement
     * comme dans le chat.
     *
     * @return array{fond: string, encre: string, gris: string, filet: string, accent: string, enteteFond: string, enteteTexte: string, blocFond: string}
     */
    public function palette(): array
    {
        return match ($this) {
            self::Clair => [
                'fond'        => '#ffffff',
                'encre'       => '#1f2937',
                'gris'        => '#6b7280',
                'filet'       => '#dee2e6',
                'accent'      => '#0047AB',
                'enteteFond'  => '#0047AB',
                'enteteTexte' => '#ffffff',
                'blocFond'    => '#f6f8fb',
            ],
            self::Sombre => [
                'fond'        => '#16181b',
                'encre'       => '#e9ecef',
                'gris'        => '#adb5bd',
                'filet'       => '#343a40',
                'accent'      => '#6ea8fe',
                'enteteFond'  => '#16305a',
                'enteteTexte' => '#e9ecef',
                'blocFond'    => '#1e2227',
            ],
        };
    }
}
