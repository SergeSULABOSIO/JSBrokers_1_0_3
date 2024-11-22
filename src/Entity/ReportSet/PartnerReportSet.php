<?php
namespace App\Entity\ReportSet;

class PartnerReportSet
{
    public const TYPE_SUBTOTAL = 0;
    public const TYPE_ELEMENT = 1;
    public const TYPE_TOTAL = 2;

    public function __construct(
        public int $type,
        public string $currency_code,
        public string $label,
        public float $gw_premium,
        public float $net_com,
        public float $taxes,
        public float $co_brokerage,
        public float $amount_paid,
        public float $balance_due,
    )
    {
        if($this->type == self::TYPE_SUBTOTAL){
            $this->label = strtoupper($this->label);
        }
    }

}
