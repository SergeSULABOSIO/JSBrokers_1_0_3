<?php

namespace App\Service\Conge;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * EXPORT DES COMPTEURS — le classeur qu'on envoie à la paie ou qu'on archive.
 *
 * ── XLSX ET NON CSV ─────────────────────────────────────────────────────────────────
 * La spécification dit « export CSV ». Le dépôt produit déjà tous ses exports en XLSX
 * (ComptaExportService, CrmReportController), avec PhpSpreadsheet déjà présent. Un CSV
 * aurait introduit un second format à maintenir, sans en-têtes lisibles ni totaux mis en
 * forme — et se serait ouvert de travers dans Excel dès le premier accent. On garde le
 * format de la maison.
 *
 * ── LES CHIFFRES SONT CEUX DE L'ÉCRAN ───────────────────────────────────────────────
 * La grille est passée telle quelle : ce service met en forme, il ne recalcule rien. Un
 * export qui referait la somme finirait par contredire la grille dont il est censé être
 * la copie.
 */
class CompteursExport
{
    private const COBALT = '0047AB';

    /**
     * @param array<string, mixed> $grille sortie de GrilleDesCompteurs::pour()
     */
    public function classeur(array $grille, string $cabinet): Response
    {
        $tableur = new Spreadsheet();
        $feuille = $tableur->getActiveSheet();
        $feuille->setTitle(sprintf('Compteurs %d', (int) $grille['exercice']));

        $feuille->fromArray(
            ['Collaborateur', 'Acquis', 'dont report N-1', 'Consommé', 'Engagé', 'Disponible', 'Alerte report'],
            null,
            'A1',
        );

        $ligne = 2;
        foreach ($grille['lignes'] as $entree) {
            $feuille->fromArray([
                $entree['agent'],
                $entree['acquis'],
                $entree['dontReport'],
                $entree['consomme'],
                $entree['engage'],
                $entree['disponible'],
                // « oui » / vide plutôt qu'une couleur : une alerte qui ne tiendrait qu'à
                // un fond de cellule disparaîtrait au premier tri ou copier-coller.
                $entree['alerte'] ? 'oui' : '',
            ], null, 'A' . $ligne);
            $ligne++;
        }

        // La ligne de totaux, séparée d'une ligne vide : collée aux données, elle se
        // ferait emporter par un tri et deviendrait une ligne de collaborateur.
        $ligne++;
        $feuille->fromArray([
            'TOTAL',
            $grille['totaux']['acquis'],
            '',
            $grille['totaux']['consomme'],
            $grille['totaux']['engage'],
            $grille['totaux']['disponible'],
            '',
        ], null, 'A' . $ligne);

        $this->habiller($feuille, $ligne);

        return $this->reponse($tableur, sprintf('Compteurs-conges-%s-%d', $this->assainir($cabinet), (int) $grille['exercice']));
    }

    private function habiller(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $feuille, int $ligneTotal): void
    {
        $feuille->getStyle('A1:G1')->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $feuille->getStyle('A1:G1')->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FF' . self::COBALT);
        $feuille->getStyle('A1:G1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $feuille->getStyle(sprintf('A%d:G%d', $ligneTotal, $ligneTotal))->getFont()->setBold(true);
        $feuille->getStyle(sprintf('B2:F%d', $ligneTotal))->getNumberFormat()->setFormatCode('0.0');

        foreach (range('A', 'G') as $colonne) {
            $feuille->getColumnDimension($colonne)->setAutoSize(true);
        }

        $feuille->freezePane('A2');
    }

    private function reponse(Spreadsheet $tableur, string $nom): Response
    {
        $reponse = new StreamedResponse(static function () use ($tableur): void {
            (new Xlsx($tableur))->save('php://output');
        });

        $reponse->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $reponse->headers->set(
            'Content-Disposition',
            HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $nom . '.xlsx'),
        );
        // Un export daté ne doit jamais être servi depuis un cache : le solde d'hier
        // ressemble trait pour trait à celui d'aujourd'hui, et rien ne le signalerait.
        $reponse->headers->set('Cache-Control', 'no-store, private');

        return $reponse;
    }

    /** Un nom de fichier ne doit contenir ni accent ni séparateur de chemin. */
    private function assainir(string $texte): string
    {
        $sans = preg_replace('/[^A-Za-z0-9]+/', '-', \App\Ai\AiText::normalize($texte)) ?? '';

        return trim($sans, '-') ?: 'cabinet';
    }
}
