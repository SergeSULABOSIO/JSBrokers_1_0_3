<?php

namespace App\Echange\Etat;

use App\Echange\Classeur\Manifeste;
use App\Echange\Service\CompteurDOccurrences;
use App\Echange\Service\Progression;
use App\Entity\EchangeOccurrence;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Utilisateur;
use App\Services\VersionService;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * PRODUIT ET FACTURE l'état du portefeuille.
 *
 * Il prend la place qu'occupait ExportateurJsbx sur la route d'export : mêmes règles de
 * solvabilité, même occurrence, même clé d'idempotence, même métrage. Ce qui change est
 * le CONTENU du fichier, pas ce qu'il coûte ni ce qu'il laisse comme trace.
 *
 * ⚠ SÉPARER « FABRIQUER » DE « FACTURER », comme ailleurs dans cette rubrique. La règle
 * n'est pas théorique : une commande de diagnostic qui empruntait le chemin complet a
 * décompté quarante-deux occurrences et débité 23 400 tokens au propriétaire d'un
 * cabinet réel, pour un contrôle que personne n'avait demandé. `produire()` ne facture
 * jamais ; `exporter()` seul le fait, et seulement en cas de succès.
 */
final class ProducteurDeLEtat
{
    public function __construct(
        private readonly EtatDuPortefeuille $etat,
        private readonly EcrivainEtat $ecrivain,
        private readonly CompteurDOccurrences $compteur,
        private readonly EntityManagerInterface $em,
        private readonly VersionService $version,
    ) {
    }

    /**
     * Produit l'état, l'enregistre et débite — dans cet ordre, et seulement si tout a
     * réussi. Un fichier qui n'a pas pu être écrit ne doit rien coûter.
     */
    public function exporter(
        Entreprise $entreprise,
        Invite $invite,
        ?Utilisateur $acteur,
        array $colonnesRetenues = [],
        ?string $graineIdempotence = null,
        ?Progression $progression = null,
    ): Response {
        // ── LE COÛT SE CONTRÔLE AVANT DE PRODUIRE QUOI QUE CE SOIT ──────────────────
        $this->compteur->verifierSolvabilite($entreprise, EchangeOccurrence::TYPE_EXPORT);

        [$classeur, $manifeste, $total] = $this->produire($entreprise, $invite, $acteur, $colonnesRetenues, $progression);
        $nomFichier = $this->nomFichier($entreprise);

        // Le périmètre d'un état est fixe : il n'a qu'une maille, la tranche. On le note
        // tel quel dans l'occurrence, pour que l'historique distingue un état d'un export
        // d'échange sans avoir à ouvrir le fichier.
        $perimetre = ['Tranche'];

        $cle = $this->compteur->cleIdempotence(
            $entreprise,
            $invite,
            EchangeOccurrence::TYPE_EXPORT,
            $perimetre,
            $graineIdempotence,
        );

        $this->em->wrapInTransaction(function () use ($entreprise, $invite, $acteur, $perimetre, $total, $cle, $manifeste, $nomFichier): void {
            $this->compteur->enregistrer(
                $entreprise,
                $invite,
                $acteur,
                EchangeOccurrence::TYPE_EXPORT,
                $perimetre,
                $total,
                $cle,
                $manifeste->empreinteEntetes,
                $nomFichier,
            );
            $this->em->flush();
        });

        return $this->reponse($classeur, $nomFichier);
    }

    /**
     * FABRIQUE le classeur, et rien d'autre : ni contrôle de solde, ni occurrence, ni
     * débit. C'est le chemin des outils de diagnostic.
     *
     * @param string[] $colonnesRetenues codes des colonnes demandées ; vide = toutes
     *
     * @return array{0: Spreadsheet, 1: Manifeste, 2: int}
     */
    public function produire(
        Entreprise $entreprise,
        Invite $invite,
        ?Utilisateur $acteur,
        array $colonnesRetenues = [],
        ?Progression $progression = null,
    ): array {
        $progression ??= Progression::muette();

        // ⚠ ON COMPTE AVANT DE COMMENCER. Un pourcentage suppose un dénominateur ; sans
        // ce pré-comptage, la barre ne saurait annoncer qu'un nombre de lignes écrites,
        // ce qui ne dit rien du temps restant. C'est un COUNT, donc quelques
        // millisecondes pour un reste crédible.
        $progression->etape('Inventaire des tranches');
        $total = $this->etat->compterLignes($entreprise);
        $progression->totaliser($total);

        $colonnes = $this->etat->colonnes($entreprise, $colonnesRetenues);

        $progression->etape('Lecture du portefeuille');
        $lignes = iterator_to_array($this->etat->lignes($entreprise, $progression), false);

        // L'écriture elle-même n'est pas instantanée : on le dit, plutôt que de laisser la
        // barre à 100 % pendant que le fichier se compresse.
        $progression->etape('Mise en forme du classeur');

        $manifeste = new Manifeste(
            uidCabinet: (string) $entreprise->getId(),
            nomCabinet: $entreprise->getNom() ?? '',
            genereLe: new \DateTimeImmutable('now'),
            generePar: $this->signature($invite, $acteur),
            versionSchema: $this->version->getVersion(),
            perimetre: array_keys($colonnes),
            // L'empreinte identifie la STRUCTURE produite. Ici elle porte sur les colonnes
            // de l'état : deux états de la même version ont la même, et un fichier retaillé
            // à la main se reconnaît.
            empreinteEntetes: hash('sha256', implode('|', array_keys($colonnes))),
        );

        return [$this->ecrivain->ecrire($manifeste, $colonnes, $lignes), $manifeste, $total];
    }

    private function reponse(Spreadsheet $classeur, string $nom): Response
    {
        $reponse = new StreamedResponse(static function () use ($classeur): void {
            (new Xlsx($classeur))->save('php://output');
        });
        $reponse->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $reponse->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $nom));
        $reponse->headers->set('Cache-Control', 'no-store, private');

        return $reponse;
    }

    /** Le nom dit ce que le fichier EST : un état, pas un fichier d'échange. */
    private function nomFichier(Entreprise $entreprise): string
    {
        $slug = preg_replace('/[^A-Za-z0-9_-]+/', '_', $entreprise->getNom() ?? 'cabinet');

        return sprintf('jsbrokers_etat_%s_%s.xlsx', trim((string) $slug, '_') ?: 'cabinet', date('Ymd-Hi'));
    }

    private function signature(Invite $invite, ?Utilisateur $acteur): string
    {
        $nom = $invite->getNom() ?: ($acteur?->getEmail() ?? 'inconnu');

        return sprintf('%s (#%d)', $nom, $invite->getId() ?? 0);
    }
}
