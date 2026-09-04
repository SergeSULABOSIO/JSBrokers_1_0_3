<?php

namespace App\Echange\Service;

use App\Echange\Canevas\CanevasDEchange;
use App\Echange\Canevas\RessourceDEchange;
use App\Echange\Classeur\EcrivainJsbx;
use App\Echange\Classeur\Manifeste;
use App\Entity\EchangeOccurrence;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Utilisateur;
use App\Services\VersionService;
use App\Token\InsufficientTokensException;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ORCHESTRE une exportation, dans un ordre qui n'est pas négociable :
 *
 *   droits → périmètre → COÛT → génération → occurrence et débit → fichier
 *
 * Le contrôle du solde vient AVANT la génération, jamais après. Produire un classeur
 * de quarante feuilles pour découvrir ensuite qu'on ne peut pas le facturer, ce serait
 * faire attendre l'utilisateur pour rien et laisser exister un fichier sans
 * contrepartie. L'occurrence et le débit, eux, viennent APRÈS et dans la même
 * transaction : on ne compte que ce qui a réellement abouti.
 *
 * Le périmètre proposé est celui des droits de l'invité, ressource par ressource. Sans
 * ce filtrage, la rubrique deviendrait un contournement propre de toute la matrice
 * d'accès : un collaborateur au périmètre restreint extrairait le cabinet entier.
 */
final class ExportateurJsbx
{
    /**
     * Taille des lots de lecture. La mémoire, ici, est le facteur limitant : la suite
     * de tests plafonne déjà à 2 Go avec un historique de plantages dans la
     * compression du classeur. On lit donc par paquets, en vidant l'unité de travail
     * entre deux.
     */
    private const LOT = 500;

