<?php

namespace App\Entity;

class ReportSet
{
    public const TYPE_SUBTOTAL = 0;
    public const TYPE_ELEMENT = 1;
    public const TYPE_TOTAL = 2;

    public function __construct(
        public int $type,
        public string $currency_code,
        public string $label,
        public float $gw_premium,
        public float $net_premium,
        public float $taxes,
        public float $gros_commission,
        public float $commission_received,
        public float $balance_due,
    )
    {
        if($this->type == self::TYPE_SUBTOTAL){
            $this->label = strtoupper($this->label);
        }
    }

}
