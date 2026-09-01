<?php

namespace App\Service\Conge;

use App\Ai\AiText;
use App\Ai\Mutation\NormaliseurDeDates;

/**
 * « LA SEMAINE PROCHAINE », « DU 3 AU 7 » — traduites en deux dates, par le SERVEUR.
 *
 * ── POURQUOI CE N'EST PAS AU MODÈLE DE LE FAIRE ─────────────────────────────────────
 * Un congé se pose sur des dates, pas sur une intention. Laisser le modèle résoudre
 * « la semaine prochaine » revient à lui demander de calculer, et il calcule parfois
 * juste. Le service, lui, calcule toujours pareil — et rend l'INTERPRÉTATION qu'il a
 * retenue, que l'assistant affiche avant d'écrire quoi que ce soit.
 *
 * ── LISTE FERMÉE, COMME LES DATES PONCTUELLES ───────────────────────────────────────
 * Même parti pris que NormaliseurDeDates : un jeu fini de formes reconnues, et
 * `null` pour tout le reste. Un refus explicite vaut infiniment mieux qu'une période
 * inventée — l'assistant demande alors les dates, ce qui est toujours acceptable.
 *
 * ── LES DATES PONCTUELLES NE SONT PAS RÉ-IMPLÉMENTÉES ───────────────────────────────
 * « du 12/08/2026 au 20/08/2026 » est délégué à NormaliseurDeDates, qui connaît déjà les
 * treize formats du projet, le jour-mois-en-premier et les mois en clair. Écrire une
 * seconde grammaire de dates ici, c'était s'engager à les corriger deux fois.
 *
 * Service pur : aucune base, aucun état, l'horloge passée en paramètre. Testable seul.
 */
class NormaliseurDePeriodes
{
    /** Jours de la semaine en clair → numéro ISO-8601. */
    private const JOURS = [
        'lundi' => 1, 'mardi' => 2, 'mercredi' => 3, 'jeudi' => 4,
        'vendredi' => 5, 'samedi' => 6, 'dimanche' => 7,
    ];

    /** Libellés ISO → français, pour l'interprétation rendue. */
    private const JOURS_LABELS = [
        1 => 'lundi', 2 => 'mardi', 3 => 'mercredi', 4 => 'jeudi',
        5 => 'vendredi', 6 => 'samedi', 7 => 'dimanche',
    ];

    public function __construct(
        private readonly NormaliseurDeDates $dates,
    ) {
    }

    /**
     * Traduit une expression de période en deux dates, ou rend null si elle n'est pas
     * comprise.
     *
     * @param string                  $expression  ce que l'utilisateur a dit
     * @param ?\DateTimeImmutable     $aujourdhui  l'horloge, injectée pour les tests
     */
    public function resoudre(string $expression, ?\DateTimeImmutable $aujourdhui = null): ?PeriodeResolue
    {
        $aujourdhui = ($aujourdhui ?? new \DateTimeImmutable('now'))->setTime(0, 0);

        // Les APOSTROPHES deviennent des espaces, droites comme typographiques :
        // « aujourd'hui » et « aujourd’hui » sont le même mot, et AiText::normalize ne
        // touche qu'aux accents et à la casse. Sans cela, la même expression était
        // comprise ou refusée selon le clavier de celui qui l'a tapée.
        $texte = str_replace(["'", '’', '`'], ' ', AiText::normalize($expression));
        $texte = trim((string) preg_replace('/\s+/u', ' ', $texte));

        if ($texte === '') {
            return null;
        }

        foreach ([
            'duXauY', 'jourNomme', 'joursRelatifs', 'semaine', 'mois', 'prochainsJours',
        ] as $forme) {
            $resultat = $this->{$forme}($texte, $aujourdhui);
            if ($resultat instanceof PeriodeResolue) {
                return $resultat;
            }
        }

        return null;
    }

