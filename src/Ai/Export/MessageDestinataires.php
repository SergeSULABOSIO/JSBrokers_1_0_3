<?php

namespace App\Ai\Export;

use App\Entity\Assureur;
use App\Entity\Contact;
use App\Entity\Entreprise;
use App\Entity\Fournisseur;
use App\Entity\Invite;
use App\Entity\Partenaire;
use App\Entity\Utilisateur;
use App\Service\Workspace\WorkspaceAccessResolver;
use Doctrine\ORM\EntityManagerInterface;

/**
 * @file Carnet d'adresses proposé pour l'envoi d'un message du chat.
 * @description Agrège les entités de l'entreprise qui portent une adresse
 * e-mail exploitable : soi-même, les contacts des clients, les collaborateurs de
 * l'espace, puis les correspondants externes (co-courtiers, assureurs,
 * fournisseurs). Les CLIENTS eux-mêmes en sont exclus : la demande porte sur des
 * contacts, et un assuré n'est pas un destinataire de travail.
 *
 * FAIL-CLOSED : chaque source est conditionnée au droit de LECTURE de l'invité
 * sur l'entité correspondante. Sans cela, un invité cantonné aux sinistres
 * découvrirait le carnet Assureurs par une porte latérale.
 *
 * `trouver()` vit ici, et non dans le contrôleur : la règle « proposé ⇔
 * acceptable » n'a ainsi qu'un seul domicile, et il devient impossible d'ajouter
 * une source d'affichage en oubliant sa validation.
 */
class MessageDestinataires
{
    /** Au-delà, la liste est tronquée — et la troncature est ANNONCÉE au picker. */
    public const MAX_CARNET = 1000;

    public const CATEGORIE_MOI = 'moi';
    public const CATEGORIE_CONTACT = 'contact';
    public const CATEGORIE_COLLABORATEUR = 'collaborateur';
    public const CATEGORIE_PARTENAIRE = 'partenaire';
    public const CATEGORIE_ASSUREUR = 'assureur';
    public const CATEGORIE_FOURNISSEUR = 'fournisseur';

    /** Libellés des puces de filtre, dans l'ordre d'affichage du picker. */
    public const CATEGORIE_LABELS = [
        self::CATEGORIE_MOI => 'Vous',
        self::CATEGORIE_CONTACT => 'Contacts',
        self::CATEGORIE_COLLABORATEUR => 'Collaborateurs',
        self::CATEGORIE_PARTENAIRE => 'Partenaires',
        self::CATEGORIE_ASSUREUR => 'Assureurs',
        self::CATEGORIE_FOURNISSEUR => 'Fournisseurs',
    ];

    private const CONTACT_TYPE_LABELS = [
        Contact::TYPE_CONTACT_PRODUCTION => 'Production',
        Contact::TYPE_CONTACT_SINISTRE => 'Sinistres',
        Contact::TYPE_CONTACT_ADMINISTRATION => 'Administration',
        Contact::TYPE_CONTACT_AUTRES => 'Autres',
    ];

    public function __construct(
        private EntityManagerInterface $em,
        private WorkspaceAccessResolver $accessResolver,
    ) {
    }

