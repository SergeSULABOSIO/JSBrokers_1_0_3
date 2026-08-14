<?php

namespace App\Tests\Ai;

use App\Ai\Mutation\DefautsContextuels;
use App\Ai\Mutation\MutationOperation;
use App\Ai\Mutation\MutationPlan;
use App\Entity\Piste;
use PHPUnit\Framework\TestCase;

/**
 * CE QUE LE DOSSIER DIT DÉJÀ — tests purs, aucun accès base.
 *
 * L'INCIDENT (2026-08-12). Sur un dossier d'assurance voyage complet, Ket a réclamé
 * cinq précisions de plus — nom de la piste, type d'avenant, description du risque,
 * nom et durée de la proposition — alors qu'elle venait elle-même d'en proposer la
 * plupart et que toutes étaient déductibles. L'utilisateur a eu le sentiment,
 * légitime, de répéter ce qu'il avait déjà donné.
 */
class DefautsContextuelsTest extends TestCase
{
    private function dossier(array $champsCotation = [], array $champsAvenant = []): MutationPlan
    {
        return new MutationPlan([
            new MutationOperation('create', 'Client', null, ['nom' => 'Mr. Jean de Dieu'], [], 'client'),
            new MutationOperation('create', 'Risque', null, ['nomComplet' => 'Assurance Voyage'], [], 'risque'),
            new MutationOperation('create', 'Piste', null, [
                'client' => '@client', 'risque' => '@risque', 'exercice' => 2026,
            ], [], 'piste'),
            new MutationOperation('create', 'Cotation', null, ['piste' => '@piste'] + $champsCotation, [
                'avenants' => [
                    new MutationOperation('create', '', null, [
                        'referencePolice' => 'SURDCVO00018389',
                        'startingAt' => '2026-09-14', 'endingAt' => '2026-10-06',
                    ] + $champsAvenant, [], 'avenant'),
                ],
            ], 'cotation'),
        ]);
    }

    private function champs(MutationPlan $plan, string $entite): array
    {
        foreach ($plan->operations as $op) {
            if ($op->entityShortName === $entite) {
                return $op->fields;
            }
        }

        return [];
    }

    public function testLeNomDuneP1steSeDeduitDuRisqueEtDuClient(): void
    {
        ['plan' => $plan] = (new DefautsContextuels())->appliquer($this->dossier());

        $this->assertSame('Assurance Voyage — Mr. Jean de Dieu', $this->champs($plan, 'Piste')['nom']);
        // La description du risque, à défaut de mieux, EST le risque.
        $this->assertSame('Assurance Voyage', $this->champs($plan, 'Piste')['descriptionDuRisque']);
    }

    /**
     * LA DURÉE SE LIT, ELLE NE SE SUPPOSE PAS. 14/09 → 06/10 fait 22 jours, soit un
     * mois entamé. Écrire « 12 » d'office — la police annuelle, cas le plus fréquent
     * — aurait été FAUX en silence sur ce contrat de voyage.
     *
     * La période n'est pas portée par la proposition mais par SON avenant : la
     * déduction doit descendre d'un niveau.
     */
    public function testLaDureeSeDeduitDeLaPeriodeDeLAvenant(): void
    {
        ['plan' => $plan] = (new DefautsContextuels())->appliquer($this->dossier());

        $this->assertSame(1, $this->champs($plan, 'Cotation')['duree']);
    }

    public function testLaDescriptionDeLAvenantReprendSaPolice(): void
    {
        ['plan' => $plan] = (new DefautsContextuels())->appliquer($this->dossier());

        $avenant = $this->champs($plan, 'Cotation');
        $this->assertSame('Assurance Voyage — Mr. Jean de Dieu', $avenant['nom'] ?? null);

        foreach ($plan->operations as $op) {
            foreach ($op->collections['avenants'] ?? [] as $enfant) {
                $this->assertSame('Police SURDCVO00018389', $enfant->fields['description']);

                return;
            }
        }
        $this->fail('L’avenant imbriqué n’a pas été trouvé.');
    }

    /** Ce que l'utilisateur a DICTÉ n'est jamais écrasé par une déduction. */
    public function testUneValeurDicteeNestJamaisRemplacee(): void
    {
        ['plan' => $plan] = (new DefautsContextuels())->appliquer(
            $this->dossier(['nom' => 'Ma proposition à moi', 'duree' => 12]),
        );

        $cotation = $this->champs($plan, 'Cotation');
        $this->assertSame('Ma proposition à moi', $cotation['nom']);
        $this->assertSame(12, $cotation['duree']);
    }

    /**
     * ON NE DEVINE JAMAIS. Sans source dans le plan, le champ reste MANQUANT et Ket
     * pose la question — c'est exactement ce qu'on veut : mieux vaut une question
     * qu'une donnée fausse dans le dossier d'un client.
     */
    public function testSansSourceAucuneDeductionNestInventee(): void
    {
        $plan = new MutationPlan([
            // Ni client ni risque : rien à déduire.
            new MutationOperation('create', 'Piste', null, ['exercice' => 2026], [], 'piste'),
            // Pas d'avenant, donc pas de période : la durée reste à demander.
            new MutationOperation('create', 'Cotation', null, [], [], 'cotation'),
        ]);

        ['plan' => $complete, 'defauts' => $defauts] = (new DefautsContextuels())->appliquer($plan);

        $this->assertArrayNotHasKey('nom', $this->champs($complete, 'Piste'));
        $this->assertArrayNotHasKey('duree', $this->champs($complete, 'Cotation'));
        // Le type d'avenant fait exception, et c'est voulu : sa source n'est pas un
        // AUTRE champ mais la STRUCTURE de l'opération — une piste qui ne dérive
        // d'aucune police ne peut être qu'une souscription. Les déductions qui
        // dépendent d'une source absente, elles, ne se déclenchent toujours pas.
        $this->assertSame(
            ['Piste — « typeAvenant » : Souscription (0) (déduit du dossier).'],
            $defauts,
        );
    }

