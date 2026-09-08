<?php

namespace App\Service\Onboarding;

use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Monnaie;
use App\Entity\ParametresConge;
use App\Entity\RolesEnAdministration;
use App\Entity\RolesEnFinance;
use App\Entity\RolesEnMarketing;
use App\Entity\RolesEnProduction;
use App\Entity\RolesEnSinistre;
use App\Service\Conge\DroitCongeParDefaut;
use App\Services\CanvasBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

/**
 * OÙ EN EST LA CONFIGURATION DU CABINET.
 *
 * Compte, pour chaque étape d'OnboardingCatalogue, ce qui est déjà enregistré, en déduit
 * un score pondéré, et rend de quoi afficher l'existant sans ouvrir la rubrique.
 *
 * ── L'APERÇU NE CONNAÎT AUCUNE ENTITÉ ───────────────────────────────────────────────
 * Le libellé d'une ligne n'est pas décidé ici : il vient du ListCanvasProvider de
 * l'entité — `colonne_principale.texte_principal.attribut_code` pour le libellé, le
 * premier `textes_secondaires` pour le détail. C'est exactement ce qu'affiche la rubrique.
 * Écrire ici une seconde règle d'affichage aurait garanti qu'un jour un assureur se lise
 * autrement dans le guide que dans sa liste.
 *
 * On ne touche NI aux `colonnes_numeriques` NI à `loadAllCalculatedValues()` : ce sont des
 * indicateurs calculés — chers, et sans objet pour une consultation simple.
 *
 * ── DEUX MODES, PARCE QUE DEUX USAGES ───────────────────────────────────────────────
 * `scoreSeul()` ne fait que les comptages : c'est lui qu'appellent le voyant de la colonne
 * 1 et le bandeau du tableau de bord, rendus à CHAQUE chargement du workspace. `pour()`
 * construit en plus les aperçus, et n'est payé que par le panneau, ouvert à la demande.
 */
class OnboardingCompletude
{
    /** Au-delà, la carte affiche « et N autres » : elle reste une consultation, pas une liste. */
    private const APERCU_MAX = 5;

    /** Le cabinet neuf naît avec son seul propriétaire : il en faut un DE PLUS pour dire « équipe ». */
    private const INVITES_ATTENDUS = 2;

    /**
     * Les cinq tables de rôles. Un droit peut être attribué dans n'importe laquelle : les
     * interroger toutes est le seul moyen de savoir si le cabinet a DÉCIDÉ un périmètre.
     *
     * @var array<int, class-string>
     */
    private const TABLES_DE_ROLES = [
        RolesEnAdministration::class,
        RolesEnFinance::class,
        RolesEnMarketing::class,
        RolesEnProduction::class,
        RolesEnSinistre::class,
    ];

    private PropertyAccessorInterface $accesseur;

    /** @var array<int, array<string, mixed>> Mémoïsation par entreprise, un seul calcul par requête. */
    private array $cache = [];

    /** @var array<int, array<string, mixed>> */
    private array $cacheScore = [];

    public function __construct(
        private EntityManagerInterface $manager,
        private OnboardingCatalogue $catalogue,
        private CanvasBuilder $canvasBuilder,
    ) {
        $this->accesseur = PropertyAccess::createPropertyAccessorBuilder()
            ->disableExceptionOnInvalidIndex()
            ->getPropertyAccessor();
    }

    /**
     * Le bilan complet : score, étapes, et l'aperçu de l'existant sur chacune.
     *
     * @return array{score: int, etapes: array<int, array<string, mixed>>, restantes: array<int, array<string, mixed>>, complet: bool}
     */
    public function pour(Entreprise $entreprise): array
    {
        $id = (int) $entreprise->getId();
        if (isset($this->cache[$id])) {
            return $this->cache[$id];
        }

        return $this->cache[$id] = $this->calculer($entreprise, true);
    }

    /**
     * Le bilan SANS aperçu — le mode des surfaces de rappel, qui n'affichent qu'un
     * pourcentage et quelques libellés d'étapes.
     *
     * @return array{score: int, etapes: array<int, array<string, mixed>>, restantes: array<int, array<string, mixed>>, complet: bool}
     */
    public function scoreSeul(Entreprise $entreprise): array
    {
        $id = (int) $entreprise->getId();
        if (isset($this->cache[$id])) {
            return $this->cache[$id];
        }
        if (isset($this->cacheScore[$id])) {
            return $this->cacheScore[$id];
        }

        return $this->cacheScore[$id] = $this->calculer($entreprise, false);
    }

