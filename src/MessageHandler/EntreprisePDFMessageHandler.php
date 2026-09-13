<?php

namespace App\MessageHandler;

use App\Message\EntreprisePDFMessage;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * @file ESQUISSE NON IMPLEMENTEE — a ne pas confondre avec une fonctionnalite.
 *
 * La generation du PDF d'entreprise devait passer par Gotenberg (Chromium pilote
 * a distance). L'installation n'a jamais abouti, et tout l'appel etait commente
 * depuis : ce gestionnaire n'a donc JAMAIS produit de PDF. Il ecrit un fichier
 * texte d'exemple, que rien ne lit et qu'aucune route ne sert.
 *
 * ── CE QUI A ETE RETIRE LE 2026-09-13, ET POURQUOI ──────────────────────────
 * La dependance a GOTENBERG_ENDPOINT (variable d'environnement + parametre
 * app.gotenberg_endpoint) laissait croire qu'un service externe devait etre
 * installe pour la mise en production. C'est faux : il n'y a rien a installer,
 * puisqu'il n'y a rien qui l'appelle. La declaration partait donc, pas le reste
 * — supprimer l'esquisse elle-meme est une decision de produit, pas de
 * deploiement.
 *
 * ── SI LA FONCTIONNALITE EST REPRISE ────────────────────────────────────────
 * Sur un hebergement mutualise, Gotenberg n'est pas une option (c'est un service
 * Docker). Le projet produit deja ses PDF en PHP pur — DomPDF et TCPDF — dans
 * cinq autres endroits : c'est cette voie-la qu'il faudra suivre.
 */
#[AsMessageHandler]
final class EntreprisePDFMessageHandler
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/public/pdfs')]
        public readonly string $path,
    ) {}

    public function __invoke(EntreprisePDFMessage $message): void
    {
        // Le dossier est preserve au clone par public/pdfs/.gitkeep, et recree
        // par bin/deploy.sh : sans lui, cette ecriture echouait sur un dossier
        // inexistant et le message partait en file « failed ».
        file_put_contents($this->path . '/' . $message->id . '.txt', "Ceci est juste un exemple");
    }
}
