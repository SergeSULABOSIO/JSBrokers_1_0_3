<?php

namespace App\Services\Canvas\Provider\Entity;

use App\Entity\Assureur;
use App\Entity\Avenant;
use App\Entity\Classeur;
use App\Entity\Client;
use App\Entity\Cotation;
use App\Entity\Document;
use App\Entity\Piste;
use App\Entity\Risque;
use App\Services\Canvas\CanvasHelper;
use App\Services\ServiceMonnaies;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.entity_canvas_provider')]
class DocumentEntityCanvasProvider implements EntityCanvasProviderInterface
{
    public function __construct(
        private ServiceMonnaies $serviceMonnaies,
        private CanvasHelper $canvasHelper
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Document::class;
    }

    public function getCanvas(): array
    {
        return [
            "parametres" => [
                "description" => "Document",
                "icone" => "document",
                'background_image' => '/images/fitures/document.png',
                'description_template' => ["Document: [[*nom]]. Parent: [[parent_string]]. Fichier: [[nomFichierStocke]]."]
            ],
            "liste" => array_merge([
                ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                ["code" => "nom", "intitule" => "Nom", "type" => "Texte"],
                ["code" => "nomFichierStocke", "intitule" => "Nom Fichier", "type" => "Texte"],
                ["code" => "createdAt", "intitule" => "Créé le", "type" => "Date"],
                ["code" => "updatedAt", "intitule" => "Modifié le", "type" => "Date"],
            ], $this->getRattachements(), $this->getSpecificIndicators())
        ];
    }

    /**
     * LES RATTACHEMENTS, EXPOSÉS COMME CRITÈRES DE RECHERCHE.
     *
     * CE QUI MANQUAIT. La recherche avancée des documents ne proposait que le nom du
     * fichier et deux dates. Or ce qu'on cherche d'un document, ce n'est presque jamais
     * son nom — c'est à QUOI il se rattache : « les pièces de ce client », « celles de
     * cette police », « ce que l'assureur nous a envoyé ». Faute de ces critères, la seule
     * façon de retrouver une pièce était d'en connaître déjà le nom de fichier, ce qui
     * suppose de l'avoir sous les yeux.
     *
     * LES SIX SONT DES RELATIONS DIRECTES de Document — aucune n'est dérivée, aucune n'est
     * calculée. Le filtre porte donc exactement sur le rattachement enregistré : une pièce
     * déposée sur la POLICE se retrouve par « Avenant », pas par « Client ». C'est la
     * lecture littérale, et c'est celle qui ne ment pas — une pièce a un parent, pas
     * quatorze ({@see \App\Service\Document\DocumentFichier::parentDe()}). Pour balayer un
     * dossier ENTIER, tous niveaux confondus, l'outil existe et c'est celui de Ket
     * ({@see \App\Service\Document\DescenteDesDocuments}).
     *
     * ET CELA VAUT AUSSI POUR KET, sans une ligne de plus : le canevas d'entité est la
     * source unique de la fiche, de la liste ET des critères de recherche — ceux de
     * l'écran comme ceux de `rechercher_entites`. Déclarer un attribut ici, c'est ouvrir
     * le filtre aux deux à la fois ; en retirer un le fermerait aux deux.
     */
    private function getRattachements(): array
    {
        return [
            ["group" => "Rattachement", "code" => "client", "intitule" => "Client", "type" => "Relation", "targetEntity" => Client::class, "displayField" => "nom"],
            ["group" => "Rattachement", "code" => "assureur", "intitule" => "Assureur", "type" => "Relation", "targetEntity" => Assureur::class, "displayField" => "nom"],
            ["group" => "Rattachement", "code" => "risque", "intitule" => "Risque", "type" => "Relation", "targetEntity" => Risque::class, "displayField" => "nomComplet"],
            ["group" => "Rattachement", "code" => "piste", "intitule" => "Piste", "type" => "Relation", "targetEntity" => Piste::class, "displayField" => "nom"],
            ["group" => "Rattachement", "code" => "cotation", "intitule" => "Proposition", "type" => "Relation", "targetEntity" => Cotation::class, "displayField" => "nom"],
            ["group" => "Rattachement", "code" => "avenant", "intitule" => "Police", "type" => "Relation", "targetEntity" => Avenant::class, "displayField" => "referencePolice"],
            ["group" => "Rattachement", "code" => "classeur", "intitule" => "Classeur", "type" => "Relation", "targetEntity" => Classeur::class, "displayField" => "nom"],
        ];
    }

    private function getSpecificIndicators(): array
    {
        return [
            ["group" => "Contexte", "code" => "parent_string", "intitule" => "Contexte", "type" => "Calcul", "format" => "Texte", "fonction" => "Document_getParentAsString", "description" => "Entité parente à laquelle le document est rattaché."],
            ["group" => "Contexte", "code" => "classeur_string", "intitule" => "Classement", "type" => "Calcul", "format" => "Texte", "fonction" => "Document_getClasseurAsString", "description" => "Classeur dans lequel le document est archivé."],
            ["group" => "Fichier", "code" => "ageDocument", "intitule" => "Âge du document", "type" => "Calcul", "format" => "Texte", "fonction" => "calculateDocumentAge", "description" => "Âge du document depuis sa création."],
            ["group" => "Fichier", "code" => "typeFichier", "intitule" => "Type de fichier", "type" => "Calcul", "format" => "Texte", "fonction" => "getDocumentTypeFichier", "description" => "Extension du fichier."],
            ["group" => "Fichier", "code" => "tailleFichier", "intitule" => "Taille du fichier", "type" => "Calcul", "format" => "Texte", "fonction" => "getDocumentTaille", "description" => "Poids du fichier sur le serveur."],
        ];
    }
}