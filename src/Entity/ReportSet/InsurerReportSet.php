<?php
namespace App\Entity\ReportSet;

class InsurerReportSet
{
    public const TYPE_SUBTOTAL = 0;
    public const TYPE_ELEMENT = 1;
    public const TYPE_TOTAL = 2;

    public int $type,
        public string $currency_code,
        public string $label,
        public float $gw_premium,
        public float $net_com,
        public float $taxes,
        public float $gros_commission,
        public float $commission_received,
        public float $balance_due,
        
    public function __construct(
        
    )
    {
        if($this->type == self::TYPE_SUBTOTAL){
            $this->label = strtoupper($this->label);
        }
    }

}
