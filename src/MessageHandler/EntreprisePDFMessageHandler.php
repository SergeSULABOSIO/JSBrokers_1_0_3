<?php

namespace App\MessageHandler;

use App\Message\EntreprisePDFMessage;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AsMessageHandler]
final class EntreprisePDFMessageHandler
{

    public function __construct(
        #[Autowire("%kernel.project_dir%/public/pdfs")]
        public readonly string $path,
        #[Autowire("%app.gotenberg_endpoint%")]
        private readonly string $gotenbegEndpoint,
        private readonly UrlGeneratorInterface $urlGenerator
    ) {}


    public function __invoke(EntreprisePDFMessage $message): void
    {
        //Générer du code php avec Gotenberg : j'ai des difficultés pour installer Gotenberg, je reviendrais ici plus tard.
        // $process = new Process([
        //     'curl',
        //     '--request',
        //     'POST',
        //     sprintf('%s/forms/chromium/convert/url', $this->gotenbegEndpoint),
        //     // $this->gotenbegEndpoint . '/forms/chromium/convert/url',
        //     '--form',
        //     sprintf('url=%s', $this->urlGenerator->generate('admin.entreprise.pdf', ['id' => $message->id], UrlGeneratorInterface::ABSOLUTE_URL)),
        //     // 'url=' . $this->urlGenerator->generate('admin.entreprise.pdf', ['id' => $message->id], UrlGeneratorInterface::ABSOLUTE_URL),
        //     '-o',
        //     $this->path . '/' . $message->id . ".pdf"
        // ]);
        // $process->run();
        // if(!$process->isSuccessful()){
        //     throw new ProcessFailedException($process);
        // }
        // file_put_contents($this->path . '/' . $message->id . ".pdf", ""); //Généer du pdf avec du code php simplement
        file_put_contents($this->path . '/' . $message->id . ".txt", "Ceci est juste un exemple"); //Généer du pdf avec du code php simplement
    }
}
