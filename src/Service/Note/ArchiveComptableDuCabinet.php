<?php

namespace App\Service\Note;

use App\Comptabilite\CourtierEcritureComptableService;
use App\Entity\Entreprise;
use App\Entity\Note;
use App\Services\Canvas\Indicator\IndicatorCalculationHelper;
use Doctrine\ORM\EntityManagerInterface;

/**
 * L'ARCHIVE COMPTABLE D'UN CABINET — ce que le propriétaire emporte avant que la base
 * n'efface ses pièces (conservation dix ans, règle OHADA ; décision de Serge du
 * 2026-10-15).
 *
 * Un ZIP qui se suffit à lui-même :
 *  • `notes/` — le PDF de chaque note émise (validée) ou de reprise, par le rendu même
 *    du téléchargement ({@see NotePdf}) ;
 *  • `notes.csv`, `lignes.csv`, `paiements.csv` — leurs montants FIGÉS ;
 *  • `journal.csv` — toutes les écritures du cabinet ;
 *  • `MANIFESTE.txt` — le contenu, et l'empreinte SHA-256 de chaque fichier.
 *
 * La remise est ENREGISTRÉE sur le cabinet (date, empreinte de l'archive) : c'est elle
 * qui rend la suppression possible ({@see ConservationDesPieces}).
 *
 * ⚠ LA DATE DE REMISE EST PRISE AVANT DE LIRE LES PIÈCES. Une archive se construit en
 * minutes ; une note validée pendant ce temps n'y est peut-être pas. Datée du début, la
 * remise la laisse « postérieure » : l'archive n'est pas réputée à jour, et la
 * suppression attend une nouvelle remise.
 *
 * ⚠ UN HÉBERGEMENT MUTUALISÉ : chaque PDF passe par un fichier temporaire (le ZIP ne
 * garde pas tout en mémoire jusqu'à sa fermeture), et la mémoire de Doctrine est vidée
 * note par note. Les fichiers temporaires sont effacés quoi qu'il arrive.
 */
