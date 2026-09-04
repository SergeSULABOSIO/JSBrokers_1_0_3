<?php

namespace App\Echange\Service;

/**
 * RAPPORTE l'avancement d'une opération longue, en la mesurant.
 *
 * ⚠ RIEN N'EST SIMULÉ ICI. Le total est connu AVANT de commencer — des lignes comptées
 * en base pour un export, des lignes lues pour un import, des opérations planifiées
 * pour une écriture — et l'avancement est incrémenté par le code qui travaille
 * réellement. Une barre qui progresse toute seule est pire qu'une barre indéterminée :
 * elle promet une échéance qu'elle ne connaît pas.
 *
 * L'ESTIMATION DU TEMPS RESTANT découle du débit CONSTATÉ depuis le début, pas d'une
 * moyenne théorique. Elle n'est publiée qu'une fois assez d'échantillons vus : annoncer
 * « 3 secondes » sur la foi de la première ligne, puis « 4 minutes » à la deuxième,
 * détruit la confiance plus sûrement que de ne rien annoncer.
 *
 * L'écoulement se fait par un callback, ce qui laisse l'appelant décider du support :
 * une réponse diffusée en NDJSON pour l'écran, rien du tout pour un test ou une
 * commande. Le service qui travaille, lui, ignore où va l'information.
 */
final class Progression
{
    /** En deçà, le débit observé est trop court pour qu'une estimation veuille dire quelque chose. */
    private const ECHANTILLON_MINIMAL = 8;

    /** On n'écrit pas une ligne de progression par enregistrement : le flux serait plus lourd que le travail. */
    private const INTERVALLE_MINIMAL_MS = 120;

    private int $fait = 0;

    private float $debut;

    private float $dernierEnvoi = 0.0;

    private string $libelle = '';

    /**
     * @param int            $total     nombre d'unités à traiter, connu d'avance
     * @param callable|null  $ecoulement fn(array $etape): void — null = ne rien publier
     */
    public function __construct(
        private int $total,
        private readonly mixed $ecoulement = null,
    ) {
        $this->debut = microtime(true);
    }

    /** Rapporteur muet : pour les tests, les commandes, et tout ce qui n'a pas d'écran. */
    public static function muette(): self
    {
        return new self(0, null);
    }

    /**
     * Nomme l'étape en cours (« Clients », « Écriture des polices »…).
     *
     * L'utilisateur veut savoir CE QUI avance, pas seulement que quelque chose avance :
     * « 43 % » sur quarante-deux feuilles ne dit pas laquelle prend du temps.
     */
    public function etape(string $libelle): void
    {
        $this->libelle = $libelle;
        $this->publier(force: true);
    }

    /** Avance de $pas unités, et publie si l'instant s'y prête. */
    public function avancer(int $pas = 1): void
    {
        $this->fait += max(0, $pas);
        $this->publier();
    }

    /**
     * Corrige le total en cours de route.
     *
     * Un import ne connaît son volume qu'après avoir lu ses feuilles : mieux vaut un
     * total ajusté une fois qu'un pourcentage faux jusqu'à la fin.
     */
    public function totaliser(int $total): void
    {
        $this->total = max(0, $total);
        $this->publier(force: true);
    }

    public function total(): int
    {
        return $this->total;
    }

    public function fait(): int
    {
        return $this->fait;
    }

    /** Pourcentage mesuré, borné à 100 — un total sous-estimé ne doit pas afficher 137 %. */
    public function pourcentage(): float
    {
        if ($this->total <= 0) {
            return 0.0;
        }

        return min(100.0, ($this->fait / $this->total) * 100);
    }

    /**
     * Secondes restantes selon le débit CONSTATÉ, ou null tant qu'on n'en sait pas assez.
     *
     * Null n'est pas un échec : c'est l'aveu honnête qu'à ce stade, toute estimation
     * serait un chiffre au hasard.
     */
    public function secondesRestantes(): ?float
    {
        if ($this->total <= 0 || $this->fait < self::ECHANTILLON_MINIMAL || $this->fait >= $this->total) {
            return null;
        }

        $ecoule = microtime(true) - $this->debut;
        if ($ecoule <= 0.0) {
            return null;
        }

        return ($ecoule / $this->fait) * ($this->total - $this->fait);
    }

    /** Marque l'opération comme terminée et publie une dernière fois. */
    public function terminer(): void
    {
        $this->fait = $this->total;
        $this->publier(force: true);
    }

    /**
     * @return array{type: string, fait: int, total: int, pct: float, libelle: string, restant: float|null}
     */
    public function etat(): array
    {
        return [
            'type'     => 'progres',
            'fait'     => $this->fait,
            'total'    => $this->total,
            'pct'      => round($this->pourcentage(), 1),
            'libelle'  => $this->libelle,
            'restant'  => $this->secondesRestantes(),
        ];
    }

    /**
     * Publie l'état, en s'interdisant d'inonder le flux.
     *
     * Sans le pas minimal, un import de deux mille lignes produirait deux mille lignes
     * de progression : le navigateur passerait plus de temps à les lire que le serveur
     * à travailler, et l'affichage n'en serait pas plus précis pour autant.
     */
    private function publier(bool $force = false): void
    {
        if (!is_callable($this->ecoulement)) {
            return;
        }

        $maintenant = microtime(true) * 1000;
        if (!$force && ($maintenant - $this->dernierEnvoi) < self::INTERVALLE_MINIMAL_MS) {
            return;
        }

        $this->dernierEnvoi = $maintenant;
        ($this->ecoulement)($this->etat());
    }
}
