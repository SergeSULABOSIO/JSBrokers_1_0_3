<?php

namespace App\Service\Workspace;

use App\Entity\Invite;
use App\Entity\Utilisateur;
use App\Repository\InviteRepository;

/**
 * @file Source unique des droits d'un invité dans l'espace de travail du courtier.
 * @description Rend enfin EFFECTIFS les rôles déjà stockés (RolesEnFinance/Marketing/
 * Production/Sinistre/Administration) : niveaux d'accès par entité —
 * Lecture / Écriture / Modification / Suppression (cf. Invite::ACCESS_*).
 *
 * Réutilisé (DRY) par :
 *  - ControllerUtilsTrait (blocage serveur aux points de passage CRUD génériques),
 *  - WorkspaceAccessExtension (masquage de la navigation et des blocs du tableau de bord),
 *  - EspaceDeTravailComponentController (filtrage du menu),
 *  - EntrepriseDashbordController (garde des blocs lazy-loadés).
 *
 * Politique (validée) : FAIL-CLOSED. Un invité sans rôle défini n'a accès à rien.
 * SEUL le propriétaire de l'entreprise a un accès total (bypass), qu'il ait des
 * rôles ou non. Un « gestionnaire d'invités » délégué (Invite::isGestionnaireInvites)
 * peut administrer les invités et leurs rôles, mais n'obtient AUCUN privilège de
 * données : son périmètre reste celui de ses propres rôles.
 *
 * On ne touche à AUCUNE logique métier : ce service ne décide que de la visibilité
 * et de l'autorisation d'accès à des fonctionnalités déjà existantes.
 */
class WorkspaceAccessResolver
{
    /** Libellés lisibles des niveaux d'accès (mêmes valeurs que Invite::ACCESS_*). */
    public const LEVEL_LABELS = [
        Invite::ACCESS_LECTURE      => 'Lecture',
        Invite::ACCESS_ECRITURE     => 'Écriture',
        Invite::ACCESS_MODIFICATION => 'Modification',
        Invite::ACCESS_SUPPRESSION  => 'Suppression',
    ];

