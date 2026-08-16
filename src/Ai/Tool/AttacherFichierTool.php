<?php

namespace App\Ai\Tool;

use App\Ai\Fichier\ConversationFichierResolver;
use App\Ai\Fichier\PieceSourceRattachement;
use App\Ai\Mutation\ConversationFichierRef;
use App\Ai\Resolution\ResolveurDeReferences;
use App\Ai\Scope\AiScope;
use App\Ai\Trousse\AiToolEcriture;
use App\Entity\AssistantConversationFichier;
use App\Entity\Invite;
use App\Service\Workspace\WorkspaceAccessResolver;
use Doctrine\ORM\EntityManagerInterface;

/**
 * « AJOUTE CE FICHIER À L'AVENANT » — le geste le plus simple qu'on puisse faire d'une
 * pièce jointe, et le seul qui n'avait pas d'outil.
 *
 * CE QUI SE PASSAIT AVANT, ET POURQUOI ÇA RATAIT. Classer une pièce passait par
 * preparer_operations, à qui le modèle devait dicter lui-même le CHAMP de rattachement :
 * `{entite:"Document", champs:{avenant:134, fichier:"@fichier:18"}}`. C'est-à-dire qu'il
 * devait savoir que la police se dit « avenant », que le client se dit « client », mais
 * qu'un paiement se rattache par « preuves » côté inverse — et que pour une tranche, une
 * note ou un assureur, il n'existait tout simplement rien. Le serveur, lui, sait tout
 * cela : PieceSourceRattachement le DÉDUIT des formulaires et des métadonnées Doctrine.
 * Le modèle n'avait donc qu'une chose à faire, deviner, et une seule façon d'échouer.
 *
 * ICI, IL NE DEVINE PLUS RIEN. Il dit QUEL fichier et QUEL objet — au besoin par son nom,
 * le serveur le résout. Tout le reste est calculé : où va la pièce, sous quelle forme
 * d'opération, avec quel libellé.
 *
 * AUCUNE LOGIQUE D'ÉCRITURE PROPRE, comme PreparerMouvementAvenantTool : l'opération
 * produite part dans preparer_operations, donc dans le même WorkspaceMutationService —
 * validation, budget, verrou « un seul plan en attente », barre « Valider et exécuter »,
 * exécution et journal viennent de là. Cet outil n'écrit rien.
 *
 * IL COUVRE TOUTES LES ENTITÉS MÉTIER : chacune porte désormais une vraie collection
 * « Documents », la même que celle du widget de son écran. La réponse « cet
 * enregistrement n'accepte pas de document » n'a donc plus de cas d'emploi.
 */
final class AttacherFichierTool implements AiToolProduisantUnPlan, AiToolConditionnel, AiToolEcriture
{
    /** Libellé du Document quand l'utilisateur n'en dicte aucun : le nom du fichier. */
    private const NOM_PAR_DEFAUT = 'Pièce jointe';

