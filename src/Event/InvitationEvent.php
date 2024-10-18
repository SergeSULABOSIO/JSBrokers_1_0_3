<?php

namespace App\Event;

use App\Entity\Invite;

class InvitationEvent
{
    public function __construct(
        public readonly Invite $data
    ) {}
}