    /**
     * Carte de permissions : nom court d'entité => [module, getter de la collection de
     * rôles sur Invite, getter du champ d'accès sur l'entité de rôle, libellé].
     *
     * Couvre les 36 entrées du menu de l'espace de travail (dont la pseudo-entité
     * « DocumentComptable », sans classe Doctrine). Attention aux alias non
     * triviaux (le nom du champ d'accès ne suit pas toujours le nom de l'entité) :
     *  - Chargement                 → getAccessTypeChargement
     *  - RevenuPourCourtier         → getAccessRevenu
     *  - ModelePieceSinistre        → getAccessTypePiece
     *  - NotificationSinistre       → getAccessNotification
     *  - OffreIndemnisationSinistre → getAccessReglement
     * RolesEnSinistre ne définit que 3 champs pour 4 rubriques : « Pièces Sinistre »
     * (PieceSinistre) partage donc le droit « Types pièces » (accessTypePiece), qui
     * gouverne l'ensemble du sous-domaine « pièces ». Cela évite un trou d'accès sans
     * modifier le schéma des rôles.
     */
    private const MAP = [
        // FINANCES
        'Monnaie'                    => ['Finances', 'getRolesEnFinance', 'getAccessMonnaie', 'Monnaies'],
        'CompteBancaire'             => ['Finances', 'getRolesEnFinance', 'getAccessCompteBancaire', 'Comptes bancaires'],
        'Taxe'                       => ['Finances', 'getRolesEnFinance', 'getAccessTaxe', 'Taxes'],
        'TypeRevenu'                 => ['Finances', 'getRolesEnFinance', 'getAccessTypeRevenu', 'Types Revenus'],
        'Tranche'                    => ['Finances', 'getRolesEnFinance', 'getAccessTranche', 'Tranches'],
        'Chargement'                 => ['Finances', 'getRolesEnFinance', 'getAccessTypeChargement', 'Types Chargements'],
        'Note'                       => ['Finances', 'getRolesEnFinance', 'getAccessNote', 'Notes'],
        'Paiement'                   => ['Finances', 'getRolesEnFinance', 'getAccessPaiement', 'Paiements'],
        'Bordereau'                  => ['Finances', 'getRolesEnFinance', 'getAccessBordereau', 'Bordereaux'],
        'RevenuPourCourtier'         => ['Finances', 'getRolesEnFinance', 'getAccessRevenu', 'Revenus'],
        'ChargeCourtier'             => ['Finances', 'getRolesEnFinance', 'getAccessCharge', 'Charges'],
        'DepenseCourtier'            => ['Finances', 'getRolesEnFinance', 'getAccessDepense', 'Dépenses'],
        'Fournisseur'                => ['Finances', 'getRolesEnFinance', 'getAccessFournisseur', 'Fournisseurs'],
        // Pseudo-entité (documents générés à la volée, aucune classe Doctrine) : gate la
        // rubrique « Documents comptables » du menu et le contrôleur/export associés.
        'DocumentComptable'          => ['Finances', 'getRolesEnFinance', 'getAccessDocumentComptable', 'Documents comptables'],
        // MARKETING
        'Piste'                      => ['Marketing', 'getRolesEnMarketing', 'getAccessPiste', 'Pistes'],
        'Tache'                      => ['Marketing', 'getRolesEnMarketing', 'getAccessTache', 'Tâches'],
        'Feedback'                   => ['Marketing', 'getRolesEnMarketing', 'getAccessFeedback', 'Feedbacks'],
        // PRODUCTION
        'Groupe'                     => ['Production', 'getRolesEnProduction', 'getAccessGroupe', 'Groupes'],
        // Portefeuille = regroupement de clients par gestionnaire de compte. Droit dédié
        // (RolesEnProduction::accessPortefeuille), paramétrable comme les autres rubriques.
        'Portefeuille'               => ['Production', 'getRolesEnProduction', 'getAccessPortefeuille', 'Portefeuilles'],
        'Client'                     => ['Production', 'getRolesEnProduction', 'getAccessClient', 'Clients'],
        'Assureur'                   => ['Production', 'getRolesEnProduction', 'getAccessAssureur', 'Assureurs'],
        'Contact'                    => ['Production', 'getRolesEnProduction', 'getAccessContact', 'Contacts'],
        'Risque'                     => ['Production', 'getRolesEnProduction', 'getAccessRisque', 'Risques'],
        'Avenant'                    => ['Production', 'getRolesEnProduction', 'getAccessAvenant', 'Avenants'],
        'Partenaire'                 => ['Production', 'getRolesEnProduction', 'getAccessPartenaire', 'Intermédiaires'],
        'Cotation'                   => ['Production', 'getRolesEnProduction', 'getAccessCotation', 'Propositions'],
        // SINISTRE
        'ModelePieceSinistre'        => ['Sinistre', 'getRolesEnSinistre', 'getAccessTypePiece', 'Types pièces'],
        'PieceSinistre'              => ['Sinistre', 'getRolesEnSinistre', 'getAccessTypePiece', 'Pièces Sinistre'],
        'NotificationSinistre'       => ['Sinistre', 'getRolesEnSinistre', 'getAccessNotification', 'Notifications'],
        'OffreIndemnisationSinistre' => ['Sinistre', 'getRolesEnSinistre', 'getAccessReglement', 'Règlements'],
        // ADMINISTRATION (Invite est géré à part, cf. isRoleManagementEntity)
        'Document'                   => ['Administration', 'getRolesEnAdministration', 'getAccessDocument', 'Documents'],
        'Classeur'                   => ['Administration', 'getRolesEnAdministration', 'getAccessClasseur', 'Classeurs'],
    ];

    /**
     * Entités relevant de la GESTION des invités et de leurs rôles. Toute lecture ou
     * mutation de ces entités exige canManageInvites() (propriétaire ou délégué),
     * indépendamment de la carte de permissions ci-dessus.
     */
    private const ROLE_MANAGEMENT_ENTITIES = [
        'Invite',
        'RolesEnFinance',
        'RolesEnMarketing',
        'RolesEnProduction',
        'RolesEnSinistre',
        'RolesEnAdministration',
        // Paramètres de l'assistant IA (nom du personnage de l'entreprise) :
        // configurer l'assistant relève de l'administration de l'espace, même
        // cercle que la gestion des invités — sans nouveau champ de rôle (KISS).
        'AssistantParametres',
    ];

    public function __construct(
        private readonly InviteRepository $inviteRepository,
    ) {
    }

