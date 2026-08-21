<?php

namespace App\Service\Retro;

use App\Entity\CompteBancaire;
use App\Entity\Entreprise;
use Doctrine\ORM\EntityManagerInterface;

/**
 * CE QU'UN REVERSEMENT DE RÉTROCOMMISSION VAUT PAR DÉFAUT — pour l'écran ET pour Ket.
 *
 * Deux chemins mènent au même acte : le picker de l'écran, qui persiste directement, et
 * l'outil `signaler_reversement_retro_agent`, qui passe par le moteur de mutation. Ils
 * doivent écrire le MÊME enregistrement quand l'utilisateur ne précise rien — sans quoi
 * « paie Alice » dit par la voix et « paie Alice » fait à la souris ne produisent pas la
 * même comptabilité.
 *
 * Ils recopiaient chacun leur formule de référence, et le compte débité n'existait que
 * d'un seul côté : tout reversement demandé à Ket partait donc EN CAISSE, quand le même
 * geste à l'écran passait par la banque. Les deux valeurs vivent ici, une seule fois.
 */
final class DefautsDuVersement
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * La référence proposée. Le format est le même des deux côtés, et il est LISIBLE :
     * un reversement sans référence est introuvable en rapprochement bancaire.
     */
    public function reference(\DateTimeImmutable $date): string
    {
        return 'RETRO-' . $date->format('dmY-His');
    }

    /**
     * Les comptes proposables, dans l'ordre où l'écran les offre.
     *
     * @return array<int, CompteBancaire>
     */
    public function comptes(Entreprise $entreprise): array
    {
        return $this->em->getRepository(CompteBancaire::class)
            ->findBy(['entreprise' => $entreprise], ['intitule' => 'ASC']);
    }

    /**
     * Le compte retenu d'office : le PREMIER de la liste.
     *
     * Un reversement passe par la banque dans la règle et par la caisse par exception —
     * c'est donc un compte qui est proposé, la caisse restant un choix explicite. Sans
     * aucun compte enregistré, il n'y a rien à proposer et la caisse redevient le défaut.
     */
    public function comptePropose(Entreprise $entreprise): ?CompteBancaire
    {
        return $this->comptes($entreprise)[0] ?? null;
    }
}