    /**
     * OUBLIE CE QU'ON SAIT DE CE CABINET.
     *
     * La mémoïsation vaut pour une LECTURE : dans une même requête, le voyant, le
     * bandeau et le guide doivent afficher le même chiffre, et le calculer trois fois
     * serait du gaspillage. Elle devient un piège dès qu'on relit APRÈS avoir écrit —
     * c'est précisément ce que fait le notifieur, en fin de requête, pour savoir si le
     * score a bougé. Sans cet oubli, il comparerait le nouveau score à lui-même et
     * n'enverrait jamais rien.
     */
    public function oublier(Entreprise $entreprise): void
    {
        $id = (int) $entreprise->getId();
        unset($this->cache[$id], $this->cacheScore[$id]);
    }

    /**
     * Les étapes restantes les plus lourdes, pour un rappel qui NOMME ce qui bloque.
     * Un pourcentage seul ne dit pas quoi faire.
     *
     * @return array<int, array<string, mixed>>
     */
    public function etapesACiter(Entreprise $entreprise, int $combien = 3): array
    {
        $restantes = $this->scoreSeul($entreprise)['restantes'];
        usort($restantes, static fn (array $a, array $b): int => $b['poids'] <=> $a['poids']);

        return array_slice($restantes, 0, $combien);
    }

