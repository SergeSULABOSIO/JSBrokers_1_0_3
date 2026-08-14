<?php

namespace App\Ai\Resolution;

use App\Ai\AiText;
use App\Ai\Scope\AiScope;
use App\Ai\Tool\EntiteLibelle;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Services\JSBDynamicSearchService;

/**
 * SOURCE UNIQUE de la résolution « un NOM dicté → un identifiant ».
 *
 * POURQUOI CE SERVICE EXISTE — et c'est la cause racine de la panne du 2026-08-10.
 * Nos outils exigeaient des IDENTIFIANTS que le modèle n'a pas. Il les chassait donc,
 * un par tour : cinq rechercher_entites d'affilée à 37 700 tokens, 188 000 tokens pour
 * un seul message, et la fenêtre d'une minute vidée d'un coup — les messages suivants,
 * « salut » compris, se heurtaient à un mur. Ce n'était pas un caprice du modèle :
 * c'est nous qui l'y obligions.
 *
 * Depuis, la règle est qu'un outil résout lui-même ce qu'on lui dicte. Le modèle
 * transmet « SUNU », « Mme Marlette », « le magasin d'habillement » ; le serveur en
 * fait des identifiants. Plus rien à chasser, donc plus de chaîne — c'est ce qui rend
 * tenable le plafond d'UN SEUL tour d'outils par message.
 *
 * ON NE DEVINE JAMAIS. Trois issues, et trois seulement :
 *  - une correspondance EXACTE, ou une seule correspondance partielle → l'identifiant ;
 *  - aucune → « introuvable », avec les valeurs disponibles quand la liste est courte ;
 *  - plusieurs → « ambigu », avec les candidats.
 * Dans les deux derniers cas l'outil rend la main et Ket pose UNE question groupée.
 * Un tour de conversation vaut mieux que cinq tours de moteur — et il est visible,
 * donc corrigible d'une phrase.
 *
 * FAIL-CLOSED : rien n'est résolu hors du droit de LECTURE de l'invité sur l'entité
 * visée, et la recherche est scopée à l'entreprise par JSBDynamicSearchService.
 */
final class ResolveurDeReferences
{
    /** Au-delà, ce n'est plus une résolution mais une liste à faire trancher. */
    private const MAX_CANDIDATS = 10;

    public function __construct(
        private readonly JSBDynamicSearchService $searchService,
        private readonly WorkspaceAccessResolver $accessResolver,
        private readonly EntiteLibelle $libelleur,
    ) {
    }

    /**
     * Résout un nom dicté en identifiant, dans le périmètre de l'invité.
     *
     * @param string $terme ce que l'utilisateur a dit : un nom, ou déjà un identifiant
     *
     * @return Reference
     */
    public function resoudre(string $entite, string $terme, AiScope $scope): Reference
    {
        $terme = trim($terme);
        $libelle = $this->accessResolver->libellesEntites()[$entite] ?? $entite;

        if ($terme === '') {
            return Reference::absente($entite, $libelle);
        }

        // Le modèle a parfois déjà l'identifiant (fiche attachée, résultat précédent) :
        // on ne va pas le lui faire redécouvrir.
        if (ctype_digit($terme)) {
            return Reference::resolue($entite, $libelle, (int) $terme, '#' . $terme, $terme);
        }

        $candidats = $this->chercher($entite, $terme, $scope);

        if ($candidats === []) {
            return Reference::introuvable($entite, $libelle, $terme, $this->apercuDuReferentiel($entite, $scope));
        }
        if (count($candidats) > 1) {
            return Reference::ambigue($entite, $libelle, $terme, $candidats);
        }

        return Reference::resolue($entite, $libelle, (int) array_key_first($candidats), (string) reset($candidats), $terme);
    }

    /**
     * Résout par RATTACHEMENT : « la piste de Mme Marlette », « la cotation de cette
     * piste ». Quand le rattachement ne désigne qu'un seul enregistrement, c'est lui —
     * et le défaut est annoncé par l'appelant, jamais subi.
     */
    public function resoudreLie(string $entite, string $champ, int $idParent, AiScope $scope): Reference
    {
        $libelle = $this->accessResolver->libellesEntites()[$entite] ?? $entite;
        $candidats = $this->chercherPar($entite, [$champ => $idParent], null, $scope);

        if ($candidats === []) {
            return Reference::introuvable($entite, $libelle, '', []);
        }
        if (count($candidats) > 1) {
            return Reference::ambigue($entite, $libelle, '', $candidats);
        }

        return Reference::resolue($entite, $libelle, (int) array_key_first($candidats), (string) reset($candidats), '');
    }

    /**
     * Les enregistrements RATTACHÉS à un autre par une relation (les pistes d'un
     * client, les cotations d'une piste). L'appelant décide de la suite : aucun,
     * un seul — le défaut à annoncer —, ou plusieurs — la question à poser.
     *
     * @return array<int, string> id => libellé
     */
    public function chercherLies(string $entite, string $champ, int $idParent, AiScope $scope): array
    {
        return $this->chercherPar($entite, [$champ => $idParent], null, $scope);
    }

