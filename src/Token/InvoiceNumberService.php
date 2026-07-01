<?php

namespace App\Token;

use App\Entity\InvoiceCounter;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * @file Attribution des numéros de facture séquentiels (FAC-AAAA-NNNNN).
 * @description Séquence ANNUELLE sans trou. Le numéro suivant est obtenu par
 * incrément sous verrou pessimiste (SELECT … FOR UPDATE) de la ligne de
 * l'année : deux encaissements concurrents ne peuvent pas obtenir le même
 * numéro. À appeler DANS la transaction d'encaissement (cf.
 * TokenPurchaseFulfillmentService) pour que le verrou tienne jusqu'au commit.
 */
class InvoiceNumberService
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    /**
     * Réserve et renvoie le prochain numéro de facture de l'année donnée
     * (année courante par défaut). DOIT être appelé dans une transaction.
     */
    public function next(?int $annee = null): string
    {
        $annee ??= (int) (new \DateTimeImmutable())->format('Y');
        $repo = $this->em->getRepository(InvoiceCounter::class);

        // Verrou pessimiste sur la ligne de l'année pour sérialiser les incréments.
        $compteur = $repo->findOneBy(['annee' => $annee]);
        if ($compteur !== null) {
            $this->em->lock($compteur, LockMode::PESSIMISTIC_WRITE);
        } else {
            // Première facture de l'année : on crée la ligne (id unique sur l'année
            // → une éventuelle course se solde par une violation de contrainte, et
            // l'appelant rejoue dans une nouvelle transaction).
            $compteur = (new InvoiceCounter())->setAnnee($annee)->setSequence(0);
            $this->em->persist($compteur);
        }

        $sequence = $compteur->increment();
        $this->em->flush();

        return $this->format($annee, $sequence);
    }

    /** Format public d'un numéro de facture : FAC-AAAA-NNNNN. */
    public function format(int $annee, int $sequence): string
    {
        return sprintf('FAC-%d-%05d', $annee, $sequence);
    }
}
