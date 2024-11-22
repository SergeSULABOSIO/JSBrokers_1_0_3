<?php
namespace App\Entity\ReportSet;

class Top20ClientReportSet
{
    public const TYPE_SUBTOTAL = 0;
    public const TYPE_ELEMENT = 1;
    public const TYPE_TOTAL = 2;

    public function __construct(
        public int $type,
        public string $label,
        public string $insurer,
        public float $gw_premium,
        public float $g_commission,
    )
    {
        if($this->type == self::TYPE_SUBTOTAL){
            $this->label = strtoupper($this->label);
        }
    }

}
