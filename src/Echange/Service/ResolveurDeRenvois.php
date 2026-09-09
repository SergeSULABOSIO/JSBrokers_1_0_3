<?php

namespace App\Echange\Service;

use App\Echange\Canevas\CanevasDEchange;
use App\Entity\Entreprise;
use Doctrine\ORM\EntityManagerInterface;

/**
 * RECONNAÎT une entité du cabinet à partir d'un LIBELLÉ lu dans un classeur.
 *
 * ── CE QU'IL FAISAIT DE PLUS, ET POURQUOI IL NE LE FAIT PLUS ────────────────────────
 * Ce service résolvait aussi des colonnes de clé étrangère selon trois niveaux —
 * « Ressource:id », libellé métier, repère local `_ref` du même fichier. Ces trois-là
 * appartenaient au format « normalisé », à une feuille par entité, que l'importation
 * n'accepte plus : le classeur de reprise porte toute sa chaîne sur UNE ligne, et c'est
 * `ReconstitueurDeTranche` qui la rattache.
 *
 * ── CE QU'IL FAIT, ET QUI EST VITAL ─────────────────────────────────────────────────
 * Il répond à « ce nom désigne-t-il quelque chose que le cabinet possède déjà ? ». C'est
 * ce qui évite qu'une reprise crée un second « KIN AVIA » à côté du premier.
 *
 * ⚠ UNE RÉFÉRENCE AMBIGUË EST UNE ERREUR, JAMAIS UN CHOIX AU HASARD. Deux clients nommés
 * « SARL Martin » ne se départagent pas : deviner, ici, c'est rattacher une police au
 * mauvais client. La seule exception est nommée et documentée — {@see reconnaitreLePremier()}
 * pour les catalogues, où deux entrées de même nom sont un doublon de configuration et non
 * deux affaires distinctes.
 *
 * ⚠ ET L'INDEX SE CHARGE EN UNE FOIS. Une requête par ligne serait ruineuse : un import de
 * deux mille lignes renvoyant chacune vers un client produirait deux mille requêtes.
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
        $this->index = [];
        $this->ambigus = [];
    }

    /**
     * RECONNAÎT une entité par son libellé — ou dit qu\'elle n\'existe pas encore.
     *
     * ── EN QUOI CE N\'EST PAS `resoudre()` ──────────────────────────────────────────
     * `resoudre()` REFUSE quand rien ne correspond, et c\'est juste pour une colonne de
     * renvoi : une police qui désigne un client introuvable est une erreur, pas une
     * invitation à créer ce client.
     *
     * La reconstitution à la maille tranche a le besoin inverse. Sa ligne porte « KIN
     * AVIA » sans identifiant, et le geste attendu est : rattacher au client existant s\'il
     * y en a un, le créer sinon. « Introuvable » y est donc une réponse normale, rendue
     * comme un renvoi VIDE, que l\'appelant traduit en création.
     *
     * ⚠ CE QUI NE CHANGE PAS, C\'EST LE REFUS DE L\'AMBIGUÏTÉ. Deux clients nommés « SARL
     * Martin » ne se départagent pas. Créer un troisième homonyme « parce qu\'on ne savait
     * pas » serait la pire des issues : le portefeuille se peuplerait de doublons que rien
     * ne signale. On refuse, en nommant le libellé.
     */
    public function reconnaitre(string $codeRessource, ?string $libelle, Entreprise $entreprise): Renvoi
    {
        $brut = trim((string) $libelle);
        if ($brut === '') {
            return Renvoi::vide();
        }

        $trouves = $this->parLibelle($codeRessource, $brut, $entreprise);

        if (count($trouves) === 1) {
            return Renvoi::identifiant($trouves[0]);
        }

        if (count($trouves) > 1) {
            return Renvoi::refus(sprintf(
                '« %s » désigne %d lignes de « %s » : impossible de savoir laquelle, et en créer '
                . 'une de plus ajouterait un doublon. Renommez l\'une des deux, ou renseignez '
                . 'l\'identifiant de la ligne visée.',
                $brut,
                count($trouves),
                $codeRessource,
            ), ambigu: true);
        }

        return Renvoi::vide();
    }

    /**
     * RECONNAÎT LE PREMIER, quand plusieurs lignes portent ce libellé.
     *
     * ── POURQUOI CETTE SECONDE PORTE EXISTE ────────────────────────────────────────
     * ⚠ POUR LES CATALOGUES, ET POUR EUX SEULS. Deux clients nommés « SARL Martin » sont
     * deux affaires : les confondre rattacherait une police au mauvais. Deux types de
     * chargement nommés « Prime nette » sont, du point de vue du métier, le même poste
     * d'assiette.
     *
     * ⚠ ET SANS CETTE PORTE, LA REPRISE SERAIT IMPOSSIBLE. Mesuré sur le cabinet réel :
     * son catalogue porte « Prime nette » SIX fois, « Commission Ordinaire » six fois —
     * 31 chargements pour cinq noms distincts, séquelles d'une initialisation rejouée.
     * Les doublons y sont rigoureusement identiques (même fonction, même taux, même
     * redevable). Refuser aurait bloqué 177 lignes sur 79, c'est-à-dire tout.
     *
     * L'appelant qui emprunte cette porte accepte donc de choisir — et doit le DIRE,
     * en avertissement. `estAmbigu()` lui apprend s'il y avait lieu.
     */
    public function reconnaitreLePremier(string $codeRessource, ?string $libelle, Entreprise $entreprise): Renvoi
    {
        $brut = trim((string) $libelle);
        if ($brut === '') {
            return Renvoi::vide();
        }

        // L'index retient le PREMIER identifiant rencontré pour un libellé donné, et
        // marque le doublon à part : on lit donc l'index directement, là où
        // `parLibelle()` rend des identifiants factices pour forcer un refus.
        $index = $this->index($codeRessource, $entreprise);
        $cle = self::normaliser($brut);

        return isset($index[$cle]) ? Renvoi::identifiant($index[$cle]) : Renvoi::vide();
    }

    /**
     * Plusieurs lignes portent-elles ce libellé ?
     *
     * ⚠ À APPELER APRÈS `reconnaitreLePremier()`, qui construit l'index — et donc le
     * relevé des doublons. Interrogé avant, ce drapeau serait toujours faux : c'est le
     * genre d'ordre implicite qui se casse au premier remaniement, d'où ce rappel.
     */
    public function estAmbigu(string $codeRessource, ?string $libelle, Entreprise $entreprise): bool
    {
        $brut = trim((string) $libelle);
        if ($brut === '') {
            return false;
        }

        $this->index($codeRessource, $entreprise);

        return isset($this->ambigus[$codeRessource . '|' . self::normaliser($brut)]);
    }

    /**
     * Identifiants dont le libellé correspond, à la casse et aux accents près.
     *
     * @return int[]
     */
    private function parLibelle(string $codeRessource, string $libelle, Entreprise $entreprise): array
    {
        $index = $this->index($codeRessource, $entreprise);
        $cle = self::normaliser($libelle);

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
            $cle = self::normaliser((string) ($ligne['libelle'] ?? ''));
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
    /**
     * LA FORME COMPARABLE D\'UN LIBELLÉ — « SFA Congo » et « sfa  congo » sont un seul
     * assureur.
     *
     * ⚠ PUBLIQUE ET STATIQUE PARCE QU\'ELLE DOIT ÊTRE LA SEULE. La reconstitution à la
     * maille tranche fabrique des repères locaux à partir des mêmes libellés
     * ({@see \App\Echange\Reprise\CleNaturelle}). Si son découpage différait de cet
     * index d\'un espace ou d\'un accent, une même valeur serait RÉSOLUE en base pour une
     * ligne et RECRÉÉE pour la suivante : deux clients pour un, et personne ne verrait
     * pourquoi.
     */
    public static function normaliser(string $texte): string
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
