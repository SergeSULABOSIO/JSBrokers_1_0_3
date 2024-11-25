<?php
namespace App\Entity\ReportSet;

use App\Entity\Utilisateur;
use DateTimeImmutable;

class TaskReportSet
{
    public const TYPE_SUBTOTAL = 0;
    public const TYPE_ELEMENT = 1;
    public const TYPE_TOTAL = 2;

    public int $typee;

    public function __construct(
        public int $type,
        public string $currency_code,
        public string $task_description,
        public string $client,
        public array $contacts = [],
        public ?Utilisateur $owner,
        public ?Utilisateur $excutor,
        public ?DateTimeImmutable $effect_date,
        public float $potential_premium = 0,
        public float $potential_commission = 0,
        public float $days_passed = 0,
    )
    {
        if($this->type == self::TYPE_SUBTOTAL){
            $this->task_description = strtoupper($this->task_description);
        }
    }

}
