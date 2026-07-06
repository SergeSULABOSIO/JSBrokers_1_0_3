<?php

namespace App\Twig\Extension;

use App\Services\Canvas\Provider\Icon\IconCanvasProvider;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class IconExtension extends AbstractExtension
{
    public function __construct(
        private IconCanvasProvider $iconCanvasProvider
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('resolve_icon_name', [$this, 'resolveIconName']),
            new TwigFunction('secondary_icon', [$this, 'secondaryIcon']),
        ];
    }

    /**
     * Devine une icône « de signification » pour une information de ligne secondaire à
     * partir de son code d'attribut. Permet d'appliquer partout le pattern de la liste des
     * clients (chaque info précédée de son icône) sans annoter chaque canevas. Un canevas
     * peut toujours forcer une icône explicite (clé `icone`), qui prime sur cette déduction.
     *
     * @param string|null $code Le code de l'attribut (ex. « email », « montantTTC », « createdAt »).
     * @return string Un nom d'icône lucide (résolu ensuite par resolve_icon_name).
     */
    public function secondaryIcon(?string $code): string
    {
        $c = strtolower((string) $code);
        if ($c === '') {
            return 'lucide:tag';
        }

        // Dates : suffixe « At » (createdAt, receivedAt…) ou mot « date ».
        if (preg_match('/at$/', $c) || str_contains($c, 'date') || str_contains($c, 'echeance')) {
            return 'lucide:calendar';
        }
        if (str_contains($c, 'email') || str_contains($c, 'mail')) {
            return 'lucide:mail';
        }
        if (str_contains($c, 'tel') || str_contains($c, 'phone')) {
            return 'lucide:phone';
        }
        if (str_contains($c, 'url') || str_contains($c, 'lien') || str_contains($c, 'link')) {
            return 'lucide:link';
        }
        if (str_contains($c, 'adresse') || str_contains($c, 'lieu') || str_contains($c, 'ville') || str_contains($c, 'pays')) {
            return 'lucide:map-pin';
        }
        // Taux / pourcentages.
        if (str_contains($c, 'taux') || str_contains($c, 'percent') || str_contains($c, 'pourcent')) {
            return 'lucide:percent';
        }
        // Montants et grandeurs financières.
        if (preg_match('/(montant|prime|commission|retro|solde|total|taxe|tva|reserve|assiette|revenu|franchise|paiement|versement|encaisse|decaisse|tresorerie|capital|usd|cdf|eur)/', $c)) {
            return 'lucide:coins';
        }
        // Statuts.
        if (str_contains($c, 'statut') || str_contains($c, 'status') || str_contains($c, 'etat')) {
            return 'lucide:info';
        }
        // Références, codes, numéros, identifiants légaux.
        if (preg_match('/(reference|numero|numimpot|rccm|idnat|licence|matricule|\bref\b|code)/', $c)) {
            return 'lucide:hash';
        }
        // Descriptions et textes libres.
        if (str_contains($c, 'description') || str_contains($c, 'commentaire') || str_contains($c, 'text') || str_contains($c, 'note')) {
            return 'lucide:align-left';
        }
        // Personnes et organisations liées.
        if (str_contains($c, 'portefeuille')) {
            return 'lucide:folder';
        }
        if (str_contains($c, 'groupe')) {
            return 'lucide:users';
        }
        if (str_contains($c, 'assureur')) {
            return 'lucide:shield';
        }
        if (str_contains($c, 'partenaire')) {
            return 'lucide:handshake';
        }
        if (str_contains($c, 'fournisseur')) {
            return 'lucide:truck';
        }
        if (str_contains($c, 'invite') || str_contains($c, 'gestionnaire') || str_contains($c, 'executor') || str_contains($c, 'executeur')) {
            return 'lucide:user-cog';
        }
        if (str_contains($c, 'client') || str_contains($c, 'assure') || str_contains($c, 'beneficiaire') || str_contains($c, 'contact')) {
            return 'lucide:user';
        }
        if (str_contains($c, 'risque') || str_contains($c, 'produit')) {
            return 'lucide:shield-alert';
        }
        if (str_contains($c, 'type') || str_contains($c, 'categorie') || str_contains($c, 'nature')) {
            return 'lucide:tag';
        }
        if (str_contains($c, 'nom') || str_contains($c, 'titre') || str_contains($c, 'libelle') || str_contains($c, 'principal') || str_contains($c, 'secondaire')) {
            return 'lucide:type';
        }
        if (str_contains($c, 'nombre') || str_contains($c, 'count') || str_contains($c, 'quantite') || str_contains($c, 'duree')) {
            return 'lucide:hash';
        }

        return 'lucide:tag';
    }

    /**
     * Résout un nom d'alias d'icône en son vrai nom.
     * Si le nom fourni est un alias connu (ex: 'assureur'), il retourne le vrai nom (ex: 'wpf:security-checked').
     * Sinon, il retourne le nom original tel quel (ex: 'lucide:file-text').
     *
     * @param string $iconName Le nom de l'icône ou son alias.
     * @return string Le nom résolu de l'icône.
     */
    public function resolveIconName(string $iconName): string
    {
        return $this->iconCanvasProvider->resolveIconName($iconName) ?? $iconName;
    }
}