    /**
     * « du 3 au 7 », « du 3 au 7 septembre », « du 12/08/2026 au 20/08/2026 ».
     *
     * Les deux bornes sont normalisées par NormaliseurDeDates. Quand seuls les QUANTIÈMES
     * sont donnés, on complète par le mois courant — ou le suivant si la période est déjà
     * passée : personne ne pose un congé pour la semaine dernière.
     */
    private function duXauY(string $texte, \DateTimeImmutable $aujourdhui): ?PeriodeResolue
    {
        if (!preg_match('/\bdu\s+(.+?)\s+au\s+(.+)$/u', $texte, $m)) {
            return null;
        }

        $gauche = trim($m[1]);
        $droite = trim($m[2]);

        // Cas « du 3 au 7 septembre » : le mois n'est porté que par la borne de droite.
        if (preg_match('/^\d{1,2}$/', $gauche) && !preg_match('/^\d{1,2}$/', $droite)) {
            $suffixe = preg_replace('/^\d{1,2}\s*/', '', $droite);
            $gauche .= ' ' . $suffixe;
        }

        // Cas « du 3 au 7 » : deux quantièmes nus, dans le mois courant.
        if (preg_match('/^\d{1,2}$/', $gauche) && preg_match('/^\d{1,2}$/', $droite)) {
            return $this->quantiemesDuMois((int) $gauche, (int) $droite, $aujourdhui);
        }

        $debut = $this->versDate($gauche);
        $fin = $this->versDate($droite);

        if ($debut === null || $fin === null || $fin < $debut) {
            return null;
        }

        return new PeriodeResolue(
            $debut,
            $fin,
            sprintf('du %s au %s', $debut->format('d/m/Y'), $fin->format('d/m/Y')),
        );
    }

    /**
     * Deux quantièmes sans mois : le mois EN COURS, ou le suivant si la période est déjà
     * derrière nous. On ne propose jamais une date passée — une demande de congé
     * rétroactive est un cas de gestion, pas une saisie courante.
     */
    private function quantiemesDuMois(int $jourDebut, int $jourFin, \DateTimeImmutable $aujourdhui): ?PeriodeResolue
    {
        if ($jourDebut < 1 || $jourDebut > 31 || $jourFin < 1 || $jourFin > 31 || $jourFin < $jourDebut) {
            return null;
        }

        foreach ([0, 1] as $decalage) {
            $mois = $aujourdhui->modify(sprintf('first day of +%d month', $decalage));
            $dernier = (int) $mois->format('t');

            if ($jourFin > $dernier) {
                continue; // « du 30 au 31 » en février : ce mois-ci ne convient pas.
            }

            $debut = $mois->setDate((int) $mois->format('Y'), (int) $mois->format('n'), $jourDebut);
            $fin = $mois->setDate((int) $mois->format('Y'), (int) $mois->format('n'), $jourFin);

            if ($fin >= $aujourdhui) {
                return new PeriodeResolue(
                    $debut,
                    $fin,
                    sprintf('du %s au %s', $debut->format('d/m/Y'), $fin->format('d/m/Y')),
                );
            }
        }

        return null;
    }

    /** « aujourd'hui », « demain », « après-demain ». */
    private function joursRelatifs(string $texte, \DateTimeImmutable $aujourdhui): ?PeriodeResolue
    {
        $formes = [
            "apres demain" => 2,
            "apres-demain" => 2,
            'demain' => 1,
            "aujourd hui" => 0,
            "aujourdhui" => 0,
        ];

        foreach ($formes as $forme => $decalage) {
            if (str_contains($texte, $forme)) {
                $jour = $aujourdhui->modify(sprintf('+%d day', $decalage));

                return new PeriodeResolue($jour, $jour, sprintf('le %s', $jour->format('d/m/Y')));
            }
        }

        return null;
    }

