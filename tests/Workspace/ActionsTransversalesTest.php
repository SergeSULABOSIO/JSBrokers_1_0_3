<?php

namespace App\Tests\Workspace;

use App\Entity\DemandeConge;
use App\Services\Canvas\Provider\Form\DemandeCongeFormCanvasProvider;
use PHPUnit\Framework\TestCase;

/**
 * UNE ACTION TRANSVERSE NE DÉPEND D'AUCUNE LIGNE.
 *
 * ── CE QUI A MOTIVÉ CE CONTRAT ──────────────────────────────────────────────────────
 * Le calendrier d'équipe et la grille des compteurs étaient déclarés `multi`, dans la
 * croyance que ce drapeau les rendait accessibles sans sélection. Il n'en dit rien : il
 * signifie « dès UNE ligne, une ou plusieurs ». Les deux écrans restaient donc enfermés
 * derrière une sélection — il fallait cocher une demande au hasard pour ouvrir un
 * calendrier qui ne parle pas d'elle — et leur voisinage avec « Soumettre la demande »
 * dans la même famille laissait croire qu'ils appartenaient au même geste.
 *
 * ── LA RÈGLE QUI COMPTE VRAIMENT ────────────────────────────────────────────────────
 * Une action `sans_selection` ne doit JAMAIS porter `%id%` dans son URL : il n'y a
 * personne pour le remplacer, et l'appel partirait vers une adresse trouée. Ce contrat
 * balaie tous les canevas, pas seulement celui des congés — la règle vaut pour la
 * prochaine rubrique qui déclarera un écran transverse.
 */
class ActionsTransversalesTest extends TestCase
{
    private const PROVIDERS = __DIR__ . '/../../src/Services/Canvas/Provider/Form';

    /**
     * LA RÈGLE GÉNÉRALE : pas d'identifiant dans l'URL d'une action qui n'a pas de ligne.
     */
    public function testAucuneActionSansSelectionNeReclameUnIdentifiant(): void
    {
        $fautives = [];

        foreach (glob(self::PROVIDERS . '/*.php') ?: [] as $chemin) {
            $source = (string) file_get_contents($chemin);

            // Chaque entrée de tableau d'action, lue entre ses accolades : on ne veut pas
            // qu'un `%id%` d'une action voisine soit imputé à celle-ci.
            preg_match_all('/\[\s*(?:\/\/[^\n]*\n\s*)*"label"\s*=>.*?\],/s', $source, $blocs);

            foreach ($blocs[0] ?? [] as $bloc) {
                if (!str_contains($bloc, 'sans_selection')) {
                    continue;
                }
                if (str_contains($bloc, '%id%')) {
                    $fautives[] = basename($chemin) . ' : ' . $this->libelleDe($bloc);
                }
            }
        }

        self::assertSame(
            [],
            $fautives,
            "Ces actions s'affichent sans sélection mais réclament un identifiant dans leur URL.\n"
            . "Il n'y a personne pour le remplacer : l'appel partirait vers une adresse trouée.\n  - "
            . implode("\n  - ", $fautives),
        );
    }

    /**
     * Les deux écrans transverses des congés s'ouvrent sans sélection, et hors du circuit
     * de validation — qui, lui, porte sur UNE demande.
     */
    public function testLesEcransDEnsembleDesCongesSOuvrentSansSelection(): void
    {
        $actions = $this->actionsDesConges();

        foreach (["Compteurs de congés", "Calendrier de l'équipe"] as $libelle) {
            $action = $actions[$libelle] ?? null;

            self::assertNotNull($action, sprintf('Action « %s » introuvable.', $libelle));
            self::assertTrue(
                $action['sans_selection'] ?? false,
                sprintf('« %s » regarde tout le cabinet : elle ne doit pas attendre qu\'une ligne soit cochée.', $libelle),
            );
            self::assertNotSame(
                'Circuit de validation',
                $action['groupe'] ?? null,
                sprintf('« %s » ne porte sur aucune demande : la ranger avec les gestes de décision le laisse croire.', $libelle),
            );
            self::assertStringNotContainsString('%id%', (string) ($action['url'] ?? ''));
        }
    }

    /**
     * LES GESTES DE DÉCISION, EUX, DÉPENDENT D'UNE DEMANDE PRÉCISE — et de son état.
     *
     * « Soumettre » n'a de sens que sur un brouillon ; « Approuver » et « Refuser » sur
     * une demande en attente ; « Annuler » sur ce qui peut encore l'être. Chacun porte
     * donc sa condition, et aucun ne s'affiche sans sélection.
     */
    public function testChaqueGesteDeDecisionResteLieAUneDemandeEtAUnEtat(): void
    {
        $attendu = [
            'Soumettre la demande' => 'peutEtreSoumise',
            'Approuver'            => 'peutEtreDecidee',
            'Refuser'              => 'peutEtreDecidee',
            'Annuler le congé'     => 'peutEtreAnnulee',
        ];

        $actions = $this->actionsDesConges();

        foreach ($attendu as $libelle => $drapeau) {
            $action = $actions[$libelle] ?? null;

            self::assertNotNull($action, sprintf('Action « %s » introuvable.', $libelle));
            self::assertArrayNotHasKey(
                'sans_selection',
                $action,
                sprintf('« %s » porte sur UNE demande : elle ne peut pas s\'afficher sans sélection.', $libelle),
            );
            self::assertSame(
                ['field' => $drapeau, 'value' => true],
                $action['condition'] ?? null,
                sprintf(
                    '« %s » doit être conditionnée par %s, sinon elle est proposée sur des demandes '
                    . 'où le geste n\'a aucun sens.',
                    $libelle,
                    $drapeau,
                ),
            );
            self::assertStringContainsString('%id%', (string) ($action['url'] ?? ''));
        }
    }

    /** @return array<string, array<string, mixed>> libellé => action */
    private function actionsDesConges(): array
    {
        $provider = new DemandeCongeFormCanvasProvider();
        $canvas = $provider->getCanvas(new DemandeConge(), null);

        $parLibelle = [];
        foreach ($canvas['parametres']['attribute_actions'] ?? [] as $action) {
            $parLibelle[$action['label']] = $action;
        }

        return $parLibelle;
    }

    private function libelleDe(string $bloc): string
    {
        preg_match('/"label"\s*=>\s*"([^"]+)"/', $bloc, $m);

        return $m[1] ?? '(sans libellé)';
    }
}
