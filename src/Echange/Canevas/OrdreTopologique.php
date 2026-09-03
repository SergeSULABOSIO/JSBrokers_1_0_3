<?php

namespace App\Echange\Canevas;

/**
 * ORDRE D'ÉCRITURE des ressources : une entité référencée est écrite avant celle qui
 * la référence, sans quoi l'import passerait son temps à désigner des lignes qui
 * n'existent pas encore.
 *
 * ⚠ LE GRAPHE RÉEL EST CYCLIQUE, et refuser les cycles serait ici refuser le modèle.
 * Mesuré sur le périmètre : Piste → Avenant (avenantDeBase) → Cotation → Piste. Une
 * opportunité désigne la police qu'elle fait évoluer ; cette police pend d'une
 * proposition ; cette proposition appartient à l'opportunité. Le cycle est le sens
 * même du modèle, pas une erreur de conception.
 *
 * On le tranche donc là où il est INOFFENSIF : sur une arête SOUPLE, c'est-à-dire une
 * clé étrangère nullable. La ligne est écrite sans ce renvoi, puis une seconde passe
 * le pose une fois la cible créée — ce qui ne demande aucune mécanique nouvelle, juste
 * une opération d'édition supplémentaire en fin de plan.
 *
 * Reste l'erreur véritable : un cycle dont TOUTES les arêtes sont DURES (colonnes non
 * nullables). Aucun ordre ne le satisferait, et aucune base ne l'accepterait — celui-là
 * lève, bruyamment, au démarrage.
 */
final class OrdreTopologique
{
    /**
     * @param array<string, RessourceDEchange>       $ressources indexées par code
     * @param array<string, array<string, string[]>> $arcs       source => cible => codes de colonnes, arêtes SOUPLES
     * @param array<string, array<string, string[]>> $arcsDurs   idem, arêtes DURES (colonne non nullable)
     *
     * @return array<string, RessourceDEchange> réordonnées, chacune portant son rang et ses renvois différés
     *
     * @throws CycleDeDependancesException si un cycle ne comporte que des arêtes dures
     */
    public function trier(array $ressources, array $arcs, array $arcsDurs): array
    {
        // Les arêtes dures sont indéfectibles ; les souples ne seront coupées qu'en
        // dernier recours, et une par une, pour n'en différer que le strict nécessaire.
        $differes = [];
        $arcsCourants = $this->fusionner($arcsDurs, $arcs);

        while (true) {
            $ordre = $this->kahn(array_keys($ressources), $arcsCourants);
            if ($ordre !== null) {
                break;
            }

            // Il reste un cycle : on coupe UNE arête souple qui y participe.
            $coupe = $this->arcSoupleDansUnCycle($ressources, $arcsCourants, $arcs);
            if ($coupe === null) {
                throw new CycleDeDependancesException(sprintf(
                    'Cycle de dépendances entre ressources d\'échange, sans aucune clé étrangère nullable '
                    . 'pour le trancher : %s. Aucun ordre d\'écriture ne peut satisfaire ce graphe.',
                    implode(', ', $this->cycleRestant($ressources, $arcsCourants)),
                ));
            }

            [$source, $cible, $colonnes] = $coupe;
            unset($arcsCourants[$source][$cible]);
            foreach ($colonnes as $colonne) {
                $differes[$source][] = $colonne;
            }
        }

        $triees = [];
        foreach ($ordre as $rang => $code) {
            $triees[$code] = $ressources[$code]->avecRang($rang, $differes[$code] ?? []);
        }

        return $triees;
    }

    /**
     * Tri de Kahn. Renvoie null — plutôt que de lever — dès qu'un cycle subsiste :
     * l'appelant en tire une décision (couper une arête), pas une erreur.
     *
     * @param string[]                               $codes
     * @param array<string, array<string, string[]>> $arcs
     *
     * @return string[]|null
     */
    private function kahn(array $codes, array $arcs): ?array
    {
        $entrant = $this->degresEntrants($codes, $arcs);

        // File ordonnée alphabétiquement : deux exécutions doivent produire le MÊME
        // classeur, sinon l'empreinte des en-têtes changerait sans raison.
        $pretes = array_keys(array_filter($entrant, static fn (int $n) => $n === 0));
        sort($pretes);

        $ordre = [];
        while ($pretes !== []) {
            $code = array_shift($pretes);
            $ordre[] = $code;

            $libere = [];
            foreach ($arcs as $source => $cibles) {
                if (!isset($cibles[$code], $entrant[$source])) {
                    continue;
                }
                if (--$entrant[$source] === 0) {
                    $libere[] = $source;
                }
            }
            foreach ($libere as $s) {
                $pretes[] = $s;
            }
            sort($pretes);
        }

        return count($ordre) === count($codes) ? $ordre : null;
    }

