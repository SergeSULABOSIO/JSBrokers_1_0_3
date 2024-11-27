<?php
namespace App\Entity\ReportSet;

use App\Entity\Utilisateur;
use DateTime;
use DateTimeImmutable;

class ClaimReportSet
{
    public const TYPE_SUBTOTAL = 0;
    public const TYPE_ELEMENT = 1;
    public const TYPE_TOTAL = 2;

    public int $type;
    public string $currency_code;
    //Policy
    public string $policy_reference;
    public string $insurer;
    public string $client;
    public string $cover;
    public float $gw_premium;
    public float $policy_limit;
    public float $policy_deductible;
    public ?DateTimeImmutable $effect_date;
    public ?DateTimeImmutable $expiry_date;
    //Claim
    public string $claim_reference;
    public string $victim;
    public string $claims_status;
    public string $bg_color;
    public ?Utilisateur $account_manager;
    public float $damage_cost;
    public float $compensation_paid;
    public float $compensation_balance;
    public string $compensation_speed;  //nb des jours entre la notification et l'indemnisation de la victime
    public ?DateTimeImmutable $notification_date;
    public ?DateTimeImmutable $settlement_date;

    public function __construct()
    {
        
    }


    /**
     * Get the value of type
     */ 
    public function getType()
    {
        return $this->type;
    }

    /**
     * Set the value of type
     *
     * @return  self
     */ 
    public function setType($type)
    {
        $this->type = $type;

        return $this;
    }

    /**
     * Get the value of currency_code
     */ 
    public function getCurrency_code()
    {
        return $this->currency_code;
    }

    /**
     * Set the value of currency_code
     *
     * @return  self
     */ 
    public function setCurrency_code($currency_code)
    {
        $this->currency_code = $currency_code;

        return $this;
    }


    /**
     * Get the value of gw_premium
     */ 
    public function getGw_premium()
    {
        return $this->gw_premium;
    }

    /**
     * Set the value of gw_premium
     *
     * @return  self
     */ 
    public function setGw_premium($gw_premium)
    {
        $this->gw_premium = $gw_premium;

        return $this;
    }

    /**
     * Get the value of insurer
     */ 
    public function getInsurer()
    {
        return $this->insurer;
    }

    /**
     * Set the value of insurer
     *
     * @return  self
     */ 
    public function setInsurer($insurer)
    {
        $this->insurer = $insurer;

        return $this;
    }

    /**
     * Get the value of account_manager
     */ 
    public function getAccount_manager()
    {
        return $this->account_manager;
    }

    /**
     * Set the value of account_manager
     *
     * @return  self
     */ 
    public function setAccount_manager($account_manager)
    {
        $this->account_manager = $account_manager;

        return $this;
    }

    /**
     * Get the value of cover
     */ 
    public function getCover()
    {
        return $this->cover;
    }

    /**
     * Set the value of cover
     *
     * @return  self
     */ 
    public function setCover($cover)
    {
        $this->cover = $cover;

        return $this;
    }

    /**
     * Get the value of effect_date
     */ 
    public function getEffect_date()
    {
        return $this->effect_date;
    }

    /**
     * Set the value of effect_date
     *
     * @return  self
     */ 
    public function setEffect_date($effect_date)
    {
        $this->effect_date = $effect_date;

        return $this;
    }

    /**
     * Get the value of expiry_date
     */ 
    public function getExpiry_date()
    {
        return $this->expiry_date;
    }

    /**
     * Set the value of expiry_date
     *
     * @return  self
     */ 
    public function setExpiry_date($expiry_date)
    {
        $this->expiry_date = $expiry_date;

        return $this;
    }

    /**
     * Get the value of client
     */ 
    public function getClient()
    {
        return $this->client;
    }

    /**
     * Set the value of client
     *
     * @return  self
     */ 
    public function setClient($client)
    {
        $this->client = $client;

        return $this;
    }


    /**
     * Get the value of bg_color
     */ 
    public function getBg_color()
    {
        return $this->bg_color;
    }

