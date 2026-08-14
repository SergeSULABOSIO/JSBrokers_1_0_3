<?php

namespace App\Ai\Document;

use App\Ai\FicheNormaliseur;
use App\Entity\Document;
use App\Service\Document\DocumentFichier;

/**
 * TOUT CE QU'ON SAIT D'UN FICHIER AU MOMENT DE LE REMETTRE À QUELQU'UN.
 *
 * CE QUE CET OBJET RÉSOUT. Télécharger un fichier sans son contexte, c'est recevoir
 * « contrat.pdf » et devoir rouvrir le logiciel pour savoir de quel dossier il sort.
 * L'utilisateur qui demande un document à Ket a besoin de la même chose que devant la
 * rubrique : d'où vient ce fichier, à quoi il se rattache, et ce que vaut l'objet
 * auquel il appartient.
 *
 * TROIS STRATES, ET AUCUN CALCULATEUR ÉCRIT ICI.
 *
 *  1. LE FICHIER : nom réel, format, poids, date de mise en ligne — la matérialité,
 *     que {@see DocumentFichier} détient déjà pour l'interface comme pour l'assistant.
 *  2. LA FICHE DU DOCUMENT : ses attributs stockés et ses indicateurs calculés
 *     (classeur, rattachement en clair, âge, type de fichier).
 *  3. LA FICHE DU PARENT : les attributs stockés de l'objet d'origine ET **tous** ses
 *     indicateurs calculés — prime, commission, statut, échéance… selon l'entité.
 *
 * Les strates 2 et 3 sortent du MÊME appel, {@see FicheNormaliseur::ficheEnrichie()},
 * qui sert déjà à lire une fiche et à décrire les objets attachés au chat. Il ne
 * tronque rien, par décision documentée dans son en-tête — c'est précisément ce qui
 * permet de promettre « 100 % de ce que la base et les calculateurs savent » sans
 * écrire une seule stratégie d'indicateur de plus.
 *
 * BEST-EFFORT SUR LE PARENT. Une stratégie de calcul qui échoue ne doit jamais priver
 * l'utilisateur de son téléchargement : on rend alors le contexte sans la fiche du
 * parent, jamais une erreur.
 */
final class ContexteDeDocument
{
    public function __construct(
        private readonly DocumentFichier $documentFichier,
        private readonly FicheNormaliseur $ficheNormaliseur,
    ) {
    }

    /**
     * La LIGNE de tableau : ce qui se lit d'un coup d'œil, et ce que le modèle a le
     * droit de présenter en colonnes (règle « une colonne présentable est une colonne
     * renvoyée »).
     *
     * @return array{
     *     id: int,
     *     nom: string,
     *     fichier: string,
     *     format: string,
     *     taille: string,
     *     octets: int|null,
     *     chargeLe: string,
     *     rattacheA: string,
     *     classeur: string
     * }
     */
    public function ligne(Document $document): array
    {
        $octets = $this->documentFichier->taille($document);
        $chargeLe = $this->documentFichier->chargeLe($document);
        $format = $this->documentFichier->extension($document);

        return [
            'id'        => (int) $document->getId(),
            'nom'       => (string) $document->getNom(),
            'fichier'   => $this->documentFichier->nomDeTelechargement($document),
            'format'    => $format === '' ? 'inconnu' : strtoupper($format),
            'taille'    => DocumentFichier::tailleLisible($octets),
            'octets'    => $octets,
            'chargeLe'  => $chargeLe?->format('Y-m-d') ?? '',
            'rattacheA' => $this->rattachement($document),
            'classeur'  => $document->getClasseur()?->getNom() ?? '',
        ];
    }

    /**
     * Le DOSSIER complet : la ligne, plus les deux fiches enrichies. C'est ce que Ket
     * lit pour commenter en prose ce qu'elle remet.
     *
     * @return array<string, mixed>
     */
    public function complet(Document $document): array
    {
        $contexte = $this->ligne($document);
        $contexte['fiche'] = $this->ficheNormaliseur->ficheEnrichie($document);

        $parent = $this->documentFichier->parentDe($document);
        if ($parent !== null) {
            $contexte['origine'] = [
                'entite' => (new \ReflectionClass($parent))->getShortName(),
                'id'     => method_exists($parent, 'getId') ? $parent->getId() : null,
                'fiche'  => $this->ficheNormaliseur->ficheEnrichie($parent),
            ];
        }

        return $contexte;
    }

    /**
     * Le rattachement en une phrase courte, destinée à une CELLULE de tableau.
     *
     * Volontairement plus bref que le « parent_string » de la stratégie d'indicateurs
     * (« Lié à l'avenant (police n°…) ») : dans une colonne, la moitié de la phrase est
     * la même sur toutes les lignes et ne distingue rien. On garde l'entité et son
     * libellé — le reste vit dans la fiche complète.
     */
    private function rattachement(Document $document): string
    {
        $parent = $this->documentFichier->parentDe($document);
        if ($parent === null) {
            return 'aucun';
        }

        $entite = (new \ReflectionClass($parent))->getShortName();
        $libelle = $this->libelle($parent);

        return $libelle === '' ? $entite : $entite . ' ' . $libelle;
    }

    /**
     * Libellé sûr d'un objet parent, SANS cast (string) : toutes les entités n'ont pas
     * de __toString, et le cast ferait échouer tout le téléchargement pour un libellé.
     */
    private function libelle(object $parent): string
    {
        foreach (['getNom', 'getReferencePolice', 'getReference', 'getLibelle', 'getTitre', 'getDescription'] as $getter) {
            if (!method_exists($parent, $getter)) {
                continue;
            }
            $valeur = $parent->{$getter}();
            if (\is_string($valeur) && trim(strip_tags($valeur)) !== '') {
                $valeur = trim(strip_tags($valeur));

                return mb_strlen($valeur) > 60 ? mb_substr($valeur, 0, 57) . '…' : $valeur;
            }
        }

        $id = method_exists($parent, 'getId') ? $parent->getId() : null;

        return $id !== null ? '#' . $id : '';
    }
}
