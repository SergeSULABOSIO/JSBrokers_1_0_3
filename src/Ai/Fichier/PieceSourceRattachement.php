<?php

namespace App\Ai\Fichier;

use App\Entity\Document;
use App\Service\Workspace\FormTreeInspector;
use Doctrine\ORM\EntityManagerInterface;

/**
 * SORT DE LA PIÈCE SOURCE : quand Ket crée un enregistrement à partir d'un
 * fichier attaché au chat, où va le fichier lui-même ?
 *
 * La pièce ne doit jamais se détacher de la donnée qu'elle a produite. Le
 * rattachement n'est PAS câblé entité par entité : il se DÉDUIT, dans cet ordre,
 * de l'arbre des FormType et des métadonnées Doctrine — une entité qui gagne
 * demain une collection « documents » dans son formulaire bascule seule au
 * niveau 1, sans toucher à cette classe.
 *
 *  - niveau 0 — l'entité EST un Document : le fichier va dans son propre champ
 *    « fichier » (seul DocumentType porte un VichFileType).
 *  - niveau 1 — le formulaire expose une collection « documents » (allow_add) :
 *    sous-opération de collection, exactement comme le widget de l'écran.
 *  - niveau 2 — pas de collection au formulaire, mais Document porte une
 *    relation vers l'entité (ex. Paiement/PaiementPrime via « preuves ») :
 *    opération de tête distincte, chaînée par référence.
 *  - niveau 3 — aucun rattachement possible : le fichier NE SERA PAS conservé en
 *    base. C'est une perte de donnée pour l'utilisateur, donc un AVERTISSEMENT
 *    que le serveur rédige lui-même. Le laisser à la charge du modèle, c'est
 *    accepter qu'il l'oublie — même raison que l'aperçu autoritaire d'un plan :
 *    ce que l'utilisateur lit avant de décider doit venir du serveur.
 */
final class PieceSourceRattachement
{
    public const NIVEAU_CHAMP_PROPRE = 0;
    public const NIVEAU_COLLECTION   = 1;
    public const NIVEAU_RELATION     = 2;
    public const NIVEAU_AUCUN        = 3;

    /** Libellé d'étape : rend le classement décochable dans la barre de décision. */
    public const ETAPE = 'Classement de la pièce';

    private const COLLECTION   = 'documents';
    private const CHAMP_FICHIER = 'fichier';
    private const ENTITE_DOCUMENT = 'Document';

    /** Relations d'audit portées par le trait : ce ne sont pas des parents de classement. */
    private const RELATIONS_AUDIT = ['entreprise', 'invite'];

