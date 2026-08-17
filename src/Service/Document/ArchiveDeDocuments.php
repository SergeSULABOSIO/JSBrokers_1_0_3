<?php

namespace App\Service\Document;

use App\Entity\Document;
use App\Entity\Entreprise;
use App\Ai\Document\Renderer\FichierTemporaire;
use App\Services\JSBDynamicSearchService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * EMPAQUETER PLUSIEURS DOCUMENTS DANS UNE ARCHIVE — la mécanique, en un seul endroit.
 *
 * POURQUOI CE SERVICE EXISTE. Tout ceci vivait en dur dans le contrôleur de l'assistant :
 * la résolution scopée des identifiants, les deux plafonds, le dé-doublonnage des noms,
 * l'écriture du ZIP et son nettoyage. La rubrique Documents a maintenant besoin
 * exactement des mêmes règles, et une seconde copie aurait dérivé — c'est toujours le
 * plafond ou la garde de périmètre qu'on oublie de reporter, et la divergence ne se voit
 * pas : elle rend simplement moins de fichiers, ou davantage qu'elle ne devrait.
 *
 * CE QU'IL GARANTIT, ET QUI N'EST PAS NÉGOCIABLE :
 *
 *  - CHAQUE identifiant est re-résolu DANS l'entreprise appelante. Une liste d'ids arrive
 *    du navigateur : sans cette re-résolution, il suffirait d'en écrire d'autres à la main
 *    pour aspirer les pièces d'un autre cabinet. Un id étranger est écarté en silence —
 *    répondre « interdit » confirmerait son existence ;
 *  - deux plafonds, en NOMBRE et en POIDS. On rassemble d'abord et on empaquette ensuite,
 *    pour ne pas découvrir à mi-archive qu'on dépasse le poids autorisé ;
 *  - les noms sont dé-doublonnés. Deux documents peuvent porter le même nom, et sans cela
 *    le second écrase le premier DANS l'archive : l'utilisateur reçoit moins de fichiers
 *    qu'il n'en a demandés, sans qu'aucune erreur ne le signale.
 */
final class ArchiveDeDocuments
{
    /**
     * Bornes de l'archive. Une archive se fabrique fichier par fichier sur le disque — la
     * mémoire n'est donc pas le risque —, mais le TEMPS l'est : `symfony serve` ne sert
     * qu'une requête à la fois, et compresser indéfiniment y gèlerait tout le reste de
     * l'application. Mieux vaut refuser en le disant que faire attendre sans expliquer.
     */
    public const MAX_FICHIERS = 50;
    public const MAX_OCTETS = 200 * 1024 * 1024;

    public function __construct(
        private readonly JSBDynamicSearchService $searchService,
        private readonly DocumentFichier $documentFichier,
        private readonly FichierTemporaire $fichierTemporaire,
    ) {
    }

    /**
     * Les documents de cette entreprise, dans l'ordre demandé, qui portent réellement un
     * binaire sur le disque.
     *
     * Un document dont le fichier manque est écarté : proposer son téléchargement
     * produirait un 404 au clic, ce qui est pire que de ne rien proposer.
     *
     * @param list<int> $ids
     *
     * @return list<Document>
     */
    public function documentsLisibles(array $ids, Entreprise $entreprise): array
    {
        $documents = [];
        foreach ($ids as $id) {
            $resultat = $this->searchService->search(Document::class, ['id' => $id], $entreprise, null, 1, 1);
            $document = $resultat['data'][0] ?? null;
            if (($resultat['status']['code'] ?? 500) !== 200 || !$document instanceof Document) {
                continue;
            }
            if (!$this->documentFichier->existe($document)) {
                continue;
            }
            $documents[] = $document;
        }

        return $documents;
    }

    /**
     * Lit une liste d'identifiants telle qu'elle arrive de l'interface (« 3,7,12 »).
     *
     * @return list<int> entiers positifs, dédoublonnés, dans l'ordre reçu
     */
    public static function identifiants(string $brut): array
    {
        return array_values(array_unique(array_filter(
            array_map('intval', explode(',', $brut)),
            static fn (int $id): bool => $id > 0,
        )));
    }

