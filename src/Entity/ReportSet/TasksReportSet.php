<?php
namespace App\Entity\ReportSet;

use App\Entity\Utilisateur;
use DateTimeImmutable;

class Top20ClientReportSet
{
    public const TYPE_SUBTOTAL = 0;
    public const TYPE_ELEMENT = 1;
    public const TYPE_TOTAL = 2;

    public function __construct(
        public int $type,
        public string $currency_code,
        public string $task_description,
        public string $client,
        public Utilisateur $owner,
        public Utilisateur $excutor,
        public DateTimeImmutable $effect_date,
        public DateTimeImmutable $expiry_date,
        public float $potential_premium,
        public float $potential_commission,
    )
    {
        if($this->type == self::TYPE_SUBTOTAL){
            $this->label = strtoupper($this->label);
        }
    }

}