    /**
     * Invité correspondant à l'utilisateur dans l'entreprise active (connectedTo).
     * Même logique que ControllerUtilsTrait::getInvite(), centralisée ici pour être
     * réutilisable par le Twig et les contrôleurs hors trait. Peut être null.
     */
    public function resolveConnectedInvite(Utilisateur $user): ?Invite
    {
        $connectedTo = $user->getConnectedTo();
        $criteria = ['utilisateur' => $user];
        if ($connectedTo !== null) {
            $criteria['entreprise'] = $connectedTo;
        }

        $invite = $this->inviteRepository->findOneBy($criteria);
        if (!$invite && $connectedTo !== null) {
            $invite = $this->inviteRepository->findOneBy(['utilisateur' => $user]);
        }

        return $invite;
    }

    /** L'utilisateur est-il le propriétaire de l'entreprise qu'il a ouverte ? */
    public function isOwnerOfConnected(Utilisateur $user): bool
    {
        $entreprise = $user->getConnectedTo();

        return $entreprise !== null && $entreprise->getUtilisateur() === $user;
    }

    /** Le propriétaire de l'entreprise : accès total, avec ou sans rôles. */
    public function isOwner(Invite $invite): bool
    {
        if ($invite->isProprietaire() === true) {
            return true;
        }

        $entreprise = $invite->getEntreprise();

        return $entreprise !== null
            && $invite->getUtilisateur() !== null
            && $entreprise->getUtilisateur() === $invite->getUtilisateur();
    }

    /** Peut administrer les invités et attribuer des rôles : propriétaire ou délégué. */
    public function canManageInvites(Invite $invite): bool
    {
        return $this->isOwner($invite) || $invite->isGestionnaireInvites() === true;
    }

    public function isRoleManagementEntity(string $entityShortName): bool
    {
        return in_array($entityShortName, self::ROLE_MANAGEMENT_ENTITIES, true);
    }

    /**
     * Niveaux d'accès autorisés (union des rôles) pour un invité sur une entité donnée.
     * NE PROPAGE PAS le bypass propriétaire (utilisé aussi par describePerimetre) : le
     * bypass est géré par can(). Une entité hors carte renvoie tous les niveaux (les
     * sous-entités structurelles ne sont pas gouvernées indépendamment).
     *
     * @return int[]
     */
    public function allowedLevels(Invite $invite, string $entityShortName): array
    {
        if (!isset(self::MAP[$entityShortName])) {
            return array_keys(self::LEVEL_LABELS);
        }

        [, $collectionGetter, $fieldGetter] = self::MAP[$entityShortName];

        $levels = [];
        foreach ($invite->{$collectionGetter}() as $role) {
            foreach ($role->{$fieldGetter}() as $level) {
                $levels[(int) $level] = true;
            }
        }

        return array_keys($levels);
    }

    /**
     * L'invité peut-il agir au niveau $level sur l'entité $entityShortName ?
     * Cœur du contrôle d'accès (fail-closed).
     */
    public function can(Invite $invite, string $entityShortName, int $level): bool
    {
        // Propriétaire : accès total inconditionnel.
        if ($this->isOwner($invite)) {
            return true;
        }

        // Gestion des invités/rôles : réservée au propriétaire ou au délégué.
        if ($this->isRoleManagementEntity($entityShortName)) {
            return $this->canManageInvites($invite);
        }

        // Sous-entité structurelle hors carte : gouvernée par son parent (déjà contrôlé).
        if (!isset(self::MAP[$entityShortName])) {
            return true;
        }

        // Entité métier : fail-closed, le niveau doit avoir été explicitement accordé.
        return in_array($level, $this->allowedLevels($invite, $entityShortName), true);
    }

    /** Raccourci lecture. */
    public function canRead(Invite $invite, string $entityShortName): bool
    {
        return $this->can($invite, $entityShortName, Invite::ACCESS_LECTURE);
    }

    /**
     * Filtre le menu de l'espace de travail : retire les rubriques hors périmètre et
     * les groupes devenus vides. Les rubriques sans entity_name (ex. Support) et le
     * tableau de bord restent visibles. `entity_name` est déjà un nom court à ce stade
     * (cf. ControllerUtilsTrait::processDataForShortEntityNames).
     *
     * @param array $menuData Structure app.menu_data déjà « raccourcie ».
     */
    public function filterMenu(array $menuData, Invite $invite): array
    {
        if ($this->isOwner($invite)) {
            return $menuData; // Propriétaire : menu complet.
        }

        $groupes = $menuData['colonne_1']['groupes'] ?? [];
        foreach ($groupes as $groupName => $groupData) {
            $rubriques = $groupData['rubriques'] ?? [];
            foreach ($rubriques as $rubName => $rubData) {
                $entityShort = $rubData['entity_name'] ?? null;
                // Rubrique sans entité (Support, etc.) : toujours visible.
                if ($entityShort !== null && !$this->canRead($invite, $entityShort)) {
                    unset($rubriques[$rubName]);
                }
            }

            if (empty($rubriques)) {
                unset($groupes[$groupName]);
            } else {
                $groupes[$groupName]['rubriques'] = $rubriques;
            }
        }

        $menuData['colonne_1']['groupes'] = $groupes;

        return $menuData;
    }

