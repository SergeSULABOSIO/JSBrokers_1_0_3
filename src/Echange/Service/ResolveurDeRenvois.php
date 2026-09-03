<?php

namespace App\Echange\Service;

use App\Ai\Mutation\MutationReferences;
use App\Echange\Canevas\CanevasDEchange;
use App\Echange\Canevas\ColonneDEchange;
use App\Entity\Entreprise;
use Doctrine\ORM\EntityManagerInterface;

/**
 * RÉSOUT une valeur de clé étrangère lue dans un classeur, selon trois niveaux et
 * dans cet ordre :
 *
 *  1. « Ressource:id » — l'identifiant écrit par l'export. Sans ambiguïté possible,
 *     et c'est ce qui rend l'aller-retour fidèle.
 *  2. Une RÉFÉRENCE MÉTIER lisible — le nom d'un client, le code d'un risque. C'est
 *     ce qu'un humain tape naturellement quand il ajoute une ligne hors ligne.
 *  3. Un REPÈRE LOCAL (`_ref`) désignant une ligne NOUVELLE du même fichier. Sans ce
 *     niveau, on ne pourrait pas créer un client et son contrat en un seul import —
 *     le contrat désignerait un identifiant qui n'existe pas encore.
 *
 * Le niveau 3 ne fabrique aucune mécanique : il se traduit en « @étiquette », le
 * renvoi que le circuit d'écriture de l'espace de travail sait déjà résoudre, au
 * dry-run comme à l'exécution.
 *
 * ⚠ UNE RÉFÉRENCE NON RÉSOLUE EST UNE ERREUR BLOQUANTE, JAMAIS UN SILENCE. Écrire la
 * ligne en laissant le lien vide produirait une fiche incohérente que personne n'a
 * demandée, et que rien à l'écran ne signalerait.
 *
 * ⚠ UNE RÉFÉRENCE AMBIGUË EST UNE ERREUR AUSSI. Deux clients nommés « SARL Martin »
 * ne se départagent pas : deviner, ici, c'est rattacher une police au mauvais client.
 */
final class ResolveurDeRenvois
{
    /**
     * Champs candidats à la reconnaissance métier, par ordre de préférence.
     *
     * `nomComplet` avant `nom` pour les risques, seule entité du périmètre dont le
     * libellé ne s'appelle pas « nom ».
     */
    private const CHAMPS_LISIBLES = ['nomComplet', 'nom', 'code', 'reference', 'referencePolice', 'numero', 'email', 'libelle'];

    /** @var array<string, true> repères locaux déclarés dans le fichier (minuscules) */
    private array $reperes = [];

    /** @var array<string, array<string, int>> mémoïsation : ressource => libellé normalisé => id */
    private array $index = [];