    /**
     * Recherche par libellé, scopée à l'entreprise.
     *
     * PLUSIEURS CHAMPS, ESSAYÉS DANS L'ORDRE — et non le seul champ d'affichage.
     *
     * L'INCIDENT DU 2026-08-14. « Attache ces fichiers à la police SURDCVO00018389 » :
     * la référence existait bien en base, sur l'avenant 134. Mais la recherche ne portait
     * que sur le champ d'AFFICHAGE, et un utilisateur ne désigne pas toujours un
     * enregistrement par ce champ-là. Ket a donc répondu que « cette police n'a pas pu
     * être trouvée dans votre portefeuille » — une phrase fausse, et de la pire espèce :
     * elle ne dit pas « je n'ai pas su chercher », elle dit « cela n'existe pas ».
     *
     * On essaie donc chaque champ identifiant, du plus précis au plus large, et on
     * S'ARRÊTE au premier qui répond. L'ordre fait la précision : une correspondance sur
     * la référence l'emporte sur une correspondance dans une description, qui n'est
     * consultée que si rien de mieux n'a été trouvé. Le coût est nul dans le cas nominal
     * — une seule requête, comme avant — et borné au nombre de champs identifiants sinon.
     *
     * Les LIBELLÉS rendus restent ceux du champ d'affichage, quel que soit le champ qui a
     * permis de trouver : la question « lequel ? » doit montrer ce que l'utilisateur
     * reconnaît, pas le champ par lequel le serveur est arrivé.
     *
     * @return array<int, string> id => libellé
     */
    public function chercher(string $entite, string $terme, AiScope $scope): array
    {
        $fqcn = 'App\\Entity\\' . $entite;
        if (!class_exists($fqcn)) {
            return [];
        }

        foreach ($this->libelleur->champsDeResolution($fqcn) as $champ) {
            $candidats = $this->chercherPar(
                $entite,
                [$champ => ['operator' => 'LIKE', 'value' => $terme, 'mode' => 'contains']],
                $terme,
                $scope,
            );
            if ($candidats !== []) {
                return $candidats;
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $criteres
     *
     * @return array<int, string> id => libellé
     */
    private function chercherPar(string $entite, array $criteres, ?string $terme, AiScope $scope): array
    {
        $fqcn = 'App\\Entity\\' . $entite;
        // FAIL-CLOSED : sans droit de lecture, l'enregistrement n'existe pas.
        if (!class_exists($fqcn) || !$this->accessResolver->canRead($scope->invite, $entite)) {
            return [];
        }

        $resultat = $this->searchService->search($fqcn, $criteres, $scope->entreprise, null, 1, self::MAX_CANDIDATS);
        if (($resultat['status']['code'] ?? 500) !== 200) {
            return [];
        }

        $champ = $this->libelleur->displayField($fqcn);
        $index = [];
        foreach ($resultat['data'] ?? [] as $entity) {
            if (!is_object($entity) || !method_exists($entity, 'getId') || $entity->getId() === null) {
                continue;
            }
            $index[(int) $entity->getId()] = $this->libelleur->libelle($entity, $champ);
        }

        if ($terme === null || $index === []) {
            return $index;
        }

        // Une correspondance EXACTE l'emporte seule : « SUNU » ne doit pas devenir
        // ambigu au motif qu'un « SUNU IARD RDC » existe aussi — le LIKE « contains »
        // les ramène tous les deux, mais l'utilisateur en a nommé un.
        $cible = $this->normaliser($terme);
        $exacts = array_filter($index, fn (string $l) => $this->normaliser($l) === $cible);

        return $exacts !== [] ? $exacts : $index;
    }

    /**
     * Aperçu du référentiel pour une question utile (« lequel ? »), quand la liste
     * est assez courte pour être lue. Au-delà, mieux vaut ne rien proposer que de
     * montrer une part arbitraire qui se lirait comme l'ensemble.
     *
     * @return array<int, string>
     */
    private function apercuDuReferentiel(string $entite, AiScope $scope): array
    {
        $tout = $this->chercherPar($entite, [], null, $scope);

        return count($tout) <= self::MAX_CANDIDATS ? $tout : [];
    }

    /**
     * Comparaison insensible à la casse, aux accents et à la ponctuation.
     *
     * DÉLÈGUE À AiText, et ce n'est pas un simple rangement. Cette méthode faisait
     * son propre `iconv('UTF-8', 'ASCII//TRANSLIT')`, qui sous Windows DÉCOMPOSE au
     * lieu de translittérer : « Société Générale » donnait « soci et e g en erale ».
     * Deux libellés issus de la base continuaient de s'égaler — les artefacts
     * s'annulent —, si bien que le défaut restait invisible ; mais « Societe
     * Generale » tapé sans accents ne pouvait plus JAMAIS égaler le nom en base. La
     * correspondance EXACTE ci-dessus, celle qui empêche « SUNU » de devenir ambigu
     * face à « SUNU IARD RDC », était donc morte sur tout nom accentué. Et depuis que
     * la vérification d'existence décide s'il faut CRÉER un maillon, cela revenait à
     * fabriquer un doublon pour tout client dont le nom porte un accent.
     */
    public function normaliser(string $valeur): string
    {
        return AiText::cle($valeur);
    }
}
