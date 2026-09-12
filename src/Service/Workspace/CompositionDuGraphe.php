<?php

namespace App\Service\Workspace;

use App\Entity\Article;
use App\Entity\Document;
use App\Entity\Entreprise;
use App\Entity\Paiement;
use App\Entity\ReversementRetroAgent;
use App\Entity\Tache;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;

/**
 * CE QUI PEND D'UNE DONNÉE, ET CE QU'IL FAUT EN FAIRE.
 *
 * Supprimer une opportunité doit emporter toute sa chaîne — propositions, polices,
 * échéances, commissions, factures, documents — sans jamais toucher au client, à
 * l'assureur, au risque ni aux autres paramètres du cabinet. Écrire cette carte à la
 * main serait une faute : le schéma compte 89 entités et 282 clés étrangères, la seule
 * table `document` en porte 47, et une liste figée serait fausse au premier champ ajouté.
 *
 * La règle se DÉDUIT donc des métadonnées Doctrine, qui sont déjà la source de vérité du
 * schéma. Elle tient en une phrase :
 *
 *   ⚠ ON DÉTRUIT CE QUI N'APPARTIENT QU'À LA CIBLE. ON DÉTACHE CE QUI APPARTIENT AUSSI À
 *     QUELQU'UN D'AUTRE. ON NE LAISSE JAMAIS UN LIEN PENDANT.
 *
 * On ne regarde QUE les clés étrangères ENTRANTES — celles que d'autres lignes braquent
 * sur la cible. C'est ce choix, et lui seul, qui met les objets transversaux hors de
 * portée : `Cotation::piste` et `Avenant::cotation` PORTENT leur colonne, donc remonter
 * par eux reviendrait à détruire le dossier entier en supprimant un avenant. On ne les
 * suit jamais, quoi qu'ils déclarent (les trois qui déclarent `cascade: remove` sont
 * neutralisés séparément, cf. {@see liensRemontants()}).
 *
 * ⚠ CE QUI DIT LA PROPRIÉTÉ, C'EST LA CASCADE DÉCLARÉE — et le schéma est remarquablement
 * cohérent là-dessus. `Piste::cotations`, `Cotation::tranches`, `Note::articles` portent
 * `cascade: remove` : ces lignes n'existent que pour leur parent. `Portefeuille::clients`,
 * `Invite::pistes`, `Assureur::notes` n'en portent AUCUNE : un client n'appartient pas à
 * un portefeuille, il y est rangé. Tenir toute collection déclarée pour une possession
 * détruisait le client avec son portefeuille — l'inverse exact de ce qu'on demande.
 *
 * Quatre sorties possibles pour une clé entrante :
 *
 *  - ENFANT   — la cible déclare la collection réciproque EN CASCADE (ou l'arête figure
 *               dans {@see ENFANTS_AJOUTES}) : la ligne part avec elle, récursivement ;
 *  - DÉTACHER — l'entité est un TÉMOIN comptable, l'arête est un détachement DÉCIDÉ, ou
 *               la colonne est simplement nullable : la ligne survit, son lien est coupé ;
 *  - REFUS    — aucune cascade déclarée ET colonne NON NULLABLE : la ligne ne peut ni
 *               survivre détachée ni être détruite sans qu'on l'ait voulu. On refuse, en
 *               NOMMANT ce qui retient (cf. `DepenseCourtier::charge`, qui est une pièce
 *               comptable, ou `Portefeuille::gestionnaire`, qu'il faut réaffecter).
 */
final class CompositionDuGraphe
{
    /** La ligne n'existe que pour la cible : elle part avec elle, récursivement. */
    public const ENFANT = 'enfant';

    /** La ligne survit, son lien vers la cible passe à nul. */
    public const DETACHER = 'detacher';

    /** La ligne ne peut ni survivre détachée ni être détruite : on refuse, en le disant. */
    public const REFUS = 'refus';

    /**
     * LA BASE S'EN CHARGE ELLE-MÊME : on ne touche à rien.
     *
     * ⚠ ET Y TOUCHER SERAIT UNE FAUTE. `classeur.client_id` est déclarée
     * `ON DELETE CASCADE` : le dossier d'un client disparaît avec lui, c'est l'intention
     * du schéma. Le moteur a commencé par la DÉTACHER — il en a fait un dossier orphelin,
     * sans client, que plus rien ne rattachait à quoi que ce soit et que les tests ne
     * savaient plus nettoyer. Un `SET NULL` déclaré coupe le lien tout seul : refaire
     * l'opération en SQL n'ajoute qu'une écriture inutile.
     */
    public const IGNORER = 'ignorer';