    /** @var array<string, true> ressources dont l'index a révélé des doublons */
    private array $ambigus = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CanevasDEchange $canevas,
    ) {
    }

    /** Réinitialise l'état entre deux contrôles — le service est partagé. */
    public function reinitialiser(): void
    {
        $this->reperes = [];
        $this->index = [];
        $this->ambigus = [];
    }

    /**
     * Déclare un repère local porté par une ligne NOUVELLE.
     *
     * Tous les repères du fichier sont déclarés AVANT la résolution : un contrat peut
     * désigner un client écrit plus bas dans la feuille, ou dans une feuille suivante.
     * Exiger l'ordre de lecture rendrait le format dépendant d'un tri que l'utilisateur
     * a parfaitement le droit de changer.
     */
    public function declarerRepere(string $repere): void
    {
        $repere = mb_strtolower(trim($repere));
        if ($repere !== '') {
            $this->reperes[$repere] = true;
        }
    }

    public function repereConnu(string $repere): bool
    {
        return isset($this->reperes[mb_strtolower(trim($repere))]);
    }

    /**
     * Résout une valeur de renvoi.
     *
     * @return Renvoi ce qu'il faut écrire dans le champ, ou le motif du refus
     */
    public function resoudre(mixed $valeur, ColonneDEchange $colonne, Entreprise $entreprise): Renvoi
    {
        $brut = is_scalar($valeur) ? trim((string) $valeur) : '';
        if ($brut === '') {
            return Renvoi::vide();
        }

        $cible = $colonne->referenceCode;
        if ($cible === null || $colonne->referenceHorsPerimetre) {
            // Colonne descriptive : elle a été exportée pour information et n'est pas
            // relue. La signaler serait bruyant ; la lire serait faux.
            return Renvoi::ignore();
        }

        // ── Niveau 1 : l'identifiant écrit par l'export ─────────────────────────────
        $uid = LigneExportable::lireUid($brut);
        if ($uid !== null) {
            [$ressource, $id] = $uid;
            if ($ressource !== $cible) {
                return Renvoi::refus(sprintf(
                    'L\'identifiant « %s » désigne une donnée de type « %s » alors que cette colonne attend « %s ».',
                    $brut,
                    $ressource,
                    $cible,
                ));
            }
            if (!$this->existe($cible, $id, $entreprise)) {
                return Renvoi::refus(sprintf(
                    'L\'identifiant « %s » ne correspond à aucune ligne de votre cabinet. '
                    . 'Il provient peut-être d\'un fichier d\'un autre cabinet, ou la ligne a été supprimée depuis l\'export.',
                    $brut,
                ));
            }

            return Renvoi::identifiant($id);
        }

        // ── Niveau 3 : un repère local du même fichier ──────────────────────────────
        // Contrôlé AVANT la reconnaissance métier : un utilisateur qui écrit « C1 »
        // désigne son repère, pas un client qui s'appellerait « C1 ».
        if ($this->repereConnu($brut)) {
            return Renvoi::repere(MutationReferences::PREFIXE . mb_strtolower($brut));
        }

        // ── Niveau 2 : une référence métier lisible ─────────────────────────────────
        $trouves = $this->parLibelle($cible, $brut, $entreprise);
        if (count($trouves) === 1) {
            return Renvoi::identifiant($trouves[0]);
        }
        if (count($trouves) > 1) {
            return Renvoi::refus(sprintf(
                '« %s » désigne %d lignes différentes : impossible de savoir laquelle. '
                . 'Utilisez l\'identifiant de la colonne %s de la feuille correspondante.',
                $brut,
                count($trouves),
                CanevasDEchange::COL_UID,
            ), ambigu: true);
        }

        return Renvoi::refus(sprintf(
            '« %s » ne correspond à aucune ligne existante ni à aucun repère de ce fichier. '
            . 'Vérifiez l\'orthographe, ou renseignez la colonne %s de la ligne visée pour pouvoir y renvoyer.',
            $brut,
            CanevasDEchange::COL_REF,
        ));
    }

    private function existe(string $codeRessource, int $id, Entreprise $entreprise): bool
    {
        $ressource = $this->canevas->ressource($codeRessource);
        if ($ressource === null) {
            return false;
        }

        // ⚠ Le scoping entreprise est ici, et il est inconditionnel : sans lui, un
        // fichier bricolé pourrait rattacher une police du cabinet voisin.
        $compte = (int) $this->em->createQueryBuilder()
            ->select('COUNT(e.id)')
            ->from($ressource->fqcn, 'e')
            ->andWhere('e.id = :id')
            ->andWhere('e.entreprise = :entreprise')
            ->setParameter('id', $id)
            ->setParameter('entreprise', $entreprise)
            ->getQuery()
            ->getSingleScalarResult();

        return $compte > 0;
    }

    /**
     * Identifiants dont le libellé correspond, à la casse et aux accents près.
     *
     * @return int[]
     */
    private function parLibelle(string $codeRessource, string $libelle, Entreprise $entreprise): array
    {
        $index = $this->index($codeRessource, $entreprise);
        $cle = $this->normaliser($libelle);

        if (isset($this->ambigus[$codeRessource . '|' . $cle])) {
            // Deux lignes portent ce libellé : on rend deux identifiants factices pour
            // que l'appelant tranche « ambigu » sans avoir à connaître cette mécanique.
            return [-1, -2];
        }

        return isset($index[$cle]) ? [$index[$cle]] : [];
    }

    /**
     * Index libellé → identifiant d'une ressource, construit UNE fois par contrôle.
     *
     * Une requête par ligne du fichier serait ruineuse : un import de deux mille lignes
     * renvoyant chacune vers un client produirait deux mille requêtes. On charge donc
     * les couples (id, libellé) en une fois, par ressource réellement référencée.
     *
     * @return array<string, int>
     */
    private function index(string $codeRessource, Entreprise $entreprise): array
    {
        if (isset($this->index[$codeRessource])) {
            return $this->index[$codeRessource];
        }

        $ressource = $this->canevas->ressource($codeRessource);
        if ($ressource === null) {
            return $this->index[$codeRessource] = [];
        }

        $meta = $this->em->getClassMetadata($ressource->fqcn);
        $champ = null;
        foreach (self::CHAMPS_LISIBLES as $candidat) {
            if ($meta->hasField($candidat)) {
                $champ = $candidat;
                break;
            }
        }
        if ($champ === null) {
            return $this->index[$codeRessource] = [];
        }

        $lignes = $this->em->createQueryBuilder()
            ->select('e.id AS id', sprintf('e.%s AS libelle', $champ))
            ->from($ressource->fqcn, 'e')
            ->andWhere('e.entreprise = :entreprise')
            ->setParameter('entreprise', $entreprise)
            ->getQuery()
            ->getArrayResult();

        $index = [];
        foreach ($lignes as $ligne) {
            $cle = $this->normaliser((string) ($ligne['libelle'] ?? ''));
            if ($cle === '') {
                continue;
            }
            if (isset($index[$cle])) {
                // Doublon : on le retient pour refuser explicitement plutôt que de
                // rendre le premier venu.
                $this->ambigus[$codeRessource . '|' . $cle] = true;
                continue;
            }
            $index[$cle] = (int) $ligne['id'];
        }

        return $this->index[$codeRessource] = $index;
    }

    /**
     * Forme comparable d'un libellé : minuscules, sans accents, ponctuation ramenée à
     * une espace. « SUNU IARD RDC » et « sunu-iard-rdc » désignent la même chose.
     */
    private function normaliser(string $texte): string
    {
        static $accents = [
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'æ' => 'ae',
            'ç' => 'c', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
            'ñ' => 'n', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o', 'œ' => 'oe',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y', 'ß' => 'ss',
        ];

        $texte = strtr(mb_strtolower(trim($texte)), $accents);
        $texte = (string) preg_replace('/[^a-z0-9]+/', ' ', $texte);

        return trim($texte);
    }
}
