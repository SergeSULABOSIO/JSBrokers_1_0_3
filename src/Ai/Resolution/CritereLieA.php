<?php

namespace App\Ai\Resolution;

use App\Ai\Scope\AiScope;
use App\Ai\Tool\AiToolResult;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Services\JSBDynamicSearchService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * « LES DOCUMENTS DE LA POLICE KIN AVIA » — la traduction d'un rattachement dicté en
 * langage naturel vers un critère de recherche, SOURCE UNIQUE pour tous les outils.
 *
 * POURQUOI CE COLLABORATEUR EXISTE. Cette résolution est tout sauf anodine : elle
 * accepte un NOM plutôt qu'un identifiant (le modèle n'a presque jamais l'identifiant,
 * et le lui exiger le condamnerait à un second tour d'outils que l'architecture
 * interdit), elle vérifie le droit de lecture sur l'entité de rattachement — référencer
 * une fiche, c'est la lire —, elle emprunte TOUS les chemins de relations et non le plus
 * court, et elle sait rendre une QUESTION déjà formulée quand le nom ne se résout pas.
 *
 * Cinquante lignes de décisions, dont chacune a été payée par un incident. Elles
 * vivaient à l'intérieur de rechercher_entites. Le second outil qui en a eu besoin
 * (telecharger_documents) n'avait donc que deux options : les recopier — et diverger au
 * premier correctif — ou s'en passer, et réclamer un identifiant que l'utilisateur n'a
 * pas. C'est le motif habituel de ce projet : ce que deux surfaces doivent faire pareil
 * se met en un seul endroit.
 *
 * TOUS LES CHEMINS, JAMAIS LE PLUS COURT SEUL. Le plus court peut emprunter une relation
 * secondaire souvent nulle (Avenant.pisteDeRenouvellement → Client) tandis que le vrai
 * lien passe plus profond (Avenant.cotation.piste.client). On les combine en OR : le
 * moteur retient un enregistrement dès qu'UN chemin pointe sur la fiche.
 */
final class CritereLieA
{
    public function __construct(
        private readonly WorkspaceAccessResolver $accessResolver,
        private readonly ResolveurDeReferences $resolveur,
        private readonly CheminsDeRelation $chemins,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @param mixed  $lieA  la valeur brute de l'argument `lieA` telle que le modèle l'a
     *                      donnée : `{entite: "Client", id: 42}` ou `{entite: "Client",
     *                      nom: "Kibali"}`. Toute autre forme donne une résolution vide.
     * @param string $fqcn  l'entité que l'on cherche (celle qu'on veut RESTREINDRE)
     */
    public function resoudre(mixed $lieA, string $fqcn, AiScope $scope): ResolutionLieA
    {
        if (!\is_array($lieA) || $lieA === []) {
            return ResolutionLieA::absent();
        }

        $labels = $this->accessResolver->libellesEntites();
        $lienType = (string) ($lieA['entite'] ?? '');
        $lienFqcn = 'App\\Entity\\' . $lienType;
        $lienId = (int) ($lieA['id'] ?? 0);
        $nomLien = trim((string) ($lieA['nom'] ?? ''));

        // NOM PLUTÔT QU'IDENTIFIANT. Le modèle n'a presque jamais l'identifiant : le lui
        // exiger, c'est le condamner à une recherche préalable — donc à un SECOND tour
        // d'outils, que l'architecture interdit (cf. MAX_TOOL_ROUNDS). C'est exactement
        // ce qui se produisait sur « les polices du client Kibali » : le modèle cherchait
        // le client, n'avait plus de tour pour chercher ses polices, et l'utilisateur
        // recevait une non-réponse. Le serveur résout donc le nom lui-même, gratuitement.
        if ($lienId <= 0 && $nomLien !== '' && isset($labels[$lienType]) && class_exists($lienFqcn)) {
            if (!$this->accessResolver->canRead($scope->invite, $lienType)) {
                return ResolutionLieA::refus(AiToolResult::horsPerimetre($labels[$lienType]));
            }
            $reference = $this->resolveur->resoudre($lienType, $nomLien, $scope);
            if ($reference->estResolue()) {
                $lienId = (int) $reference->id;
            } else {
                // On ne devine pas : on rend une question DÉJÀ formulée, avec ses
                // candidats. Une question précise vaut mieux qu'une liste vide.
                return ResolutionLieA::refus(AiToolResult::ok([
                    'pret'      => false,
                    'aDemander' => [$reference->question()],
                    'note'      => 'Le rattachement demandé ne se résout pas. Pose la question telle quelle, '
                        . 'en UNE ligne, en PROPOSANT les « valeurs » quand il y en a. N’invente aucun '
                        . 'identifiant, ne relance AUCUN outil et n’annonce pas de liste.',
                ]));
            }
        }

        if (!isset($labels[$lienType]) || !class_exists($lienFqcn) || $lienId <= 0) {
            return ResolutionLieA::ignore();
        }
        if (!$this->accessResolver->canRead($scope->invite, $lienType)) {
            return ResolutionLieA::refus(AiToolResult::horsPerimetre($labels[$lienType]));
        }

        $cheminsVers = $this->chemins->vers($fqcn, $lienFqcn);

        // RATTACHEMENT UNIVERSEL — l'entité cherchée porte-t-elle le couple
        // cibleType/cibleId ? La question se pose aux MÉTADONNÉES, pas à une liste :
        // aujourd'hui seul Document le porte, et le jour où une autre entité l'adopte,
        // ce collaborateur n'a pas à l'apprendre.
        //
        // Sans cela, « les documents de la tranche n°12 » retombait sur ignore() —
        // c'est-à-dire AUCUN filtre —, et l'outil rendait tous les documents du
        // portefeuille en les présentant comme ceux de la tranche. Une réponse fausse
        // et confiante, la pire des deux.
        $cibleUniverselle = $this->supporteCibleUniverselle($fqcn) ? $lienType : null;

        if ($cheminsVers === [] && $cibleUniverselle === null) {
            return ResolutionLieA::ignore();
        }

        return ResolutionLieA::lie(
            ['entite' => $lienType, 'id' => $lienId],
            [JSBDynamicSearchService::LIEN_MULTI_CHEMINS => array_filter([
                'paths' => $cheminsVers,
                'id'    => $lienId,
                'cible' => $cibleUniverselle,
            ])],
        );
    }

    /** L'entité cherchée porte-t-elle le couple de rattachement universel ? */
    private function supporteCibleUniverselle(string $fqcn): bool
    {
        try {
            $meta = $this->em->getClassMetadata($fqcn);
        } catch (\Throwable) {
            return false;
        }

        return $meta->hasField('cibleType') && $meta->hasField('cibleId');
    }
}