    /**
     * LES DEUX OUBLIS DE CASCADE, COMBLÉS ICI PLUTÔT QUE DANS LES ENTITÉS.
     *
     * Ces arêtes désignent une VRAIE possession que le mapping ne déclare pas :
     *  — une ligne de facture n'a pas d'objet sans l'échéance qu'elle facture ni sans la
     *    commission qu'elle liquide (`Note::articles`, elle, déclare bien sa cascade) ;
     *  — un document accroché à un feedback ou à une pièce de sinistre : pure omission,
     *    les 45 autres collections de documents déclarent toutes la leur.
     *
     * ⚠ ON NE CORRIGE PAS LES ENTITÉS, ET C'EST UN CHOIX. Poser `cascade: remove` sur
     * `Tranche::articles` détruirait des lignes de facture SANS toucher à leur note, dont
     * les totaux deviendraient faux en silence. Le moteur, lui, remonte jusqu'à la note
     * (cf. {@see PARENTS_ORPHELINS}) et ne la détruit que si elle se vide entièrement.
     *
     * @var array<class-string, string[]> classe qui porte la clé => champs
     */
    private const ENFANTS_AJOUTES = [
        'App\Entity\Article'  => ['tranche', 'revenuFacture'],
        'App\Entity\Document' => ['feedback', 'pieceSinistre'],
    ];

    /**
     * LES CASCADES DÉCLARÉES QU'ON N'APPLIQUE PAS, PAR DÉCISION MÉTIER.
     *
     * `Risque::pistes` et `Groupe::clients` portent `cascade: remove` : supprimer un
     * risque du catalogue emporterait toutes les affaires qui le visent, et supprimer un
     * groupe tous ses clients. Ce sont des PARAMÈTRES du cabinet — on les détache, on ne
     * détruit pas le portefeuille avec (décision du propriétaire, 2026-09-11).
     *
     * @var array<class-string, string[]> classe qui porte la clé => champs
     */
    private const DETACHEMENTS_DECIDES = [
        'App\Entity\Piste'  => ['risque'],
        'App\Entity\Client' => ['groupe'],
    ];

    /**
     * LES TRACES QU'UNE SUPPRESSION NE DOIT PAS EFFACER.
     *
     * Un versement de rétrocommission est un DÉCAISSEMENT RÉEL : l'argent est sorti, la
     * pièce est en comptabilité. Supprimer l'affaire qu'il solde ne le rend pas fictif.
     * Il est donc détaché, jamais détruit — et le rapport le dit à l'utilisateur.
     *
     * ⚠ CETTE CARTE NE DOIT PAS S'ALLONGER SANS MOTIF COMPTABLE. Chaque entrée est une
     * ligne qui survivra orpheline : ce n'est justifiable que si elle porte une trace que
     * le cabinet doit pouvoir retrouver.
     *
     * @var array<class-string, true>
     */
    private const TEMOINS = [
        ReversementRetroAgent::class => true,
    ];

    /**
     * LES PARENTS QUI MEURENT DE N'AVOIR PLUS D'ENFANTS.
     *
     * Une facture n'est qu'un en-tête : ce sont ses lignes qui la font exister. Quand
     * toutes les lignes d'une note disparaissent avec le dossier, garder la note serait
     * garder une facture à zéro, que rien ne rattache plus à rien. Elle rejoint donc le
     * plan, et emporte à son tour ses règlements et ses pièces.
     *
     * ⚠ MAIS SEULEMENT SI ELLE SE VIDE ENTIÈREMENT. Une note peut couvrir plusieurs
     * affaires — c'est même la raison d'être d'une facturation groupée depuis un
     * bordereau. Détruire une pièce comptable qui concerne des dossiers qu'on n'a pas
     * demandé de supprimer serait exactement la faute que ce moteur existe pour éviter.
     *
     * @var array<class-string, array{champ: string}>
     */
    private const PARENTS_ORPHELINS = [
        Article::class => ['champ' => 'note'],
    ];

