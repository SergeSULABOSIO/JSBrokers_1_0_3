<?php

namespace App\Service\Document;

use App\Entity\Document;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Symfony\Component\Mime\MimeTypes;
use Vich\UploaderBundle\Storage\StorageInterface;

/**
 * SOURCE UNIQUE de la MATÉRIALITÉ d'un Document : le binaire derrière la ligne en
 * base — existe-t-il, que pèse-t-il, dans quel format, depuis quand, sous quel nom
 * doit-il arriver chez l'utilisateur, et à quel objet métier se rattache-t-il.
 *
 * POURQUOI CE SERVICE EXISTE. Ces questions étaient posées à trois endroits qui ne
 * se parlaient pas, et qui ne répondaient pas pareil :
 *
 *  - {@see \App\Controller\Admin\DocumentController::downloadApi()} servait le fichier
 *    sous son nom de STOCKAGE Vich (« contrat-a1b2c3d4.pdf ») ;
 *  - le contrôleur de l'assistant restituait, lui, un nom lisible — via une méthode
 *    PRIVÉE que l'interface ne pouvait pas appeler ;
 *  - {@see \App\Services\Canvas\Indicator\DocumentIndicatorStrategy} énumérait les
 *    parents possibles À LA MAIN, et en oubliait un : un document servant de preuve à
 *    un PaiementPrime s'y annonçait « rattaché à aucun élément parent », alors que la
 *    relation existe depuis que le signalement de paiement de prime existe.
 *
 * C'est la famille de défaut habituelle : une donnée qui EST là, qu'une surface ne
 * regarde pas, et dont l'utilisateur conclut qu'elle n'existe pas. Le remède est le
 * même que partout ailleurs dans ce projet — une seule vérité, DÉRIVÉE du code.
 *
 * LA LISTE DES PARENTS N'EST DONC PAS ÉCRITE ICI : elle est lue dans les métadonnées
 * Doctrine. Le jour où l'on rattache Document à une nouvelle entité, aucune liste
 * n'est à mettre à jour — ni ici, ni dans la stratégie d'indicateurs.
 */
final class DocumentFichier
{
    /**
     * Le champ Vich de l'entité Document. Nommer la propriété PHP et non la colonne :
     * c'est ce que réclament StorageInterface et DownloadHandler.
     */
    public const CHAMP_VICH = 'fichier';

    /**
     * Relations ManyToOne qui ne sont PAS un rattachement métier.
     *
     * `entreprise` et `invite` viennent d'AuditableTrait et portent le SCOPING, pas
     * l'origine : elles existent sur une quarantaine d'entités et répondent à « à qui
     * appartient cette ligne », jamais à « de quel dossier sort ce fichier ». Les
     * oublier ici ferait répondre « ce document est rattaché à l'entreprise Untel » à
     * chaque question — le premier ManyToOne déclaré l'emportant sur tous les autres.
     *
     * Le `classeur` est un RANGEMENT : un document classé dans « Contrats 2026 » et
     * rattaché à un avenant a pour origine l'avenant. Il reste restitué à part, sous
     * sa propre étiquette.
     */
    private const RELATIONS_NON_PARENTES = ['classeur', 'entreprise', 'invite'];

    /** @var list<string>|null noms des relations parentes, lus une fois dans les métadonnées */
    private ?array $relationsParentes = null;

