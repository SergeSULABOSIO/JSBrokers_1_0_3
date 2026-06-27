<?php

namespace App\Services;

use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Provisionne une entreprise nouvellement créée : la rattache à son propriétaire,
 * crée l'invité « Administrateur » (propriétaire) et sème les paramètres par défaut
 * (monnaies, taxes, chargements, risques, groupes) pour que l'espace de travail soit
 * immédiatement exploitable.
 *
 * Volontairement HORS métrage de tokens : le débit éventuel (création d'une entreprise
 * supplémentaire = 200 tokens) reste de la responsabilité de l'appelant
 * (App\Controller\Admin\EntrepriseController::create). L'assistant d'onboarding
 * (App\Controller\OnboardingController) appelle ce service SANS débiter, la toute
 * première entreprise du courtier étant offerte.
 *
 * Source de vérité de la séquence create + invite + initialisation, extraite de
 * EntrepriseController::create pour éviter toute duplication.
 */
class ServiceProvisionEntreprise
{
    public function __construct(
        private EntityManagerInterface $manager,
        private ServiceInitialisationEntreprise $serviceInitialisation,
    ) {}

    /**
     * Crée l'entreprise, son invité propriétaire et ses paramètres par défaut en une
     * seule transaction. Retourne l'invité propriétaire (nécessaire pour construire
     * l'URL d'entrée dans l'espace de travail).
     */
    public function provisionner(Entreprise $entreprise, Utilisateur $proprietaire): Invite
    {
        // rccm / idnat / numimpot sont NOT NULL en base mais facultatifs côté métier :
        // le formulaire complet les remplit (chaîne vide si laissés vides), tandis que
        // l'assistant d'onboarding allégé ne les expose pas. On garantit donc une valeur
        // non nulle ici sans jamais écraser une valeur déjà saisie.
        $entreprise->setRccm($entreprise->getRccm() ?? '');
        $entreprise->setIdnat($entreprise->getIdnat() ?? '');
        $entreprise->setNumimpot($entreprise->getNumimpot() ?? '');

        // On persiste d'abord l'entreprise pour qu'elle obtienne un ID.
        $this->manager->persist($entreprise);
        $this->manager->flush();

        // Rattachement explicite au créateur (le POST_SUBMIT du formulaire le fait déjà,
        // mais on le garantit ici pour les appels hors formulaire).
        $entreprise->setUtilisateur($proprietaire);

        // Invité propriétaire de l'entreprise (rôle administrateur).
        $invite = (new Invite())
            ->setNom('Administrateur')
            ->setUtilisateur($proprietaire)
            ->setEntreprise($entreprise)
            ->setProprietaire(true);
        $this->manager->persist($invite);

        // Paramètres par défaut : aucun flush interne, un seul flush ci-dessous.
        $this->serviceInitialisation->initialiser($entreprise, $invite);

        $this->manager->flush();

        return $invite;
    }
}