final class ArchiveComptableDuCabinet
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NotePdf $notePdf,
        private readonly IndicatorCalculationHelper $calcul,
        private readonly CourtierEcritureComptableService $journal,
    ) {
    }

    /**
     * Construit l'archive dans un fichier temporaire et enregistre sa remise.
     *
     * @return array{chemin: string, nom: string, empreinte: string, notes: int}
     */
    public function remettre(Entreprise $entreprise): array
    {
        $remiseLe = new \DateTimeImmutable();
        $idCabinet = (int) $entreprise->getId();
        $nomCabinet = (string) $entreprise->getNom();
        @set_time_limit(0);

        $chemin = tempnam(sys_get_temp_dir(), 'archive-') ?: throw new \RuntimeException('Fichier temporaire impossible.');
        $temporaires = [];
        $reussi = false;
        try {
            $zip = new \ZipArchive();
            if ($zip->open($chemin, \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Archive impossible à créer.');
            }
            $empreintes = [];
            $ajouter = static function (string $nom, string $contenu) use ($zip, &$empreintes): void {
                $zip->addFromString($nom, $contenu);
                $empreintes[$nom] = hash('sha256', $contenu);
            };

            // LE JOURNAL D'ABORD, tant que le cabinet est en mémoire.
            $lignesJournal = [['Date', 'Pièce', 'Libellé', 'Compte', 'Intitulé du compte', 'Débit', 'Crédit']];
            foreach ($this->journal->ecritures($entreprise) as $ecriture) {
                foreach ($ecriture['lignes'] as $ligne) {
                    $lignesJournal[] = [
                        $ecriture['date']?->format('Y-m-d'), (string) $ecriture['piece'], (string) $ecriture['libelle'],
                        (string) $ligne['compte'], (string) $ligne['libelle'], self::montant((float) $ligne['debit']), self::montant((float) $ligne['credit']),
                    ];
                }
            }

            $ids = $this->em->getConnection()->fetchFirstColumn(
                'SELECT id FROM note WHERE entreprise_id = ? AND (validated = 1 OR figee_le IS NOT NULL OR origine = ?) ORDER BY id',
                [$idCabinet, Note::ORIGINE_REPRISE],
            );
            $lignesNotes = [['Référence', 'Type', 'Destinataire', 'Objet', 'Émise le', 'Origine', 'HT', 'Taxe', 'TTC', 'Payé']];
            $lignesArticles = [['Note', 'Revenu', 'Échéance', 'Quantité', 'HT', 'TTC']];
            $lignesPaiements = [['Note', 'Payé le', 'Montant', 'Référence']];
            $noms = [];
            foreach ($ids as $id) {
                /** @var Note $note */
                $note = $this->em->find(Note::class, (int) $id);
                $nom = self::nomDansLArchive($note, $noms);
                $pdf = tempnam(sys_get_temp_dir(), 'note-') ?: throw new \RuntimeException('Fichier temporaire impossible.');
                $temporaires[] = $pdf;
                file_put_contents($pdf, $this->notePdf->rendre($note));
                $zip->addFile($pdf, 'notes/' . $nom);
                $empreintes['notes/' . $nom] = hash_file('sha256', $pdf);

                $ttc = $this->calcul->getNoteMontantTotal($note);
                $ht = $note->getArticles()->isEmpty() && $note->estFigee() ? (float) $note->getMontantHtFige() : $this->calcul->getNoteMontantHT($note);
                $lignesNotes[] = [
                    $note->getReference(),
                    $note->getType() === Note::TYPE_NOTE_DE_CREDIT ? 'Crédit' : 'Débit',
                    (string) $this->calcul->getNoteAddressedToString($note),
                    (string) $note->getNom(),
                    ($note->getEmiseLe() ?? $note->getSentAt() ?? $note->getCreatedAt())?->format('Y-m-d H:i:s'),
                    $note->estDeReprise() ? 'Reprise' : 'Joseara',
                    self::montant($ht), self::montant($ttc - $ht), self::montant($ttc),
                    self::montant($this->calcul->getNoteMontantPaye($note)),
                ];
                foreach ($note->getArticles() as $article) {
                    $lignesArticles[] = [
                        $note->getReference(),
                        (string) $article->getRevenuFacture()?->getNom(),
                        (string) $article->getTranche()?->getNom(),
                        (string) $article->getQuantite(),
                        self::montant($this->calcul->getArticleMontantHT($article)),
                        self::montant($this->calcul->getArticleMontant($article)),
                    ];
                }
                foreach ($note->getPaiements() as $paiement) {
                    $lignesPaiements[] = [$note->getReference(), $paiement->getPaidAt()?->format('Y-m-d'), self::montant((float) $paiement->getMontant()), (string) $paiement->getReference()];
                }
                // Le graphe de cette note ne sert plus : la mémoire ne grossit pas avec le lot.
                $this->em->clear();
                $this->calcul->reset();
            }

            $ajouter('notes.csv', self::csv($lignesNotes));
            $ajouter('lignes.csv', self::csv($lignesArticles));
            $ajouter('paiements.csv', self::csv($lignesPaiements));
            $ajouter('journal.csv', self::csv($lignesJournal));

            $manifeste = sprintf("Archive comptable du cabinet « %s » (#%d)\nRemise le %s\n%d note(s) émise(s) ou de reprise.\n"
                . "Pièces à conserver dix ans (Acte uniforme OHADA relatif au droit comptable).\n\nEmpreintes SHA-256 :\n",
                $nomCabinet, $idCabinet, $remiseLe->format('d/m/Y H:i:s'), count($ids));
            foreach ($empreintes as $fichier => $empreinte) {
                $manifeste .= $empreinte . '  ' . $fichier . "\n";
            }
            $zip->addFromString('MANIFESTE.txt', $manifeste);
            $zip->close();

            $empreinte = hash_file('sha256', $chemin);
            $this->em->find(Entreprise::class, $idCabinet)->enregistrerRemiseDeLArchive($remiseLe, $empreinte);
            $this->em->flush();
            $reussi = true;
        } finally {
            foreach ($temporaires as $fichier) {
                @unlink($fichier);
            }
            if (!$reussi) {
                @unlink($chemin);
            }
        }

        return [
            'chemin' => $chemin,
            'nom' => sprintf('archive-comptable-%s-%s.zip', trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $nomCabinet), '-'), $remiseLe->format('Y-m-d')),
            'empreinte' => $empreinte,
            'notes' => count($ids),
        ];
    }

    /**
     * Le nom du PDF dans l'archive : la référence, réduite aux caractères qu'acceptent tous
     * les systèmes (une référence de reprise est une saisie libre) ; unique dans l'archive.
     *
     * @param array<string, true> $dejaPris
     */
    private static function nomDansLArchive(Note $note, array &$dejaPris): string
    {
        $base = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $note->getReference()), '._') ?: 'note';
        $nom = 'note-' . $base . '.pdf';
        if (isset($dejaPris[mb_strtolower($nom)])) {
            $nom = sprintf('note-%s-%d.pdf', $base, $note->getId());
        }
        $dejaPris[mb_strtolower($nom)] = true;

        return $nom;
    }

    private static function montant(float $valeur): string
    {
        return number_format(round($valeur, 2), 2, '.', '');
    }

    /** @param list<list<string|null>> $lignes */
    private static function csv(array $lignes): string
    {
        $flux = fopen('php://temp', 'r+');
        fwrite($flux, "\xEF\xBB\xBF"); // BOM : un tableur lit les accents
        foreach ($lignes as $ligne) {
            fputcsv($flux, array_map(self::cellule(...), $ligne), ';');
        }
        rewind($flux);

        return (string) stream_get_contents($flux);
    }

    /**
     * ⚠ UNE SAISIE NE DEVIENT PAS UNE FORMULE. Un objet de note, un nom de client, une
     * référence de paiement commençant par `=`, `+`, `-` ou `@` s'exécuterait dans le
     * tableur de qui ouvre l'archive. Une apostrophe en tête le neutralise. Les montants,
     * eux (négatifs compris), restent des nombres.
     */
    private static function cellule(mixed $valeur): string
    {
        $texte = (string) $valeur;
        if ($texte !== '' && !is_numeric($texte) && preg_match('/^[=+\-@\t\r]/', $texte)) {
            return "'" . $texte;
        }

        return $texte;
    }
}
