<?php

namespace App\Services\Canvas\Provider\Numeric;

use App\Services\Canvas\ListCanvasProvider;
use Doctrine\Persistence\Proxy;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

/**
 * LA BARRE DES TOTAUX NE TOTALISE QUE CE QUE LA LIGNE MONTRE.
 *
 * Les options du sélecteur « Totaliser : … » et les colonnes numériques du tableau
 * étaient déclarées dans DEUX fichiers qui ne se connaissaient pas : les
 * `colonnes_numeriques` d'un ListCanvasProvider d'un côté, le dictionnaire d'un
 * NumericCanvasProvider de l'autre. Rien ne garantissait qu'ils parlent des mêmes
 * grandeurs — et ils avaient divergé.
 *
 * La rubrique Intermédiaires affichait six colonnes et proposait vingt-huit options,
 * dont « Nb. Pistes ». Choisir celle-là affichait « Total 11,00 $US » là où 11 était un
 * NOMBRE DE PISTES : `list-summary_controller.js` ne sait rendre que de la monnaie. Un
 * compte présenté comme un montant, dans un outil dont le métier est l'argent.
 *
 * Ce service ferme l'écart PAR CONSTRUCTION : le canevas numérique d'une entité EST la
 * liste de ses colonnes, lue au même endroit et dans le même ordre. Ajouter une colonne
 * ajoute son option, la retirer la retire. Il n'y a plus de liste à tenir à jour.
 *
 * ── TROIS RÈGLES, TOUTES DÉLIBÉRÉES ─────────────────────────────────────────────────
 *
 * 1. LES TAUX SONT ÉCARTÉS (`attribut_unité === '%'`). La somme des « Part (%) » de dix
 *    partenaires n'est pas une part. Et le contrôleur JS ne saurait de toute façon pas
 *    l'afficher : il ignore le `unit: '%'` que produisait l'ancien dictionnaire, et
 *    formaterait « 150 % » en « 1,50 $US ».
 *
 * 2. CHAQUE COLONNE PRODUIT TOUJOURS UNE ENTRÉE, à zéro si la valeur manque. Le
 *    sélecteur est bâti sur la PREMIÈRE LIGNE seulement (`Object.values(data)[0]`,
 *    list-summary_controller.js) : une clé absente sur la ligne 1 fait disparaître
 *    l'option pour la page entière. Le canevas se déduisant de la CLASSE et non de
 *    l'instance, sa forme est identique d'une ligne à l'autre — ce mode dégradé
 *    disparaît.
 *
 * 3. LES MONTANTS SONT EN CENTIMES. C'est le contrat de `formatCurrency()`, qui divise
 *    par 100 avant de formater.
 */
final class ListColumnsNumericCanvasBuilder
{
    /**
     * Les colonnes retenues, par classe d'entité.
     *
     * ⚠ CE CACHE N'EST PAS UN CONFORT. `ServiceMonnaies::getCodeMonnaieAffichage()`, que
     * CHAQUE colonne d'un ListCanvasProvider appelle, déclenche un `findBy()` sans
     * mémoire. Sans lui, une page de vingt intermédiaires à six colonnes coûterait cent
     * vingt requêtes de monnaie. Le service étant un singleton de requête, la portée du
     * cache est exactement la bonne.
     *
     * @var array<class-string, array<string, string>> classe => [code => libellé]
     */
    private array $colonnesParClasse = [];

    public function __construct(
        private readonly ListCanvasProvider $listCanvasProvider,
        private readonly PropertyAccessorInterface $propertyAccessor,
    ) {
    }

    /**
     * @return array<string, array{description: string, value: float}>
     */
    public function build(object $entity): array
    {
        $canevas = [];
        foreach ($this->colonnesTotalisables($this->classeReelle($entity)) as $code => $libelle) {
            $canevas[$code] = [
                'description' => $libelle,
                // L'arrondi entier évite les 1234.5600000000001 qu'accumulerait la
                // multiplication flottante sur une page de vingt lignes.
                'value' => round(((float) ($this->lireValeur($entity, $code) ?? 0.0)) * 100),
            ];
        }

        return $canevas;
    }

    /**
     * @return array<string, string> code => libellé, dans l'ordre des colonnes de l'écran
     */
    private function colonnesTotalisables(string $classeEntite): array
    {
        if (isset($this->colonnesParClasse[$classeEntite])) {
            return $this->colonnesParClasse[$classeEntite];
        }

        $retenues = [];
        foreach ($this->listCanvasProvider->getCanvas($classeEntite)['colonnes_numeriques'] ?? [] as $colonne) {
            $code = $colonne['attribut_code'] ?? null;
            // La comparaison est au '%' LITTÉRAL, jamais « différent de la monnaie » :
            // getCodeMonnaieAffichage() rend null quand aucune monnaie n'est configurée,
            // et ce test-là écarterait alors TOUTES les colonnes.
            if ($code === null || ($colonne['attribut_unité'] ?? null) === '%') {
                continue;
            }

            // Le libellé de l'option EST le titre de la colonne : sous « Totaliser : »,
            // l'usager retrouve le mot exact qu'il lit en tête de colonne.
            $retenues[$code] = $colonne['titre_colonne'] ?? $code;
        }

        return $this->colonnesParClasse[$classeEntite] = $retenues;
    }

    /**
     * LA MÊME VALEUR QUE LA CELLULE, LUE DE LA MÊME FAÇON.
     *
     * La cellule fait `attribute(entity, code)` (_list_row.html.twig), dont Twig résout la
     * précédence ainsi : propriété publique ou DYNAMIQUE d'abord, accesseur ensuite.
     * PropertyAccess fait l'INVERSE — le getter d'abord. On réplique donc l'ordre de Twig,
     * puis on délègue le seul cas de l'accesseur au composant.
     *
     * Aucune collision n'existe aujourd'hui sur ces rubriques ; mais le jour où une entité
     * portera à la fois une propriété posée par sa stratégie et un getter lisant la colonne
     * persistée, la barre et la cellule afficheraient deux chiffres différents — le bug
     * même que ce service existe pour fermer.
     *
     * Les trois cas réels :
     *   • propriété publique déclarée     → Portefeuille::$primeTotale, Partenaire::$montantPur
     *   • propriété DYNAMIQUE posée par     Invite::$primeTotale, $montantTTC, $montantPur
     *     CanvasBuilder::loadAllCalculatedValues()
     *   • propriété privée + accesseur    → PaiementPrime::$montant / getMontant()
     */
    private function lireValeur(object $entity, string $code): float|int|null
    {
        if (isset($entity->$code) || \array_key_exists($code, (array) $entity)) {
            return $this->siNombre($entity->$code);
        }

        try {
            if ($this->propertyAccessor->isReadable($entity, $code)) {
                return $this->siNombre($this->propertyAccessor->getValue($entity, $code));
            }
        } catch (\Throwable) {
            // Une valeur illisible — propriété typée non initialisée, relation rompue —
            // vaut zéro : la barre n'a pas à faire tomber la page qu'elle résume.
        }

        return null;
    }

    /**
     * Garde-fou contre une colonne déclarée « nombre » qui porterait du texte : mieux vaut
     * un total à zéro qu'une concaténation muette.
     */
    private function siNombre(mixed $valeur): float|int|null
    {
        return \is_int($valeur) || \is_float($valeur) ? $valeur : null;
    }

    private function classeReelle(object $entity): string
    {
        return $entity instanceof Proxy ? get_parent_class($entity) : $entity::class;
    }
}
