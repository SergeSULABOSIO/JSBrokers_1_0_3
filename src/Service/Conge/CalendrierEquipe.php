<?php

namespace App\Service\Conge;

use App\Entity\DemandeConge;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\JourFerie;
use App\Repository\DemandeCongeRepository;
use App\Repository\JourFerieRepository;

/**
 * QUI EST ABSENT, ET QUAND — la grille d'un mois.
 *
 * ── POURQUOI UNE GRILLE ET NON UNE LISTE ────────────────────────────────────────────
 * Une liste de demandes répond à « qui a posé quoi ». Elle ne répond pas à « peut-on se
 * permettre trois absents la semaine du 12 ? », qui est la question d'un responsable — et
 * qu'on ne lit qu'en voyant les absences les unes SOUS les autres, alignées sur les mêmes
 * jours.
 *
 * ── LE SERVEUR CALCULE, LE GABARIT AFFICHE ──────────────────────────────────────────
 * Toute la grille est construite ici : jours du mois, week-ends, fériés, et pour chaque
 * collaborateur la nature de son absence jour par jour. Le template n'a plus qu'à poser
 * des cases. Un calendrier dont le navigateur calculerait les cellules finirait par ne
 * plus dire la même chose que la liste.
 *
 * ── ON NE MONTRE QUE LES ABSENCES APPROUVÉES ────────────────────────────────────────
 * Une demande en attente n'est pas une absence : l'afficher ferait renoncer des
 * collaborateurs à poser leurs jours au vu d'une absence qui n'aura peut-être jamais
 * lieu. Le valideur, lui, voit la file d'attente à côté.
 */
class CalendrierEquipe
{
    /** Nombre de collaborateurs affichés au plus : au-delà, la grille cesse d'être lisible. */
    private const MAX_LIGNES = 40;

    private const MOIS = [
        1 => 'janvier', 2 => 'février', 3 => 'mars', 4 => 'avril', 5 => 'mai', 6 => 'juin',
        7 => 'juillet', 8 => 'août', 9 => 'septembre', 10 => 'octobre', 11 => 'novembre', 12 => 'décembre',
    ];

    public function __construct(
        private readonly DemandeCongeRepository $demandeRepository,
        private readonly JourFerieRepository $jourFerieRepository,
        private readonly RegimeDeLAgent $regimes,
        private readonly EquipeDuCollaborateur $equipes,
    ) {
    }

    /**
     * La grille d'un mois pour un cabinet.
     *
     * @param bool $limiterALEquipe Restreindre aux collègues du demandeur — ce que voit
     *                              un collaborateur ordinaire, à qui les absences de tout
     *                              le cabinet ne regardent pas.
     *
     * @return array{
     *     mois: int, annee: int, libelle: string,
     *     precedent: array{mois: int, annee: int}, suivant: array{mois: int, annee: int},
     *     jours: array<int, array{numero: int, iso: int, lettre: string, ferie: ?string, weekend: bool, aujourdhui: bool}>,
     *     lignes: array<int, array{agent: string, cases: array<int, array{type: ?string, couleur: ?string, libelle: ?string, travaille: bool}>, total: float}>,
     *     legende: array<string, string>
     * }
     */
    public function grille(
        Entreprise $entreprise,
        int $annee,
        int $mois,
        ?Invite $demandeur = null,
        bool $limiterALEquipe = false,
    ): array {
        $premier = (new \DateTimeImmutable(sprintf('%04d-%02d-01', $annee, $mois)))->setTime(0, 0);
        $dernier = $premier->modify('last day of this month');

        $jours = $this->jours($premier, $dernier, $entreprise);
        $absences = $this->demandeRepository->absencesApprouveesSurPeriode($entreprise, $premier, $dernier);

        $lignes = $this->lignes($absences, $jours, $premier, $demandeur, $limiterALEquipe);

        return [
            'mois' => $mois,
            'annee' => $annee,
            'libelle' => sprintf('%s %d', self::MOIS[$mois] ?? (string) $mois, $annee),
            'precedent' => $this->decaler($annee, $mois, -1),
            'suivant' => $this->decaler($annee, $mois, 1),
            'jours' => $jours,
            'lignes' => $lignes,
            'legende' => $this->legende($absences),
        ];
    }

    /**
     * Les colonnes du mois : numéro, jour de la semaine, férié, week-end.
     *
     * Le week-end est celui du CALENDRIER (samedi, dimanche), pas celui d'un régime : la
     * grille est commune à tout le monde, et un régime individuel s'y lit sur la ligne de
     * l'intéressé, pas sur l'en-tête.
     *
     * @return array<int, array{numero: int, iso: int, lettre: string, ferie: ?string, weekend: bool, aujourdhui: bool}>
     */
    private function jours(\DateTimeImmutable $premier, \DateTimeImmutable $dernier, Entreprise $entreprise): array
    {
        $feries = [];
        /** @var JourFerie $ferie */
        foreach ($this->jourFerieRepository->pourPeriode($entreprise, $premier, $dernier) as $ferie) {
            $date = $ferie->getDate();
            if ($date !== null) {
                $feries[$date->format('Y-m-d')] = (string) $ferie->getLibelle();
            }
        }

        $aujourdhui = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $lettres = [1 => 'L', 2 => 'M', 3 => 'M', 4 => 'J', 5 => 'V', 6 => 'S', 7 => 'D'];

        $jours = [];
        $courant = $premier;
        while ($courant <= $dernier) {
            $iso = (int) $courant->format('N');
            $cle = $courant->format('Y-m-d');

            $jours[(int) $courant->format('j')] = [
                'numero' => (int) $courant->format('j'),
                'iso' => $iso,
                'lettre' => $lettres[$iso],
                'ferie' => $feries[$cle] ?? null,
                'weekend' => $iso >= 6,
                'aujourdhui' => $cle === $aujourdhui,
            ];

            $courant = $courant->modify('+1 day');
        }

        return $jours;
    }

