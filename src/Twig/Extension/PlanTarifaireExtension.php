<?php

namespace App\Twig\Extension;

use App\Entity\Article;
use App\Entity\Assureur;
use App\Entity\AutoriteFiscale;
use App\Entity\Avenant;
use App\Entity\Bordereau;
use App\Entity\Chargement;
use App\Entity\ChargementPourPrime;
use App\Entity\Classeur;
use App\Entity\Client;
use App\Entity\CompteBancaire;
use App\Entity\ConditionPartage;
use App\Entity\Contact;
use App\Entity\Cotation;
use App\Entity\Document;
use App\Entity\Entreprise;
use App\Entity\Feedback;
use App\Entity\Groupe;
use App\Entity\Invite;
use App\Entity\ModelePieceSinistre;
use App\Entity\Monnaie;
use App\Entity\Note;
use App\Entity\NotificationSinistre;
use App\Entity\OffreIndemnisationSinistre;
use App\Entity\Operation;
use App\Entity\Paiement;
use App\Entity\Partenaire;
use App\Entity\PieceSinistre;
use App\Entity\Piste;
use App\Entity\RevenuPourCourtier;
use App\Entity\Risque;
use App\Entity\RolesEnAdministration;
use App\Entity\RolesEnFinance;
use App\Entity\RolesEnMarketing;
use App\Entity\RolesEnProduction;
use App\Entity\RolesEnSinistre;
use App\Entity\Tache;
use App\Entity\Taxe;
use App\Entity\Tranche;
use App\Entity\TypeRevenu;
use App\Token\CouponService;
use App\Token\ParametresTokenService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Expose le plan tarifaire des tokens aux templates (vitrine publique, page
 * « Fonctionnement des tokens »…). Le but est que TOUT affichage de tarif lise
 * la même source de vérité que le backend de facturation : App\Token\
 * ParametresTokenService, qui résout les valeurs depuis la BDD (entité
 * PlateformeParametres, éditable via la Console) avec repli sur les constantes
 * TokenPricing. Ainsi, toute édition du plan en Console se reflète partout, et
 * le prix AFFICHÉ correspond toujours au prix FACTURÉ.
 */
