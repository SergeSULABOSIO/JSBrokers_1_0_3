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
 *  - niveau 2b — UNIVERSEL : aucune relation typée, mais l'entité est persistée.
 *    Le couple `cibleType`/`cibleId` de Document la désigne. C'est ce niveau qui
 *    rend l'attachement possible sur N'IMPORTE QUEL objet de la plateforme.
 *  - niveau 3 — aucun rattachement possible : le fichier NE SERA PAS conservé en
 *    base. C'est une perte de donnée pour l'utilisateur, donc un AVERTISSEMENT
 *    que le serveur rédige lui-même. Le laisser à la charge du modèle, c'est
 *    accepter qu'il l'oublie — même raison que l'aperçu autoritaire d'un plan :
 *    ce que l'utilisateur lit avant de décider doit venir du serveur.
 *    Depuis l'ouverture du niveau universel, il ne subsiste plus que pour une
 *    entité INCONNUE (nom qui ne désigne aucune classe persistée).
 *
 * ⚠️ L'ORDRE EST LA RÈGLE, et il n'est pas décoratif. Un rattachement doit avoir
 * UNE écriture et une seule : si le couple universel pouvait doubler une relation
 * typée, la rubrique Documents et l'assistant liraient deux vérités différentes du
 * même fichier. Le niveau universel n'est donc atteint que lorsque les trois
 * premiers ont échoué — c'est ce que vérifie PieceSourceRattachementTest.
 */
final class PieceSourceRattachement
{
    // Les valeurs se lisent comme un RANG : plus le niveau est bas, plus le
    // rattachement est spécifique et proche de ce que l'écran fait déjà. Personne ne
    // persiste ces entiers (ils ne voyagent que dans le résultat d'outil du tour
    // courant), et tout le code les désigne par leur constante — c'est ce qui permet
    // d'insérer le niveau universel à sa place logique plutôt qu'à la fin.
    public const NIVEAU_CHAMP_PROPRE = 0;
    public const NIVEAU_COLLECTION   = 1;
    public const NIVEAU_RELATION     = 2;
    public const NIVEAU_UNIVERSEL    = 3;
    public const NIVEAU_AUCUN        = 4;

    /** Les deux moitiés du rattachement universel, telles que DocumentType les expose. */
    public const CHAMP_CIBLE_TYPE = 'cibleType';
    public const CHAMP_CIBLE_ID   = 'cibleId';

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

        // RATTACHEMENT UNIVERSEL — atteint seulement ici, une fois les trois formes
        // spécifiques écartées. L'ordre n'est pas une commodité : si ce couple pouvait
        // doubler une relation typée, le même fichier serait rattaché de deux façons
        // et les deux surfaces (rubrique Documents, assistant) n'en liraient pas la
        // même. Le dernier recours reste le dernier.
        if ($this->estUneEntitePersistee($shortName)) {
            return $this->descripteur(
                self::NIVEAU_UNIVERSEL,
                champ: self::CHAMP_CIBLE_ID,
                cibleType: $shortName,
                explication: sprintf(
                    'La pièce sera enregistrée comme Document rattaché à %s. Cette rubrique n’a pas de '
                    . 'collection « Documents » à l’écran : le rattachement est porté par le document '
                    . 'lui-même, et le fichier se retrouve depuis la rubrique Documents comme depuis '
                    . 'l’assistant.',
                    $libelle,
                ),
            );
        }

        return $this->descripteur(
            self::NIVEAU_AUCUN,
            explication: sprintf('Aucun rattachement de pièce n’est possible sur %s.', $libelle),
            avertissement: sprintf(
                'ATTENTION — « %s » ne désigne aucun enregistrement de la plateforme : LE FICHIER SOURCE '
                . 'NE SERA PAS CONSERVÉ EN BASE. Seules les données extraites du fichier y seront '
                . 'enregistrées. La pièce reste attachée à cette conversation (vous pouvez la '
                . 'retélécharger depuis le chat), mais elle disparaîtra avec elle.',
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
            // Même forme que le niveau 2 — une opération de tête chaînée au socle —,
            // au champ de rattachement près. `cibleId` accepte le renvoi « @socle »
            // comme n'importe quel autre champ : la résolution des renvois est
            // indifférente au nom du champ (WorkspaceMutationService), ce qui permet
            // de rattacher la pièce à un objet créé dans le MÊME plan.
            self::NIVEAU_UNIVERSEL => [
                'cible'    => 'operation',
                'fragment' => [
                    'op'     => 'create',
                    'entite' => self::ENTITE_DOCUMENT,
                    'etape'  => self::ETAPE,
                    'champs' => [
                        self::CHAMP_CIBLE_TYPE => $descripteur['cibleType'],
                        self::CHAMP_CIBLE_ID   => $renvoiSocle,
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

    /**
     * L'entité existe-t-elle vraiment, et Doctrine la persiste-t-elle ?
     *
     * C'est la SEULE condition du rattachement universel — et c'est aussi ce qui
     * empêche d'écrire un couple qui ne désignerait rien : un nom d'entité inventé
     * par le modèle (« Sinistre », « Dossier ») ne doit pas produire un document
     * rattaché à un fantôme, il doit produire l'avertissement de perte.
     */
    private function estUneEntitePersistee(string $shortName): bool
    {
        $fqcn = 'App\\Entity\\' . $shortName;
        if (!class_exists($fqcn)) {
            return false;
        }

        try {
            // Une classe du dossier Entity qui n'est PAS une entité mappée (les DTO
            // de ReportSet, par exemple) lève ici : c'est exactement le tri voulu.
            return !$this->em->getClassMetadata($fqcn)->isMappedSuperclass;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array{niveau:int, rattachable:bool, collection:?string, champ:?string, cibleType:?string, explication:string, avertissement:?string} */
    private function descripteur(
        int $niveau,
        ?string $collection = null,
        ?string $champ = null,
        ?string $cibleType = null,
        string $explication = '',
        ?string $avertissement = null,
    ): array {
        return [
            'niveau'        => $niveau,
            'rattachable'   => $niveau !== self::NIVEAU_AUCUN,
            'collection'    => $collection,
            'champ'         => $champ,
            // Renseigné au seul niveau universel : c'est la valeur littérale qui
            // partira dans la colonne `cible_type` du document.
            'cibleType'     => $cibleType,
            'explication'   => $explication,
            'avertissement' => $avertissement,
        ];
    }
}
