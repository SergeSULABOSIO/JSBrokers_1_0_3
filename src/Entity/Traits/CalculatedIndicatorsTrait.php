<?php

namespace App\Entity\Traits;

use Symfony\Component\Serializer\Annotation\Groups;

trait CalculatedIndicatorsTrait
{
    #[Groups(['list:read'])]
    public ?float $prime_totale = null;
    #[Groups(['list:read'])]
    public ?float $prime_totale_payee = null;
    #[Groups(['list:read'])]
    public ?float $prime_totale_solde = null;
    #[Groups(['list:read'])]
    public ?float $commission_totale = null;
    #[Groups(['list:read'])]
    public ?float $commission_totale_encaissee = null;
    #[Groups(['list:read'])]
    public ?float $commission_totale_solde = null;
    #[Groups(['list:read'])]
    public ?float $commission_nette = null;
    #[Groups(['list:read'])]
    public ?float $commission_pure = null;
    #[Groups(['list:read'])]
    public ?float $commission_partageable = null;
    #[Groups(['list:read'])]
    public ?float $prime_nette = null;
    #[Groups(['list:read'])]
    public ?float $reserve = null;
    #[Groups(['list:read'])]
    public ?float $retro_commission_partenaire = null;
    #[Groups(['list:read'])]
    public ?float $retro_commission_partenaire_payee = null;
    #[Groups(['list:read'])]
    public ?float $retro_commission_partenaire_solde = null;
    #[Groups(['list:read'])]
    public ?float $taxe_courtier = null;
    #[Groups(['list:read'])]
    public ?float $taxe_courtier_payee = null;
    #[Groups(['list:read'])]
    public ?float $taxe_courtier_solde = null;
    #[Groups(['list:read'])]
    public ?float $taxe_assureur = null;
    #[Groups(['list:read'])]
    public ?float $taxe_assureur_payee = null;
    #[Groups(['list:read'])]
    public ?float $taxe_assureur_solde = null;
    #[Groups(['list:read'])]
    public ?float $sinistre_payable = null;
    #[Groups(['list:read'])]
    public ?float $sinistre_paye = null;
    #[Groups(['list:read'])]
    public ?float $sinistre_solde = null;
    #[Groups(['list:read'])]
    public ?float $taux_sinistralite = null;
    #[Groups(['list:read'])]
    public ?float $taux_de_commission = null;
    #[Groups(['list:read'])]
    public ?float $taux_de_retrocommission_effectif = null;
    #[Groups(['list:read'])]
    public ?float $taux_de_paiement_prime = null;
    #[Groups(['list:read'])]
    public ?float $taux_de_paiement_commission = null;
    #[Groups(['list:read'])]
    public ?float $taux_de_paiement_retro_commission = null;
    #[Groups(['list:read'])]
    public ?float $taux_de_paiement_taxe_courtier = null;
    #[Groups(['list:read'])]
    public ?float $taux_de_paiement_taxe_assureur = null;
    #[Groups(['list:read'])]
    public ?float $taux_de_paiement_sinistre = null;
}