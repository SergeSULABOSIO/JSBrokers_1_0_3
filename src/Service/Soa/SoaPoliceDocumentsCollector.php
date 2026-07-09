<?php

namespace App\Service\Soa;

use App\Entity\Avenant;
use App\Entity\Client;
use App\Entity\Cotation;
use App\Entity\Document;
use App\Entity\Piste;

/**
 * Rassemble tous les documents enregistrés sur le serveur concernant un dossier
 * du pipe commercial (Client, Piste, Cotation ou Avenant/Police), quel que soit
 * le niveau où ils ont été attachés. Règle de périmètre : les ASCENDANTS directs
 * (contexte), l'entité elle-même et ses DESCENDANTS :
 *   - Client   → client + toutes ses pistes + leurs cotations + leurs polices ;
 *   - Piste    → client + la piste + ses cotations + leurs polices ;
 *   - Cotation → client + piste parente + la cotation + ses polices ;
 *   - Avenant  → client + piste + cotation parentes + la police.
 * Chaque entrée est enrichie du niveau d'attache et de la famille de format
 * (pastille pdf/word/excel…). Utilisé par le picker « Documents » des deux SOA
 * (courtier et client public) et par les actions spéciales des rubriques du
 * workspace (Client, Piste, Cotation, Avenant).
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
     * @param Client|Piste|Cotation|Avenant $entity
     * @return array<int, array{document: Document, niveau: string, extension: string, famille: string}>
     */
    public function collectFor(object $entity): array
    {
        $client    = null;
        $pistes    = [];
        $cotations = [];
        $avenants  = [];

        if ($entity instanceof Client) {
            $client = $entity;
            foreach ($entity->getPistes() as $piste) {
                $pistes[] = $piste;
            }
            foreach ($pistes as $piste) {
                foreach ($piste->getCotations() as $cotation) {
                    $cotations[] = $cotation;
                }
            }
            foreach ($cotations as $cotation) {
                foreach ($cotation->getAvenants() as $avenant) {
                    $avenants[] = $avenant;
                }
            }
        } elseif ($entity instanceof Piste) {
            $client = $entity->getClient();
            $pistes = [$entity];
            foreach ($entity->getCotations() as $cotation) {
                $cotations[] = $cotation;
            }
            foreach ($cotations as $cotation) {
                foreach ($cotation->getAvenants() as $avenant) {
                    $avenants[] = $avenant;
                }
            }
        } elseif ($entity instanceof Cotation) {
            $piste  = $entity->getPiste();
            $client = $piste?->getClient();
            if ($piste !== null) {
                $pistes = [$piste];
            }
            $cotations = [$entity];
            foreach ($entity->getAvenants() as $avenant) {
                $avenants[] = $avenant;
            }
        } elseif ($entity instanceof Avenant) {
            $cotation = $entity->getCotation();
            $piste    = $cotation?->getPiste();
            $client   = $piste?->getClient();
            if ($piste !== null) {
                $pistes = [$piste];
            }
            if ($cotation !== null) {
                $cotations = [$cotation];
            }
            $avenants = [$entity];
        } else {
            throw new \InvalidArgumentException(sprintf('Type non supporté par le collecteur de documents : %s', get_debug_type($entity)));
        }

        $sources = [];
        foreach ($pistes as $piste) {
            $sources[] = ['niveau' => 'Piste', 'documents' => $piste->getDocuments()];
        }
        foreach ($cotations as $cotation) {
            $sources[] = ['niveau' => 'Cotation', 'documents' => $cotation->getDocuments()];
        }
        foreach ($avenants as $avenant) {
            $sources[] = ['niveau' => 'Police', 'documents' => $avenant->getDocuments()];
        }
        if ($client !== null) {
            $sources[] = ['niveau' => 'Client', 'documents' => $client->getDocuments()];
        }

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

    /**
     * @return array<int, array{document: Document, niveau: string, extension: string, famille: string}>
     */
    public function collect(Avenant $avenant): array
    {
        return $this->collectFor($avenant);
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