    /** « lundi prochain », « vendredi ». Le jour nommé à venir, ou celui de la semaine suivante. */
    private function jourNomme(string $texte, \DateTimeImmutable $aujourdhui): ?PeriodeResolue
    {
        if (!preg_match('/\b(' . implode('|', array_keys(self::JOURS)) . ')\b/u', $texte, $m)) {
            return null;
        }

        // « la semaine prochaine » contient « semaine », pas un jour ; mais « lundi de la
        // semaine prochaine » contient les deux. On laisse alors la forme « semaine » gagner
        // seulement si aucun jour n'est nommé — ce qui est déjà le cas ici puisqu'on a matché.
        $cible = self::JOURS[$m[1]];
        $prochaineSemaine = str_contains($texte, 'prochain') || str_contains($texte, 'suivant');

        $jour = $aujourdhui;
        do {
            $jour = $jour->modify('+1 day');
        } while ((int) $jour->format('N') !== $cible);

        if ($prochaineSemaine && $jour->diff($aujourdhui)->days < 7 && str_contains($texte, 'semaine')) {
            $jour = $jour->modify('+7 days');
        }

        return new PeriodeResolue(
            $jour,
            $jour,
            sprintf('le %s %s', self::JOURS_LABELS[$cible], $jour->format('d/m/Y')),
        );
    }

    /**
     * « cette semaine », « la semaine prochaine ».
     *
     * Du LUNDI au VENDREDI : c'est ce que les gens veulent dire en posant une semaine de
     * congé. Le décompte réel retirera de toute façon ce qui n'est pas travaillé.
     */
    private function semaine(string $texte, \DateTimeImmutable $aujourdhui): ?PeriodeResolue
    {
        if (!str_contains($texte, 'semaine')) {
            return null;
        }

        $prochaine = str_contains($texte, 'prochain') || str_contains($texte, 'suivant');
        $lundi = $aujourdhui->modify($prochaine ? 'monday next week' : 'monday this week');
        $vendredi = $lundi->modify('+4 days');

        return new PeriodeResolue(
            $lundi,
            $vendredi,
            sprintf(
                '%s, du lundi %s au vendredi %s',
                $prochaine ? 'la semaine prochaine' : 'cette semaine',
                $lundi->format('d/m/Y'),
                $vendredi->format('d/m/Y'),
            ),
        );
    }

    /** « le mois prochain », « ce mois-ci » — du premier au dernier jour. */
    private function mois(string $texte, \DateTimeImmutable $aujourdhui): ?PeriodeResolue
    {
        if (!str_contains($texte, 'mois')) {
            return null;
        }

        $prochain = str_contains($texte, 'prochain') || str_contains($texte, 'suivant');
        $premier = $aujourdhui->modify($prochain ? 'first day of next month' : 'first day of this month');
        $dernier = $premier->modify('last day of this month');

        return new PeriodeResolue(
            $premier,
            $dernier,
            sprintf(
                '%s, du %s au %s',
                $prochain ? 'le mois prochain' : 'ce mois-ci',
                $premier->format('d/m/Y'),
                $dernier->format('d/m/Y'),
            ),
        );
    }

    /** « les 5 prochains jours », « pendant 3 jours ». */
    private function prochainsJours(string $texte, \DateTimeImmutable $aujourdhui): ?PeriodeResolue
    {
        if (!preg_match('/\b(\d{1,2})\s+(?:prochains?\s+)?jours?\b/u', $texte, $m)
            && !preg_match('/\bpendant\s+(\d{1,2})\s+jours?\b/u', $texte, $m)) {
            return null;
        }

        $nombre = (int) $m[1];
        if ($nombre < 1 || $nombre > 90) {
            return null;
        }

        // « les 5 prochains jours » commence DEMAIN : aujourd'hui n'est plus à venir.
        $debut = $aujourdhui->modify('+1 day');
        $fin = $debut->modify(sprintf('+%d days', $nombre - 1));

        return new PeriodeResolue(
            $debut,
            $fin,
            sprintf('%d jour(s) à partir du %s, soit jusqu\'au %s', $nombre, $debut->format('d/m/Y'), $fin->format('d/m/Y')),
        );
    }

    /** Délègue à la grammaire de dates du projet, puis reconstruit un objet date. */
    private function versDate(string $brut): ?\DateTimeImmutable
    {
        $normalise = $this->dates->normaliser($brut, 'date_immutable');

        if (!is_string($normalise) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $normalise)) {
            return null;
        }

        try {
            return new \DateTimeImmutable($normalise);
        } catch (\Throwable) {
            return null;
        }
    }
}
