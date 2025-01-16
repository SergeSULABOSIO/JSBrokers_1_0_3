<?php

namespace App\Constantes;

use App\Entity\Note;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\ArrayCollection;

class PanierNotes
{
    public const NOM = "PANIER_NOTES_TEMPORAIRES";
    /**
     * @var Collection<int, Note>
     */
    private Collection $notes;


    public function __construct() {
        $this->notes = new ArrayCollection();

    }

    public function __toString()
    {
        return self::NOM . ", " . count($this->getNotes()) . " note(s)";
    }

    /**
     * Get note>
     *
     * @return  Collection<int,
     */ 
    public function getNotes()
    {
        return $this->notes;
    }

    /**
     * Set note>
     *
     * @param  Collection<int,  $notes  Note>
     *
     * @return  self
     */ 
    public function setNotes(Collection $notes)
    {
        $this->notes = $notes;

        return $this;
    }

    public function addNote(Note $note): static
    {
        $index = -1;
        foreach ($this->notes as $oldnote) {
            $index++;
            if ($oldnote->getSignature() == $note->getSignature()) {
                break;
            }
        }
        if ($index == -1) {
            $this->notes->add($note);
        }else{
            $this->notes->set($index, $note);
        }

        return $this;
    }

    public function removeNote(Note $note): static
    {
        $this->notes->removeElement($note);

        return $this;
    }

    public function viderPanier(){
        $this->notes = new ArrayCollection();
    }

    public function getNote(string $signature): Note{
        foreach ($this->notes as $note) {
            if ($note->getSignature() == $signature) {
                return $note;
            }
        }
        return null;
    }
}
