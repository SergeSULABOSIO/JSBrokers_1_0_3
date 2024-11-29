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
    public string $invoice_reference;
    public float $net_amount;
    public float $taxes;
    public float $gross_due;
    public float $amount_paid;
    public float $balance_due;
    public DateTimeImmutable $date_submition;

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
}