    public function __construct(
        private readonly PreparerOperationsTool $preparer,
        private readonly PieceSourceRattachement $rattachement,
        private readonly ConversationFichierResolver $fichiers,
        private readonly ResolveurDeReferences $resolveur,
        private readonly WorkspaceAccessResolver $accessResolver,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function name(): string
    {
        return 'attacher_fichier';
    }

    public function description(): string
    {
        return "ATTACHE une pièce jointe de la conversation à un enregistrement de la plateforme, et "
            . "l'y CONSERVE — « ajoute ce fichier à l'avenant MIC2026-001 », « joins-le au dossier du "
            . "client Orange », « range cette facture dans la tranche n°3 », « mets ce document sur la "
            . "piste Kibali ». C'est l'outil du CLASSEMENT d'un fichier, et le seul : n'utilise JAMAIS "
            . "preparer_operations pour cela, tu devrais y deviner le champ de rattachement et tu te "
            . "tromperais. Donne le fichier (fichierId, lu dans la section PIÈCES JOINTES) et l'objet "
            . "visé (cible) : le serveur détermine seul OÙ la pièce doit aller. "
            . "LES RUBRIQUES QUE JE SERS sont énumérées dans « cible.entite » — n'en invente aucune "
            . "autre. Toute fiche de la plateforme porte une collection « Documents » à l'écran : si "
            . "l'utilisateur en vise une qui n'est pas dans cette liste, dis-lui simplement d'y déposer "
            . "le fichier depuis le widget « Documents » de sa fiche, jamais que c'est impossible. "
            . "Différent de analyser_fichier_pour_saisie, qui sert à CRÉER un enregistrement à partir "
            . "des DONNÉES lues dans le fichier ; ici, on range le fichier lui-même sur un "
            . "enregistrement qui existe déjà. Prépare un PLAN à valider. N'écrit rien.";
    }

    public function aiguillage(): string
    {
        return '« ajoute / joins / attache / range / mets ce fichier (ce document, cette pièce, ce contrat, '
            . 'cette facture) dans / sur / à <un enregistrement> » — police, client, cotation, piste, '
            . 'sinistre, paiement, tranche, note, assureur, portefeuille… La liste EXACTE des rubriques '
            . 'que je sers est l\'énumération de mon paramètre « cible.entite ». Donne-moi '
            . 'cible={entite:"Avenant", nom:"MIC2026-001"} : tu n\'as pas besoin de l\'identifiant, je '
            . 'résous le nom. Moi seul sais OÙ la pièce doit être rangée — ne le devine pas avec '
            . 'preparer_operations.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'fichierId' => [
                    'type' => 'integer',
                    'description' => "Identifiant de la pièce jointe, tel qu'il figure dans la section "
                        . "PIÈCES JOINTES (« @fichier:18 » => 18). Ne l'invente JAMAIS.",
                ],
                'cible' => [
                    'type' => 'object',
                    'description' => "L'enregistrement auquel attacher le fichier. Donne « id » si tu le "
                        . 'connais, sinon « nom » — le serveur résout le nom lui-même et te rend les '
                        . 'candidats si plusieurs correspondent.',
                    'properties' => [
                        // ÉNUMÉRATION DÉRIVÉE, et non une liste d'exemples. Le modèle ne
                        // peut PLUS nommer une rubrique dont le plan serait refusé
                        // ensuite : celles qui figurent ici sont exactement celles que
                        // le serveur sait servir. Une rubrique ouverte demain y entre
                        // toute seule.
                        'entite' => [
                            'type' => 'string',
                            'enum' => $this->rattachement->entitesAttachables(),
                            'description' => "Nom court de l'entité visée. UNIQUEMENT une valeur de cette liste : "
                                . "ce sont les rubriques auxquelles je sais attacher une pièce.",
                        ],
                        'id'     => ['type' => 'integer', 'description' => "Identifiant de l'enregistrement."],
                        'nom'    => ['type' => 'string', 'description' => "Nom ou référence de l'enregistrement, si l'id est inconnu."],
                    ],
                ],
                'nom' => [
                    'type' => 'string',
                    'description' => "Libellé à donner au document (ex. « Contrat signé »). Facultatif : "
                        . "à défaut, le nom d'origine du fichier est repris tel quel.",
                ],
                'remplacerPlanEnAttente' => [
                    'type' => 'boolean',
                    'description' => "Ne mets true QUE si un plan attend déjà une décision ET que "
                        . "l'utilisateur demande de le CHANGER.",
                ],
            ],
            'required' => ['fichierId'],
        ];
    }

    /** Chemin simulé neutralisé : une écriture relève du LLM réel (comme preparer_operations). */
    public function match(string $question, AiScope $scope): ?array
    {
        return null;
    }

    /**
     * Sans pièce jointe, cet outil n'a rien à attacher : le déclarer coûterait sa
     * description à chaque tour d'une conversation qui n'en a pas. Même économie que
     * analyser_fichier_pour_saisie, et même miroir exact de la garde d'execute().
     */
    public function estDisponible(AiScope $scope): bool
    {
        return $scope->conversation !== null && \count($scope->conversation->getFichiers()) > 0;
    }

    public function execute(array $args, AiScope $scope): AiToolResult
    {
        // FAIL-CLOSED, dans l'outil et jamais dans le prompt : attacher un fichier CRÉE un
        // Document. Sans droit d'écriture dessus, la question ne se pose pas.
        if (!$this->accessResolver->can($scope->invite, 'Document', Invite::ACCESS_ECRITURE)) {
            return AiToolResult::horsPerimetre('Documents');
        }

        $piece = $this->pieceJointe($args, $scope);
        if ($piece instanceof AiToolResult) {
            return $piece;
        }

        $cible = $this->cible($args, $scope);
        if ($cible instanceof AiToolResult) {
            return $cible;
        }
        [$shortName, $cibleId, $libelleCible] = $cible;

        $descripteur = $this->rattachement->resoudre($shortName, $libelleCible);
        if (!$descripteur['rattachable']) {
            // N'arrive plus que pour un nom d'entité qui ne désigne rien : le
            // rattachement universel couvre tout ce qui est réellement persisté.
            return AiToolResult::ok([
                'pret'     => false,
                'bloquant' => $descripteur['explication'],
                'note'     => 'Dis-le en UNE phrase et demande à quel enregistrement la pièce doit être '
                    . 'rattachée. Ne présente AUCUN plan et n’annonce AUCUN bouton.',
            ]);
        }

        $nomDocument = trim((string) ($args['nom'] ?? ''));
        if ($nomDocument === '') {
            $nomDocument = trim((string) $piece->getNomOriginal()) ?: self::NOM_PAR_DEFAUT;
        }

        $fragment = $this->rattachement->fragmentGabarit(
            $descripteur,
            ConversationFichierRef::marqueur((int) $piece->getId()),
            $nomDocument,
            $cibleId,
        );
        if ($fragment === null) {
            return AiToolResult::ok([
                'pret'     => false,
                'bloquant' => $descripteur['explication'],
                'note'     => 'Dis-le en UNE phrase. Ne présente AUCUN plan et n’annonce AUCUN bouton.',
            ]);
        }

        $resultat = $this->preparer->execute([
            'operations'             => [$this->operation($fragment, $shortName, $cibleId)],
            'remplacerPlanEnAttente' => ($args['remplacerPlanEnAttente'] ?? false) === true,
        ], $scope);

        // LE REFUS DU MOTEUR PASSE AVANT TOUT, ET SEUL : ses refus sont des STATUS_OK
        // porteurs de « pret: false », et leur agrafer une consigne « présente le plan »
        // ferait rédiger au modèle un plan en prose sans bouton (plan fantôme).
        if ($resultat->status !== AiToolResult::STATUS_OK || ($resultat->data['pret'] ?? false) !== true) {
            return $resultat;
        }

        return AiToolResult::ok($resultat->data + [
            'fichier'      => ['id' => $piece->getId(), 'nom' => $piece->getNomOriginal()],
            'rattachement' => [
                'cible'       => $libelleCible,
                'explication' => $descripteur['explication'],
            ],
            // Le classement d'une pièce est irréversible du point de vue de l'utilisateur
            // (le fichier quitte la conversation pour entrer au dossier) : il doit lire
            // OÙ elle atterrit avant de valider, dans les mots du serveur.
            'note' => 'Annonce en UNE phrase le fichier et l’enregistrement qui va le recevoir, en '
                . 'reprenant « explication » — c’est le serveur qui sait où va la pièce, pas toi. '
                . 'Puis laisse la barre de validation faire le reste : n’annonce aucun enregistrement '
                . 'déjà fait, rien n’est écrit tant que l’utilisateur n’a pas validé.',
        ], $resultat->uiAction);
    }

    /**
     * L'opération de plan correspondant au fragment, selon là où le fragment doit
     * atterrir. La RÈGLE (où va la pièce) reste dans PieceSourceRattachement ; il ne
     * reste ici que l'emballage — l'objet visé existant déjà, il n'y a aucun socle à
     * créer et le renvoi est un identifiant réel.
     *
     * @param array{cible:string, fragment:array} $fragment
     */
    private function operation(array $fragment, string $shortName, int $cibleId): array
    {
        return match ($fragment['cible']) {
            // Niveaux 2 et 2b : le fragment EST déjà une opération de création de Document.
            'operation'   => $fragment['fragment'],
            // Niveau 1 : la pièce entre dans la collection « Documents » de l'objet, très
            // exactement comme le widget de l'écran l'aurait fait.
            'collections' => [
                'op'          => 'edit',
                'entite'      => $shortName,
                'id'          => $cibleId,
                'collections' => [$fragment['fragment']],
            ],
            // Niveau 0 : la cible EST un Document ; on remplace son fichier.
            default       => [
                'op'     => 'edit',
                'entite' => 'Document',
                'id'     => $cibleId,
                'champs' => $fragment['fragment'],
            ],
        };
    }

    /**
     * La pièce jointe visée, ou le refus déjà rédigé.
     *
     * Le motif d'un refus vient de ConversationFichierResolver, qui LISTE les pièces
     * réellement attachées : « la pièce #19 n'existe pas » laisse sans recours,
     * « pièces disponibles : #18 CONTRACT.pdf » se corrige en un message.
     */
    private function pieceJointe(array $args, AiScope $scope): AssistantConversationFichier|AiToolResult
    {
        $id = (int) ($args['fichierId'] ?? 0);
        $piece = $id > 0 ? $this->fichiers->trouver($id, $scope) : null;
        if ($piece !== null) {
            return $piece;
        }

        $resolue = $this->fichiers->resoudre(ConversationFichierRef::marqueur($id), $scope, false);

        return AiToolResult::ok([
            'pret'     => false,
            'bloquant' => $resolue->motif ?? 'Cette pièce jointe est introuvable dans cette conversation.',
            'note'     => 'Dis-le en UNE phrase, en NOMMANT les pièces réellement disponibles s’il y en a, '
                . 'et demande laquelle attacher. Ne présente AUCUN plan et n’annonce AUCUN bouton.',
        ]);
    }

    /**
     * L'objet visé : [nom court, identifiant, libellé lisible], ou le refus déjà rédigé.
     *
     * NOM PLUTÔT QU'IDENTIFIANT, comme partout ailleurs : l'utilisateur dit « la police
     * MIC2026-001 », pas « l'avenant 134 ». Exiger l'identifiant condamnerait le modèle à
     * un tour d'outil préalable que l'architecture ne lui accorde pas.
     *
     * @return array{0:string, 1:int, 2:string}|AiToolResult
     */
    private function cible(array $args, AiScope $scope): array|AiToolResult
    {
        $cible = \is_array($args['cible'] ?? null) ? $args['cible'] : [];
        $shortName = trim((string) ($cible['entite'] ?? ''));
        $labels = $this->accessResolver->libellesEntites();
        $libelle = $labels[$shortName] ?? $shortName;

        if ($shortName === '' || !isset($labels[$shortName]) || !class_exists('App\\Entity\\' . $shortName)) {
            return AiToolResult::ok([
                'pret'     => false,
                'bloquant' => 'À quel enregistrement ce fichier doit-il être attaché ?',
                'note'     => 'Pose la question en UNE ligne, dans le vocabulaire des écrans (police, client, '
                    . 'cotation, tranche…). Ne présente AUCUN plan et n’annonce AUCUN bouton.',
            ]);
        }

        // RÉFÉRENCER UNE FICHE, C'EST LA LIRE — même garde que CritereLieA.
        if (!$this->accessResolver->canRead($scope->invite, $shortName)) {
            return AiToolResult::horsPerimetre($libelle);
        }

        $id = (int) ($cible['id'] ?? 0);
        $nom = trim((string) ($cible['nom'] ?? ''));
        if ($id <= 0 && $nom !== '') {
            $reference = $this->resolveur->resoudre($shortName, $nom, $scope);
            if (!$reference->estResolue()) {
                return AiToolResult::ok([
                    'pret'      => false,
                    'aDemander' => [$reference->question()],
                    'note'      => 'L’enregistrement visé ne se résout pas. Pose la question telle quelle, en '
                        . 'UNE ligne, en PROPOSANT les « valeurs » quand il y en a. N’invente aucun '
                        . 'identifiant, ne relance AUCUN outil et ne présente AUCUN plan.',
                ]);
            }
            $id = (int) $reference->id;
        }

        if ($id <= 0 || !$this->existe($shortName, $id, $scope)) {
            return AiToolResult::ok([
                'pret'     => false,
                'bloquant' => sprintf('Aucun enregistrement « %s » ne correspond dans cet espace de travail.', $libelle),
                'note'     => 'Dis-le en UNE phrase et demande la référence exacte. Ne présente AUCUN plan '
                    . 'et n’annonce AUCUN bouton.',
            ]);
        }

        return [$shortName, $id, $libelle];
    }

    /**
     * L'enregistrement existe-t-il, DANS L'ENTREPRISE DU SCOPE ?
     *
     * Le scoping n'est pas une formalité : sans lui, un identifiant dicté au hasard
     * rattacherait une pièce au dossier d'un autre cabinet. Les entités qui ne portent
     * pas d'entreprise (paramétrage partagé) sont acceptées telles quelles — c'est le
     * même contrat que partout ailleurs, l'absence de colonne valant absence de cloison.
     */
    private function existe(string $shortName, int $id, AiScope $scope): bool
    {
        $fqcn = 'App\\Entity\\' . $shortName;
        try {
            $entite = $this->em->find($fqcn, $id);
        } catch (\Throwable) {
            return false;
        }
        if ($entite === null) {
            return false;
        }
        if (!method_exists($entite, 'getEntreprise')) {
            return true;
        }
        $entreprise = $entite->getEntreprise();

        return $entreprise === null || $entreprise->getId() === $scope->entreprise->getId();
    }
}