    /**
     * LA FRONTIÈRE DU CABINET.
     *
     * Presque toutes les entités portent `entreprise_id` en NON NULLABLE et sans
     * collection réciproque déclarée : prendre une entreprise pour racine ferait donc
     * d'elle, par la règle ci-dessus, le parent de TOUT le cabinet. C'est vrai, et c'est
     * précisément ce qu'on ne veut pas déclencher depuis une corbeille de liste — vider
     * un cabinet est le métier de `app:cabinet:vider`, qui désarme les contraintes parce
     * qu'il efface l'ENSEMBLE et n'a donc plus rien à quoi une clé pourrait manquer.
     *
     * @var array<class-string, true>
     */
    private const FRONTIERES = [
        Entreprise::class  => true,
        Utilisateur::class => true,
    ];

    /**
     * CE QU'ON PEUT ÉPARGNER SANS DÉFAIRE LE DOSSIER.
     *
     * Une proposition ne survit pas à son affaire, ni une échéance à sa proposition : ce
     * sont des MAILLONS, et la base le dit. Une pièce jointe, un règlement, une tâche, eux,
     * existent très bien seuls — leur clé est nullable et leur perte n'est la conséquence de
     * rien. Ce sont donc les seuls que l'écran propose de garder, en coupant le lien plutôt
     * qu'en détruisant la ligne.
     *
     * ⚠ C'EST UNE CARTE D'INTENTION MÉTIER, PAS UNE DÉDUCTION DU SCHÉMA. Beaucoup de
     * colonnes sont nullables sans que garder l'enfant ait le moindre sens : une ligne de
     * facture sans sa facture n'est pas une pièce épargnée, c'est un débris. On nomme donc
     * les trois familles où l'arbitrage individuel a un sens, et rien d'autre.
     *
     * @var array<class-string, true>
     */
    private const DETACHABLES_A_ECRAN = [
        Document::class  => true,
        Paiement::class  => true,
        Tache::class     => true,
    ];

    /** @var array<class-string, array<int, array{source: class-string, champ: string, nature: string}>>|null */
    private ?array $entrantes = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Les clés étrangères qui POINTENT vers cette classe, avec le geste à faire.
     *
     * @return array<int, array{source: class-string, champ: string, nature: string}>
     */
    public function sorties(string $classe): array
    {
        return $this->index()[$classe] ?? [];
    }

    /**
     * Les associations to-one PROPRIÉTAIRES déclarées en `cascade: remove`, qu'il faut
     * mettre à nul AVANT de supprimer la ligne.
     *
     * ⚠ SANS CELA, SUPPRIMER UN AVENANT EMPORTE TOUT LE DOSSIER. `Avenant::cotation`
     * remonte vers sa proposition, `Cotation::piste` vers l'opportunité, et celle-ci
     * redescend sur toutes ses autres propositions. Le schéma n'en compte que trois, et
     * toutes trois sont nullables : la neutralisation est complète et sans effet de bord.
     *
     * @return string[] noms de champs
     */
    public function liensRemontants(string $classe): array
    {
        $champs = [];
        foreach ($this->associations($classe) as $champ => $mapping) {
            if (!$mapping->isToOneOwningSide()) {
                continue;
            }
            if (!$mapping->isCascadeRemove() && empty($mapping->orphanRemoval)) {
                continue;
            }
            if ($this->nullable($mapping)) {
                $champs[] = $champ;
            }
        }

        return $champs;
    }

    /**
     * Le champ par lequel cette classe désigne un parent qui ne survit pas à la perte de
     * tous ses enfants, ou null.
     */
    public function parentOrphelin(string $classe): ?string
    {
        return self::PARENTS_ORPHELINS[$classe]['champ'] ?? null;
    }

    /** Cette classe EST le cabinet (ou son propriétaire) : on ne la prend jamais pour racine. */
    /**
     * L'utilisateur peut-il épargner une ligne de cette classe sans défaire le dossier ?
     *
     * Détermine, à l'écran, si décocher un nœud le DÉTACHE (il survit, son lien coupé) ou
     * s'il faut au contraire renoncer à supprimer tout ce qui le porte.
     */
    public function estDetachableAEcran(string $classe): bool
    {
        return isset(self::DETACHABLES_A_ECRAN[$classe]);
    }

    public function estFrontiere(string $classe): bool
    {
        return isset(self::FRONTIERES[$classe]);
    }

    // ─────────────────────────────── Interne ──────────────────────────────────

