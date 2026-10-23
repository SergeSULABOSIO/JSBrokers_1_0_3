<?php

namespace App\Service\Document;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Mime\MimeTypes;
use Vich\UploaderBundle\Storage\StorageInterface;

/**
 * LA RÉPONSE QUI SERT UN FICHIER DÉPOSÉ — une seule, pour toutes les routes (chantier 18,
 * décisions de Serge du 2026-10-22).
 *
 * Un fichier déposé vient d'un utilisateur : il peut être un .html ou un .svg porteur de
 * script. Ouvert DANS le navigateur depuis joseara.com, il s'exécuterait avec la session du
 * cabinet. D'où :
 *
 *   — AFFICHAGE seulement si on le demande ET si le type RÉEL (lu dans le contenu, pas
 *     l'extension) est dans la liste blanche : PDF, PNG, JPEG, GIF, WebP. Tout le reste part
 *     en `attachment` ;
 *   — sur TOUTES ces réponses : `X-Content-Type-Options: nosniff` (le navigateur ne devine
 *     pas un autre type) et `Content-Security-Policy: sandbox` (aucun script, aucune
 *     origine, même si le fichier finissait affiché) ;
 *   — le NOM D'ORIGINE est nettoyé (retours à la ligne, guillemets, caractères de contrôle,
 *     séparateurs de chemin) puis encodé selon la RFC 5987 (`filename*=UTF-8''…`), avec un
 *     repli ASCII : un nom piégé ne peut pas injecter d'en-tête.
 */
final class ReponseDePiece
{
    /** Les seuls types que le navigateur peut afficher. */
    public const AFFICHABLES = ['application/pdf', 'image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    public function __construct(
        private readonly StorageInterface $storage,
    ) {
    }

    /**
     * Le fichier d'une entité Vich (pièce, fichier ou document de Ket).
     *
     * @throws NotFoundHttpException si l'entité ne porte aucun fichier, ou s'il manque sur le disque
     */
    public function pour(object $entite, string $champ, string $nom, bool $afficher = false): BinaryFileResponse
    {
        $chemin = $this->storage->resolvePath($entite, $champ);
        if ($chemin === null || $chemin === '' || !is_file($chemin)) {
            throw new NotFoundHttpException('Fichier introuvable.');
        }

        return $this->servir($chemin, $nom, $afficher);
    }

    public function servir(string $chemin, string $nom, bool $afficher = false): BinaryFileResponse
    {
        $type = (new MimeTypes())->guessMimeType($chemin) ?? 'application/octet-stream';
        $affiche = $afficher && \in_array($type, self::AFFICHABLES, true);

        $reponse = new BinaryFileResponse($chemin);
        // Hors affichage, le type ne dit RIEN au navigateur : un .js servi sous son type
        // pourrait être chargé par <script src> depuis joseara.com (script-src 'self').
        $reponse->headers->set('Content-Type', $affiche ? $type : 'application/octet-stream');
        $reponse->headers->set('Content-Disposition', self::disposition(
            $affiche ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $nom,
        ));
        self::durcir($reponse);

        return $reponse;
    }

    /** Les en-têtes de toute réponse qui livre un fichier déposé (archives comprises). */
    public static function durcir(\Symfony\Component\HttpFoundation\Response $reponse): void
    {
        $reponse->headers->set('X-Content-Type-Options', 'nosniff');
        $reponse->headers->set('Content-Security-Policy', 'sandbox');
    }

    /** `Content-Disposition` d'un nom choisi par un utilisateur : nettoyé, puis RFC 5987. */
    public static function disposition(string $disposition, string $nom): string
    {
        $propre = self::nomPropre($nom);
        $repli = self::repliAscii($propre);

        return HeaderUtils::makeDisposition($disposition, $propre, $repli);
    }

    /** Longueur maximale d'un nom de téléchargement (extension comprise). */
    public const LONGUEUR_MAX = 150;

    /**
     * Sans contrôle (C0, C1), format (bidi U+202E…, largeur nulle), séparateur de ligne,
     * guillemet, antislash, barre ni `%` ; borné à LONGUEUR_MAX en gardant l'extension ;
     * jamais vide. Un UTF-8 invalide devient « document ».
     */
    public static function nomPropre(string $nom): string
    {
        $nom = preg_replace('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]+/u', ' ', $nom) ?? '';
        $nom = str_replace(['"', '\\', '/', '%'], ['', '-', '-', ''], $nom);
        $nom = trim(preg_replace('/\s+/u', ' ', $nom) ?? '', " .\t");
        if (mb_strlen($nom) > self::LONGUEUR_MAX) {
            $extension = pathinfo($nom, PATHINFO_EXTENSION);
            $extension = ($extension !== '' && mb_strlen($extension) <= 10) ? '.' . $extension : '';
            $nom = rtrim(mb_substr($nom, 0, self::LONGUEUR_MAX - mb_strlen($extension)), ' .') . $extension;
        }

        return $nom === '' ? 'document' : $nom;
    }

    /** Le repli des navigateurs anciens : ASCII imprimable, sans les caractères interdits. */
    private static function repliAscii(string $nom): string
    {
        $ascii = preg_replace('/[^\x20-\x7E]/', '_', $nom) ?? '';
        $ascii = str_replace(['"', '\\', '/', '%'], '', $ascii);

        return trim($ascii) === '' ? 'document' : $ascii;
    }
}
