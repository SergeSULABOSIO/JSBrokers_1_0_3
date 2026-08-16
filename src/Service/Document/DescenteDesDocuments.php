<?php

namespace App\Service\Document;

use App\Entity\Document;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * TOUS LES FICHIERS SOUS UNE FICHE, quelle que soit la profondeur.
 *
 * « Donne-moi les fichiers du client Jean de Dieu » ne désigne pas les pièces posées sur
 * la ligne « client » : cela désigne le DOSSIER — le client, ses pistes, leurs cotations,
 * leurs polices, et ce qui pend en dessous. Demander la même chose d'une piste part du
 * niveau de la piste et descend ; d'une cotation, part de la cotation. La règle est
 * toujours la même : on part de la fiche nommée et on va vers le BAS.
 *
 * CE QUE CELA REMPLACE. La recherche de fichiers de l'assistant filtrait Document par un
 * critère de rattachement : elle ne rendait donc que les pièces DIRECTEMENT accrochées à
 * la fiche. Sur un client qui porte une police, elle répondait « un seul fichier » — et
 * l'utilisateur, qui savait qu'il y en avait d'autres plus bas, devait les demander une
 * par une, dossier par dossier. C'est ce dialogue-là que cette classe supprime.
 *
 * POURQUOI UNE DESCENTE DÉRIVÉE, ET NON QUATRE CAS ÉCRITS À LA MAIN. Le collecteur du
 * SOA énumérait Client → Piste → Cotation → Avenant, et lui seul ; toute autre rubrique
 * lui était inconnue. Or une pièce peut désormais pendre à n'importe quelle entité — une
 * tranche, un paiement, un sinistre. Le chemin se lit donc dans les métadonnées : on suit
 * les collections de l'entité, puis les leurs, en s'arrêtant sur les garde-fous
 * ci-dessous. Une rubrique ouverte demain est explorée sans que personne y pense.
 *
 * ⚠️ TROIS GARDE-FOUS, ET AUCUN N'EST DÉCORATIF :
 *  - la PROFONDEUR est bornée. Le graphe métier reboucle (une piste connaît son client,
 *    qui connaît ses pistes) et une descente sans borne parcourrait le portefeuille
 *    entier depuis n'importe quelle fiche ;
 *  - les entités DÉJÀ VISITÉES ne le sont pas deux fois, par identité — c'est ce qui
 *    coupe les cycles ;
 *  - le nombre de nœuds visités est PLAFONNÉ. Mieux vaut une réponse tronquée dont on
 *    sait qu'elle l'est (le plafond est rapporté à l'appelant) qu'une requête qui ne
 *    revient pas.
 */
final class DescenteDesDocuments
{
    /**
     * Profondeur maximale de descente, en niveaux d'enfants.
     *
     * Quatre suffit au dossier commercial le plus profond du métier — client → piste →
     * cotation → avenant → (tranches, documents). Au-delà, on ne descend plus dans un
     * dossier, on visite le portefeuille.
     */
    public const PROFONDEUR_MAX = 4;

    /** Nombre maximal d'enregistrements visités : un dossier, pas une base entière. */
    public const NOEUDS_MAX = 400;

    /**
     * Collections à NE PAS suivre.
     *
     * `documents` et `preuves` sont la RÉCOLTE, pas le chemin : les suivre ferait
     * redescendre depuis un document. Les autres sont des collections d'audit ou de
     * journal, qui gonflent la descente sans jamais porter de pièce.
     */
    private const COLLECTIONS_IGNOREES = ['documents', 'preuves', 'tokenConsumptions', 'messages', 'contextes'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DocumentFichier $fichier,
    ) {
    }

    /**
     * Les documents trouvés sous cette fiche, la fiche elle-même comprise.
     *
     * Chaque ligne porte son NIVEAU — la rubrique de l'enregistrement qui la détient —
     * parce que c'est ce que l'utilisateur cherche à savoir quand il retrouve un fichier
     * dans une liste : d'où il sort.
     *
     * @param array<string, string> $libelles nom court d'entité => intitulé d'écran
     *
     * @return array{
     *     documents: list<array{document: Document, niveau: string, entite: string, source: object}>,
     *     tronque: bool,
     *     noeuds: int
     * }
     */
    public function depuis(object $racine, array $libelles = [], int $profondeurMax = self::PROFONDEUR_MAX): array
    {
        $vus = [];              // entités déjà visitées, par classe#id
        $documentsVus = [];     // documents déjà récoltés, par id
        $trouves = [];
        $noeuds = 0;
        $tronque = false;

        // Parcours en LARGEUR : les pièces les plus proches de la fiche nommée sortent
        // les premières. Si le plafond tombe, ce qui manque est le plus lointain — la
        // troncature ampute donc le moins pertinent, jamais le plus attendu.
        $file = [[$racine, 0]];
        $vus[$this->cle($racine)] = true;

        while ($file !== []) {
            [$entite, $profondeur] = array_shift($file);

            if (++$noeuds > self::NOEUDS_MAX) {
                $tronque = true;
                break;
            }

            foreach ($this->documentsDe($entite) as $document) {
                $id = $document->getId();
                if ($id === null || isset($documentsVus[$id])) {
                    continue;
                }
                $documentsVus[$id] = true;
                $shortName = $this->nomCourt($entite);
                $trouves[] = [
                    'document' => $document,
                    'entite'   => $shortName,
                    'niveau'   => $libelles[$shortName] ?? $shortName,
                    'source'   => $entite,
                ];
            }

            if ($profondeur >= $profondeurMax) {
                continue;
            }

            foreach ($this->enfantsDe($entite) as $enfant) {
                $cle = $this->cle($enfant);
                if (isset($vus[$cle])) {
                    continue;
                }
                $vus[$cle] = true;
                $file[] = [$enfant, $profondeur + 1];
            }
        }

        return ['documents' => $trouves, 'tronque' => $tronque, 'noeuds' => $noeuds];
    }

    /**
     * Les pièces portées PAR cette entité, INTERROGÉES en base — jamais lues dans la
     * collection inverse.
     *
     * ⚠️ ET C'EST TOUT L'INTÉRÊT. Une entité créée dans l'unité de travail courante garde
     * une collection inverse VIDE en mémoire : `$document->setAvenant($avenant)` renseigne
     * le côté propriétaire, et rien n'ajoute le document à `$avenant->getDocuments()`
     * tant que l'entité n'a pas été rechargée. Lire la collection ferait donc répondre
     * « aucun fichier » sur un dossier qu'on vient de constituer — précisément le moment
     * où l'utilisateur regarde. La requête, elle, voit ce qui est en base.
     *
     * Elle règle aussi le vocabulaire d'un coup : `documents` et `preuves` sont deux noms
     * de collection, mais UN SEUL champ sur Document (la relation vers l'entité). On
     * interroge donc ce champ, quel que soit le mot que le formulaire emploie.
     *
     * @return list<Document>
     */
    private function documentsDe(object $entite): array
    {
        $champ = $this->fichier->parentsPossibles()[$this->nomCourt($entite)] ?? null;
        if ($champ === null || !method_exists($entite, 'getId') || $entite->getId() === null) {
            return [];
        }

        return $this->em->getRepository(Document::class)->findBy([$champ => $entite], ['id' => 'ASC']);
    }

    /**
     * Les enregistrements SOUS celui-ci : les membres de ses collections.
     *
     * On ne suit que les collections (un-vers-plusieurs, plusieurs-vers-plusieurs) :
     * remonter une relation vers-un ferait sortir du dossier par le haut — depuis une
     * cotation, on atteindrait le client, puis toutes ses autres pistes. « Les fichiers
     * de cette cotation » deviendrait « les fichiers de tout le monde ».
     *
     * @return list<object>
     */
    private function enfantsDe(object $entite): array
    {
        $meta = $this->em->getClassMetadata($entite::class);
        if (!method_exists($entite, 'getId') || $entite->getId() === null) {
            return [];
        }

        $enfants = [];
        foreach (array_keys($meta->getAssociationMappings()) as $champ) {
            if (\in_array($champ, self::COLLECTIONS_IGNOREES, true)) {
                continue;
            }
            if (!$meta->isCollectionValuedAssociation($champ)) {
                continue;
            }
            $cible = $meta->getAssociationTargetClass($champ);
            // Un document est une RÉCOLTE, jamais un chemin : on ne redescend pas
            // depuis lui, quel que soit le nom de la collection qui le porte.
            if ($cible === Document::class) {
                continue;
            }

            // MÊME RAISON QUE POUR LES DOCUMENTS : la collection inverse d'une entité
            // créée dans l'unité de travail courante est VIDE en mémoire. La lire ferait
            // s'arrêter la descente au premier étage sur un dossier qu'on vient de
            // constituer. On interroge donc l'enfant par son champ de rattachement.
            if ($meta->isAssociationInverseSide($champ)) {
                $mappedBy = $meta->getAssociationMappedByTargetField($champ);
                foreach ($this->em->getRepository($cible)->findBy([$mappedBy => $entite]) as $enfant) {
                    $enfants[] = $enfant;
                }
                continue;
            }

            // Côté PROPRIÉTAIRE d'un plusieurs-vers-plusieurs : aucun champ à interroger
            // sur l'enfant, la table de jointure porte seule le lien. On lit alors la
            // collection — sur une entité rechargée, Doctrine la charge à la demande.
            $getter = 'get' . ucfirst($champ);
            if (!method_exists($entite, $getter)) {
                continue;
            }
            $valeur = $entite->{$getter}();
            $membres = $valeur instanceof Collection ? $valeur->toArray() : (is_array($valeur) ? $valeur : []);
            foreach ($membres as $membre) {
                if (\is_object($membre)) {
                    $enfants[] = $membre;
                }
            }
        }

        return $enfants;
    }

    /** Identité stable d'une entité pour la détection de cycle. */
    private function cle(object $entite): string
    {
        $id = method_exists($entite, 'getId') ? $entite->getId() : spl_object_id($entite);

        return $this->nomCourt($entite) . '#' . $id;
    }

    /** Nom court de la classe RÉELLE (une entité chargée peut arriver en proxy). */
    private function nomCourt(object $entite): string
    {
        return (new \ReflectionClass($this->em->getClassMetadata($entite::class)->getName()))->getShortName();
    }

    /**
     * Une ligne de tableau prête à présenter, pour un document trouvé.
     *
     * Le format et la taille sont lus par DocumentFichier — la même source que la fiche
     * et que le téléchargement —, jamais recalculés ici : trois lectures du même fichier
     * finiraient par en donner trois tailles.
     *
     * @param array{document: Document, niveau: string, entite: string, source: object} $trouve
     *
     * @return array{id: int, nom: string, format: string, taille: string, octets: ?int, niveau: string}
     */
    public function ligne(array $trouve, int $numero): array
    {
        $document = $trouve['document'];
        $extension = $this->fichier->extension($document);
        $octets = $this->fichier->taille($document);

        return [
            'n°'     => $numero,
            'id'     => (int) $document->getId(),
            'nom'    => $this->fichier->nomDeTelechargement($document),
            'format' => $extension !== '' ? strtoupper($extension) : '—',
            'taille' => DocumentFichier::tailleLisible($octets),
            'octets' => $octets,
            'niveau' => $trouve['niveau'],
        ];
    }
}
