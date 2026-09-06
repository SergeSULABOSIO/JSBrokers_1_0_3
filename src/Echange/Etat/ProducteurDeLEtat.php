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
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use Psr\Log\LoggerInterface;
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
    /**
     * Le tableau croisé est-il posé dans le classeur ?
     *
     * ⚠ À FALSE TANT QU'AUCUN TABLEUR NE PEUT VALIDER LE RÉSULTAT ICI. Voir
     * `ecrireSur()` pour l'incident : Excel a fini par planter au démarrage.
     *
     * ⚠ ET LA REPASSER À TRUE NE SUFFIRA PAS. La feuille SYNTHESE n'est plus vide : elle
     * porte désormais la synthèse PAR FORMULES d'`EcrivainEtat::ecrireSynthese()`. Le
     * croisement s'y poserait par-dessus. Le jour venu, il faudra choisir — l'un ou
     * l'autre —, pas empiler les deux.
     */
    private const CROISEMENT_ACTIF = false;

    public function __construct(
        private readonly EtatDuPortefeuille $etat,
        private readonly EcrivainEtat $ecrivain,
        private readonly CompteurDOccurrences $compteur,
        private readonly EntityManagerInterface $em,
        private readonly VersionService $version,
        private readonly InjecteurDeTcd $injecteur,
        private readonly LoggerInterface $logger,
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
        ?string $validite = null,
        string $exercice = ExerciceDesTranches::TOUS,
        ?string $graineIdempotence = null,
        ?Progression $progression = null,
    ): Response {
        // ── LE COÛT SE CONTRÔLE AVANT DE PRODUIRE QUOI QUE CE SOIT ──────────────────
        $this->compteur->verifierSolvabilite($entreprise, EchangeOccurrence::TYPE_EXPORT);

        [$classeur, $manifeste, $total] = $this->produire($entreprise, $invite, $acteur, $colonnesRetenues, $validite, $exercice, $progression);
        $nomFichier = $this->nomFichier(
            $entreprise,
            ValiditeDesTranches::normaliser($validite),
            ExerciceDesTranches::normaliser($exercice, $this->etat->exercices($entreprise)),
        );

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
     * @param string[]    $colonnesRetenues codes des colonnes demandées ; vide = toutes
     * @param string|null $validite         statut de souscription retenu ; null = toutes
     *
     * @return array{0: Spreadsheet, 1: Manifeste, 2: int}
     */
    public function produire(
        Entreprise $entreprise,
        Invite $invite,
        ?Utilisateur $acteur,
        array $colonnesRetenues = [],
        ?string $validite = null,
        string $exercice = ExerciceDesTranches::TOUS,
        ?Progression $progression = null,
    ): array {
        $progression ??= Progression::muette();

        // ⚠ ON COMPTE AVANT DE COMMENCER. Un pourcentage suppose un dénominateur ; sans
        // ce pré-comptage, la barre ne saurait annoncer qu'un nombre de lignes écrites,
        // ce qui ne dit rien du temps restant. C'est un COUNT, donc quelques
        // millisecondes pour un reste crédible.
        $progression->etape('Inventaire des tranches');
        $exercice = ExerciceDesTranches::normaliser($exercice, $this->etat->exercices($entreprise));
        $total = $this->etat->compterLignes($entreprise, $validite, $exercice);
        $progression->totaliser($total);

        $colonnes = $this->etat->colonnes($entreprise, $colonnesRetenues);

        $progression->etape('Lecture du portefeuille');
        $lignes = iterator_to_array($this->etat->lignes($entreprise, $progression, $validite, $exercice), false);

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

        return [
            $this->ecrivain->ecrire(
                $manifeste,
                $colonnes,
                $lignes,
                ValiditeDesTranches::normaliser($validite),
                $exercice,
            ),
            $manifeste,
            $total,
        ];
    }

    /**
     * ÉCRIT LE CLASSEUR SUR DISQUE, synthèse comprise.
     *
     * ⚠ IL PASSE PAR UN FICHIER, et il le faut : un tableau croisé n'est pas écrit par
     * PhpSpreadsheet mais INJECTÉ dans le zip une fois celui-ci scellé. On ne peut pas
     * injecter dans un flux qu'on est en train d'émettre.
     *
     * ⚠ L'INJECTION NE DOIT JAMAIS FAIRE ÉCHOUER L'EXPORT. Si elle achoppe, on rend le
     * classeur SANS sa synthèse plutôt que rien du tout : perdre une feuille de confort
     * est un désagrément, perdre l'état entier est une panne.
     *
     * ⚠ PUBLIC À DESSEIN : la commande de diagnostic emprunte EXACTEMENT ce chemin. Lui
     * en faire recomposer un second — sauver ici, injecter là — reviendrait à vérifier une
     * mécanique que personne n'exécute en production.
     */
    public function ecrireSur(Spreadsheet $classeur, string $chemin): void
    {
        (new Xlsx($classeur))->save($chemin);

        // ⚠ L'INJECTION DU TABLEAU CROISÉ EST COUPÉE — 06/09/2026.
        //
        // Deux tentatives, deux échecs chez l'utilisateur : d'abord « Nous avons trouvé
        // un problème dans le contenu », puis un PLANTAGE d'Excel au démarrage suivant.
        // Le second est inacceptable : un export ne doit pas faire tomber le tableur de
        // celui qui l'ouvre.
        //
        // ⚠ LA LEÇON N'EST PAS SUR LE XML. Ce poste n'a ni Excel ni LibreOffice
        // pilotable : les dix-sept contrôles de `app:etat:smoke` disent que le paquet
        // est cohérent — ils ne disent PAS qu'Excel l'accepte. Livrer une pièce dont le
        // seul juge est chez l'utilisateur, c'est lui faire porter la vérification ;
        // deux fois de suite, c'est lui faire perdre son temps.
        //
        // `InjecteurDeTcd` et ses tests restent en place : le jour où un tableur pourra
        // valider le résultat ici, il suffira de repasser cette constante à true.
        if (self::CROISEMENT_ACTIF) {
            try {
                $this->poserLaSynthese($classeur, $chemin);
            } catch (\Throwable $e) {
                $this->logger->warning('État du portefeuille : la synthèse n\'a pas pu être posée.', [
                    'erreur' => $e->getMessage(),
                ]);
            }
        }
    }

    private function reponse(Spreadsheet $classeur, string $nom): Response
    {
        $chemin = (string) tempnam(sys_get_temp_dir(), 'jsbx_etat_');
        $this->ecrireSur($classeur, $chemin);

        $reponse = new StreamedResponse(static function () use ($chemin): void {
            readfile($chemin);
            @unlink($chemin);
        });
        $reponse->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $reponse->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $nom));
        $reponse->headers->set('Cache-Control', 'no-store, private');

        return $reponse;
    }

    /**
     * Pose le tableau croisé sur la feuille SYNTHESE du classeur déjà écrit.
     *
     * ⚠ LES INDEX DE COLONNES SE LISENT SUR LE CLASSEUR, jamais sur le catalogue : c'est
     * la position RÉELLE dans la feuille qui compte, et l'utilisateur a pu retirer des
     * colonnes. Un index deviné sur le catalogue complet sommerait la mauvaise colonne
     * dès le premier export restreint — et le total serait plausible.
     */
    private function poserLaSynthese(Spreadsheet $classeur, string $chemin): void
    {
        $donnees = $classeur->getSheetByName(EtatDuPortefeuille::FEUILLE);
        $synthese = $classeur->getSheetByName(InjecteurDeTcd::FEUILLE);
        if ($donnees === null || $synthese === null) {
            return;
        }

        $entetes = [];
        $derniere = Coordinate::columnIndexFromString($donnees->getHighestDataColumn());
        for ($i = 1; $i <= $derniere; ++$i) {
            $entetes[] = (string) $donnees->getCell(Coordinate::stringFromColumnIndex($i) . '1')->getValue();
        }

        $index = static function (string $libelle) use ($entetes): ?int {
            $trouve = array_search($libelle, $entetes, true);

            return $trouve === false ? null : (int) $trouve;
        };

        // Les axes du croisement, dans l'ordre de la capture : le mois, puis l'assureur.
        $lignes = array_values(array_filter([
            $index('Police · Mois d\'effet'),
            $index('Assureur'),
        ], static fn (?int $i) => $i !== null));

        $valeurs = array_values(array_filter([
            $index('Prime · Totale'),
            $index('Prime · Payée'),
            $index('Prime · Solde'),
            $index('Commission · TTC'),
            $index('Commission · Encaissée'),
            $index('Commission · Solde'),
            $index('Commission · Exigible'),
        ], static fn (?int $i) => $i !== null));

        // Sans axe ni valeur, un croisement n'a rien à montrer : on s'abstient plutôt que
        // de produire une coquille qu'Excel refuserait.
        if ($lignes === [] || $valeurs === []) {
            return;
        }

        $this->injecteur->injecter(
            $chemin,
            EtatDuPortefeuille::FEUILLE,
            sprintf(
                'A1:%s%d',
                Coordinate::stringFromColumnIndex($derniere),
                // ⚠ LA LIGNE DE TOTAUX EST HORS SOURCE : la laisser dedans ferait compter
                // une seconde fois chaque montant, et le croisement afficherait le double.
                max(2, $donnees->getHighestDataRow() - 1),
            ),
            $classeur->getIndex($synthese) + 1,
            $lignes,
            $valeurs,
            $entetes,
        );
    }

    /** Le nom dit ce que le fichier EST : un état, pas un fichier d'échange. */
    private function nomFichier(Entreprise $entreprise, string $validite, string $exercice): string
    {
        $slug = preg_replace('/[^A-Za-z0-9_-]+/', '_', $entreprise->getNom() ?? 'cabinet');

        return sprintf(
            'jsbrokers_etat_%s%s_%s.xlsx',
            trim((string) $slug, '_') ?: 'cabinet',
            // Deux états posés côte à côte sur un bureau — l'un des polices, l'autre des
            // projets — ne se distingueraient que par l'heure de génération.
            ($validite === ValiditeDesTranches::TOUTES ? '' : '_' . $validite)
                . ($exercice === ExerciceDesTranches::TOUS ? '' : '_' . $exercice),
            date('Ymd-Hi'),
        );
    }

    private function signature(Invite $invite, ?Utilisateur $acteur): string
    {
        $nom = $invite->getNom() ?: ($acteur?->getEmail() ?? 'inconnu');

        return sprintf('%s (#%d)', $nom, $invite->getId() ?? 0);
    }
}
