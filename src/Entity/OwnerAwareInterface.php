<?php
namespace App\Entity;

interface OwnerAwareInterface
{
    public function setInvite(?Invite $invite): self;
}