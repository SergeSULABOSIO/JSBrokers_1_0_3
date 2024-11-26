<?php
namespace App\Entity\ReportSet;

use DateTime;

class RenewalReportSet
{
    public const TYPE_SUBTOTAL = 0;
    public const TYPE_ELEMENT = 1;
    public const TYPE_TOTAL = 2;

    public int $type;
    public string $currency_code;
    public string $label;
    public float $gw_premium;
    public float $g_commission;
    public ?DateTime $effect_date;
    public ?DateTime $expiry_date;

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
}
