<?php

namespace App\Services;

use DateTimeImmutable;
use Symfony\Component\Form\Event\PostSubmitEvent;

class FormListenerFactory
{

    public function timeStamps(): callable
    {
        return function (PostSubmitEvent $event) {
            $data = $event->getData();
            $data->setUpdatedAt(new DateTimeImmutable('now'));
            if (!$data->getId()) {
                $data->setCreatedAt(new DateTimeImmutable('now'));
            }
        };
    }
}
