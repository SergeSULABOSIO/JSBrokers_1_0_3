<?php

namespace App\Service\Note;

use App\Comptabilite\CourtierEcritureComptableService;
use App\Entity\DepenseCourtier;
use App\Entity\Document;
use App\Entity\Entreprise;
use App\Entity\Note;
use App\Entity\Paiement;
use App\Entity\PaiementPrime;
use App\Entity\ReversementRetroAgent;
use App\Service\Document\DocumentFichier;
use App\Services\Canvas\Indicator\IndicatorCalculationHelper;
use Doctrine\ORM\EntityManagerInterface;

/**
 * L'ARCHIVE COMPTABLE D'UN CABINET — ce que le cabinet emporte avant que la base n'efface
 * ses pièces (conservation dix ans, règle OHADA ; décisions de Serge du 2026-10-15 et du
 * 2026-10-16).
 *
 * Un ZIP qui se suffit à lui-même :
 *  • `notes/` — le PDF de chaque note émise (validée) ou de reprise, par le rendu même
 *    du téléchargement ({@see NotePdf}) ;
 *  • un CSV par nature de pièce — `notes.csv`, `lignes.csv`, `paiements.csv` (notes et
 *    indemnités), `paiements-de-prime.csv`, `depenses.csv`, `reversements.csv` —, montants
 *    FIGÉS pour les notes ;
 *  • `justificatifs/<nature>/<id de la ligne>-<nom>` — les fichiers joints à chaque pièce ;
 *    la colonne « Justificatifs » du CSV renvoie à leur chemin ;
 *  • `journal.csv` — toutes les écritures du cabinet (capital compris) ;
 *  • `MANIFESTE.txt` — le contenu, le décompte des pièces, et l'empreinte SHA-256 de
 *    chaque fichier.
 *
 * La remise est ENREGISTRÉE sur le cabinet (date, empreinte de l'archive) : c'est elle
 * qui rend la suppression possible ({@see ConservationDesPieces}).
 *
 * ⚠ LA DATE DE REMISE EST PRISE AVANT DE LIRE LES PIÈCES : une pièce enregistrée pendant
 * la construction laisse l'archive « pas à jour ».
 *
 * ⚠ UN HÉBERGEMENT MUTUALISÉ : chaque PDF passe par un fichier temporaire, les
 * justificatifs sont ajoutés depuis leur fichier (jamais lus en mémoire), et la mémoire de
 * Doctrine est vidée pièce par pièce. Les fichiers temporaires sont effacés quoi qu'il
 * arrive.
 */
final class ArchiveComptableDuCabinet
{
    private \ZipArchive $zip;
    /** @var array<string, string> chemin dans l'archive => empreinte */
    private array $empreintes = [];
    /** @var array<string, true> */
    private array $nomsPris = [];
    private int $idCabinet = 0;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NotePdf $notePdf,
        private readonly IndicatorCalculationHelper $calcul,
        private readonly CourtierEcritureComptableService $journal,
        private readonly DocumentFichier $documentFichier,
        private readonly ConservationDesPieces $conservation,
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
        $this->idCabinet = $idCabinet;
        $nomCabinet = (string) $entreprise->getNom();
        $decompte = $this->conservation->decompteEnClair($this->conservation->piecesAConserver($entreprise)) ?: 'aucune pièce';
        @set_time_limit(0);

