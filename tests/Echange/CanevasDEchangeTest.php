<?php

namespace App\Tests\Echange;

use App\Ai\Mutation\MutationAllowlist;
use App\Echange\Canevas\CanevasDEchange;
use App\Echange\Canevas\ColonneDEchange;
use App\Echange\Canevas\RessourceDEchange;
use App\Service\Workspace\WorkspaceAccessResolver;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Le canevas d'échange ne DÉCLARE rien : il dérive. Ces tests verrouillent le contrat
 * qui rend cette dérivation sûre — car un dérivateur qui se trompe se trompe sur les
 * quarante-deux entités à la fois.
 */
class CanevasDEchangeTest extends KernelTestCase
{
    private function canevas(): CanevasDEchange
    {
        self::bootKernel();

        return static::getContainer()->get(CanevasDEchange::class);
    }

    /**
     * LE critère d'acceptation de la rubrique : ajouter une entité au périmètre
     * d'échange ne demande qu'un nom dans l'allowlist. Si ce test tombe, c'est qu'une
     * liste parallèle est née quelque part.
     */
    public function testLePerimetreEstExactementCeluiDeLAllowlist(): void
    {
        $codes = array_keys($this->canevas()->toutes());
        sort($codes);

        $attendus = MutationAllowlist::MEMBRES;
        sort($attendus);

        self::assertSame($attendus, $codes, "Le périmètre d'échange doit se dériver de MutationAllowlist, sans liste propre.");
    }

    /** Une ressource sans colonne serait une feuille vide : le dérivateur a échoué en silence. */
    public function testChaqueRessourceProduitDesColonnes(): void
    {
        foreach ($this->canevas()->toutes() as $code => $ressource) {
            self::assertNotEmpty($ressource->colonnes, sprintf('La ressource « %s » n\'expose aucune colonne.', $code));
            self::assertNotEmpty(
                $ressource->colonnesModifiables(),
                sprintf('La ressource « %s » n\'expose aucune colonne modifiable : elle serait exportable mais jamais réimportable.', $code),
            );
        }
    }

    /**
     * Test 17 de la spécification, dans sa forme utile : l'ordre existe, il est total,
     * et il respecte les dépendances qui n'ont PAS été différées.
     */
    public function testLOrdreTopologiqueEstTotalEtRespecteLesDependances(): void
    {
        $toutes = $this->canevas()->toutes();

        $rangs = [];
        foreach ($toutes as $code => $ressource) {
            $rangs[$code] = $ressource->rang;
        }
        self::assertCount(count($toutes), array_unique($rangs), 'Deux ressources partagent le même rang.');

        foreach ($toutes as $code => $ressource) {
            foreach ($ressource->dependances as $dependance) {
                // Un renvoi différé est justement celui qu'on a renoncé à ordonner :
                // il sera posé par une seconde passe, après création de la cible.
                $colonnesVersLaCible = array_filter(
                    $ressource->colonnes,
                    static fn (ColonneDEchange $c) => $c->referenceCode === $dependance,
                );
                $toutesDifferees = $colonnesVersLaCible !== [] && array_reduce(
                    $colonnesVersLaCible,
                    static fn (bool $porte, ColonneDEchange $c) => $porte && $ressource->renvoiEstDiffere($c->code),
                    true,
                );
                if ($toutesDifferees) {
                    continue;
                }

                self::assertLessThan(
                    $ressource->rang,
                    $toutes[$dependance]->rang,
                    sprintf('« %s » dépend de « %s » : la cible doit être écrite avant.', $code, $dependance),
                );
            }
        }
    }

    /**
     * Le cycle Piste → Avenant → Cotation → Piste EXISTE dans le modèle. Le canevas
     * doit le trancher, pas s'y casser — et le trancher sur une arête nullable, la
     * seule qu'une seconde passe puisse réparer.
     */
    public function testLeCycleDuModeleEstTrancheSurUneAreteNullable(): void
    {
        $toutes = $this->canevas()->toutes();

        $differes = [];
        foreach ($toutes as $code => $ressource) {
            foreach ($ressource->renvoisDifferes as $colonne) {
                $differes[] = $code . '.' . $colonne;
            }
        }

        self::assertNotEmpty($differes, 'Aucun renvoi différé : le cycle Piste/Avenant/Cotation aurait dû en produire un.');

        // Tout renvoi différé doit porter sur une colonne qui TOLÈRE le vide, sinon la
        // première passe échouerait sur un champ obligatoire.
        foreach ($toutes as $code => $ressource) {
            foreach ($ressource->renvoisDifferes as $codeColonne) {
                $colonne = $ressource->colonne($codeColonne);
                self::assertNotNull($colonne, sprintf('Renvoi différé « %s.%s » sans colonne correspondante.', $code, $codeColonne));
                self::assertFalse(
                    $colonne->obligatoire,
                    sprintf('Le renvoi « %s.%s » est différé alors qu\'il est obligatoire : la première passe échouerait.', $code, $codeColonne),
                );
            }
        }
    }

