<?php
namespace App\Entity\ReportSet;

use App\Entity\Utilisateur;
use DateTimeImmutable;

class CashflowReportSet
{
    public const TYPE_SUBTOTAL = 0;
    public const TYPE_ELEMENT = 1;
    public const TYPE_TOTAL = 2;

    public int $index;
    public int $type;
    public string $currency_code;
    public string $description;
    public string $debtor;
    public string $status;
    public string $days_passed;
    public string $invoice_reference;
    public float $net_amount;
    public float $taxes;
    public float $gross_due;
    public float $amount_paid;
    public float $balance_due;
    public ?Utilisateur $user;
    public ?DateTimeImmutable $date_submition;
    public ?DateTimeImmutable $date_payment;

    public function __construct()
    {
        
    }
    

    /**
     * Get the value of description
     */ 
    public function getDescription()
    {
        return $this->description;
    }

    /**
     * Set the value of description
     *
     * @return  self
     */ 
    public function setDescription($description)
    {
        $this->description = $description;

        return $this;
    }

    /**
     * Get the value of debtor
     */ 
    public function getDebtor()
    {
        return $this->debtor;
    }

    /**
     * Set the value of debtor
     *
     * @return  self
     */ 
    public function setDebtor($debtor)
    {
        $this->debtor = $debtor;

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
     * Get the value of invoice_reference
     */ 
    public function getInvoice_reference()
    {
        return $this->invoice_reference;
    }

    /**
     * Set the value of invoice_reference
     *
     * @return  self
     */ 
    public function setInvoice_reference($invoice_reference)
    {
        $this->invoice_reference = $invoice_reference;

        return $this;
    }

    /**
     * Get the value of net_amount
     */ 
    public function getNet_amount()
    {
        return $this->net_amount;
    }

    /**
     * Set the value of net_amount
     *
     * @return  self
     */ 
    public function setNet_amount($net_amount)
    {
        $this->net_amount = $net_amount;

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
     * Get the value of gross_due
     */ 
    public function getGross_due()
    {
        return $this->gross_due;
    }

    /**
     * Set the value of gross_due
     *
     * @return  self
     */ 
    public function setGross_due($gross_due)
    {
        $this->gross_due = $gross_due;

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
     * Get the value of date_submition
     */ 
    public function getDate_submition()
    {
        return $this->date_submition;
    }

    /**
     * Set the value of date_submition
     *
     * @return  self
     */ 
    public function setDate_submition($date_submition)
    {
        $this->date_submition = $date_submition;

        return $this;
    }

    /**
     * Get the value of date_payment
     */ 
    public function getDate_payment()
    {
        return $this->date_payment;
    }

    /**
     * Set the value of date_payment
     *
     * @return  self
     */ 
    public function setDate_payment($date_payment)
    {
        $this->date_payment = $date_payment;

        return $this;
    }

    /**
     * Get the value of index
     */ 
    public function getIndex()
    {
        return $this->index;
    }

    /**
     * Set the value of index
     *
     * @return  self
     */ 
    public function setIndex($index)
    {
        $this->index = $index;

        return $this;
    }

    /**
     * Get the value of user
     */ 
    public function getUser()
    {
        return $this->user;
    }

    /**
     * Set the value of user
     *
     * @return  self
     */ 
    public function setUser($user)
    {
        $this->user = $user;

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
     * Get the value of days_passed
     */ 
    public function getDays_passed()
    {
        return $this->days_passed;
    }

    /**
     * Set the value of days_passed
     *
     * @return  self
     */ 
    public function setDays_passed($days_passed)
    {
        $this->days_passed = $days_passed;

        return $this;
    }
}