        $chemin = tempnam(sys_get_temp_dir(), 'archive-') ?: throw new \RuntimeException('Fichier temporaire impossible.');
        $temporaires = [];
        $reussi = false;
        $this->empreintes = [];
        $this->nomsPris = [];
        try {
            $this->zip = new \ZipArchive();
            if ($this->zip->open($chemin, \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Archive impossible à créer.');
            }

            // LE JOURNAL D'ABORD, tant que le cabinet est en mémoire.
            $journal = [['Date', 'Pièce', 'Libellé', 'Compte', 'Intitulé du compte', 'Débit', 'Crédit']];
            foreach ($this->journal->ecritures($entreprise) as $ecriture) {
                foreach ($ecriture['lignes'] as $ligne) {
                    $journal[] = [
                        $ecriture['date']?->format('Y-m-d'), (string) $ecriture['piece'], (string) $ecriture['libelle'],
                        (string) $ligne['compte'], (string) $ligne['libelle'], self::montant((float) $ligne['debit']), self::montant((float) $ligne['credit']),
                    ];
                }
            }

            // LES NOTES : leur PDF, leurs lignes, leurs justificatifs.
            $idsNotes = $this->ids('SELECT id FROM note WHERE entreprise_id = ? AND (validated = 1 OR figee_le IS NOT NULL OR origine = ?) ORDER BY id', [$idCabinet, Note::ORIGINE_REPRISE]);
            $notes = [['Id', 'Référence', 'Type', 'Destinataire', 'Objet', 'Émise le', 'Origine', 'HT', 'Taxe', 'TTC', 'Payé', 'Justificatifs']];
            $lignes = [['Note', 'Revenu', 'Échéance', 'Quantité', 'HT', 'TTC']];
            foreach ($idsNotes as $id) {
                /** @var Note $note */
                $note = $this->em->find(Note::class, $id);
                $pdf = tempnam(sys_get_temp_dir(), 'note-') ?: throw new \RuntimeException('Fichier temporaire impossible.');
                $temporaires[] = $pdf;
                file_put_contents($pdf, $this->notePdf->rendre($note));
                $this->ajouterFichier('notes/' . $this->nomUnique('note-' . self::nomSur((string) $note->getReference(), 'note') . '.pdf', 'note-' . $note->getId() . '.pdf'), $pdf);

                $ttc = $this->calcul->getNoteMontantTotal($note);
                $ht = $note->getArticles()->isEmpty() && $note->estFigee() ? (float) $note->getMontantHtFige() : $this->calcul->getNoteMontantHT($note);
                $notes[] = [
                    $note->getId(), $note->getReference(),
                    $note->getType() === Note::TYPE_NOTE_DE_CREDIT ? 'Crédit' : 'Débit',
                    (string) $this->calcul->getNoteAddressedToString($note),
                    (string) $note->getNom(),
                    ($note->getEmiseLe() ?? $note->getSentAt() ?? $note->getCreatedAt())?->format('Y-m-d H:i:s'),
                    $note->estDeReprise() ? 'Reprise' : 'Joseara',
                    self::montant($ht), self::montant($ttc - $ht), self::montant($ttc),
                    self::montant($this->calcul->getNoteMontantPaye($note)),
                    $this->joindre('notes', (int) $note->getId(), $note->getDocuments()),
                ];
                foreach ($note->getArticles() as $article) {
                    $lignes[] = [
                        $note->getReference(),
                        (string) $article->getRevenuFacture()?->getNom(),
                        (string) $article->getTranche()?->getNom(),
                        (string) $article->getQuantite(),
                        self::montant($this->calcul->getArticleMontantHT($article)),
                        self::montant($this->calcul->getArticleMontant($article)),
                    ];
                }
                $this->liberer();
            }

            // LES AUTRES PIÈCES, chacune avec ses justificatifs.
            $paiements = [['Id', 'Note', 'Indemnité (offre)', 'Payé le', 'Montant', 'Référence', 'Justificatifs']];
            foreach ($this->ids('SELECT id FROM paiement WHERE entreprise_id = ? ORDER BY id', [$idCabinet]) as $id) {
                /** @var Paiement $p */
                $p = $this->em->find(Paiement::class, $id);
                $paiements[] = [
                    $p->getId(), (string) $p->getNote()?->getReference(), (string) $p->getOffreIndemnisationSinistre()?->getId(),
                    $p->getPaidAt()?->format('Y-m-d'), self::montant((float) $p->getMontant()), (string) $p->getReference(),
                    $this->joindre('paiements', (int) $p->getId(), $p->getPreuves()),
                ];
                $this->liberer();
            }

            $primes = [['Id', 'Échéance', 'Police', 'Payé le', 'Montant', 'Référence', 'Justificatifs']];
            foreach ($this->ids('SELECT id FROM paiement_prime WHERE entreprise_id = ? ORDER BY id', [$idCabinet]) as $id) {
                /** @var PaiementPrime $pp */
                $pp = $this->em->find(PaiementPrime::class, $id);
                $avenant = $pp->getTranche()?->getCotation()?->getAvenants()->first() ?: null;
                $primes[] = [
                    $pp->getId(), (string) $pp->getTranche()?->getNom(), (string) $avenant?->getReferencePolice(),
                    $pp->getPaidAt()?->format('Y-m-d'), self::montant((float) $pp->getMontant()), (string) $pp->getReference(),
                    $this->joindre('paiements-de-prime', (int) $pp->getId(), $pp->getPreuves()),
                ];
                $this->liberer();
            }

            $depenses = [['Id', 'Date', 'Charge', 'Tiers', 'Montant TTC', 'TVA (%)', 'Statut', 'Référence', 'Justificatifs']];
            foreach ($this->ids('SELECT id FROM depense_courtier WHERE entreprise_id = ? ORDER BY id', [$idCabinet]) as $id) {
                /** @var DepenseCourtier $d */
                $d = $this->em->find(DepenseCourtier::class, $id);
                $depenses[] = [
                    $d->getId(), $d->getDateDepense()?->format('Y-m-d'), (string) $d->getCharge()?->getLibelle(), (string) $d->getTiersLibelle(),
                    self::montant($d->getMontantFloat()), (string) $d->getTauxTva(), $d->getStatutLabel(), (string) $d->getReference(),
                    $this->joindre('depenses', (int) $d->getId(), $d->getDocuments()),
                ];
                $this->liberer();
            }

            $reversements = [['Id', 'Bénéficiaire', 'Police', 'Échéance', 'Payé le', 'Montant', 'Référence', 'Lot', 'Justificatifs']];
            foreach ($this->ids('SELECT id FROM reversement_retro_agent WHERE entreprise_id = ? ORDER BY id', [$idCabinet]) as $id) {
                /** @var ReversementRetroAgent $r */
                $r = $this->em->find(ReversementRetroAgent::class, $id);
                $reversements[] = [
                    $r->getId(), (string) $r->getBeneficiaire()?->getNom(), (string) $r->getAvenant()?->getReferencePolice(),
                    (string) $r->getTranche()?->getNom(), $r->getPaidAt()?->format('Y-m-d'), self::montant((float) $r->getMontant()),
                    (string) $r->getReference(), (string) $r->getLotReference(),
                    $this->joindre('reversements', (int) $r->getId(), $r->getDocuments()),
                ];
                $this->liberer();
            }

            foreach (['notes.csv' => $notes, 'lignes.csv' => $lignes, 'paiements.csv' => $paiements, 'paiements-de-prime.csv' => $primes,
                'depenses.csv' => $depenses, 'reversements.csv' => $reversements, 'journal.csv' => $journal] as $nom => $contenu) {
                $csv = self::csv($contenu);
                $this->zip->addFromString($nom, $csv);
                $this->empreintes[$nom] = hash('sha256', $csv);
            }

            $manifeste = sprintf("Archive comptable du cabinet « %s » (#%d)\nRemise le %s\nPièces : %s.\n"
                . "Pièces à conserver dix ans (Acte uniforme OHADA relatif au droit comptable).\n\nEmpreintes SHA-256 :\n",
                $nomCabinet, $idCabinet, $remiseLe->format('d/m/Y H:i:s'), $decompte);
            foreach ($this->empreintes as $fichier => $empreinte) {
                $manifeste .= $empreinte . '  ' . $fichier . "\n";
            }
            $this->zip->addFromString('MANIFESTE.txt', $manifeste);
            // ⚠ LA FERMETURE EST LE MOMENT OÙ TOUT S'ÉCRIT : libzip n'ouvre les fichiers ajoutés
            // qu'ici. Un justificatif supprimé entre-temps, un disque plein — et le ZIP sort
            // VIDE, sans exception (avertissement seulement, en production). Une archive vide
            // remise rendrait le cabinet supprimable : on échoue, rien n'est enregistré.
            if ($this->zip->close() !== true || !is_file($chemin) || filesize($chemin) === 0) {
                throw new \RuntimeException('L’archive comptable n’a pas pu être écrite (un justificatif a disparu pendant sa construction, ou le disque est plein). Rien n’a été remis : réessayez.');
            }

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
            'notes' => count($idsNotes),
        ];
    }

