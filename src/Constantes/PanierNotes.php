<?php

namespace App\Constantes;

use App\Entity\Note;

class PanierNotes
{
    public const NOM = "PANIER_NOTES_TEMPORAIRES";
    private ?Note $note = null;


    public function __construct() {}

    public function __toString()
    {
        return self::NOM . ", " . $this->getNote();
    }

    /**
     * Get the value of note
     */
    public function getNote(): ?Note
    {
        return $this->note;
    }

    /**
     * Set the value of note
     *
     * @return  self
     */
    public function setNote($note)
    {
        $this->note = $note;

        return $this;
    }

    public function viderPanier()
    {
        $this->note = null;

        return $this;
    }
}
