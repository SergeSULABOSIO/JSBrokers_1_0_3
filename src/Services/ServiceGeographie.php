<?php

namespace App\Services;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Source de vérité unique pour les données géographiques JS Brokers
 * (pays d'Asie, d'Afrique et d'Europe francophone).
 *
 * Charge une seule fois le dataset assets/data/pays_villes.json :
 *   "250": { "nom": "France", "monnaie": "EUR", "villes": [ {"id": 1001, "nom": "Paris"}, … ] }
 *
 * - le code pays est le code ISO 3166-1 NUMÉRIQUE (clé) ;
 * - la monnaie est le code ISO 4217 ;
 * - chaque ville porte un identifiant numérique stable.
 *
 * Ce service alimente à la fois les choix du formulaire (EntrepriseType),
 * la validation côté serveur (mêmes choix), l'endpoint AJAX (EntrepriseController)
 * et l'affichage des libellés. Tout doit passer par lui pour rester cohérent.
 */
class ServiceGeographie
{
    /** @var array<string, array{nom: string, monnaie: string, villes: array<int, array{id: int, nom: string}>}>|null */
    private ?array $data = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
    ) {}

    /**
     * Charge (paresseusement) et met en cache le dataset.
     *
     * @return array<string, array{nom: string, monnaie: string, villes: array<int, array{id: int, nom: string}>}>
     */
    private function getData(): array
    {
        if ($this->data === null) {
            $chemin = $this->projectDir . '/assets/data/pays_villes.json';
            $contenu = is_file($chemin) ? file_get_contents($chemin) : false;
            $this->data = $contenu === false ? [] : (json_decode($contenu, true) ?? []);
        }

        return $this->data;
    }

    /**
     * Choix pour le champ « Pays » (sélection unique), triés par nom.
     *
     * @return array<string, int> ['Afrique du Sud' => 710, 'Algérie' => 12, …]
     */
    public function getPaysChoices(): array
    {
        $choices = [];
        foreach ($this->getData() as $code => $pays) {
            $choices[$pays['nom']] = (int) $code;
        }
        uksort($choices, fn($a, $b) => strcoll($a, $b));

        return $choices;
    }

    /**
     * Villes d'un pays (liste brute pour l'API JSON).
     *
     * @return array<int, array{id: int, nom: string}>
     */
    public function getVilles(int $codePays): array
    {
        $pays = $this->getPays($codePays);

        return $pays['villes'] ?? [];
    }

    /**
     * Choix pour le champ « Ville » d'un pays donné (pour le formulaire / la validation).
     *
     * @return array<string, int> ['Paris' => 1001, …]
     */
    public function getVilleChoices(int $codePays): array
    {
        $choices = [];
        foreach ($this->getVilles($codePays) as $ville) {
            $choices[$ville['nom']] = (int) $ville['id'];
        }

        return $choices;
    }

    /**
     * Code monnaie (ISO 4217) du pays, ou null si inconnu.
     */
    public function getMonnaie(int $codePays): ?string
    {
        return $this->getPays($codePays)['monnaie'] ?? null;
    }

    /**
     * Nom du pays à partir de son code ISO numérique, ou null si inconnu.
     */
    public function getNomPays(int $codePays): ?string
    {
        return $this->getPays($codePays)['nom'] ?? null;
    }

    /**
     * Nom d'une ville à partir de son identifiant numérique, ou null si introuvable.
     */
    public function getNomVille(int $idVille): ?string
    {
        foreach ($this->getData() as $pays) {
            foreach ($pays['villes'] as $ville) {
                if ((int) $ville['id'] === $idVille) {
                    return $ville['nom'];
                }
            }
        }

        return null;
    }

    /**
     * @return array{nom: string, monnaie: string, villes: array<int, array{id: int, nom: string}>}|null
     */
    private function getPays(int $codePays): ?array
    {
        // Les clés JSON gardent un éventuel zéro initial (« 012 ») ; on normalise.
        $data = $this->getData();
        foreach ($data as $code => $pays) {
            if ((int) $code === $codePays) {
                return $pays;
            }
        }

        return null;
    }
}