    /** Reste-t-il une étape SANS laquelle la chaîne de production s'arrête ? */
    public function resteDuBloquant(Entreprise $entreprise): bool
    {
        foreach ($this->scoreSeul($entreprise)['restantes'] as $etape) {
            if ($etape['poids'] === OnboardingCatalogue::POIDS_BLOQUANT) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{score: int, etapes: array<int, array<string, mixed>>, restantes: array<int, array<string, mixed>>, complet: bool}
     */
    private function calculer(Entreprise $entreprise, bool $avecApercu): array
    {
        $etapes = [];
        $restantes = [];
        $poidsFaits = 0;

        foreach ($this->catalogue->etapes() as $etape) {
            $etat = $this->etatDe($etape, $entreprise, $avecApercu);
            $etape = array_merge($etape, $etat);
            $etapes[] = $etape;

            if ($etape['fait']) {
                $poidsFaits += $etape['poids'];
            } else {
                $restantes[] = $etape;
            }
        }

        $total = $this->catalogue->poidsTotal();
        $score = $total > 0 ? (int) round($poidsFaits * 100 / $total) : 100;

        // Un score qui affiche 100 % alors qu'il reste une étape serait un mensonge
        // d'arrondi : on plafonne à 99 tant que tout n'est pas fait.
        if ($score >= 100 && $restantes !== []) {
            $score = 99;
        }

        return [
            'score' => $score,
            'etapes' => $etapes,
            'restantes' => $restantes,
            'complet' => $restantes === [],
        ];
    }

    /**
     * L'état d'une étape : est-elle faite, combien d'objets, et l'aperçu.
     *
     * @param array<string, mixed> $etape
     *
     * @return array{fait: bool, nombre: int, apercu: array<int, array<string, mixed>>, reste: int}
     */
    private function etatDe(array $etape, Entreprise $entreprise, bool $avecApercu): array
    {
        // LES TROIS RÈGLES PROPRES vivent ici, et nulle part ailleurs : une étape à seuil
        // se compte, les autres ont une définition métier qu'un simple `count` trahirait.
        return match ($etape['cle']) {
            'taux_change' => $this->etatTauxDeChange($entreprise, $avecApercu),
            'collaborateurs' => $this->etatCollaborateurs($entreprise, $avecApercu),
            'parametres_conges' => $this->etatParametresConges($entreprise, $avecApercu),
            default => $this->etatParSeuil($etape, $entreprise, $avecApercu),
        };
    }

    /**
     * @param array<string, mixed> $etape
     *
     * @return array{fait: bool, nombre: int, apercu: array<int, array<string, mixed>>, reste: int}
     */
    private function etatParSeuil(array $etape, Entreprise $entreprise, bool $avecApercu): array
    {
        $nombre = $this->compter($etape['entite'], $entreprise);
        $apercu = $avecApercu ? $this->apercu($etape['entite'], $entreprise) : [];

        return [
            'fait' => $nombre >= (int) $etape['seuil'],
            'nombre' => $nombre,
            'apercu' => $apercu,
            'reste' => max(0, $nombre - count($apercu)),
        ];
    }

    /**
     * LE TAUX DE CHANGE DE LA MONNAIE LOCALE.
     *
     * Le semis pose la monnaie du pays à 1.00 — un placeholder. Tant qu'il n'a pas bougé,
     * toute conversion est fausse en silence. L'étape est faite si le cabinet travaille en
     * dollars (aucune monnaie locale n'a alors été semée, il n'y a rien à corriger) ou si
     * le taux a été retouché.
     *
     * @return array{fait: bool, nombre: int, apercu: array<int, array<string, mixed>>, reste: int}
     */
    private function etatTauxDeChange(Entreprise $entreprise, bool $avecApercu): array
    {
        /** @var Monnaie|null $locale */
        $locale = $this->manager->getRepository(Monnaie::class)
            ->findOneBy(['entreprise' => $entreprise, 'locale' => true]);

        if ($locale === null) {
            // Cabinet en dollars : pas de seconde monnaie, donc pas de taux à ajuster.
            return ['fait' => true, 'nombre' => 0, 'apercu' => [], 'reste' => 0];
        }

        $taux = (float) $locale->getTauxusd();
        $fait = abs($taux - 1.0) > 0.000001;

        return [
            'fait' => $fait,
            'nombre' => 1,
            'apercu' => $avecApercu ? [$this->ligne($locale, Monnaie::class)] : [],
            'reste' => 0,
        ];
    }

    /**
     * LES COLLABORATEURS ET LEURS DROITS — une seule étape, deux conditions.
     *
     * Inviter sans habiliter laisse le collaborateur devant un espace vide : le rôle
     * « Congés (accès de base) », posé d'office à chaque invité par DroitCongeParDefaut,
     * ne compte donc pas — sinon l'étape se validerait toute seule, sans qu'aucun droit
     * ait été décidé.
     *
     * @return array{fait: bool, nombre: int, apercu: array<int, array<string, mixed>>, reste: int}
     */
    private function etatCollaborateurs(Entreprise $entreprise, bool $avecApercu): array
    {
        $nombre = $this->compter(Invite::class, $entreprise);
        $apercu = $avecApercu ? $this->apercu(Invite::class, $entreprise) : [];

        return [
            'fait' => $nombre >= self::INVITES_ATTENDUS && $this->aUnRoleDecide($entreprise),
            'nombre' => $nombre,
            'apercu' => $apercu,
            'reste' => max(0, $nombre - count($apercu)),
        ];
    }

    /**
     * Un rôle attribué par le cabinet, par opposition à celui que tout invité reçoit
     * d'office. Les cinq tables de rôles sont interrogées jusqu'à la première trouvaille.
     */
    private function aUnRoleDecide(Entreprise $entreprise): bool
    {
        foreach (self::TABLES_DE_ROLES as $classe) {
            $trouve = $this->manager->createQueryBuilder()
                ->select('1')
                ->from($classe, 'r')
                ->where('r.entreprise = :e')
                ->andWhere('r.nom IS NULL OR r.nom <> :offert')
                ->setParameter('e', $entreprise)
                ->setParameter('offert', DroitCongeParDefaut::NOM_ROLE)
                ->setMaxResults(1)
                ->getQuery()
                ->getScalarResult();

            if ($trouve !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * LES PARAMÈTRES DE CONGÉ.
     *
     * ParametresCongeRepository::pourEntreprise() rend une instance TRANSITOIRE quand
     * aucune n'est enregistrée : s'y fier ferait croire l'étape faite dès le premier jour.
     * On compte donc en base.
     *
     * @return array{fait: bool, nombre: int, apercu: array<int, array<string, mixed>>, reste: int}
     */
    private function etatParametresConges(Entreprise $entreprise, bool $avecApercu): array
    {
        $existant = $this->manager->getRepository(ParametresConge::class)
            ->findOneBy(['entreprise' => $entreprise]);

        return [
            'fait' => $existant !== null,
            'nombre' => $existant !== null ? 1 : 0,
            'apercu' => ($avecApercu && $existant !== null) ? [$this->ligne($existant, ParametresConge::class)] : [],
            'reste' => 0,
        ];
    }

    /** @param class-string $classe */
    private function compter(string $classe, Entreprise $entreprise): int
    {
        return (int) $this->manager->getRepository($classe)->count(['entreprise' => $entreprise]);
    }

    /**
     * Les premiers objets enregistrés, réduits à ce qu'une carte peut montrer.
     *
     * @param class-string $classe
     *
     * @return array<int, array{id: int, libelle: string, detail: string}>
     */
    private function apercu(string $classe, Entreprise $entreprise): array
    {
        // ON NE TRIE QUE SUR UNE VRAIE COLONNE. L'attribut d'affichage d'une rubrique
        // n'est pas toujours un champ Doctrine : `RegimeTravail` se libelle par un
        // `periodeLibelle` CALCULÉ, que le dépôt refuse en clause de tri
        // (UnrecognizedField). Sans cette garde, une seule entité de ce genre suffisait
        // à faire tomber tout le guide.
        $tri = [];
        $principal = $this->attributPrincipal($classe);
        if ($principal !== null && $this->manager->getClassMetadata($classe)->hasField($principal)) {
            $tri = [$principal => 'ASC'];
        }

        $objets = $this->manager->getRepository($classe)
            ->findBy(['entreprise' => $entreprise], $tri, self::APERCU_MAX);

        return array_map(fn (object $objet): array => $this->ligne($objet, $classe), $objets);
    }

    /**
     * Une ligne d'aperçu, libellée comme la rubrique le ferait.
     *
     * @param class-string $classe
     *
     * @return array{id: int, libelle: string, detail: string}
     */
    private function ligne(object $objet, string $classe): array
    {
        $principal = $this->attributPrincipal($classe);
        $secondaire = $this->attributSecondaire($classe);

        $libelle = $principal !== null ? $this->valeur($objet, $principal) : '';
        if ($libelle === '') {
            // Repli plutôt qu'une ligne vide : une ligne sans libellé, au milieu de lignes
            // qui en portent, se lit comme une anomalie.
            $libelle = '#' . $this->accesseur->getValue($objet, 'id');
        }

        return [
            'id' => (int) $this->accesseur->getValue($objet, 'id'),
            'libelle' => $libelle,
            'detail' => $secondaire !== null ? $this->valeur($objet, $secondaire) : '',
        ];
    }

    /** La valeur d'un attribut, ramenée à du texte affichable — jamais une exception. */
    private function valeur(object $objet, string $attribut): string
    {
        try {
            $valeur = $this->accesseur->getValue($objet, $attribut);
        } catch (\Throwable) {
            return '';
        }

        if ($valeur === null || is_array($valeur) || is_object($valeur)) {
            return $valeur instanceof \Stringable ? trim((string) $valeur) : '';
        }

        return is_bool($valeur) ? ($valeur ? 'Oui' : 'Non') : trim((string) $valeur);
    }

    /** @param class-string $classe */
    private function attributPrincipal(string $classe): ?string
    {
        $canvas = $this->canvasDeListe($classe);

        return $canvas['colonne_principale']['texte_principal']['attribut_code'] ?? null;
    }

    /** @param class-string $classe */
    private function attributSecondaire(string $classe): ?string
    {
        $canvas = $this->canvasDeListe($classe);

        return $canvas['colonne_principale']['textes_secondaires'][0]['attribut_code'] ?? null;
    }

    /**
     * @param class-string $classe
     *
     * @return array<string, mixed>
     */
    private function canvasDeListe(string $classe): array
    {
        try {
            return $this->canvasBuilder->getListeCanvas($classe);
        } catch (\Throwable) {
            // Une entité sans provider de liste ne doit pas priver le guide de sa carte :
            // elle perd son libellé, pas son bouton.
            return [];
        }
    }
}
