<?php

namespace App\Ai\Document;

use App\Ai\Document\Renderer\FichierTemporaire;
use App\Ai\Document\Renderer\RapportRendererResolver;
use App\Entity\AssistantDocument;
use App\Entity\AssistantMessage;
use App\Entity\Entreprise;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * FABRIQUE le document et le dépose : rendu, nommage, stockage, persistance.
 *
 * Ne débite RIEN. Le métrage reste au contrôleur, pour deux raisons : il doit se
 * produire APRÈS que les octets existent — on ne facture pas un rendu qui a
 * échoué — et un solde insuffisant doit devenir une réponse HTTP 402, pas une
 * exception traversant une couche de fabrication.
 *
 * Le binaire est confié à Vich via un fichier temporaire : le déplacement dans
 * var/uploads/assistant-documents a lieu au flush, avec un nom de stockage unique.
 * C'est le même mécanisme que la copie temporaire de `@fichier:` dans
 * WorkspaceMutationService.
 */
final class DocumentProducteur
{
    public function __construct(
        private readonly RapportRendererResolver $resolver,
        private readonly RapportAssembleur $assembleur,
        private readonly DocumentNommage $nommage,
        private readonly FichierTemporaire $temporaire,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Rend le document et renvoie ses OCTETS, sans rien persister.
     *
     * Séparé de la persistance : c'est ce qui permet au contrôleur de n'engager le
     * portefeuille qu'une fois le fichier réellement fabriqué.
     */
    public function rendre(RapportSpec $spec, DocumentFormat $format, PiedDePage $pied, ?ThemeDocument $theme = null): string
    {
        // Le thème n'est demandé qu'aux formats qui savent le tenir : ailleurs, on
        // rend clair plutôt que de laisser un rendu à moitié sombre s'échapper.
        $theme = $format->supporteTheme() ? ($theme ?? ThemeDocument::defaut()) : ThemeDocument::defaut();

        return $this->resolver->pour($format)->rendre($spec, $this->assembleur->sections($spec), $pied, $theme);
    }

    /**
     * Dépose les octets et persiste l'entité. Le flush est laissé à l'appelant :
     * il tient la transaction qui englobe aussi le débit.
     */
    public function deposer(
        string $octets,
        RapportSpec $spec,
        DocumentFormat $format,
        DocumentDevis $devis,
        AssistantMessage $message,
        Entreprise $entreprise,
        ?Utilisateur $auteur,
        \DateTimeImmutable $produitLe,
    ): AssistantDocument {
        $conversation = $message->getConversation();
        $nom = $this->nommage->nomFichier($spec->titre, $format, $produitLe);

        $document = (new AssistantDocument())
            ->setConversation($conversation)
            ->setMessage($message)
            ->setEntreprise($entreprise)
            ->setAuteur($auteur)
            ->setTitre(mb_substr($spec->titre, 0, 255))
            ->setNomFichier($nom)
            ->setFormat($format->value)
            ->setMime($format->mime())
            ->setTaille(strlen($octets))
            ->setCaracteres($devis->caracteres)
            ->setPages($devis->pages)
            ->setCoutTokens($devis->cout)
            ->setCreatedAt($produitLe);

        // Vich attend un fichier sur disque : on écrit les octets dans un
        // temporaire, qu'il DÉPLACERA au flush. Le temporaire n'est donc pas
        // supprimé ici — d'où l'écriture directe plutôt qu'un passage par
        // FichierTemporaire::avec(), qui nettoie toujours.
        //
        // UploadedFile et non File : le SmartUniqueNamer configuré sur ce mapping
        // appelle getClientOriginalName(), qui n'existe QUE sur UploadedFile. Avec
        // un File nu, aucun nom n'est calculé, le binaire n'est jamais déplacé et
        // nomFichierStocke reste null — la ligne existe alors en base sans fichier
        // derrière, et le téléchargement répond 404. C'est le même détour que
        // ConversationFichierResolver pour les pièces jointes.
        $chemin = $this->temporaire->chemin($format->extension());
        file_put_contents($chemin, $octets);
        $document->setFichier(new UploadedFile($chemin, $nom, $format->mime(), null, true));

        if ($conversation !== null) {
            $conversation->addDocumentGenere($document);
        }
        $this->em->persist($document);

        return $document;
    }

    /**
     * Le descriptif que le chat consomme : ce qui s'affiche sur le bouton, et
     * l'adresse derrière. Rendu ICI pour que la bulle en direct et la restauration
     * après rechargement montrent exactement la même chose.
     *
     * @return array<string, mixed>
     */
    public static function descriptif(AssistantDocument $document, string $url): array
    {
        $format = DocumentFormat::depuis($document->getFormat());

        return [
            'idDocument'    => $document->getId(),
            'titre'         => $document->getTitre(),
            'nom'           => $document->getNomFichier(),
            'format'        => $format->value,
            'formatBadge'   => $format->badge(),
            'formatLibelle' => $format->libelle(),
            'taille'        => $document->getTaille(),
            // Taille déjà FORMATÉE : le bouton rendu en direct par le navigateur et
            // celui rendu par Twig après un rechargement doivent afficher la même
            // chose, au caractère près.
            'tailleLisible' => self::tailleLisible($document->getTaille()),
            'pages'         => $document->getPages(),
            'coutTokens'    => $document->getCoutTokens(),
            'url'           => $url,
        ];
    }

    /** Taille lisible (o / Ko / Mo) — miroir de formatTaille() côté chat. */
    public static function tailleLisible(int $octets): string
    {
        if ($octets < 1024) {
            return $octets . ' o';
        }
        if ($octets < 1024 * 1024) {
            return number_format($octets / 1024, 1, ',', ' ') . ' Ko';
        }

        return number_format($octets / (1024 * 1024), 1, ',', ' ') . ' Mo';
    }
}