    /**
     * Libellés lisibles des entités gouvernées par la carte de permissions
     * (nom court => libellé de rubrique du menu). Permet à l'assistant IA de
     * dériver son lexique métier sans dupliquer la carte (DRY).
     *
     * @return array<string, string>
     */
    public function libellesEntites(): array
    {
        $labels = [];
        foreach (self::MAP as $entityShortName => [, , , $label]) {
            $labels[$entityShortName] = $label;
        }

        return $labels;
    }

    /** L'invité a-t-il au moins un périmètre (sinon : coquille « aucun accès ») ? */
    public function hasAnyPerimetre(Invite $invite): bool
    {
        if ($this->canManageInvites($invite)) {
            return true;
        }

        foreach (array_keys(self::MAP) as $entityShortName) {
            if (!empty($this->allowedLevels($invite, $entityShortName))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Description lisible du périmètre, groupée par module — réutilisée par l'e-mail
     * de notification (details) et exploitable en UI. Clé = module, valeur = liste
     * « Entité (niveaux) ».
     *
     * @return array<string, string>
     */
    public function describePerimetre(Invite $invite): array
    {
        if ($this->isOwner($invite)) {
            return ['Accès' => "Accès complet à l'espace de travail (propriétaire de l'entreprise)."];
        }

        $parModule = [];
        foreach (self::MAP as $entityShortName => [$module, , , $label]) {
            $levels = $this->allowedLevels($invite, $entityShortName);
            if (empty($levels)) {
                continue;
            }
            sort($levels);
            $niveaux = array_map(fn (int $l) => self::LEVEL_LABELS[$l] ?? (string) $l, $levels);
            $parModule[$module][] = sprintf('%s (%s)', $label, implode(', ', $niveaux));
        }

        $details = [];
        foreach ($parModule as $module => $items) {
            $details[$module] = implode(' · ', $items);
        }

        if ($this->canManageInvites($invite)) {
            $details['Administration'] = trim(($details['Administration'] ?? '') . ' · Gestion des invités et des rôles', ' ·');
        }

        if (empty($details)) {
            $details['Périmètre'] = "Aucun accès ne vous a encore été attribué. Contactez le propriétaire de l'espace de travail.";
        }

        return $details;
    }

    /**
     * Variante STRUCTURÉE de describePerimetre(), destinée à un rendu riche (e-mail de
     * notification de périmètre) : au lieu d'une chaîne « Entité (niveaux) · … » par
     * module, on renvoie l'arborescence complète module → entités → niveaux, afin que
     * la vue puisse la présenter en cartes/pastilles lisibles plutôt qu'en une longue
     * ligne dense et peu professionnelle.
     *
     * @return array{
     *     owner: bool,
     *     gestionnaire: bool,
     *     modules: array<int, array{nom: string, entites: array<int, array{nom: string, niveaux: string[]}>}>
     * }
     */
    public function describePerimetreDetailed(Invite $invite): array
    {
        if ($this->isOwner($invite)) {
            return ['owner' => true, 'gestionnaire' => true, 'modules' => []];
        }

        $parModule = [];
        foreach (self::MAP as $entityShortName => [$module, , , $label]) {
            $levels = $this->allowedLevels($invite, $entityShortName);
            if (empty($levels)) {
                continue;
            }
            sort($levels);
            $niveaux = array_map(fn (int $l) => self::LEVEL_LABELS[$l] ?? (string) $l, $levels);
            $parModule[$module][] = ['nom' => $label, 'niveaux' => $niveaux];
        }

        $modules = [];
        foreach ($parModule as $nom => $entites) {
            $modules[] = ['nom' => $nom, 'entites' => $entites];
        }

        return [
            'owner'        => false,
            'gestionnaire' => $this->canManageInvites($invite),
            'modules'      => $modules,
        ];
    }
}