    /**
     * ⚠ SÉCURITÉ. Exporter la colonne de scoping laisserait déplacer un enregistrement
     * vers un autre cabinet depuis un tableur. Elle ne doit apparaître nulle part.
     */
    public function testLaColonneDeScopingNestJamaisExposee(): void
    {
        foreach ($this->canevas()->toutes() as $code => $ressource) {
            foreach ($ressource->colonnes as $colonne) {
                self::assertNotSame('entreprise', $colonne->code, sprintf('La ressource « %s » expose sa colonne de scoping.', $code));
                self::assertNotSame('id', $colonne->code, sprintf('La ressource « %s » expose son id brut (le _uid technique le porte).', $code));
            }
        }
    }

    /** Excel refuse un classeur dont deux onglets portent le même nom, ou un nom trop long. */
    public function testLesNomsDeFeuilleSontValidesEtUniques(): void
    {
        $vus = [];
        foreach ($this->canevas()->toutes() as $code => $ressource) {
            $feuille = $ressource->feuille;
            self::assertLessThanOrEqual(31, mb_strlen($feuille), sprintf('Onglet « %s » trop long (%s).', $feuille, $code));
            self::assertDoesNotMatchRegularExpression('/[\[\]:*?\/\\\\]/', $feuille, sprintf('Onglet « %s » : caractère interdit par Excel.', $feuille));
            self::assertArrayNotHasKey($feuille, $vus, sprintf('Onglet « %s » en double (%s et %s).', $feuille, $vus[$feuille] ?? '?', $code));
            $vus[$feuille] = $code;
        }
    }

    /**
     * Le filtrage par les droits n'est pas un confort d'affichage : sans lui, la
     * rubrique extrait tout le cabinet au profit d'un périmètre restreint.
     */
    public function testLesRessourcesSontFiltreesParLesDroitsDeLInvite(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $canevas = $container->get(CanevasDEchange::class);
        $resolver = $container->get(WorkspaceAccessResolver::class);

        $invite = $this->inviteSansAucunDroit();

        $lisibles = $canevas->ressourcesLisibles($invite);
        self::assertSame([], array_keys($lisibles), 'Un invité sans aucun droit ne doit voir aucune ressource échangeable.');

        $ecrivables = $canevas->ressourcesEcrivables($invite);
        self::assertSame([], array_keys($ecrivables), 'Un invité sans aucun droit ne doit pouvoir écrire aucune ressource.');

        // Contrôle croisé : ce que dit le canevas doit être ce que dit le resolver,
        // entité par entité — c'est l'invariant de parité écran / Ket / export.
        foreach ($canevas->toutes() as $code => $ressource) {
            self::assertFalse($resolver->canRead($invite, $code), sprintf('Le resolver accorde « %s » alors que le canevas le refuse.', $code));
        }
    }

    /**
     * Un invité neuf, sans propriété et sans le moindre rôle.
     *
     * Les rôles sont des COLLECTIONS sur Invite, pas des relations simples : un invité
     * fraîchement construit n'en porte donc aucune, ce qui est exactement le cas à
     * tester — allowedLevels() itère sur une collection vide et ne rend aucun niveau.
     */
    private function inviteSansAucunDroit(): \App\Entity\Invite
    {
        $invite = new \App\Entity\Invite();
        $invite->setProprietaire(false);

        return $invite;
    }

    /** Le canevas est mémoïsé : deux appels doivent rendre exactement le même objet. */
    public function testLeCanevasEstStable(): void
    {
        $canevas = $this->canevas();
        $premier = $canevas->toutes();
        $second = $canevas->toutes();

        self::assertSame(array_keys($premier), array_keys($second));
        foreach ($premier as $code => $ressource) {
            self::assertInstanceOf(RessourceDEchange::class, $ressource);
            self::assertSame($ressource->rang, $second[$code]->rang);
            self::assertSame($ressource->feuille, $second[$code]->feuille);
        }
    }
}
