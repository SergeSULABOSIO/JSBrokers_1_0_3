<?php

namespace App\Tests\Ai;

use App\Ai\Mutation\MutationAllowlist;
use App\Ai\Parite\CouvertureDesEcrans;
use App\Service\Workspace\WorkspaceAccessResolver;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * CE QUE L'ÉCRAN FAIT ET QUE KET NE FERA PAS — déclaré, motivé, et dit à Ket.
 *
 * ── POURQUOI CE FICHIER EXISTE ──────────────────────────────────────────────
 * `PariteEcranKetTest` inventorie les actions déclarées en `attribute_actions`.
 * Mais créer un invité et lui attribuer un rôle ne sont pas des actions de
 * canevas : ce sont les boutons GÉNÉRIQUES de la barre d'outils, pilotés par
 * `endpoint_submit_url`. Le manifeste ne les voyait donc pas, le test ne les
 * réclamait pas, et l'écart passait en silence.
 *
 * `KetPeripherePariteTest`, lui, exige que toute entité lisible soit mutable —
 * et il passe, parce qu'`Invite` est absent des DEUX ensembles à la fois. Un
 * invariant satisfait par exclusion symétrique ne constate aucune parité : il
 * décrit un trou.
 *
 * C'est par ce trou que l'incident du 2026-09-14 est passé. Un courtier demande
 * d'enregistrer un collaborateur ; l'entité qui porte les collaborateurs est
 * fermée à l'écriture, délibérément, pour qu'une conversation ne puisse pas
 * fabriquer un accès. Mais rien ne disait cette frontière à Ket, et le prompt lui
 * interdit par ailleurs, deux fois et avec emphase, de répondre qu'elle ne sait
 * pas créer. Sommée de créer et empêchée de refuser, elle a pris l'entité voisine
 * qu'elle POUVAIT écrire — un contact de client — et a réclamé un téléphone
 * obligatoire qui n'a aucun sens pour un collaborateur.
 *
 * La frontière doit donc être DÉCLARÉE (ce test), MOTIVÉE (ce test), et RÉCITÉE
 * par Ket avec le chemin d'écran (cf. la section dérivée dans AiContextBuilder).
 *
 * ⚠ CE MANIFESTE NE FILTRE RIEN À L'EXÉCUTION, comme le reste de
 * `CouvertureDesEcrans` : les verrous restent où ils sont — `MutationAllowlist`,
 * `WorkspaceAccessResolver::isRoleManagementEntity()`. Ici, on constate.
 */
class FrontiereDesEcransTest extends KernelTestCase
{
    private const MENU = __DIR__ . '/../../config/packages/menu.yaml';

    /** Même exigence que ECRAN_SEULEMENT : un motif trop court n'est pas un motif. */
    private const LONGUEUR_MINIMALE = 60;

    /**
     * TOUTE RUBRIQUE VISIBLE ET NON ÉCRIVABLE EST DÉCLARÉE.
     *
     * Le couple est exactement celui qui fait mal : l'utilisateur VOIT la rubrique
     * à l'écran, donc il peut légitimement la demander à Ket ; et Ket ne peut pas
     * l'écrire. Sans déclaration, ce cas produit une substitution silencieuse.
     */
    public function testChaqueRubriqueFermeeALEcritureEstDeclareeALaFrontiere(): void
    {
        self::assertTrue(
            \defined(CouvertureDesEcrans::class . '::ENTITES_ECRAN_SEULEMENT'),
            'CouvertureDesEcrans doit déclarer ENTITES_ECRAN_SEULEMENT : nom court d\'entité => motif écrit, '
            . 'disant pourquoi ce geste reste à l\'écran ET par quel chemin l\'utilisateur l\'y accomplit.',
        );

        $resolver = static::getContainer()->get(WorkspaceAccessResolver::class);
        $declarees = CouvertureDesEcrans::ENTITES_ECRAN_SEULEMENT;
        $manquantes = [];

        foreach ($this->entitesDesRubriques() as $shortName) {
            // Gouvernée par la gestion des invités : c'est la famille que Ket ne
            // touche pas, et la seule qui soit à la fois visible et fermée.
            if (!$resolver->isRoleManagementEntity($shortName)) {
                continue;
            }
            if (MutationAllowlist::autorise($shortName)) {
                continue;
            }
            if (!array_key_exists($shortName, $declarees)) {
                $manquantes[] = $shortName;
            }
        }

        self::assertSame([], $manquantes, sprintf(
            "Ces entités ont une rubrique à l'écran mais sont fermées à l'écriture de Ket, sans que la "
            . "frontière soit déclarée : %s.\n"
            . "Ajoutez-les à CouvertureDesEcrans::ENTITES_ECRAN_SEULEMENT avec le motif ET le chemin "
            . "d'écran. Sans cela, Ket ne sait pas qu'elle doit refuser — et elle écrit dans l'entité "
            . 'voisine plutôt que de nommer la frontière.',
            implode(', ', $manquantes),
        ));
    }