    /** Un identifiant nu n'est pas un nom : on ne remplit pas un libellé avec « 4 ». */
    public function testUnIdentifiantNuNeSertJamaisDeNom(): void
    {
        $plan = new MutationPlan([
            new MutationOperation('create', 'Piste', null, ['client' => 12, 'risque' => '7'], [], 'piste'),
        ]);

        ['plan' => $complete] = (new DefautsContextuels())->appliquer($plan);

        $this->assertArrayNotHasKey('nom', $this->champs($complete, 'Piste'));
    }

    /**
     * TOUTE DÉDUCTION EST ANNONCÉE. Une valeur posée par le serveur est une écriture
     * que l'utilisateur n'a pas dictée : elle doit figurer dans la liste qu'il relira
     * avant de valider, sans quoi ce serait une écriture qu'on ne lui a pas montrée.
     */
    public function testChaqueDeductionEstAnnonceeAvecSonChamp(): void
    {
        ['defauts' => $defauts] = (new DefautsContextuels())->appliquer($this->dossier());

        $this->assertNotEmpty($defauts);
        foreach ($defauts as $ligne) {
            $this->assertStringContainsString('déduit du dossier', $ligne);
        }
        $joint = implode("\n", $defauts);
        foreach (['Piste — « nom »', 'Cotation — « duree »', 'Avenant — « description »'] as $attendu) {
            $this->assertStringContainsString($attendu, $joint);
        }
    }

    /**
     * UNE POLICE NEUVE EST UNE SOUSCRIPTION, et Ket n'a pas à poser la question.
     *
     * Le type d'avenant figure dans CHOIX_METIER_REQUIS : il était donc réclamé à
     * chaque création de piste, alors qu'une police qui ne dérive d'aucune autre ne
     * peut être qu'une souscription. Une question de plus sur un dossier que
     * l'utilisateur vient de fournir en entier.
     */
    public function testUnePisteQuiNeDeriveDeRienEstUneSouscription(): void
    {
        $plan = new MutationPlan([
            new MutationOperation('create', 'Piste', null, ['client' => 'Mme Marlette', 'risque' => 'Voyage']),
        ]);

        ['plan' => $complete, 'defauts' => $defauts] = (new DefautsContextuels())->appliquer($plan);

        $this->assertSame(Piste::AVENANT_SOUSCRIPTION, $complete->operations[0]->fields['typeAvenant']);
        // Annoncé EN LIBELLÉ : « typeAvenant : 0 » ne permettrait pas au courtier de
        // contester la déduction avant de valider, ce qui viderait l'annonce de son objet.
        $this->assertStringContainsString('Souscription', implode("\n", $defauts));
    }

    /**
     * L'AUTRE MOITIÉ, ET C'EST ELLE QUI PROTÈGE. Une piste DÉRIVÉE porte le lien vers
     * sa police de base : y écrire « souscription » serait exactement le mensonge
     * silencieux que CHOIX_METIER_REQUIS cherchait à empêcher — « une souscription là
     * où il fallait un renouvellement ».
     */
    public function testUnePisteDeriveeNeRecoitAucunTypeParDefaut(): void
    {
        $plan = new MutationPlan([
            new MutationOperation('create', 'Piste', null, [
                'client' => 'Mme Marlette',
                'risque' => 'Voyage',
                'avenantDeBase' => 42,
            ]),
        ]);

        ['plan' => $complete] = (new DefautsContextuels())->appliquer($plan);

        $this->assertArrayNotHasKey(
            'typeAvenant',
            $complete->operations[0]->fields,
            'Une piste dérivée doit garder son type dicté : le déduire écraserait un renouvellement.',
        );
    }

    /** Un type explicitement dicté n'est jamais remplacé par le défaut. */
    public function testUnTypeDicteLEmporteSurLeDefaut(): void
    {
        $plan = new MutationPlan([
            new MutationOperation('create', 'Piste', null, [
                'client' => 'Mme Marlette',
                'typeAvenant' => Piste::AVENANT_RENOUVELLEMENT,
            ]),
        ]);

        ['plan' => $complete] = (new DefautsContextuels())->appliquer($plan);

        $this->assertSame(Piste::AVENANT_RENOUVELLEMENT, $complete->operations[0]->fields['typeAvenant']);
    }

    /** Une édition n'est jamais complétée : on ne réécrit pas des champs non dictés. */
    public function testUneEditionNestJamaisCompletee(): void
    {
        $plan = new MutationPlan([
            new MutationOperation('edit', 'Piste', 5, ['client' => 'Mme Marlette', 'risque' => 'Incendie']),
        ]);

        ['plan' => $complete, 'defauts' => $defauts] = (new DefautsContextuels())->appliquer($plan);

        $this->assertArrayNotHasKey('nom', $complete->operations[0]->fields);
        $this->assertSame([], $defauts);
    }
}