    /**
     * Index inverse du schéma : cible => clés entrantes classées. Construit UNE fois,
     * en lisant toutes les métadonnées — jamais recopié à la main.
     *
     * @return array<class-string, array<int, array{source: class-string, champ: string, nature: string}>>
     */
    private function index(): array
    {
        if ($this->entrantes !== null) {
            return $this->entrantes;
        }

        $index = [];
        try {
            $toutes = $this->em->getMetadataFactory()->getAllMetadata();
        } catch (\Throwable) {
            return $this->entrantes = [];
        }

        foreach ($toutes as $meta) {
            if (!$meta instanceof ClassMetadata) {
                continue;
            }
            foreach ($meta->getAssociationMappings() as $champ => $mapping) {
                // ⚠ LES RELATIONS MULTIPLES NE SONT PAS SUIVIES, et ce n'est pas un oubli :
                // une table de jointure n'appartient à personne. Doctrine efface les lignes
                // du côté propriétaire, la base celles de l'autre côté (les deux tables de
                // jointure concernées sont en ON DELETE CASCADE). Un risque ciblé par une
                // condition de partage se DÉTACHE ainsi tout seul, sans jamais être détruit.
                if (!$mapping->isToOneOwningSide()) {
                    continue;
                }
                $cible = (string) $mapping->targetEntity;
                if ($cible === '') {
                    continue;
                }

                $index[$cible][] = [
                    'source' => $meta->getName(),
                    'champ'  => $champ,
                    'nature' => $this->nature($meta->getName(), $champ, $mapping, $cible),
                ];
            }
        }

        return $this->entrantes = $index;
    }

    /** ENFANT, DÉTACHER, REFUS ou IGNORER — décidé par le SCHÉMA, sauf les rares arêtes nommées ci-dessus. */
    private function nature(string $source, string $champ, object $mapping, string $cible): string
    {
        // La base applique déjà sa propre règle sur cette colonne : on ne la double pas.
        $onDelete = strtoupper((string) ((($mapping->joinColumns ?? [])[0] ?? null)?->onDelete ?? ''));
        if ($onDelete === 'CASCADE' || $onDelete === 'SET NULL') {
            return self::IGNORER;
        }

        // Une trace comptable survit toujours, même quand la cible la déclare en cascade.
        if (isset(self::TEMOINS[$source])) {
            return self::DETACHER;
        }
        // Un paramètre du catalogue se retire des affaires, il ne les emporte pas.
        if (in_array($champ, self::DETACHEMENTS_DECIDES[$source] ?? [], true)) {
            return self::DETACHER;
        }
        // Les deux possessions que le mapping oublie de déclarer.
        if (in_array($champ, self::ENFANTS_AJOUTES[$source] ?? [], true)) {
            return self::ENFANT;
        }

        // ⚠ C'EST LA CASCADE QUI DIT LA PROPRIÉTÉ, PAS LA SEULE EXISTENCE D'UNE
        // COLLECTION. `Portefeuille::clients` et `Invite::pistes` sont déclarées sans
        // cascade : ce sont des rangements, pas des possessions.
        if ($this->cibleDeclareUneCascade($mapping, $cible)) {
            return self::ENFANT;
        }

        // Rien de déclaré : la colonne tranche. Nullable = la ligne a une vie propre, on
        // coupe le lien. NON NULLABLE = elle ne peut ni survivre ni disparaître sans
        // qu'on l'ait voulu : on refuse, et on nomme ce qui retient.
        return $this->nullable($mapping) ? self::DETACHER : self::REFUS;
    }

    /** La cible déclare-t-elle la collection réciproque EN CASCADE de suppression ? */
    private function cibleDeclareUneCascade(object $mapping, string $cible): bool
    {
        $inverse = $mapping->inversedBy ?? null;
        if ($inverse === null || $inverse === '') {
            return false;
        }

        try {
            $metaCible = $this->em->getClassMetadata($cible);
            if (!$metaCible->hasAssociation($inverse)) {
                return false; // mapping incohérent : le champ inverse n'existe pas
            }
            $collection = $metaCible->getAssociationMapping($inverse);
        } catch (\Throwable) {
            return false;
        }

        return $collection->isCascadeRemove() || !empty($collection->orphanRemoval);
    }

    /** La colonne accepte-t-elle NULL (par défaut oui, comme Doctrine) ? */
    private function nullable(object $mapping): bool
    {
        $jc = ($mapping->joinColumns ?? [])[0] ?? null;

        return $jc === null ? true : (($jc->nullable ?? true) !== false);
    }

    /** @return iterable<string, object> */
    private function associations(string $classe): iterable
    {
        try {
            return $this->em->getClassMetadata($classe)->getAssociationMappings();
        } catch (\Throwable) {
            return [];
        }
    }
}