    /**
     * Set the value of bg_color
     *
     * @return  self
     */ 
    public function setBg_color($bg_color)
    {
        $this->bg_color = $bg_color;

        return $this;
    }


    /**
     * Get the value of policy_reference
     */ 
    public function getPolicy_reference()
    {
        return $this->policy_reference;
    }

    /**
     * Set the value of policy_reference
     *
     * @return  self
     */ 
    public function setPolicy_reference($policy_reference)
    {
        $this->policy_reference = $policy_reference;

        return $this;
    }

    /**
     * Get the value of claim_reference
     */ 
    public function getClaim_reference()
    {
        return $this->claim_reference;
    }

    /**
     * Set the value of claim_reference
     *
     * @return  self
     */ 
    public function setClaim_reference($claim_reference)
    {
        $this->claim_reference = $claim_reference;

        return $this;
    }

    /**
     * Get the value of claims_status
     */ 
    public function getClaims_status()
    {
        return $this->claims_status;
    }

    /**
     * Set the value of claims_status
     *
     * @return  self
     */ 
    public function setClaims_status($claims_status)
    {
        $this->claims_status = $claims_status;

        return $this;
    }


    /**
     * Get the value of damage_cost
     */ 
    public function getDamage_cost()
    {
        return $this->damage_cost;
    }

    /**
     * Set the value of damage_cost
     *
     * @return  self
     */ 
    public function setDamage_cost($damage_cost)
    {
        $this->damage_cost = $damage_cost;

        return $this;
    }

    /**
     * Get the value of compensation_speed
     */ 
    public function getCompensation_speed()
    {
        return $this->compensation_speed;
    }

    /**
     * Set the value of compensation_speed
     *
     * @return  self
     */ 
    public function setCompensation_speed($compensation_speed)
    {
        $this->compensation_speed = $compensation_speed;

        return $this;
    }

    /**
     * Get the value of policy_limit
     */ 
    public function getPolicy_limit()
    {
        return $this->policy_limit;
    }

    /**
     * Set the value of policy_limit
     *
     * @return  self
     */ 
    public function setPolicy_limit($policy_limit)
    {
        $this->policy_limit = $policy_limit;

        return $this;
    }

    /**
     * Get the value of policy_deductible
     */ 
    public function getPolicy_deductible()
    {
        return $this->policy_deductible;
    }

    /**
     * Set the value of policy_deductible
     *
     * @return  self
     */ 
    public function setPolicy_deductible($policy_deductible)
    {
        $this->policy_deductible = $policy_deductible;

        return $this;
    }

    /**
     * Get the value of victim
     */ 
    public function getVictim()
    {
        return $this->victim;
    }

    /**
     * Set the value of victim
     *
     * @return  self
     */ 
    public function setVictim($victim)
    {
        $this->victim = $victim;

        return $this;
    }

    /**
     * Get the value of compensation_paid
     */ 
    public function getCompensation_paid()
    {
        return $this->compensation_paid;
    }

    /**
     * Set the value of compensation_paid
     *
     * @return  self
     */ 
    public function setCompensation_paid($compensation_paid)
    {
        $this->compensation_paid = $compensation_paid;

        return $this;
    }

    /**
     * Get the value of compensation_balance
     */ 
    public function getCompensation_balance()
    {
        return $this->compensation_balance;
    }

    /**
     * Set the value of compensation_balance
     *
     * @return  self
     */ 
    public function setCompensation_balance($compensation_balance)
    {
        $this->compensation_balance = $compensation_balance;

        return $this;
    }

    /**
     * Get the value of notification_date
     */ 
    public function getNotification_date()
    {
        return $this->notification_date;
    }

    /**
     * Set the value of notification_date
     *
     * @return  self
     */ 
    public function setNotification_date($notification_date)
    {
        $this->notification_date = $notification_date;

        return $this;
    }

    /**
     * Get the value of settlement_date
     */ 
    public function getSettlement_date()
    {
        return $this->settlement_date;
    }

    /**
     * Set the value of settlement_date
     *
     * @return  self
     */ 
    public function setSettlement_date($settlement_date)
    {
        $this->settlement_date = $settlement_date;

        return $this;
    }
}
