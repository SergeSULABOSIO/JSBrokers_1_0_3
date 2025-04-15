<?php

namespace App\Entity\ReportSet;

use App\Entity\Utilisateur;
use DateTimeImmutable;

class TaskReportSet
{
    public const TYPE_SUBTOTAL = 0;
    public const TYPE_ELEMENT = 1;
    public const TYPE_TOTAL = 2;

    public int $type = self::TYPE_ELEMENT;
    public string $currency_code = "$";
    public string $task_description = "";
    public string $client = "";
    public string $endorsement = "";
    public string $lead = "";
    public array $contacts = [];
    public ?Utilisateur $owner = null;
    public ?Utilisateur $excutor = null;
    public ?DateTimeImmutable $effect_date = null;
    public ?string $effect_date_comment = "";
    public float $potential_premium = 0;
    public float $potential_commission = 0;
    public float $nbFeedbacks = 0;
    public float $days_remaining = 0;
    public string $statusExecution = "";

    public function __construct()
    {
        
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
     * Get the value of task_description
     */ 
    public function getTask_description()
    {
        return $this->task_description;
    }

    /**
     * Set the value of task_description
     *
     * @return  self
     */ 
    public function setTask_description($task_description)
    {
        if ($this->type == self::TYPE_SUBTOTAL) {
            $this->task_description = strtoupper($this->task_description);
        }
        
        $this->task_description = $task_description;

        return $this;
    }

    /**
     * Get the value of client
     */ 
    public function getClient()
    {
        return $this->client;
    }

    /**
     * Set the value of client
     *
     * @return  self
     */ 
    public function setClient($client)
    {
        $this->client = $client;

        return $this;
    }

    /**
     * Get the value of contacts
     */ 
    public function getContacts()
    {
        return $this->contacts;
    }

    /**
     * Set the value of contacts
     *
     * @return  self
     */ 
    public function setContacts($contacts)
    {
        $this->contacts = $contacts;

        return $this;
    }

    /**
     * Get the value of owner
     */ 
    public function getOwner()
    {
        return $this->owner;
    }

    /**
     * Set the value of owner
     *
     * @return  self
     */ 
    public function setOwner($owner)
    {
        $this->owner = $owner;

        return $this;
    }

    /**
     * Get the value of excutor
     */ 
    public function getExcutor()
    {
        return $this->excutor;
    }

    /**
     * Set the value of excutor
     *
     * @return  self
     */ 
    public function setExcutor($excutor)
    {
        $this->excutor = $excutor;

        return $this;
    }

    /**
     * Get the value of effect_date
     */ 
    public function getEffect_date()
    {
        return $this->effect_date;
    }

    /**
     * Set the value of effect_date
     *
     * @return  self
     */ 
    public function setEffect_date($effect_date)
    {
        $this->effect_date = $effect_date;

        return $this;
    }


    /**
     * Get the value of statusExecution
     */ 
    public function getStatusExecution()
    {
        return $this->statusExecution;
    }

    /**
     * Set the value of statusExecution
     *
     * @return  self
     */ 
    public function setStatusExecution($statusExecution)
    {
        $this->statusExecution = $statusExecution;

        return $this;
    }

    /**
     * Get the value of potential_premium
     */ 
    public function getPotential_premium()
    {
        return $this->potential_premium;
    }

    /**
     * Set the value of potential_premium
     *
     * @return  self
     */ 
    public function setPotential_premium($potential_premium)
    {
        $this->potential_premium = $potential_premium;

        return $this;
    }

    /**
     * Get the value of potential_commission
     */ 
    public function getPotential_commission()
    {
        return $this->potential_commission;
    }

    /**
     * Set the value of potential_commission
     *
     * @return  self
     */ 
    public function setPotential_commission($potential_commission)
    {
        $this->potential_commission = $potential_commission;

        return $this;
    }

    /**
     * Get the value of days_passed
     */ 
    public function getNBFeedbacks()
    {
        return $this->nbFeedbacks;
    }

    /**
     * Set the value of days_passed
     *
     * @return  self
     */ 
    public function setNBFeedbacks($nbFeedbacks)
    {
        $this->nbFeedbacks = $nbFeedbacks;

        return $this;
    }

    /**
     * Get the value of days_remaining
     */ 
    public function getDays_remaining()
    {
        return $this->days_remaining;
    }

    /**
     * Set the value of days_remaining
     *
     * @return  self
     */ 
    public function setDays_remaining($days_remaining)
    {
        $this->days_remaining = $days_remaining;

        return $this;
    }


    /**
     * Get the value of endorsement
     */ 
    public function getEndorsement()
    {
        return $this->endorsement;
    }

    /**
     * Set the value of endorsement
     *
     * @return  self
     */ 
    public function setEndorsement($endorsement)
    {
        $this->endorsement = $endorsement;

        return $this;
    }

    /**
     * Get the value of lead
     */ 
    public function getLead()
    {
        return $this->lead;
    }

    /**
     * Set the value of lead
     *
     * @return  self
     */ 
    public function setLead($lead)
    {
        $this->lead = $lead;

        return $this;
    }

    /**
     * Get the value of effect_date_comment
     */ 
    public function getEffect_date_comment()
    {
        return $this->effect_date_comment;
    }

    /**
     * Set the value of effect_date_comment
     *
     * @return  self
     */ 
    public function setEffect_date_comment($effect_date_comment)
    {
        $this->effect_date_comment = $effect_date_comment;

        return $this;
    }
}
