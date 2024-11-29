<?php
namespace App\Entity\ReportSet;

use DateTimeImmutable;

class CashflowReportSet
{
    public const TYPE_SUBTOTAL = 0;
    public const TYPE_ELEMENT = 1;
    public const TYPE_TOTAL = 2;

    public int $number;
    public string $description;
    public string $debtor;
    public string $status;
    public string $invoice_reference;
    public float $net_amount;
    public float $taxes;
    public float $gross_due;
    public float $amount_paid;
    public float $balance_due;
    public DateTimeImmutable $date_submition;
    public DateTimeImmutable $date_payment;

    public function __construct()
    {
        
    }

    /**
     * Get the value of number
     */ 
    public function getNumber()
    {
        return $this->number;
    }

    /**
     * Set the value of number
     *
     * @return  self
     */ 
    public function setNumber($number)
    {
        $this->number = $number;

        return $this;
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
}
