<?php

namespace App\Entity\ReportSet;

class PartnerReportSet
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
    public float $co_brokerage;
    public float $amount_paid;
    public float $balance_due;
    public float $partner_rate;

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
     * Get the value of co_brokerage
     */ 
    public function getCo_brokerage()
    {
        return $this->co_brokerage;
    }

    /**
     * Set the value of co_brokerage
     *
     * @return  self
     */ 
    public function setCo_brokerage($co_brokerage)
    {
        $this->co_brokerage = $co_brokerage;

        return $this;
    }

    /**
     * Get the value of amount_paid
     */ 
    public function getAmount_paid()
    {
        return $this->amount_paid;
    }

    /**
     * Set the value of amount_paid
     *
     * @return  self
     */ 
    public function setAmount_paid($amount_paid)
    {
        $this->amount_paid = $amount_paid;

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

    /**
     * Get the value of partner_rate
     */ 
    public function getPartner_rate()
    {
        return $this->partner_rate;
    }

    /**
     * Set the value of partner_rate
     *
     * @return  self
     */ 
    public function setPartner_rate($partner_rate)
    {
        $this->partner_rate = $partner_rate;

        return $this;
    }
}