    public function __construct(
        private readonly StorageInterface $storage,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** Le binaire est-il RÉELLEMENT sur le disque ? (une ligne en base ne le garantit pas) */
    public function existe(Document $document): bool
    {
        $chemin = $this->chemin($document);

        return $chemin !== null && is_file($chemin);
    }

    /** Chemin absolu du binaire, ou null si le document ne porte aucun fichier. */
    public function chemin(Document $document): ?string
    {
        if (($document->getNomFichierStocke() ?? '') === '') {
            return null;
        }

        return $this->storage->resolvePath($document, self::CHAMP_VICH) ?: null;
    }

    /** Poids en octets, ou null si le binaire est absent. */
    public function taille(Document $document): ?int
    {
        $chemin = $this->chemin($document);
        if ($chemin === null || !is_file($chemin)) {
            return null;
        }
        $taille = @filesize($chemin);

        return $taille === false ? null : $taille;
    }

    /** Extension en minuscules, sans point ; chaîne vide si indéterminable. */
    public function extension(Document $document): string
    {
        return strtolower(pathinfo((string) $document->getNomFichierStocke(), PATHINFO_EXTENSION));
    }

    /**
     * Type MIME déduit de l'EXTENSION, jamais du contenu.
     *
     * Deviner par le contenu (finfo) coûterait une lecture disque à chaque ligne d'une
     * liste, pour une information dont la seule consommation est l'en-tête d'une
     * réponse HTTP — que Vich pose déjà lui-même au moment du streaming.
     */
    public function mimeType(Document $document): string
    {
        $ext = $this->extension($document);
        if ($ext === '') {
            return 'application/octet-stream';
        }

        return MimeTypes::getDefault()->getMimeTypes($ext)[0] ?? 'application/octet-stream';
    }

    /**
     * Date de MISE EN LIGNE du document sur le serveur.
     *
     * C'est `createdAt`, et le choix mérite d'être justifié : `updatedAt` semble plus
     * proche du dépôt du binaire (le setter Vich le repositionne à chaque nouveau
     * fichier), mais le PreUpdate d'AuditableTrait le repositionne AUSSI pour une
     * simple correction de libellé. Il répond donc à « dernière modification », pas à
     * « déposé le » — et annoncer une date de chargement d'aujourd'hui parce qu'on a
     * corrigé une faute de frappe dans le nom serait un mensonge tranquille.
     *
     * `createdAt` dit exactement ce qu'on veut dire, et c'est déjà la date que la
     * rubrique Documents affiche sous l'intitulé « Ajouté le ». Les deux surfaces
     * racontent ainsi la même histoire. La date de dernière modification reste
     * disponible par ailleurs : la fiche enrichie la porte sous « modifieLe ».
     */
    public function chargeLe(Document $document): ?\DateTimeInterface
    {
        return $document->getCreatedAt();
    }

    /**
     * Le nom sous lequel le fichier doit ARRIVER chez l'utilisateur.
     *
     * Le libellé d'un Document (« Contrat KIN AVIA ») n'a pas d'extension : servi tel
     * quel, le fichier reçu est inouvrable — le système d'exploitation ne sait pas quoi
     * en faire. On restitue donc l'extension RÉELLE, celle que le nom de stockage
     * conserve (SmartUniqueNamer préserve l'extension d'origine).
     *
     * Les caractères interdits dans un nom de fichier Windows sont neutralisés : un
     * libellé de document est saisi librement, et « Avenant 12/2026 » y créerait un
     * chemin, pas un nom.
     */
    public function nomDeTelechargement(Document $document): string
    {
        $nom = trim((string) $document->getNom());
        $nom = trim(preg_replace('#[\\\\/:*?"<>|\r\n]+#', '-', $nom) ?? '');
        if ($nom === '') {
            $nom = 'document';
        }

        $ext = $this->extension($document);
        if ($ext !== '' && !str_ends_with(strtolower($nom), '.' . $ext)) {
            $nom .= '.' . $ext;
        }

        return $nom;
    }

    /**
     * L'objet métier auquel ce document est rattaché — le premier renseigné parmi les
     * relations ManyToOne de l'entité, hors rangement.
     *
     * « Le premier » et non « tous » : le formulaire n'en pose qu'un, et les 14 autres
     * colonnes restent nulles. Un document a une origine, pas quatorze.
     */
    public function parentDe(Document $document): ?object
    {
        foreach ($this->relationsParentes() as $relation) {
            $getter = 'get' . ucfirst($relation);
            if (!method_exists($document, $getter)) {
                continue;
            }
            $parent = $document->{$getter}();
            if (\is_object($parent)) {
                return $parent;
            }
        }

        return null;
    }

    /**
     * Noms des relations parentes, DANS L'ORDRE DE DÉCLARATION de l'entité.
     *
     * L'ordre compte : c'est lui qui départage un document qui porterait deux
     * rattachements. Le conserver tel que l'entité le déclare rend le choix
     * reproductible et lisible — on n'a qu'à ouvrir Document.php pour le connaître.
     *
     * @return list<string>
     */
    private function relationsParentes(): array
    {
        if ($this->relationsParentes !== null) {
            return $this->relationsParentes;
        }

        $relations = [];
        foreach ($this->em->getClassMetadata(Document::class)->getAssociationMappings() as $champ => $mapping) {
            // Doctrine ORM 3 rend des objets AssociationMapping ; les versions
            // antérieures, des tableaux. On accepte les deux : ce service ne doit pas
            // se taire le jour d'une montée de version — se taire, ici, signifierait
            // « ce document n'est rattaché à rien », ce qui est faux.
            $estManyToOne = \is_object($mapping) && method_exists($mapping, 'isManyToOne')
                ? $mapping->isManyToOne()
                : (\is_array($mapping) && ($mapping['type'] ?? null) === ClassMetadata::MANY_TO_ONE);

            if (!$estManyToOne || \in_array($champ, self::RELATIONS_NON_PARENTES, true)) {
                continue;
            }
            $relations[] = $champ;
        }

        return $this->relationsParentes = $relations;
    }

    /** Poids lisible (o / Ko / Mo) — la même échelle que celle affichée par le chat. */
    public static function tailleLisible(?int $octets): string
    {
        if ($octets === null) {
            return 'inconnue';
        }
        if ($octets < 1024) {
            return $octets . ' o';
        }
        if ($octets < 1024 * 1024) {
            return number_format($octets / 1024, 1, ',', ' ') . ' Ko';
        }

        return number_format($octets / (1024 * 1024), 1, ',', ' ') . ' Mo';
    }
}