    /**
     * Carnet exploitable, dédoublonné (insensible à la casse) et borné.
     *
     * @return array{
     *     destinataires: array<int, array{email: string, nom: string, detail: string, categorie: string, origine: string}>,
     *     tronque: int
     * } `tronque` = nombre d'adresses non listées (0 si tout tient)
     */
    public function collecter(Entreprise $entreprise, ?Invite $invite, ?Utilisateur $acteur): array
    {
        $destinataires = [];
        $vus = [];

        $ajouter = static function (string $email, string $nom, string $detail, string $categorie, string $origine = '') use (&$destinataires, &$vus): void {
            $email = trim($email);
            if ($email === '') {
                return;
            }
            $cle = mb_strtolower($email);
            if (isset($vus[$cle])) {
                return; // première source vue = celle qui gagne (ordre ci-dessous)
            }
            $vus[$cle] = true;
            $destinataires[] = [
                'email' => $email,
                'nom' => trim($nom) !== '' ? trim($nom) : $email,
                'detail' => $detail,
                'categorie' => $categorie,
                'origine' => $origine,
            ];
        };

        // 1. Soi-même : cas d'usage majoritaire (« je m'archive cette analyse »),
        //    gratuit et sans exposer le carnet.
        if ($acteur !== null) {
            $ajouter((string) $acteur->getEmail(), (string) $acteur->getNom(), 'Vous — votre compte', self::CATEGORIE_MOI);
        }

        // 2. Contacts des clients : LA cible principale.
        if ($invite !== null && $this->accessResolver->canRead($invite, 'Contact')) {
            foreach ($this->avecEmail(Contact::class, $entreprise) as $contact) {
                /** @var Contact $contact */
                $details = array_filter([
                    trim((string) $contact->getFonction()),
                    self::CONTACT_TYPE_LABELS[$contact->getType()] ?? null,
                ]);
                $ajouter(
                    (string) $contact->getEmail(),
                    (string) $contact->getNom(),
                    $details !== [] ? implode(' — ', $details) : 'Contact',
                    self::CATEGORIE_CONTACT,
                    (string) $contact->getClient()?->getNom(),
                );
            }
        }

        // 3. Collaborateurs de l'espace. On passe par le compte utilisateur :
        //    Invite::$email est transitoire (vidé au rattachement du compte),
        //    donc inexploitable comme carnet d'adresses.
        foreach ($entreprise->getInvites() as $collaborateur) {
            $compte = $collaborateur->getUtilisateur();
            if ($compte === null || $compte->getId() === $acteur?->getId()) {
                continue;
            }
            $ajouter(
                (string) $compte->getEmail(),
                (string) ($collaborateur->getNom() ?: $compte->getNom()),
                'Collaborateur',
                self::CATEGORIE_COLLABORATEUR,
            );
        }

        // 4. Correspondants externes.
        if ($invite !== null && $this->accessResolver->canRead($invite, 'Partenaire')) {
            foreach ($this->avecEmail(Partenaire::class, $entreprise) as $partenaire) {
                /** @var Partenaire $partenaire */
                $ajouter((string) $partenaire->getEmail(), (string) $partenaire->getNom(), 'Co-courtier', self::CATEGORIE_PARTENAIRE);
            }
        }
        if ($invite !== null && $this->accessResolver->canRead($invite, 'Assureur')) {
            foreach ($this->avecEmail(Assureur::class, $entreprise) as $assureur) {
                /** @var Assureur $assureur */
                $ajouter((string) $assureur->getEmail(), (string) $assureur->getNom(), 'Assureur', self::CATEGORIE_ASSUREUR);
            }
        }
        if ($invite !== null && $this->accessResolver->canRead($invite, 'Fournisseur')) {
            foreach ($this->avecEmail(Fournisseur::class, $entreprise) as $fournisseur) {
                /** @var Fournisseur $fournisseur */
                $personne = trim((string) $fournisseur->getPersonneContact());
                $ajouter(
                    (string) $fournisseur->getEmail(),
                    $personne !== '' ? $personne : (string) $fournisseur->getNom(),
                    'Fournisseur',
                    self::CATEGORIE_FOURNISSEUR,
                    (string) $fournisseur->getNom(),
                );
            }
        }

        $total = \count($destinataires);
        if ($total > self::MAX_CARNET) {
            // Troncature ANNONCÉE : une liste silencieusement coupée se lit
            // comme une liste complète.
            return ['destinataires' => \array_slice($destinataires, 0, self::MAX_CARNET), 'tronque' => $total - self::MAX_CARNET];
        }

        return ['destinataires' => $destinataires, 'tronque' => 0];
    }

    /**
     * Re-validation SERVEUR d'une adresse postée : elle doit appartenir
     * STRICTEMENT au carnet. null = hors carnet — l'appelant décide alors
     * (adresse saisie à la main : acceptée après validation et tracée comme
     * telle). Jamais de confiance à l'e-mail fourni par le client HTTP.
     *
     * @return array{email: string, nom: string, detail: string, categorie: string, origine: string}|null
     */
    public function trouver(Entreprise $entreprise, ?Invite $invite, ?Utilisateur $acteur, string $email): ?array
    {
        return $this->trouverPlusieurs($entreprise, $invite, $acteur, [$email])[mb_strtolower(trim($email))] ?? null;
    }

    /**
     * Même règle que trouver(), mais pour un LOT d'adresses : le carnet n'est
     * collecté qu'une fois, au lieu d'une fois par destinataire.
     *
     * @param array<int, string> $emails
     * @return array<string, array{email: string, nom: string, detail: string, categorie: string, origine: string}|null>
     *         indexé par adresse en minuscules ; null = hors carnet
     */
    public function trouverPlusieurs(Entreprise $entreprise, ?Invite $invite, ?Utilisateur $acteur, array $emails): array
    {
        $recherches = [];
        foreach ($emails as $email) {
            $cle = mb_strtolower(trim($email));
            if ($cle !== '') {
                $recherches[$cle] = null;
            }
        }
        if ($recherches === []) {
            return [];
        }

        foreach ($this->collecter($entreprise, $invite, $acteur)['destinataires'] as $destinataire) {
            $cle = mb_strtolower($destinataire['email']);
            if (array_key_exists($cle, $recherches)) {
                $recherches[$cle] = $destinataire;
            }
        }

        return $recherches;
    }

    /**
     * Entités de l'entreprise portant une adresse non vide. Le filtre est fait
     * en SQL : inutile d'hydrater un carnet entier pour en écarter la moitié.
     *
     * @param class-string $fqcn
     * @return array<int, object>
     */
    private function avecEmail(string $fqcn, Entreprise $entreprise): array
    {
        return $this->em->createQueryBuilder()
            ->select('x')
            ->from($fqcn, 'x')
            ->andWhere('x.entreprise = :entreprise')
            ->andWhere('x.email IS NOT NULL')
            ->andWhere("TRIM(x.email) <> ''")
            ->setParameter('entreprise', $entreprise)
            ->orderBy('x.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
