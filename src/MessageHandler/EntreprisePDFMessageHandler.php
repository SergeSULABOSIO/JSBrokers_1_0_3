<?php

namespace App\MessageHandler;

use App\Message\EntreprisePDFMessage;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class EntreprisePDFMessageHandler
{

    public function __construct(
        #[Autowire("%kernel.project_dir%/public/pdfs")]
        public readonly string $path,
    ) {}


    public function __invoke(EntreprisePDFMessage $message): void
    {
        // do something with your message
        file_put_contents($this->path . '/' . $message->id . ".pdf", "");
    }
}