    public function __construct(
        private readonly FormTreeInspector $formTree,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Où ira la pièce source si l'on crée un enregistrement de cette entité.
     *
     * @param string      $shortName Nom court de l'entité de tête visée
     * @param string|null $libelle   Libellé lisible de la rubrique (pour l'avertissement)
     *
     * @return array{niveau:int, rattachable:bool, collection:?string, champ:?string,
     *               explication:string, avertissement:?string}
     */
    public function resoudre(string $shortName, ?string $libelle = null): array
    {
        $libelle ??= $shortName;

        if ($shortName === self::ENTITE_DOCUMENT) {
            return $this->descripteur(
                self::NIVEAU_CHAMP_PROPRE,
                champ: self::CHAMP_FICHIER,
                explication: 'La pièce est déposée directement dans le champ « fichier » du document créé.',
            );
        }

        $collection = $this->formTree->collectionEditable($shortName, self::COLLECTION);
        if ($collection !== null && $collection->allowAdd && $collection->childShortName === self::ENTITE_DOCUMENT) {
            return $this->descripteur(
                self::NIVEAU_COLLECTION,
                collection: self::COLLECTION,
                explication: sprintf(
                    'La pièce sera enregistrée comme Document et ajoutée à la collection « Documents » de %s — '
                    . 'la même que celle du formulaire à l’écran.',
                    $libelle,
                ),
            );
        }

        $champ = $this->relationDepuisDocument($shortName);
        if ($champ !== null) {
            return $this->descripteur(
                self::NIVEAU_RELATION,
                champ: $champ,
                explication: sprintf(
                    'La pièce sera enregistrée comme Document rattaché à %s (champ « %s »).',
                    $libelle,
                    $champ,
                ),
            );
        }

        return $this->descripteur(
            self::NIVEAU_AUCUN,
            explication: sprintf('Aucun rattachement de pièce n’est possible sur %s.', $libelle),
            avertissement: sprintf(
                'ATTENTION — la rubrique « %s » n’a pas de collection « Documents » et aucun document ne peut '
                . 'lui être rattaché : LE FICHIER SOURCE NE SERA PAS CONSERVÉ EN BASE. Seules les données '
                . 'extraites du fichier y seront enregistrées. La pièce reste attachée à cette conversation '
                . '(vous pouvez la retélécharger depuis le chat), mais elle disparaîtra avec elle.',
                $libelle,
            ),
        );
    }

    /**
     * Fragment à insérer dans le gabarit de plan pour classer la pièce, selon le
     * niveau résolu. Rule et matérialisation vivent ensemble : impossible de
     * décrire un rattachement qu'on ne saurait pas produire.
     *
     * Renvoie null au niveau 3 (rien à classer).
     *
     * @param string   $marqueurFichier « @fichier:<id> »
     * @param string   $nomDocument     Nom du Document (dérivé du nom d'origine de la pièce)
     * @param string   $renvoiSocle     Étiquette de renvoi vers la tête (« @socle ») ou id réel en édition
     *
     * @return array{cible:string, fragment:array}|null
     *         cible = 'champs' | 'collections' | 'operation'
     */
    public function fragmentGabarit(
        array $descripteur,
        string $marqueurFichier,
        string $nomDocument,
        string|int $renvoiSocle,
    ): ?array {
        return match ($descripteur['niveau']) {
            self::NIVEAU_CHAMP_PROPRE => [
                'cible'    => 'champs',
                'fragment' => [self::CHAMP_FICHIER => $marqueurFichier],
            ],
            self::NIVEAU_COLLECTION => [
                'cible'    => 'collections',
                'fragment' => [
                    'collection' => self::COLLECTION,
                    'elements'   => [[
                        'op'     => 'create',
                        'etape'  => self::ETAPE,
                        'champs' => ['nom' => $nomDocument, self::CHAMP_FICHIER => $marqueurFichier],
                    ]],
                ],
            ],
            self::NIVEAU_RELATION => [
                'cible'    => 'operation',
                'fragment' => [
                    'op'     => 'create',
                    'entite' => self::ENTITE_DOCUMENT,
                    'etape'  => self::ETAPE,
                    'champs' => [
                        $descripteur['champ']  => $renvoiSocle,
                        'nom'                  => $nomDocument,
                        self::CHAMP_FICHIER    => $marqueurFichier,
                    ],
                ],
            ],
            default => null,
        };
    }

    /**
     * Champ de Document pointant vers cette entité (ManyToOne propriétaire),
     * hors relations d'audit. null si Document ne connaît pas cette entité.
     */
    private function relationDepuisDocument(string $shortName): ?string
    {
        $cible = 'App\\Entity\\' . $shortName;
        if (!class_exists($cible)) {
            return null;
        }
        try {
            $meta = $this->em->getClassMetadata(Document::class);
        } catch (\Throwable) {
            return null;
        }

        foreach ($meta->getAssociationMappings() as $champ => $mapping) {
            if (in_array($champ, self::RELATIONS_AUDIT, true)) {
                continue;
            }
            if (!$mapping->isManyToOne() || !$mapping->isOwningSide()) {
                continue;
            }
            if (ltrim((string) ($mapping->targetEntity ?? ''), '\\') === $cible) {
                return $champ;
            }
        }

        return null;
    }

    /** @return array{niveau:int, rattachable:bool, collection:?string, champ:?string, explication:string, avertissement:?string} */
    private function descripteur(
        int $niveau,
        ?string $collection = null,
        ?string $champ = null,
        string $explication = '',
        ?string $avertissement = null,
    ): array {
        return [
            'niveau'        => $niveau,
            'rattachable'   => $niveau !== self::NIVEAU_AUCUN,
            'collection'    => $collection,
            'champ'         => $champ,
            'explication'   => $explication,
            'avertissement' => $avertissement,
        ];
    }
}