    /**
     * Une ligne par collaborateur absent, avec ses cases.
     *
     * ON NE LISTE QUE LES ABSENTS. Afficher tout le cabinet donnerait une grille
     * majoritairement vide où l'information se perd — et sur un cabinet de trente
     * personnes, il faudrait défiler pour trouver les trois qui comptent.
     *
     * @param DemandeConge[] $absences
     * @param array<int, array<string, mixed>> $jours
     * @return array<int, array{agent: string, cases: array<int, array<string, mixed>>, total: float}>
     */
    private function lignes(
        array $absences,
        array $jours,
        \DateTimeImmutable $premier,
        ?Invite $demandeur,
        bool $limiterALEquipe,
    ): array {
        $perimetre = null;
        if ($limiterALEquipe && $demandeur !== null) {
            $perimetre = [$demandeur->getId() => true];
            foreach ($this->equipes->collegues($demandeur) as $collegue) {
                $perimetre[$collegue->getId()] = true;
            }
        }

        $parAgent = [];
        foreach ($absences as $demande) {
            $agent = $demande->getAgent();
            $id = $agent?->getId();
            if ($agent === null || $id === null) {
                continue;
            }
            if ($perimetre !== null && !isset($perimetre[$id])) {
                continue;
            }

            $parAgent[$id] ??= ['agent' => $agent, 'demandes' => []];
            $parAgent[$id]['demandes'][] = $demande;
        }

        $lignes = [];
        foreach ($parAgent as $entree) {
            /** @var Invite $agent */
            $agent = $entree['agent'];
            $joursOuvres = $this->regimes->joursOuvresDe($agent, $premier);

            $cases = [];
            $total = 0.0;
            foreach ($jours as $numero => $jour) {
                $travaille = !$jour['weekend'] && $jour['ferie'] === null
                    && in_array($jour['iso'], $joursOuvres, true);

                $case = ['type' => null, 'couleur' => null, 'libelle' => null, 'travaille' => $travaille];

                foreach ($entree['demandes'] as $demande) {
                    if ($this->couvre($demande, $premier, $numero)) {
                        $type = $demande->getTypeAbsence();
                        $case['type'] = (string) ($type?->getLibelle() ?? 'Absence');
                        $case['couleur'] = $type?->getCouleur() ?: '#0047AB';
                        $case['libelle'] = sprintf(
                            '%s — %s',
                            (string) $agent->getNom(),
                            $case['type'],
                        );
                        if ($travaille) {
                            $total += 1.0;
                        }
                        break;
                    }
                }

                $cases[$numero] = $case;
            }

            $lignes[] = ['agent' => (string) $agent->getNom(), 'cases' => $cases, 'total' => $total];
        }

        // Par nom : sur une grille qu'on relit chaque mois, un ordre stable évite de
        // rechercher la même personne à un endroit différent à chaque ouverture.
        usort($lignes, static fn (array $a, array $b) => strcasecmp($a['agent'], $b['agent']));

        return array_slice($lignes, 0, self::MAX_LIGNES);
    }

    /** La demande couvre-t-elle ce quantième du mois affiché ? */
    private function couvre(DemandeConge $demande, \DateTimeImmutable $premier, int $numero): bool
    {
        $debut = $demande->getDateDebut();
        $fin = $demande->getDateFin();
        if ($debut === null || $fin === null) {
            return false;
        }

        $jour = $premier->setDate((int) $premier->format('Y'), (int) $premier->format('n'), $numero);

        return $jour >= $debut->setTime(0, 0) && $jour <= $fin->setTime(0, 0);
    }

    /**
     * La légende : type d'absence → couleur, pour les seuls types PRÉSENTS au mois.
     *
     * Une légende exhaustive listerait des couleurs qu'on ne voit nulle part, et
     * obligerait à chercher laquelle sert vraiment.
     *
     * @param DemandeConge[] $absences
     * @return array<string, string>
     */
    private function legende(array $absences): array
    {
        $legende = [];
        foreach ($absences as $demande) {
            $type = $demande->getTypeAbsence();
            if ($type === null) {
                continue;
            }
            $legende[(string) $type->getLibelle()] = $type->getCouleur() ?: '#0047AB';
        }

        ksort($legende);

        return $legende;
    }

    /** @return array{mois: int, annee: int} */
    private function decaler(int $annee, int $mois, int $pas): array
    {
        $date = (new \DateTimeImmutable(sprintf('%04d-%02d-01', $annee, $mois)))
            ->modify(sprintf('%+d month', $pas));

        return ['mois' => (int) $date->format('n'), 'annee' => (int) $date->format('Y')];
    }
}