    /** @return list<int> */
    private function ids(string $sql, array $parametres): array
    {
        return array_map('intval', $this->em->getConnection()->fetchFirstColumn($sql, $parametres));
    }

    /** Le graphe d'une pièce ne sert plus : la mémoire ne grossit pas avec le lot. */
    private function liberer(): void
    {
        $this->em->clear();
        $this->calcul->reset();
    }

    /**
     * Range les justificatifs d'une pièce sous `justificatifs/<nature>/<id>-<nom>` et rend
     * leurs chemins (séparés par « | ») pour la colonne du CSV. Un fichier absent du disque
     * est signalé, pas inventé.
     *
     * @param iterable<Document> $documents
     */
    private function joindre(string $nature, int $idLigne, iterable $documents): string
    {
        $chemins = [];
        foreach ($documents as $document) {
            // Seuls les documents DU CABINET sortent : un document d'un autre cabinet,
            // rattaché par erreur à une pièce d'ici, ne fuit pas par l'archive.
            if ($document->getEntreprise()?->getId() !== $this->idCabinet) {
                continue;
            }
            $nom = self::nomSur($this->documentFichier->nomDeTelechargement($document), 'document');
            $cible = 'justificatifs/' . $nature . '/' . $this->nomUnique($idLigne . '-' . $nom, $idLigne . '-' . $document->getId() . '-' . $nom);
            $source = $this->documentFichier->existe($document) ? $this->documentFichier->chemin($document) : null;
            if ($source === null || !is_file($source)) {
                $chemins[] = $cible . ' (fichier absent du serveur)';
                continue;
            }
            $this->ajouterFichier($cible, $source);
            $chemins[] = $cible;
        }

        return implode(' | ', $chemins);
    }

