<?php

namespace App\Service\Soa;

use App\Entity\Avenant;
use App\Entity\Client;
use App\Entity\Document;

/**
 * Rassemble tous les documents enregistrés sur le serveur concernant une police
 * (avenant), quel que soit le niveau du pipe où ils ont été attachés :
 * Piste → Cotation → Police (avenant) → Client. Chaque entrée est enrichie du
 * niveau d'attache et de la famille de format (pastille pdf/word/excel…).
 * Utilisé par le picker « Documents de la police » des deux SOA (courtier et
 * client public).
 */
class SoaPoliceDocumentsCollector
{
    /** Familles de format par extension — pilotent la pastille du picker. */
    private const FAMILLES = [
        'pdf'  => ['pdf'],
        'word' => ['doc', 'docx', 'odt', 'rtf'],
        'excel' => ['xls', 'xlsx', 'ods', 'csv'],
        'ppt'  => ['ppt', 'pptx', 'odp'],
        'image' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'tif', 'tiff'],
        'archive' => ['zip', 'rar', '7z', 'tar', 'gz'],
    ];

    /**
     * @return array<int, array{document: Document, niveau: string, extension: string, famille: string}>
     */
    public function collect(Avenant $avenant): array
    {
        $cotation = $avenant->getCotation();
        $piste    = $cotation?->getPiste();
        $client   = $piste?->getClient();

        $sources = [
            ['niveau' => 'Piste',    'documents' => $piste?->getDocuments() ?? []],
            ['niveau' => 'Cotation', 'documents' => $cotation?->getDocuments() ?? []],
            ['niveau' => 'Police',   'documents' => $avenant->getDocuments()],
            ['niveau' => 'Client',   'documents' => $client?->getDocuments() ?? []],
        ];

        $items = [];
        $vus   = [];
        foreach ($sources as $source) {
            foreach ($source['documents'] as $document) {
                /** @var Document $document */
                if (isset($vus[$document->getId()])) {
                    continue;
                }
                $vus[$document->getId()] = true;

                $extension = strtolower(pathinfo((string) $document->getNomFichierStocke(), PATHINFO_EXTENSION));
                $items[] = [
                    'document'  => $document,
                    'niveau'    => $source['niveau'],
                    'extension' => $extension,
                    'famille'   => $this->famille($extension),
                ];
            }
        }

        return $items;
    }

    /** Le client (assuré) auquel appartient la police — null si le pipe est incomplet. */
    public function clientDeLaPolice(Avenant $avenant): ?Client
    {
        return $avenant->getCotation()?->getPiste()?->getClient();
    }

    /**
     * Un document appartient-il au pipe de ce client ? Garde du téléchargement
     * public tokenisé : seul un document attaché au client ou à l'une de ses
     * pistes/cotations/polices est servi.
     */
    public function documentAppartientAuClient(Document $document, Client $client): bool
    {
        $clientDuDocument = $document->getClient()
            ?? $document->getPiste()?->getClient()
            ?? $document->getCotation()?->getPiste()?->getClient()
            ?? $document->getAvenant()?->getCotation()?->getPiste()?->getClient();

        return $clientDuDocument !== null && $clientDuDocument->getId() === $client->getId();
    }

    private function famille(string $extension): string
    {
        foreach (self::FAMILLES as $famille => $extensions) {
            if (in_array($extension, $extensions, true)) {
                return $famille;
            }
        }

        return 'autre';
    }
}
