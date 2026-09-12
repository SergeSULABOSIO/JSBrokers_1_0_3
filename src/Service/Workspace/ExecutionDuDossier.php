<?php

namespace App\Service\Workspace;

use App\Echange\Service\Progression;
use Doctrine\ORM\EntityManagerInterface;

/**
 * EXÉCUTE UN DOSSIER LOT PAR LOT — une transaction chacun, un journal qui les nomme.
 *
 * L'arbre se décompose en SOUS-RACINES : l'affaire entière si tout est coché, sinon les
 * branches restées cochées. Chacune est un plan à elle seule, donc une transaction à elle
 * seule.
 *
 * ⚠ AUCUNE TRANSACTION ENGLOBANTE, ET C'EST LE POINT CENTRAL. `SuppressionEnCascade::executer()`
 * détecte une transaction active et s'y insère plutôt que d'en ouvrir une : une transaction
 * chapeau ferait donc qu'un seul lot en échec emporterait tous les autres. Or c'est
 * l'inverse qu'on veut — un échec n'interrompt pas la file, il est nommé et on continue.
 *
 * ⚠ ET `$em->clear()` APRÈS CHAQUE LOT, succès comme échec. Sans lui, l'unité de travail
 * accumule les entités de tous les lots, et un lot annulé y laisse des objets marqués pour
 * suppression que le lot suivant reflusherait.
 */
final class ExecutionDuDossier
{
    /**
     * Budget de temps consulté ENTRE deux lots, jamais au milieu d'un.
     *
     * ⚠ IL NE COUPE PAS UNE TRANSACTION : il décide seulement s'il en ouvre une de plus. Au
     * bout du budget, la réponse rend la main avec la liste des lots restants, et l'écran
     * relance. C'est le palier — sans entité, sans colonne curseur, sans worker : rejouer
     * un lot déjà exécuté replanifie à vide, donc l'opération est idempotente par
     * construction.
     */
    public const BUDGET_SECONDES = 20;

