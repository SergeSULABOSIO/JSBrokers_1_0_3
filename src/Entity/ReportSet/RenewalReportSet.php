<?php
namespace App\Entity\ReportSet;

use App\Entity\Utilisateur;
use DateTime;
use DateTimeImmutable;

class RenewalReportSet
{
    public const TYPE_SUBTOTAL = 0;
    public const TYPE_ELEMENT = 1;
    public const TYPE_TOTAL = 2;

    public int $type;
    public string $currency_code;
    public string $label;
    public string $insurer;
    public string $client;
    public string $status;
    public string $endorsement;
    public string $cover;
    public string $bg_color;
    public ?Utilisateur $account_manager;
    public string $remaining_days;
    public int $endorsement_id;
    public float $gw_premium;
    public float $g_commission;
    public ?DateTimeImmutable $effect_date;
    public ?DateTimeImmutable $expiry_date;

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
     * Get the value of label
     */ 
    public function getLabel()
    {
        return $this->label;
    }

    /**
     * Set the value of label
     *
     * @return  self
     */ 
    public function setLabel($label)
    {
        if($this->type == self::TYPE_SUBTOTAL){
            $this->label = strtoupper($this->label);
        }
        $this->label = $label;

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
     * Get the value of g_commission
     */ 
    public function getG_commission()
    {
        return $this->g_commission;
    }

    /**
     * Set the value of g_commission
     *
     * @return  self
     */ 
    public function setG_commission($g_commission)
    {
        $this->g_commission = $g_commission;

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
     * Get the value of endorsement
     */ 
    public function getEndorsement()
    {
        return $this->endorsement;
    }

    /**
     * Set the value of endorsement
     *
     * @return  self
     */ 
    public function setEndorsement($endorsement)
    {
        $this->endorsement = $endorsement;

        return $this;
    }

    /**
     * Get the value of status
     */ 
    public function getStatus()
    {
        return $this->status;
    }

    /**
     * Set the value of status
     *
     * @return  self
     */ 
    public function setStatus($status)
    {
        $this->status = $status;

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
     * Get the value of remaining_days
     */ 
    public function getRemaining_days()
    {
        return $this->remaining_days;
    }

    /**
     * Set the value of remaining_days
     *
     * @return  self
     */ 
    public function setRemaining_days($remaining_days)
    {
        $this->remaining_days = $remaining_days;

        return $this;
    }

    /**
     * Get the value of endorsement_id
     */ 
    public function getEndorsement_id()
    {
        return $this->endorsement_id;
    }

    /**
     * Set the value of endorsement_id
     *
     * @return  self
     */ 
    public function setEndorsement_id($endorsement_id)
    {
        $this->endorsement_id = $endorsement_id;

        return $this;
    }
}
