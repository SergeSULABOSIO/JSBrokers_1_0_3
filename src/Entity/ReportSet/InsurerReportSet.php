<?php

namespace App\Entity\ReportSet;

class InsurerReportSet
{
    public const TYPE_SUBTOTAL = 0;
    public const TYPE_ELEMENT = 1;
    public const TYPE_TOTAL = 2;

    public int $type;
    public string $currency_code;
    public string $label;
    public float $gw_premium;
    public float $net_com;
    public float $taxes;
    public float $gros_commission;
    public float $commission_received;
    public float $balance_due;

    public function __construct()
    {
        
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
        $this->label = $label;
        if ($this->type == self::TYPE_SUBTOTAL) {
            $this->label = strtoupper($this->label);
        }
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
     * Get the value of net_com
     */ 
    public function getNet_com()
    {
        return $this->net_com;
    }

    /**
     * Set the value of net_com
     *
     * @return  self
     */ 
    public function setNet_com($net_com)
    {
        $this->net_com = $net_com;

        return $this;
    }

    /**
     * Get the value of taxes
     */ 
    public function getTaxes()
    {
        return $this->taxes;
    }

    /**
     * Set the value of taxes
     *
     * @return  self
     */ 
    public function setTaxes($taxes)
    {
        $this->taxes = $taxes;

        return $this;
    }

    /**
     * Get the value of gros_commission
     */ 
    public function getGros_commission()
    {
        return $this->gros_commission;
    }

    /**
     * Set the value of gros_commission
     *
     * @return  self
     */ 
    public function setGros_commission($gros_commission)
    {
        $this->gros_commission = $gros_commission;

        return $this;
    }

    /**
     * Get the value of commission_received
     */ 
    public function getCommission_received()
    {
        return $this->commission_received;
    }

    /**
     * Set the value of commission_received
     *
     * @return  self
     */ 
    public function setCommission_received($commission_received)
    {
        $this->commission_received = $commission_received;

        return $this;
    }

    /**
     * Get the value of balance_due
     */ 
    public function getBalance_due()
    {
        return $this->balance_due;
    }

    /**
     * Set the value of balance_due
     *
     * @return  self
     */ 
    public function setBalance_due($balance_due)
    {
        $this->balance_due = $balance_due;

        return $this;
    }
}