    /** Un motif doit être défendable : « non applicable » n'en est pas un. */
    public function testChaqueFrontierePorteUnMotifEtUnChemin(): void
    {
        self::assertNotEmpty(
            CouvertureDesEcrans::ENTITES_ECRAN_SEULEMENT,
            'La frontière ne peut pas être vide : au moins la gestion des invités en relève.',
        );

        foreach (CouvertureDesEcrans::ENTITES_ECRAN_SEULEMENT as $shortName => $motif) {
            self::assertGreaterThanOrEqual(self::LONGUEUR_MINIMALE, mb_strlen($motif), sprintf(
                'Le motif de « %s » est trop court pour dire quoi que ce soit. Écrivez pourquoi ce geste '
                . "reste à l'écran, et par quel chemin l'utilisateur l'y accomplit.",
                $shortName,
            ));
        }
    }

    /**
     * UNE FRONTIÈRE DÉCLARÉE DOIT ÊTRE UNE VRAIE FRONTIÈRE. Déclarer « écran
     * seulement » une entité que Ket sait par ailleurs écrire serait un mensonge
     * dans les deux sens : le manifeste dirait faux, et Ket refuserait ce qu'elle
     * peut faire.
     */
    public function testAucuneFrontiereNeDecritUneEntiteQueKetPeutEcrire(): void
    {
        $contradictions = [];
        foreach (array_keys(CouvertureDesEcrans::ENTITES_ECRAN_SEULEMENT) as $shortName) {
            if (MutationAllowlist::autorise($shortName)) {
                $contradictions[] = $shortName;
            }
        }

        self::assertSame([], $contradictions, sprintf(
            'Ces entités sont déclarées « écran seulement » alors que MutationAllowlist les ouvre à '
            . "l'écriture : %s. Retirez-les de la frontière, ou de l'allowlist.",
            implode(', ', $contradictions),
        ));
    }

    /**
     * Les entités portées par une rubrique du menu, en nom court.
     *
     * Lecture de la configuration, jamais une liste recopiée : une rubrique ajoutée
     * demain est couverte sans que personne ait à y penser.
     *
     * @return list<string>
     */
    private function entitesDesRubriques(): array
    {
        self::assertFileExists(self::MENU, 'menu.yaml introuvable : ce test ne prouverait plus rien.');

        $courts = [];
        // `array_walk_recursive` prend son premier argument PAR RÉFÉRENCE : lui passer
        // directement le retour de parseFile() est une erreur d'exécution, pas une
        // question de style.
        $menu = Yaml::parseFile(self::MENU);
        array_walk_recursive(
            $menu,
            static function (mixed $valeur, string|int $cle) use (&$courts): void {
                if ($cle !== 'entity_name' || !is_string($valeur)) {
                    return;
                }
                $position = strrpos($valeur, '\\');
                $courts[$position === false ? $valeur : substr($valeur, $position + 1)] = true;
            },
        );

        self::assertNotEmpty($courts, 'Aucune rubrique relevée dans menu.yaml : la lecture a cessé de '
            . 'fonctionner, et ce test passerait en ne prouvant rien.');

        return array_keys($courts);
    }
}