    /** Garde-fou d'entrée : au-delà, la charge vient d'ailleurs que de l'écran. */
    public const LOTS_MAX = 500;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SuppressionEnCascade $moteur,
    ) {
    }

    /**
     * @param string[]      $lots    clés « NomCourt#identifiant », dans l'ordre
     * @param callable|null $publier fn(array $ligne): void — une ligne de journal
     *
     * @return array{faits: array<int, array<string, mixed>>, echecs: array<int, array<string, mixed>>,
     *               restants: string[], detruits: int, detaches: int, conservations: string[]}
     */
    public function executer(
        object $racine,
        array $lots,
        ExclusionsDeSuppression $exclusions,
        ?callable $publier = null,
    ): array {
        $autorises = $this->cequiEstAutorise($racine);
        $debut = microtime(true);
        $total = max(1, $this->moteur->planifier($racine)->total());

        $faits = [];
        $echecs = [];
        $restants = [];
        $conservations = [];
        $detruits = 0;
        $detaches = 0;
        $faitsAvant = 0;

        foreach (array_slice($lots, 0, self::LOTS_MAX) as $rang => $cle) {
            // ⚠ LE BUDGET SE CONSULTE ICI, entre deux lots — jamais pendant.
            if ($rang > 0 && (microtime(true) - $debut) > self::BUDGET_SECONDES) {
                $restants = array_values(array_slice($lots, $rang));
                break;
            }

            $cible = $this->cibleDuLot((string) $cle, $autorises);
            if ($cible === null) {
                $echecs[] = ['cle' => $cle, 'nom' => (string) $cle, 'motif' => "Cet élément ne fait pas partie du dossier affiché."];
                $this->publierLot($publier, $cle, 'echec', ['motif' => "Cet élément ne fait pas partie du dossier affiché."]);
                continue;
            }

            $nom = $this->nomDe($cible, (string) $cle);
            $this->publierLot($publier, $cle, 'en-cours', ['nom' => $nom]);

            try {
                $plan = $this->moteur->planifier($cible, $exclusions);
                // ⚠ UNE `Progression` PAR LOT, dont le callback REMAPPE l'avancement sur le
                // dossier entier : une instance partagée ferait repartir la barre de zéro à
                // chaque lot, et une barre qui recule ment.
                $rapport = $this->moteur->executer($plan, $this->progressionDuLot($publier, $nom, $faitsAvant, $total));

                $detruits += (int) $rapport['detruits'];
                $detaches += (int) $rapport['detaches'];
                $faitsAvant += (int) $rapport['detruits'] + (int) $rapport['detaches'];
                foreach ($rapport['conservations'] as $phrase) {
                    $conservations[] = $phrase;
                }
                $faits[] = ['cle' => $cle, 'nom' => $nom, 'detruits' => $rapport['detruits'], 'detaches' => $rapport['detaches']];
                $this->publierLot($publier, $cle, 'fait', [
                    'nom' => $nom, 'detruits' => $rapport['detruits'], 'detaches' => $rapport['detaches'],
                ]);
            } catch (\Throwable $erreur) {
                $motif = $this->motifLisible($erreur);
                $echecs[] = ['cle' => $cle, 'nom' => $nom, 'motif' => $motif];
                $this->publierLot($publier, $cle, 'echec', ['nom' => $nom, 'motif' => $motif]);
            } finally {
                $this->rincer();
            }
        }

        return [
            'faits'         => $faits,
            'echecs'        => $echecs,
            'restants'      => $restants,
            'detruits'      => $detruits,
            'detaches'      => $detaches,
            'conservations' => array_values(array_unique($conservations)),
        ];
    }

    /**
     * CE QUE LE DOSSIER AFFICHÉ CONTIENT VRAIMENT — la garde d'appartenance.
     *
     * ⚠ SANS ELLE, LA ROUTE EFFACERAIT N'IMPORTE QUOI. Les lots arrivent du navigateur : une
     * charge forgée pourrait nommer la proposition d'un autre dossier, voire d'un autre
     * client. On replanifie donc la racine une fois, et rien qui n'y figure ne passe.
     *
     * @return array<string, class-string> « Court#id » => classe complète
     */
    private function cequiEstAutorise(object $racine): array
    {
        $plan = $this->moteur->planifier($racine);
        $autorises = [];
        foreach ($plan->aDetruire as $classe => $ids) {
            $court = $this->court($classe);
            foreach ($ids as $id) {
                $autorises[sprintf('%s#%d', $court, $id)] = $classe;
            }
        }

        return $autorises;
    }

    /** @param array<string, class-string> $autorises */
    private function cibleDuLot(string $cle, array $autorises): ?object
    {
        $classe = $autorises[$cle] ?? null;
        if ($classe === null) {
            return null;
        }
        $id = (int) substr($cle, strrpos($cle, '#') + 1);

        try {
            return $this->em->find($classe, $id);
        } catch (\Throwable) {
            return null;
        }
    }

    private function progressionDuLot(?callable $publier, string $nom, int $faitsAvant, int $total): ?Progression
    {
        if ($publier === null) {
            return null;
        }

        return new Progression(0, static function (array $etape) use ($publier, $nom, $faitsAvant, $total): void {
            $fait = $faitsAvant + (int) $etape['fait'];
            $publier([
                'type'    => 'progres',
                'fait'    => $fait,
                'total'   => $total,
                'pct'     => round(min(100.0, ($fait / max(1, $total)) * 100), 1),
                'libelle' => trim($nom . ' — ' . (string) $etape['libelle'], ' —'),
                'restant' => null,
            ]);
        });
    }

    private function publierLot(?callable $publier, string $cle, string $etat, array $extra = []): void
    {
        if ($publier !== null) {
            $publier(['type' => 'lot', 'cle' => $cle, 'etat' => $etat] + $extra);
        }
    }

    /**
     * Vide l'unité de travail entre deux lots, sans laisser une panne de nettoyage
     * interrompre une file qui, elle, avance.
     */
    private function rincer(): void
    {
        try {
            if ($this->em->isOpen()) {
                $this->em->clear();
            }
        } catch (\Throwable) {
            // Rien à dire à l'utilisateur : le lot suivant rechargera ce dont il a besoin.
        }
    }

    /** Le motif d'un échec, dans les mots du métier quand on les a. */
    private function motifLisible(\Throwable $erreur): string
    {
        if ($erreur instanceof MutationException) {
            return $erreur->getMessage();
        }
        $message = trim($erreur->getMessage());

        return $message !== '' && !str_contains($message, 'SQLSTATE')
            ? $message
            : "Cette partie du dossier n'a pas pu être supprimée. Elle est restée intacte.";
    }

    private function nomDe(object $cible, string $repli): string
    {
        foreach (['getNom', 'getReference', 'getReferencePolice', 'getLibelle'] as $getter) {
            if (method_exists($cible, $getter)) {
                $valeur = trim((string) $cible->{$getter}());
                if ($valeur !== '') {
                    return $valeur;
                }
            }
        }

        return $repli;
    }

    private function court(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');

        return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
    }
}