    public function __construct(
        private readonly CanevasDEchange $canevas,
        private readonly EcrivainJsbx $ecrivain,
        private readonly LigneExportable $traducteur,
        private readonly CompteurDOccurrences $compteur,
        private readonly VersionService $version,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Périmètre réellement exportable par cet invité, dépendances comprises.
     *
     * @param string[] $codesDemandes vide = tout ce qui est lisible
     *
     * @return array<string, RessourceDEchange> en ordre topologique
     */
    public function perimetre(Invite $invite, array $codesDemandes = []): array
    {
        $lisibles = $this->canevas->ressourcesLisibles($invite);
        if ($codesDemandes === []) {
            return $lisibles;
        }

        $retenus = $this->canevas->fermerSurLesDependances($codesDemandes, $lisibles);

        return array_filter(
            $lisibles,
            static fn (string $code) => in_array($code, $retenus, true),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * Produit le classeur et, en cas de succès seulement, enregistre l'occurrence et
     * débite.
     *
     * @param string[] $codesDemandes
     *
     * @throws InsufficientTokensException si le solde ne couvre pas l'opération
     * @throws \RuntimeException           si le périmètre retenu est vide
     */
    public function exporter(
        Entreprise $entreprise,
        Invite $invite,
        ?Utilisateur $acteur,
        array $codesDemandes = [],
        ?string $graineIdempotence = null,
        ?Progression $progression = null,
    ): Response {
        $ressources = $this->perimetre($invite, $codesDemandes);
        if ($ressources === []) {
            throw new \RuntimeException(
                'Aucune donnée à exporter : votre périmètre d\'accès ne couvre aucune des données demandées.',
            );
        }

        // ── LE COÛT SE CONTRÔLE AVANT DE PRODUIRE QUOI QUE CE SOIT ──────────────────
        $this->compteur->verifierSolvabilite($entreprise, EchangeOccurrence::TYPE_EXPORT);

        [$classeur, $manifeste, $total] = $this->produire($entreprise, $invite, $acteur, $ressources, $progression);
        $nomFichier = $this->nomFichier($entreprise);

        // ── OCCURRENCE ET DÉBIT : un seul geste, et seulement en cas de succès ──────
        $perimetreCodes = array_keys($ressources);
        $cle = $this->compteur->cleIdempotence(
            $entreprise,
            $invite,
            EchangeOccurrence::TYPE_EXPORT,
            $perimetreCodes,
            $graineIdempotence,
        );

        $this->em->wrapInTransaction(function () use ($entreprise, $invite, $acteur, $perimetreCodes, $total, $cle, $manifeste, $nomFichier): void {
            $this->compteur->enregistrer(
                $entreprise,
                $invite,
                $acteur,
                EchangeOccurrence::TYPE_EXPORT,
                $perimetreCodes,
                $total,
                $cle,
                // L'empreinte des en-têtes identifie la STRUCTURE du fichier produit :
                // c'est elle qui permettra de rattacher un dépôt à l'export dont il vient.
                $manifeste->empreinteEntetes,
                $nomFichier,
            );
            $this->em->flush();
        });

        return $this->reponse($classeur, $nomFichier);
    }

    /**
     * PRODUIT le classeur, et rien d'autre : ni contrôle de solde, ni occurrence, ni
     * débit.
     *
     * ⚠ SÉPARER « FABRIQUER » DE « FACTURER » n'est pas un raffinement d'architecture.
     * La commande de vérification à chaud (app:echange:smoke) empruntait le chemin
     * complet, faute d'en avoir un autre : une seule passe sur les quarante-deux
     * ressources d'un cabinet réel a décompté quarante-deux occurrences et débité
     * 23 400 tokens au propriétaire — pour un contrôle technique que personne n'avait
     * demandé. Un outil de diagnostic ne doit jamais pouvoir facturer.
     *
     * @param array<string, RessourceDEchange> $ressources
     *
     * @return array{0: \PhpOffice\PhpSpreadsheet\Spreadsheet, 1: Manifeste, 2: int}
     */
    public function produire(
        Entreprise $entreprise,
        Invite $invite,
        ?Utilisateur $acteur,
        array $ressources,
        ?Progression $progression = null,
    ): array {
        $progression ??= Progression::muette();

        // ⚠ ON COMPTE AVANT DE COMMENCER. Un pourcentage suppose un dénominateur : sans
        // ce pré-comptage, on ne saurait dire que « la troisième feuille sur
        // quarante-deux », ce qui ne dit rien du temps restant quand une feuille pèse
        // trois lignes et la suivante douze mille. Ce sont des COUNT, donc quelques
        // millisecondes pour un dénominateur juste.
        $progression->etape('Inventaire des données');
        $progression->totaliser($this->compterLignes($entreprise, $ressources));

        $lignes = [];
        $total = 0;
        foreach ($ressources as $code => $ressource) {
            $progression->etape($ressource->libelle);
            $lignes[$code] = $this->lignesDe($entreprise, $ressource, $progression);
            $total += count($lignes[$code]);
        }

        // L'écriture du classeur elle-même n'est pas instantanée : on le dit plutôt que
        // de laisser la barre à 100 % pendant que le fichier se compresse.
        $progression->etape('Mise en forme du classeur');

        $manifeste = new Manifeste(
            uidCabinet: (string) $entreprise->getId(),
            nomCabinet: $entreprise->getNom() ?? '',
            genereLe: new \DateTimeImmutable('now'),
            generePar: $this->signature($invite, $acteur),
            // Jamais de numéro en dur : la version vient de la logique de versionnage
            // de l'application, celle-là même qui s'incrémente à chaque commit.
            versionSchema: $this->version->getVersion(),
            perimetre: array_keys($ressources),
            empreinteEntetes: Manifeste::empreinte($ressources, EcrivainJsbx::COLONNES_TECHNIQUES),
        );

        return [$this->ecrivain->ecrire($manifeste, $ressources, $lignes), $manifeste, $total];
    }

    /**
     * Toutes les lignes d'une ressource pour ce cabinet.
     *
     * ⚠ Le SCOPING PAR ENTREPRISE est ici, et il est inconditionnel : jamais un
     * findAll() de repository. Les quarante-deux entités du périmètre portent toutes la
     * colonne (elle vient d'AuditableTrait), ce qui a été vérifié — mais la vérification
     * ne vaut que tant que la requête l'applique.
     *
     * @return array<int, array<string, mixed>>
     */
    private function lignesDe(Entreprise $entreprise, RessourceDEchange $ressource, ?Progression $progression = null): array
    {
        $lignes = [];
        $offset = 0;

        while (true) {
            $lot = $this->em->createQueryBuilder()
                ->select('e')
                ->from($ressource->fqcn, 'e')
                ->andWhere('e.entreprise = :entreprise')
                ->setParameter('entreprise', $entreprise)
                ->orderBy('e.id', 'ASC')
                ->setFirstResult($offset)
                ->setMaxResults(self::LOT)
                ->getQuery()
                ->getResult();

            if ($lot === []) {
                break;
            }

            foreach ($lot as $entite) {
                $lignes[] = $this->traducteur->convertir($entite, $ressource);
                $progression?->avancer();
            }

            $offset += self::LOT;
            if (count($lot) < self::LOT) {
                break;
            }
        }

        return $lignes;
    }

    /**
     * Nombre total de lignes à écrire, tous périmètres confondus.
     *
     * Des COUNT scopés au cabinet : c'est le dénominateur du pourcentage. Une ressource
     * dont le comptage échoue est comptée pour zéro plutôt que de faire échouer
     * l'export — un dénominateur imparfait vaut mieux qu'un export refusé.
     *
     * @param array<string, RessourceDEchange> $ressources
     */
    private function compterLignes(Entreprise $entreprise, array $ressources): int
    {
        $total = 0;
        foreach ($ressources as $ressource) {
            try {
                $total += (int) $this->em->createQueryBuilder()
                    ->select('COUNT(e.id)')
                    ->from($ressource->fqcn, 'e')
                    ->andWhere('e.entreprise = :entreprise')
                    ->setParameter('entreprise', $entreprise)
                    ->getQuery()
                    ->getSingleScalarResult();
            } catch (\Throwable) {
                continue;
            }
        }

        return $total;
    }

    /**
     * Réponse de téléchargement, sur le patron des autres exports de la maison :
     * flux vers php://output, jamais de fichier temporaire.
     */
    private function reponse(\PhpOffice\PhpSpreadsheet\Spreadsheet $classeur, string $nom): Response
    {
        $reponse = new StreamedResponse(static function () use ($classeur): void {
            (new Xlsx($classeur))->save('php://output');
        });
        $reponse->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $reponse->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $nom));
        $reponse->headers->set('Cache-Control', 'no-store, private');

        return $reponse;
    }

    private function nomFichier(Entreprise $entreprise): string
    {
        $slug = preg_replace('/[^A-Za-z0-9_-]+/', '_', $entreprise->getNom() ?? 'cabinet');

        return sprintf('jsbrokers_%s_%s.xlsx', trim((string) $slug, '_') ?: 'cabinet', date('Ymd-Hi'));
    }

    private function signature(Invite $invite, ?Utilisateur $acteur): string
    {
        $nom = $invite->getNom() ?: ($acteur?->getEmail() ?? 'inconnu');

        return sprintf('%s (#%d)', $nom, $invite->getId() ?? 0);
    }
}