    private function ajouterFichier(string $cible, string $source): void
    {
        // Un fichier illisible ne donne pas une empreinte vide au manifeste : il fait échouer
        // la remise, qui n'est pas enregistrée.
        $empreinte = hash_file('sha256', $source);
        if ($empreinte === false) {
            throw new \RuntimeException(sprintf('Le fichier « %s » est illisible : l’archive n’est pas remise.', $cible));
        }
        $this->zip->addFile($source, $cible);
        $this->empreintes[$cible] = $empreinte;
    }

    /**
     * Un nom libre au premier venu ; s'il est pris (casse confondue), le repli — et s'il
     * l'est aussi, un suffixe numéroté. Deux fichiers ne s'écrasent jamais dans le ZIP.
     */
    private function nomUnique(string $nom, string $repli): string
    {
        $retenu = isset($this->nomsPris[mb_strtolower($nom)]) ? $repli : $nom;
        $base = $retenu;
        for ($i = 2; isset($this->nomsPris[mb_strtolower($retenu)]); ++$i) {
            $point = strrpos($base, '.');
            $retenu = $point === false ? $base . '-' . $i : substr($base, 0, $point) . '-' . $i . substr($base, $point);
        }
        $this->nomsPris[mb_strtolower($retenu)] = true;

        return $retenu;
    }

    /** Réduit un nom aux caractères qu'acceptent tous les systèmes (une saisie est libre). */
    private static function nomSur(string $nom, string $defaut): string
    {
        return trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '_', $nom), '._') ?: $defaut;
    }

    private static function montant(float $valeur): string
    {
        return number_format(round($valeur, 2), 2, '.', '');
    }

    /** @param list<list<string|int|null>> $lignes */
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
