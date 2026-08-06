<?php

namespace App\Tests\Workspace;

use App\Services\Search\TranchePaiementScope;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Compacité des groupes de chips de la rubrique Tranches.
 *
 * La rubrique porte QUATRE groupes cumulables. Tant que chaque bouton répétait le critère
 * (« Prime payée », « Prime partiellement payée », « Prime impayée »), deux groupes
 * suffisaient à remplir la ligne et les suivants passaient à la ligne — l'utilisateur ne
 * voyait plus qu'il pouvait les croiser. Le critère est donc écrit une seule fois, en titre
 * du groupe, et les boutons ne portent que la valeur.
 *
 * Ce test garde l'intention : rien n'empêche, sinon, de réintroduire un libellé long au
 * prochain axe ajouté.
 */
class TrancheChipsCompactsTest extends KernelTestCase
{
    public function testUnLibelleDeChipNeRepeteJamaisLeTitreDeSonGroupe(): void
    {
        foreach (TranchePaiementScope::AXES as $cle => $axe) {
            foreach (array_keys($axe['valeurs']) as $valeur) {
                $court = TranchePaiementScope::libelleCourt($cle, $valeur);

                $this->assertStringNotContainsStringIgnoringCase(
                    $axe['titre'],
                    $court,
                    sprintf(
                        'Le chip « %s » répète le titre « %s » de son groupe : le critère est déjà écrit au-dessus.',
                        $court,
                        $axe['titre'],
                    ),
                );
            }
        }
    }

    /**
     * Un chip court doit rester court : au-delà d'une quinzaine de caractères, quatre
     * groupes ne tiennent plus sur une ligne d'écran courant.
     */
    public function testLesLibellesCourtsRestentCourts(): void
    {
        foreach (TranchePaiementScope::AXES as $cle => $axe) {
            foreach (array_keys($axe['valeurs']) as $valeur) {
                $court = TranchePaiementScope::libelleCourt($cle, $valeur);

                $this->assertLessThanOrEqual(
                    15,
                    mb_strlen($court),
                    sprintf('Le chip « %s » est trop long pour une barre à quatre groupes.', $court),
                );
            }
        }
    }

    /**
     * Le libellé COMPLET reste disponible et non ambigu : c'est lui que porte le badge de
     * recherche, l'infobulle du chip et la réponse de l'assistant.
     *
     * L'exigence ne vaut que pour les trois DETTES : « Payée » ou « Partielle » ne disent
     * pas, seuls, qui doit quoi — c'est précisément la confusion que le découpage en axes a
     * supprimée. L'axe d'échéance n'a pas ce besoin : « Échues (en retard) » se comprend
     * isolément, sans avoir à répéter le mot « échéance ».
     */
    public function testLeLibelleCompletDUneDetteNommeToujoursSaDette(): void
    {
        $dettes = [
            TranchePaiementScope::AXE_PRIME,
            TranchePaiementScope::AXE_COMMISSION,
            TranchePaiementScope::AXE_RETRO,
        ];

        foreach ($dettes as $cle) {
            $axe = TranchePaiementScope::AXES[$cle];
            foreach (array_keys($axe['valeurs']) as $valeur) {
                $complet = TranchePaiementScope::libelle($cle, $valeur);

                $this->assertStringContainsStringIgnoringCase(
                    $axe['titre'],
                    $complet,
                    sprintf('Le libellé complet « %s » doit nommer sa dette.', $complet),
                );
            }
        }
    }

    /**
     * Chaque valeur d'axe a son libellé court ET son icône : sans cela, libelleCourt()
     * retomberait silencieusement sur le libellé complet, et un chip se retrouverait sans
     * icône là où toutes les autres rubriques en portent une.
     */
    public function testChaqueValeurALeSienne(): void
    {
        foreach (TranchePaiementScope::AXES as $axe) {
            $this->assertSame(
                array_keys($axe['valeurs']),
                array_keys($axe['courts']),
                sprintf('L\'axe « %s » doit fournir un libellé court par valeur.', $axe['titre']),
            );
            $this->assertSame(
                array_keys($axe['valeurs']),
                array_keys($axe['icones']),
                sprintf('L\'axe « %s » doit fournir une icône par valeur.', $axe['titre']),
            );
        }
    }

    /**
     * UNE SEULE RÈGLE POUR TOUTES LES RUBRIQUES : chaque chip porte une icône, le titre du
     * groupe n'en porte pas. Tranche y avait fait exception — icône unique sur le titre,
     * boutons nus — ce qui la désalignait d'Avenants, Propositions et Pistes. L'écart ne se
     * voyait qu'à l'œil, d'où ce test.
     */
    public function testChaqueChipPorteUneIconeEtAucunGroupeNEnPorte(): void
    {
        // Les providers ont des dépendances (monnaies, icônes) : on les prend au conteneur
        // plutôt que de les instancier, pour vérifier le canevas RÉELLEMENT servi.
        self::bootKernel();
        $conteneur = static::getContainer();

        $providers = [
            'Tranche' => \App\Services\Canvas\Provider\List\TrancheListCanvasProvider::class,
            'Avenant' => \App\Services\Canvas\Provider\List\AvenantListCanvasProvider::class,
            'Cotation' => \App\Services\Canvas\Provider\List\CotationListCanvasProvider::class,
            'Piste' => \App\Services\Canvas\Provider\List\PisteListCanvasProvider::class,
        ];

        foreach ($providers as $entite => $classe) {
            $liste = $conteneur->get($classe)->getCanvas();
            foreach ($liste['filtres_predefinis'] ?? [] as $groupe) {
                $this->assertArrayNotHasKey(
                    'icon',
                    $groupe,
                    sprintf('%s : le titre du groupe ne porte pas d\'icône.', $entite),
                );
                foreach ($groupe['options'] as $option) {
                    $this->assertArrayHasKey(
                        'icon',
                        $option,
                        sprintf('%s : le chip « %s » doit porter une icône.', $entite, $option['label']),
                    );
                }
            }
        }
    }
}