    /**
     * L'archive des documents donnés — ou la réponse qui explique pourquoi il n'y en a pas.
     *
     * Rend une `Response` plutôt que de lever : un dépassement de plafond n'est pas une
     * anomalie mais un refus qui doit être LU par l'utilisateur, avec la conduite à tenir.
     * Une liste vide, elle, est bien une 404 — il n'y a rien à montrer.
     *
     * @param list<Document> $documents
     */
    public function archiver(array $documents, string $nomArchive): Response
    {
        if ($documents === []) {
            throw new NotFoundHttpException('Aucun document téléchargeable.');
        }
        if (\count($documents) > self::MAX_FICHIERS) {
            return $this->refus(sprintf(
                'Trop de documents demandés (%d) : le maximum est de %d par archive.',
                \count($documents),
                self::MAX_FICHIERS,
            ));
        }

        $aEmpaqueter = [];
        $poids = 0;
        foreach ($documents as $document) {
            $chemin = $this->documentFichier->chemin($document);
            if ($chemin === null || !is_file($chemin)) {
                continue;
            }
            $poids += (int) $this->documentFichier->taille($document);
            if ($poids > self::MAX_OCTETS) {
                return $this->refus(sprintf(
                    'Archive trop volumineuse : le total dépasse %d Mo. Téléchargez les documents séparément.',
                    intdiv(self::MAX_OCTETS, 1024 * 1024),
                ));
            }
            $aEmpaqueter[] = [$chemin, $this->documentFichier->nomDeTelechargement($document)];
        }

        if ($aEmpaqueter === []) {
            throw new NotFoundHttpException('Aucun document téléchargeable.');
        }

        $cheminZip = $this->fichierTemporaire->chemin('zip');
        $zip = new \ZipArchive();
        if ($zip->open($cheminZip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("L'archive n'a pas pu être créée.");
        }

        $utilises = [];
        foreach ($aEmpaqueter as [$chemin, $nom]) {
            $zip->addFile($chemin, self::nomUniqueDansArchive($nom, $utilises));
        }
        $zip->close();

        $reponse = new BinaryFileResponse($cheminZip);
        $reponse->deleteFileAfterSend(true);
        $reponse->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, self::nomDeFichier($nomArchive));
        $reponse->headers->set('Content-Type', 'application/zip');

        return $reponse;
    }

    /** Un refus lisible, en texte brut : il s'affiche tel quel si le navigateur l'ouvre. */
    private function refus(string $message): Response
    {
        return new Response(
            $message,
            Response::HTTP_REQUEST_ENTITY_TOO_LARGE,
            ['Content-Type' => 'text/plain; charset=UTF-8'],
        );
    }

    /**
     * Un nom d'archive utilisable sur tous les systèmes de fichiers.
     *
     * Le libellé vient de la base, donc de la saisie d'un utilisateur : il peut contenir
     * des barres obliques ou des deux-points, que Windows refuse et qu'un serveur pourrait
     * interpréter comme un chemin.
     */
    public static function nomDeFichier(string $libelle): string
    {
        $nom = trim(preg_replace('#[\\\\/:*?"<>|\r\n]+#', '-', $libelle) ?? '');

        return ($nom === '' ? 'documents' : mb_substr($nom, 0, 120)) . '.zip';
    }

    /**
     * Un nom encore libre dans l'archive : « contrat.pdf », puis « contrat (2).pdf ».
     *
     * @param array<string, true> $utilises noms déjà placés, modifié par référence
     */
    public static function nomUniqueDansArchive(string $nom, array &$utilises): string
    {
        $cle = mb_strtolower($nom);
        if (!isset($utilises[$cle])) {
            $utilises[$cle] = true;

            return $nom;
        }

        $ext = pathinfo($nom, PATHINFO_EXTENSION);
        $base = $ext === '' ? $nom : substr($nom, 0, -\strlen($ext) - 1);
        for ($i = 2; ; ++$i) {
            $candidat = $ext === '' ? sprintf('%s (%d)', $base, $i) : sprintf('%s (%d).%s', $base, $i, $ext);
            $cle = mb_strtolower($candidat);
            if (!isset($utilises[$cle])) {
                $utilises[$cle] = true;

                return $candidat;
            }
        }
    }
}