class PlanTarifaireExtension extends AbstractExtension
{
    /**
     * Catalogue des entités facturables connues : libellés bilingues + alias
     * d'icône (cf. IconCanvasProvider). Sert à deux usages :
     *  - traduire le FQCN dans la table publique « Fonctionnement des tokens » ;
     *  - alimenter le sélecteur d'entité (et les icônes) de l'éditeur de poids
     *    d'écriture en Console.
     * Toute entité absente de ce catalogue retombe sur le nom court de sa classe
     * et l'icône par défaut (cf. getEntiteLabel() / getEntiteIcon()).
     *
     * Ce catalogue couvre désormais l'INTÉGRALITÉ des entités manipulées dans le
     * workspace des courtiers (cf. config/packages/menu.yaml + collections des
     * formulaires), afin que le poids d'écriture puisse être facturé sur 100 %
     * d'entre elles. Le métrage lui-même est générique (TokenAccountService::
     * meterWrite lit ParametresTokenService::weightFor pour n'importe quel FQCN) :
     * ce catalogue ne fait qu'exposer chaque entité au paramétrage et à l'affichage.
     * L'organisation suit les rubriques du menu du workspace.
     *
     * @var array<class-string, array{fr:string, en:string, icon:string}>
     */
    private const ENTITES = [
        // ── Socle ────────────────────────────────────────────────────────────
        Entreprise::class    => ['fr' => 'Entreprise',     'en' => 'Company',      'icon' => 'entreprise'],

        // ── Finances ─────────────────────────────────────────────────────────
        Monnaie::class       => ['fr' => 'Monnaie',        'en' => 'Currency',        'icon' => 'monnaie'],
        CompteBancaire::class => ['fr' => 'Compte bancaire', 'en' => 'Bank account', 'icon' => 'compte-bancaire'],
        Taxe::class          => ['fr' => 'Taxe',           'en' => 'Tax',             'icon' => 'taxe'],
        AutoriteFiscale::class => ['fr' => 'Autorité fiscale', 'en' => 'Tax authority', 'icon' => 'autorite-fiscale'],
        TypeRevenu::class    => ['fr' => 'Type de revenu', 'en' => 'Revenue type',    'icon' => 'type-revenu'],
        RevenuPourCourtier::class => ['fr' => 'Revenu',    'en' => 'Broker revenue',  'icon' => 'revenu'],
        Tranche::class       => ['fr' => 'Tranche',        'en' => 'Installment',     'icon' => 'tranche'],
        Chargement::class    => ['fr' => 'Type de chargement', 'en' => 'Loading type', 'icon' => 'chargement'],
        ChargementPourPrime::class => ['fr' => 'Chargement sur prime', 'en' => 'Premium loading', 'icon' => 'chargement'],
        Note::class          => ['fr' => 'Note de débit',  'en' => 'Debit note',      'icon' => 'note'],
        Article::class       => ['fr' => 'Article',        'en' => 'Line item',       'icon' => 'note'],
        Paiement::class      => ['fr' => 'Paiement',       'en' => 'Payment',         'icon' => 'paiement'],
        Operation::class     => ['fr' => 'Opération',      'en' => 'Operation',       'icon' => 'operation'],
        Bordereau::class     => ['fr' => 'Bordereau',      'en' => 'Bordereau',       'icon' => 'bordereau'],

        // ── Marketing ────────────────────────────────────────────────────────
        Piste::class         => ['fr' => 'Piste',          'en' => 'Lead',            'icon' => 'piste'],
        Tache::class         => ['fr' => 'Tâche',          'en' => 'Task',            'icon' => 'tache'],
        Feedback::class      => ['fr' => 'Feedback',       'en' => 'Feedback',        'icon' => 'feedback'],

        // ── Production ────────────────────────────────────────────────────────
        Groupe::class        => ['fr' => 'Groupe',         'en' => 'Group',           'icon' => 'groupe'],
        Client::class        => ['fr' => 'Client',         'en' => 'Client',          'icon' => 'client'],
        Assureur::class      => ['fr' => 'Assureur',       'en' => 'Insurer',         'icon' => 'assureur'],
        Contact::class       => ['fr' => 'Contact',        'en' => 'Contact',         'icon' => 'contact'],
        Risque::class        => ['fr' => 'Risque',         'en' => 'Risk',            'icon' => 'risque'],
        Cotation::class      => ['fr' => 'Cotation',       'en' => 'Quote',           'icon' => 'cotation'],
        Avenant::class       => ['fr' => 'Avenant',        'en' => 'Endorsement',     'icon' => 'avenant'],
        Partenaire::class    => ['fr' => 'Partenaire',     'en' => 'Partner',         'icon' => 'partenaire'],
        ConditionPartage::class => ['fr' => 'Condition de partage', 'en' => 'Sharing condition', 'icon' => 'condition'],

        // ── Sinistre ──────────────────────────────────────────────────────────
        ModelePieceSinistre::class => ['fr' => 'Type de pièce sinistre', 'en' => 'Claim document type', 'icon' => 'modele-piece'],
        PieceSinistre::class => ['fr' => 'Pièce sinistre', 'en' => 'Claim document',  'icon' => 'piece-sinistre'],
        NotificationSinistre::class => ['fr' => 'Notification de sinistre', 'en' => 'Claim notification', 'icon' => 'sinistre'],
        OffreIndemnisationSinistre::class => ['fr' => 'Règlement de sinistre', 'en' => 'Claim settlement', 'icon' => 'offre'],

        // ── Administration ───────────────────────────────────────────────────
        Document::class      => ['fr' => 'Document',       'en' => 'Document',        'icon' => 'document'],
        Classeur::class      => ['fr' => 'Classeur',       'en' => 'Binder',          'icon' => 'classeur'],
        Invite::class        => ['fr' => 'Invité',         'en' => 'Invitee',         'icon' => 'invite'],

        // ── Rôles collaborateurs (par pôle) ──────────────────────────────────
        RolesEnAdministration::class => ['fr' => 'Rôle en administration', 'en' => 'Administration role', 'icon' => 'role'],
        RolesEnFinance::class => ['fr' => 'Rôle en finance', 'en' => 'Finance role',   'icon' => 'role'],
        RolesEnMarketing::class => ['fr' => 'Rôle en marketing', 'en' => 'Marketing role', 'icon' => 'role'],
        RolesEnProduction::class => ['fr' => 'Rôle en production', 'en' => 'Production role', 'icon' => 'role'],
        RolesEnSinistre::class => ['fr' => 'Rôle en sinistre', 'en' => 'Claims role',  'icon' => 'role'],
    ];