    /**
     * Une arête souple participant encore à un cycle, choisie de façon DÉTERMINISTE
     * (ordre alphabétique source puis cible) : l'ordre des feuilles ne doit pas
     * dépendre de l'ordre de parcours d'un tableau.
     *
     * @param array<string, RessourceDEchange>       $ressources
     * @param array<string, array<string, string[]>> $arcsCourants
     * @param array<string, array<string, string[]>> $arcsSouples
     *
     * @return array{0: string, 1: string, 2: string[]}|null
     */
    private function arcSoupleDansUnCycle(array $ressources, array $arcsCourants, array $arcsSouples): ?array
    {
        $bloqueesIndex = array_flip($this->cycleRestant($ressources, $arcsCourants));

        $sources = array_keys($arcsCourants);
        sort($sources);
        foreach ($sources as $source) {
            if (!isset($bloqueesIndex[$source])) {
                continue;
            }
            $cibles = array_keys($arcsCourants[$source]);
            sort($cibles);
            foreach ($cibles as $cible) {
                // Souple ET participant au blocage : c'est un candidat à la coupe.
                if (isset($bloqueesIndex[$cible], $arcsSouples[$source][$cible])) {
                    return [$source, $cible, $arcsSouples[$source][$cible]];
                }
            }
        }

        return null;
    }

    /**
     * Ressources encore bloquées après un Kahn incomplet : celles qui composent le ou
     * les cycles restants. Sert à cibler la coupe et à nommer l'erreur.
     *
     * @param array<string, RessourceDEchange>       $ressources
     * @param array<string, array<string, string[]>> $arcs
     *
     * @return string[]
     */
    private function cycleRestant(array $ressources, array $arcs): array
    {
        $codes = array_keys($ressources);
        $entrant = $this->degresEntrants($codes, $arcs);

        $pretes = array_keys(array_filter($entrant, static fn (int $n) => $n === 0));
        $vus = [];
        while ($pretes !== []) {
            $code = array_shift($pretes);
            $vus[$code] = true;
            foreach ($arcs as $source => $cibles) {
                if (isset($cibles[$code], $entrant[$source]) && --$entrant[$source] === 0) {
                    $pretes[] = $source;
                }
            }
        }

        $bloquees = array_values(array_diff($codes, array_keys($vus)));
        sort($bloquees);

        return $bloquees;
    }

    /**
     * Degré entrant = nombre de ressources dont CELLE-CI dépend (arcs source → cible,
     * la source dépendant de la cible). Une cible hors périmètre est ignorée.
     *
     * @param string[]                               $codes
     * @param array<string, array<string, string[]>> $arcs
     *
     * @return array<string, int>
     */
    private function degresEntrants(array $codes, array $arcs): array
    {
        $entrant = array_fill_keys($codes, 0);
        foreach ($arcs as $source => $cibles) {
            if (!isset($entrant[$source])) {
                continue;
            }
            foreach (array_keys($cibles) as $cible) {
                if (isset($entrant[$cible])) {
                    ++$entrant[$source];
                }
            }
        }

        return $entrant;
    }

    /**
     * @param array<string, array<string, string[]>> $durs
     * @param array<string, array<string, string[]>> $souples
     *
     * @return array<string, array<string, string[]>>
     */
    private function fusionner(array $durs, array $souples): array
    {
        $fusion = $durs;
        foreach ($souples as $source => $cibles) {
            foreach ($cibles as $cible => $colonnes) {
                $fusion[$source][$cible] = array_merge($fusion[$source][$cible] ?? [], $colonnes);
            }
        }

        return $fusion;
    }
}
