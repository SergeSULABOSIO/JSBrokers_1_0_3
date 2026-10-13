<?php

namespace App\Service\Avenant;

use App\Entity\Cotation;

/**
 * CE QU'UN AVENANT REÇOIT QUAND ON SOUSCRIT UNE COTATION.
 *
 * Ces règles vivaient dans l'outil de l'assistant (`souscrire_cotation`), et nulle part
 * ailleurs. L'écran, lui, proposait « maintenant + 1 an » à tout avenant créé sous une
 * cotation — sans lire sa durée : une proposition de six mois devenait une police d'un an
 * dès qu'on la souscrivait à la main, et la même souscription donnait deux périodes selon
 * qu'on la faisait par l'écran ou par Ket.
 *
 * Une seule source désormais, pour trois chemins : le « + » des avenants d'une cotation,
 * l'action « Souscrire » d'une carte de piste, et Ket.
 */
final class DefautsDeSouscription
{
    /**
     * Les valeurs de l'avenant, et les phrases qui annoncent celles qu'on a choisies à la
     * place de l'utilisateur (Ket les lui dit ; l'écran les lui montre dans le formulaire).
     *
     * @param \DateTimeImmutable|null $dateEffet la date dictée ; à défaut, aujourd'hui
     * @param string|null             $numero    le numéro dicté ; à défaut, 0 (la police d'origine)
     *
     * @return array{startingAt: \DateTimeImmutable, endingAt: ?\DateTimeImmutable, numero: string, description: string, annonces: list<string>}
     */
    public function pour(Cotation $cotation, ?\DateTimeImmutable $dateEffet = null, ?string $numero = null): array
    {
        $annonces = [];

        $debut = $dateEffet;
        if ($debut === null) {
            $debut = new \DateTimeImmutable('today');
            $annonces[] = sprintf('Prise d’effet : %s (aucune date dictée).', $debut->format('d/m/Y'));
        }

        // LA DURÉE DE LA PROPOSITION FAIT LA PÉRIODE : début + durée en mois − 1 jour. Sans
        // durée, on ne devine pas de fin.
        $duree = (int) $cotation->getDuree();
        $fin = $duree > 0 ? $debut->modify(sprintf('+%d month', $duree))->modify('-1 day') : null;
        if ($fin !== null) {
            $annonces[] = sprintf(
                'Période de couverture : du %s au %s (durée de %d mois portée par la proposition).',
                $debut->format('d/m/Y'),
                $fin->format('d/m/Y'),
                $duree,
            );
        }

        $numero = trim((string) $numero);
        if ($numero === '') {
            $numero = '0';
            $annonces[] = 'Numéro d’avenant : 0 (la police d’origine).';
        }

        return [
            'startingAt' => $debut,
            'endingAt' => $fin,
            'numero' => $numero,
            'description' => sprintf('Souscription de la proposition « %s ».', (string) $cotation->getNom()),
            'annonces' => $annonces,
        ];
    }

    /**
     * Une proposition déjà souscrite ne se souscrit pas deux fois : ce serait un second
     * contrat sur le même marché.
     */
    public function dejaSouscrite(Cotation $cotation): bool
    {
        return count($cotation->getAvenants()) > 0;
    }
}
