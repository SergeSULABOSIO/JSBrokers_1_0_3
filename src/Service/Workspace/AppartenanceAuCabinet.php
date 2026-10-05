<?php

namespace App\Service\Workspace;

use App\Entity\Entreprise;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * CETTE ENTITÉ EST-ELLE CELLE D'UN AUTRE CABINET ?
 *
 * ── CE QUI MANQUAIT ─────────────────────────────────────────────────────────────
 * Le produit ne vérifiait que le TYPE. `mayAccessEntity($entityClass, …)` juge si le
 * périmètre de rôles de l'invité couvre « les Assureurs » ; il ne dit rien de
 * l'assureur #34615. L'instance, elle, était chargée par `->find($id)` — un identifiant
 * venu de l'URL ou du corps de la requête.
 *
 * Et aucun `SQLFilter` Doctrine n'est déclaré dans ce projet : ni `filters:` dans
 * `config/packages/doctrine.yaml`, ni classe étendant `SQLFilter`, ni
 * `getFilters()->enable(...)`. Rien ne restreignait donc ce `find()` au cabinet ouvert.
 *
 * Le motif « type vérifié, instance non vérifiée » se retrouvait sur les TROIS verbes :
 * lecture du détail, ouverture du formulaire, suppression, et soumission.
 *
 * ── POURQUOI CE N'EST PAS UN CONTRÔLE DE RÔLES ──────────────────────────────────
 * `mayAccessEntity()` n'est PAS une barrière inter-cabinets, et ne peut pas le devenir :
 * l'inscription est publique, tout inscrit devient propriétaire de son cabinet, et
 * `WorkspaceAccessResolver::can()` rend `true` sans condition pour un propriétaire. C'est
 * un filtre de périmètre fonctionnel ENTRE COLLABORATEURS d'un même cabinet. La question
 * « à quel cabinet appartient cette ligne ? » est d'une autre nature, et appelle un autre
 * service.
 *
 * ── UN SEUL CAS N'A PAS D'`entreprise` ──────────────────────────────────────────
 * Sur les cinquante classes qu'une route `{id}` peut atteindre, quarante-neuf portent la
 * relation directement. La cinquantième, `Operation`, la tient de son bordereau. Le
 * chemin est donc DÉCLARÉ ici, et non deviné : une règle du genre « pas d'entreprise ⇒
 * autorisé » ouvrirait en grand toutes les sous-entités qu'un parent protège.
 */
class AppartenanceAuCabinet
{
    /**
     * Les classes qui tiennent leur cabinet d'un PARENT, et par quel chemin.
     *
     * Chaque entrée est une décision, pas une commodité. Ajouter une classe ici revient à
     * dire « son cabinet est celui de son parent » — ce qui doit être vrai, et le rester.
     *
     * @var array<class-string, string> classe => nom de l'accesseur vers le parent
     */
    private const PAR_LE_PARENT = [
        \App\Entity\Operation::class => 'getBordereau',
    ];

    public function __construct(
        private CabinetActif $cabinetActif,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * L'entité appartient-elle au cabinet ouvert ?
     *
     * Sans cabinet ouvert, la réponse est NON — jamais « on ne sait pas, donc oui ».
     */
    public function estDuCabinetOuvert(?object $entite): bool
    {
        if ($entite === null) {
            return false;
        }

        $ouvert = $this->cabinetActif->entreprise();
        if ($ouvert === null) {
            return false;
        }

        $sienne = $this->entrepriseDe($entite);

        return $sienne !== null && $sienne->getId() === $ouvert->getId();
    }

    /**
     * Refuse par un 404, et non par un 403.
     *
     * Un 403 confirmerait que l'identifiant existe ailleurs — il transformerait le refus
     * en oracle : en balayant les numéros, on apprendrait combien d'assureurs compte la
     * plateforme et où s'arrêtent les séries. Un 404 ne distingue pas « pas à vous » de
     * « n'existe pas », ce qui est exactement ce qu'un autre cabinet doit pouvoir croire.
     */
    public function exigerLeCabinetOuvert(?object $entite, string $quoi = 'Cette entité'): void
    {
        if (!$this->estDuCabinetOuvert($entite)) {
            throw new NotFoundHttpException($quoi . " n'a pas été trouvé.");
        }
    }

    /**
     * Le cabinet d'une entité — le sien, ou celui de son parent déclaré.
     *
     * Une classe qui n'a ni `entreprise` ni chemin déclaré rend `null`, donc un refus. Le
     * silence se lit comme un non : c'est la seule lecture sûre quand on ne sait pas.
     */
    private function entrepriseDe(object $entite): ?Entreprise
    {
        $classe = $this->em->getClassMetadata($entite::class)->getName();

        if (isset(self::PAR_LE_PARENT[$classe])) {
            $parent = $entite->{self::PAR_LE_PARENT[$classe]}();

            return $parent === null ? null : $this->entrepriseDe($parent);
        }

        if (method_exists($entite, 'getEntreprise')) {
            $entreprise = $entite->getEntreprise();

            return $entreprise instanceof Entreprise ? $entreprise : null;
        }

        return null;
    }
}
