<?php

namespace App\Services\Canvas\Provider\List;

use App\Services\Search\ProductionScope;

/**
 * LA RUBRIQUE « PRODUCTION INTERMÉDIAIRES » — ce que chaque intermédiaire apporte.
 *
 * Elle remplace le rapport de production, qui ne s'ouvrait que par la porte d'une fiche
 * (celle d'un partenaire, ou celle d'un invité) et ne se situait nulle part dans l'arbre du
 * menu : son entête, sa recherche et ses commandes étaient dessinés à la main, sans rapport
 * avec la coquille des trente autres rubriques.
 *
 * ── LE TABLEAU N'A PAS BOUGÉ ────────────────────────────────────────────────────────
 * Ses vingt-trois colonnes disent le trajet de l'argent, de la prime du client jusqu'au
 * solde qui reste dû à l'intermédiaire. C'est un document qu'on conteste : il ne se réduit
 * pas aux trois textes du rendu générique. Le canevas déclare donc `rendu_personnalise`, et
 * le socle s'efface — tout le reste de la coquille est rendu à l'identique.
 *
 * ── TROIS CHIPS, ET AUCUN N'EST DÉCORATIF ───────────────────────────────────────────
 * Le STATUT partitionne les affaires (souscrites ⊎ en attente ⊎ caduques) ; le TYPE et le
 * BÉNÉFICIAIRE désignent de qui l'on parle. Sans bénéficiaire, la rubrique reste VIDE :
 * les affaires d'un intermédiaire se calculent une à une par le moteur de partage, et les
 * calculer pour tout le monde d'emblée serait payer très cher un écran que personne n'a
 * demandé.
 */
class ProductionIntermediaireListCanvasProvider implements ListCanvasProviderInterface
{
    public function supports(string $entityClassName): bool
    {
        return $entityClassName === ProductionScope::ENTITE;
    }

    public function getCanvas(): array
    {
        return [
            // LE TABLEAU DU RAPPORT, DÉPLACÉ SANS RETOUCHE. C'est l'unique rubrique qui
            // apporte son propre rendu ; le socle l'inclut à la place de son tableau
            // générique (cf. `_List_manager.html.twig`).
            'rendu_personnalise' => 'components/production/_lignes.html.twig',

            // Le titre de colonne du rendu générique n'est jamais lu ici, mais la coquille
            // l'attend pour nommer la rubrique dans ses libellés d'accessibilité.
            'colonne_principale' => [
                'titre_colonne' => 'Affaires produites',
                'texte_principal' => ['attribut_code' => 'client', 'icone' => 'client'],
                'textes_secondaires' => [],
            ],

            'filtres_predefinis' => [
                [
                    // LA PARTITION DES AFFAIRES, et elle est à valeur unique : c'est ce qui
                    // garantit qu'une vue ne mélange jamais les avenants et les propositions,
                    // et donc que l'identifiant d'une ligne reste un entier.
                    'critere' => ProductionScope::CLE_STATUT,
                    'libelle' => 'Statut',
                    'options' => ProductionScope::optionsChips(
                        ProductionScope::CLE_STATUT,
                        [
                            'souscrites' => 'action:check',
                            'en_attente' => 'action:ongoing',
                            'caduques' => 'action:cancel',
                        ],
                        'Toutes',
                    ),
                ],
                [
                    // La rubrique porte les DEUX familles : un agent interne et un partenaire
                    // externe n'ont ni la même dette ni le même compte comptable, et c'est le
                    // seul moyen de lire l'une sans l'autre.
                    'critere' => ProductionScope::CLE_TYPE,
                    'libelle' => 'Type',
                    'options' => ProductionScope::optionsChips(
                        ProductionScope::CLE_TYPE,
                        [
                            ProductionScope::TYPE_AGENT => 'invite',
                            ProductionScope::TYPE_PARTENAIRE => 'partenaire',
                        ],
                        'Tous',
                    ),
                ],
                [
                    // LE CHIP-SÉLECTEUR : ses options ne portent pas une valeur mais une
                    // ENTITÉ où aller chercher les valeurs au clic — déjà scopée au cabinet
                    // et déjà gardée par les droits de son entité.
                    //
                    // Sa visibilité et son implication viennent du SOCLE partagé avec les
                    // « Rétros intermédiaires » : choisir « SUNU Courtage » aligne le chip
                    // Type du même geste, et « Choisir un partenaire… » disparaît quand le
                    // Type est sur Agent — un filtre qui viderait la liste à coup sûr n'a pas
                    // à être offert.
                    'critere' => ProductionScope::CLE_BENEFICIAIRE,
                    'libelle' => 'Bénéficiaire',
                    'options' => ProductionScope::optionsBeneficiaire(),
                ],
            ],

            // AUCUNE COLONNE NUMÉRIQUE GÉNÉRIQUE : le tableau a les siennes, et son pied les
            // totalise. Ce que la barre des totaux additionne lui est fourni séparément, par
            // le contrôleur (`numericAttributesAndValues`).
            'colonnes_numeriques' => [],
        ];
    }
}
