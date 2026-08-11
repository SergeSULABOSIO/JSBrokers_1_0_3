<?php

namespace App\Ai\Document;

use App\Entity\Entreprise;
use App\Token\ParametresTokenService;
use App\Token\TokenAccountService;

/**
 * LA FORMULE, à un seul endroit.
 *
 *     cout = ceil( (base + pages × parPage) × multiplicateur(format) )
 *     pages = max(1, ceil(caractères / caractèresParPage))
 *
 * Deux lecteurs doivent en sortir le MÊME nombre, sans quoi la promesse « vous
 * voyez le prix avant de valider » ne tient pas : l'outil qui présente le plan, et
 * l'endpoint qui produit et débite. C'est le même principe DRY que
 * WorkspaceMutationService::facturablesArbre() pour les plans d'écriture.
 *
 * Un troisième lecteur s'y ajoute — la page publique « Fonctionnement des tokens »,
 * via chiffrerPages() : le prix AFFICHÉ au visiteur est littéralement produit par
 * le code qui FACTURE, et non par un calcul recopié dans un template.
 */
final class DocumentTarificateur
{
    public function __construct(
        private readonly ParametresTokenService $parametres,
        private readonly TokenAccountService $tokenAccountService,
    ) {
    }

    /** Nombre de pages FACTURÉES d'un contenu (arrondi supérieur, minimum une). */
    public function pages(string $contenu): int
    {
        // mb_strlen, jamais strlen : un document français est accentué, le compter
        // en octets doublerait sa facture.
        return $this->pagesDepuisCaracteres(mb_strlen($contenu));
    }

    public function pagesDepuisCaracteres(int $caracteres): int
    {
        return max(1, (int) ceil(max(0, $caracteres) / $this->parametres->documentCaracteresParPage()));
    }

    /** Chiffrage à partir du contenu FINAL du document. */
    public function chiffrer(string $contenu, DocumentFormat|string $format): DocumentDevis
    {
        return $this->assembler(mb_strlen($contenu), DocumentFormat::depuis($format));
    }

    /**
     * Chiffrage à partir d'un nombre de pages déjà connu — la porte de la page
     * publique et de tout simulateur. Passe par le MÊME assemblage : il n'existe
     * qu'un seul `ceil` de multiplication dans le projet.
     */
    public function chiffrerPages(int $pages, DocumentFormat|string $format): DocumentDevis
    {
        $pages = max(1, $pages);

        return $this->assembler($pages * $this->parametres->documentCaracteresParPage(), DocumentFormat::depuis($format), $pages);
    }

    /** Coût sec, quand la ventilation n'intéresse pas l'appelant. */
    public function cout(string $contenu, DocumentFormat|string $format): int
    {
        return $this->chiffrer($contenu, $format)->cout;
    }

    /**
     * Le budget tel que la barre de décision doit l'afficher : le devis, le solde
     * du propriétaire, ce qu'il resterait, et le coût de CHACUN des formats.
     *
     * `parFormat` permet au sélecteur de format de réafficher le prix instantanément
     * sans aller-retour — tout en gardant le serveur seul maître du calcul.
     *
     * @return array<string, mixed>
     */
    public function budget(string $contenu, DocumentFormat $format, Entreprise $entreprise): array
    {
        $devis = $this->chiffrer($contenu, $format);
        $solde = $this->tokenAccountService->availableFor($entreprise);

        $parFormat = [];
        foreach (DocumentFormat::cases() as $candidat) {
            $chiffrage = $this->chiffrer($contenu, $candidat);
            $parFormat[] = [
                'valeur'  => $candidat->value,
                'libelle' => $candidat->libelle(),
                'badge'   => $candidat->badge(),
                'cout'    => $chiffrage->cout,
            ];
        }

        return $devis->toArray() + [
            'coutEstime'      => $devis->cout,
            'soldeDisponible' => $solde,
            'resteApres'      => max(0, $solde - $devis->cout),
            'suffisant'       => $solde >= $devis->cout,
            'explication'     => $devis->explication(),
            'parFormat'       => $parFormat,
        ];
    }

    private function assembler(int $caracteres, DocumentFormat $format, ?int $pages = null): DocumentDevis
    {
        $caracteres = max(0, $caracteres);
        $parPage = $this->parametres->documentParPage();
        $base = $this->parametres->documentBase();
        $carParPage = $this->parametres->documentCaracteresParPage();
        $pages ??= $this->pagesDepuisCaracteres($caracteres);
        $multiplicateur = $this->parametres->documentMultiplicateur($format->value);

        $avant = $base + $pages * $parPage;

        return new DocumentDevis(
            format: $format,
            caracteres: $caracteres,
            pages: $pages,
            caracteresParPage: $carParPage,
            base: $base,
            parPage: $parPage,
            multiplicateur: $multiplicateur,
            coutAvantFormat: $avant,
            // L'arrondi tombe APRÈS la multiplication : arrondir le sous-total
            // donnerait un autre prix, et deux implémentations divergeraient.
            cout: (int) ceil($avant * $multiplicateur),
        );
    }
}