    public function __construct(
        private ParametresTokenService $parametres,
        private CouponService $couponService,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('plan_tarifaire', [$this, 'getPlanTarifaire']),
            new TwigFunction('promos_vitrine', [$this, 'getPromosVitrine']),
            new TwigFunction('token_pack_label', [$this, 'getPackLabel']),
            new TwigFunction('token_entite_label', [$this, 'getEntiteLabel']),
            new TwigFunction('token_entite_icon', [$this, 'getEntiteIcon']),
            new TwigFunction('token_entites_facturables', [$this, 'getEntitesFacturables']),
        ];
    }

    /**
     * Meilleures promos publiques par paquet, pour la vitrine : map
     * « clé de paquet => { code, type, valeur, montantFinal, remiseUsd } ». Calculée
     * en lisant la MÊME source que le paiement (CouponService), si bien que le prix
     * remisé AFFICHÉ correspond exactement au prix FACTURÉ avec ce coupon. Les
     * paquets sans promo publique applicable sont absents de la map.
     *
     * @return array<string, array{code: string, type: string, valeur: float, montantFinal: float, remiseUsd: float}>
     */
    public function getPromosVitrine(): array
    {
        $now = new \DateTimeImmutable();
        $promos = [];

        foreach ($this->parametres->packs() as $key => $pack) {
            $promo = $this->couponService->meilleureRemisePublique($key, (float) $pack['price'], $now);
            if ($promo !== null) {
                $promos[$key] = $promo;
            }
        }

        return $promos;
    }

    /**
     * Carte « FQCN => libellé » des entités facturables connues, pour alimenter le
     * sélecteur d'entité de l'éditeur de poids d'écriture (Console). Source unique :
     * le catalogue ENTITES ci-dessus.
     *
     * @return array<string, string>
     */
    public function getEntitesFacturables(string $lang = 'fr'): array
    {
        $entites = [];
        foreach (array_keys(self::ENTITES) as $fqcn) {
            $entites[$fqcn] = $this->getEntiteLabel($fqcn, $lang);
        }

        return $entites;
    }

    /**
     * Donne accès au service de plan tarifaire ; les templates appellent ses
     * méthodes publiques : plan_tarifaire().freeAllowance, .freeWindowHours,
     * .readWeight, .defaultWriteWeight, .packs, .pack('intermediaire'),
     * .writeWeights…
     */
    public function getPlanTarifaire(): ParametresTokenService
    {
        return $this->parametres;
    }

    /**
     * Libellé public d'un paquet à partir de sa clé technique : on lit la MÊME
     * source que la vitrine (ParametresTokenService → label configuré ou repli
     * TokenPricing::PACKS). Repli pour une clé absente du plan (donnée héritée) :
     * la clé capitalisée, identique au filtre Twig `capitalize` historique.
     */
    public function getPackLabel(string $key): string
    {
        return $this->parametres->pack($key)['label'] ?? ucfirst(mb_strtolower($key));
    }

    /** Libellé d'affichage d'une entité (par FQCN) dans la langue demandée. */
    public function getEntiteLabel(string $fqcn, string $lang = 'fr'): string
    {
        if (isset(self::ENTITES[$fqcn])) {
            return self::ENTITES[$fqcn][$lang] ?? self::ENTITES[$fqcn]['fr'];
        }

        // Entité inconnue (ajoutée via le JSON) : on affiche le nom court de la classe.
        $parts = explode('\\', $fqcn);

        return end($parts) ?: $fqcn;
    }

    /** Alias d'icône (cf. IconCanvasProvider) d'une entité, repli sur 'default'. */
    public function getEntiteIcon(string $fqcn): string
    {
        return self::ENTITES[$fqcn]['icon'] ?? 'default';
    }
}
