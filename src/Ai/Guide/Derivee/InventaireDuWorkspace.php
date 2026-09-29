<?php

namespace App\Ai\Guide\Derivee;

use App\Ai\Guide\FicheDerivee;
use App\Ai\Mutation\MutationAllowlist;
use App\Ai\Parite\CouvertureDesEcrans;
use App\Ai\Parite\LibellesDesActionsDEcran;
use App\Ai\Reglage\CatalogueDesReglages;
use App\Service\Workspace\WorkspaceAccessResolver;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * TOUT CE QUI SE FAIT DANS L'ESPACE DE TRAVAIL — y compris ce que Ket ne fait pas.
 *
 * ── POURQUOI CETTE FICHE EXISTE ─────────────────────────────────────────────
 * Le prompt interdit à Ket, deux fois et avec emphase, de répondre qu'elle ne peut
 * pas créer. C'est une bonne règle : sans elle, elle s'excusait de ne pas savoir
 * enregistrer alors qu'elle le sait. Mais une interdiction sans exception nommée ne
 * rend pas le modèle honnête — elle le rend inventif. Le 2026-09-14, sommée
 * d'enregistrer un collaborateur, Ket a créé un CONTACT DE CLIENT et réclamé un
 * téléphone obligatoire qui n'a aucun sens pour un collaborateur. Le 2026-09-21,
 * sommée de facturer une commission, elle a nié l'existence d'une échéance que
 * l'écran affichait.
 *
 * La FRONTIÈRE du prompt couvre le premier cas : les rubriques que Ket n'écrit
 * jamais y sont nommées, avec leur motif et leur chemin d'écran. Mais elle ne
 * répond pas à « est-ce que tu peux… ? » sur les quarante autres rubriques et les
 * trente-quatre boutons de l'application. C'est ce que cette fiche fait.
 *
 * ── POURQUOI ELLE EST DÉRIVÉE, ET SERVIE À LA DEMANDE ───────────────────────
 * Écrite à la main, elle serait fausse au commit suivant — c'est très exactement
 * ce qui est arrivé à `bordereau.md`, dont une ligne renvoyait encore le courtier à
 * l'écran pour un geste que Ket sait désormais faire. Elle se calcule donc à partir
 * des sources qui font déjà autorité, et des tests interdisent qu'une rubrique, un
 * bouton ou un outil lui échappe.
 *
 * Et elle ne part PAS dans le prompt : seule sa ligne de catalogue y figure. Le
 * texte — environ six kilo-octets — n'est chargé que si le modèle l'ouvre.
 *
 * ── CE QU'ELLE NE FAIT PAS : LE PÉRIMÈTRE ───────────────────────────────────
 * Elle décrit la PLATEFORME, pas les droits de celui qui demande. C'est délibéré.
 * « Je ne sais pas faire ça » renvoie à un écran ; « vous n'avez pas le droit »
 * renvoie au propriétaire de l'espace : deux phrases, deux suites différentes. Les
 * fondre en une seule ferait dire « je ne sais pas » là où il fallait dire
 * « demandez le droit ». Le périmètre est déjà dit deux fois ailleurs (la section
 * du prompt, et les gardes fail-closed de chaque outil) ; un troisième énoncé ne
 * serait qu'une troisième chance de diverger.
 *
 * C'est aussi ce qui permet à `ConsulterGuideTool` de garder sa garantie de classe :
 * « pas de garde de périmètre, les fiches ne portent AUCUNE donnée d'entreprise ».
 * Ici, rien que des noms de rubriques, de boutons et d'outils.
 */
final class InventaireDuWorkspace implements FicheDerivee
{
    public const SLUG = 'inventaire-du-workspace';

    /** Mémoïsation d'instance : le worker vit, c'est donc un relevé par processus. */
    private ?string $rendu = null;

    /**
     * @param array<string, mixed> $menuData la structure du menu, déjà paramètre de
     *        conteneur — la relire en YAML serait une seconde source
     */
    public function __construct(
        private readonly WorkspaceAccessResolver $accesResolver,
        private readonly CatalogueDesReglages $catalogueDesReglages,
        private readonly LibellesDesActionsDEcran $libelles,
        #[Autowire('%app.menu_data%')] private readonly array $menuData,
    ) {
    }

    public function slug(): string
    {
        return self::SLUG;
    }

    public function titre(): string
    {
        return 'Inventaire de l’espace de travail';
    }

    public function description(): string
    {
        // LITTÉRAL, ET COURT : cette ligne est payée à chaque tour (cf. FicheDerivee).
        return 'Toutes les rubriques et tous les boutons de l’espace de travail, avec en regard ce '
            . 'que je sais refaire en conversation et ce qui reste un geste d’écran, motif compris. '
            . 'À ouvrir pour répondre à « est-ce que tu peux… ? ».';
    }

    public function contenu(): string
    {
        return $this->rendu ??= $this->construire();
    }

    private function construire(): string
    {
        return implode("\n\n", array_filter([
            $this->entete(),
            $this->sectionRubriques(),
            $this->sectionBoutons(),
            $this->sectionSansEcran(),
            $this->modeDEmploi(),
        ]));
    }

    private function entete(): string
    {
        return <<<'TXT'
        # Inventaire de l’espace de travail

        > Tout ce qui se fait dans l’espace de travail, rubrique par rubrique et bouton par
        > bouton, avec en regard ce que je sais refaire en conversation et ce qui reste un
        > geste d’écran — motif compris.

        ⚠ CET INVENTAIRE DÉCRIT LA PLATEFORME, PAS LES DROITS DE L’UTILISATEUR. Ce qu’il voit
        réellement dépend de son périmètre. Si un outil me renvoie un refus de périmètre, c’est
        ce refus qui fait foi, jamais cette page — et la bonne réponse est alors « vous n’avez
        pas ce droit », pas « je ne sais pas le faire ».
        TXT;
    }

    /**
     * LES RUBRIQUES : ce que je lis et j'écris, ce que je lis seulement, et ce qui
     * reste un geste d'écran. Ce qui est ordinaire est agrégé par module ; ce qui est
     * exceptionnel a sa ligne et son motif.
     */
    private function sectionRubriques(): string
    {
        $libelles = $this->accesResolver->libellesEntites();
        $frontiere = CouvertureDesEcrans::ENTITES_ECRAN_SEULEMENT;

        $ecrivables = [];
        $lisibles = [];
        $fermees = [];

        foreach ($this->rubriquesDuMenu() as $module => $rubriques) {
            foreach ($rubriques as $nomAffiche => $court) {
                if (isset($frontiere[$court])) {
                    // Recopié MOT POUR MOT depuis le manifeste : paraphraser ici ferait
                    // deux textes à tenir d'accord, et c'est le mode de défaillance que
                    // FrontiereDiteAKetTest a été écrit pour interdire.
                    $fermees[$court] = sprintf('- %s — %s', $this->nomLisible($court, $libelles, $nomAffiche), $frontiere[$court]);
                    continue;
                }
                if (MutationAllowlist::autorise($court)) {
                    $ecrivables[$module][] = $nomAffiche;
                    continue;
                }
                $lisibles[] = sprintf('- %s — %s', $nomAffiche, $this->pourquoiPasEcrivable($court));
            }
        }

        // ⚠ LA FRONTIÈRE NE TIENT PAS TOUTE DANS LE MENU. Les cinq jeux de droits
        // (RolesEn*) n'ont pas de rubrique à eux : ils vivent sur la fiche d'un invité,
        // en édition seulement. Les omettre ici laisserait Ket sans réponse à « peux-tu
        // lui donner les droits Finances ? » — précisément la question qui l'avait fait
        // écrire dans une entité voisine le 2026-09-14. On récite donc la frontière
        // ENTIÈRE, que ses entrées soient au menu ou non.
        foreach ($frontiere as $court => $motif) {
            $fermees[$court] ??= sprintf('- %s — %s', $this->nomLisible($court, $libelles), $motif);
        }

        $lignes = ['## 1. Les rubriques de l’espace de travail', '', '### Je les lis ET je les enregistre (créer, corriger, supprimer)', ''];
        foreach ($ecrivables as $module => $noms) {
            $lignes[] = sprintf('- %s — %s', $module, implode(', ', $noms));
        }

        if ($lisibles !== []) {
            $lignes[] = '';
            $lignes[] = '### Je les lis, mais je n’y enregistre rien';
            $lignes[] = '';
            $lignes = array_merge($lignes, $lisibles);
        }

        if ($fermees !== []) {
            $lignes[] = '';
            $lignes[] = '### Ni lecture ni écriture de ma part : ce sont des gestes d’écran';
            $lignes[] = '';
            $lignes = array_merge($lignes, array_values($fermees));
        }

        return implode("\n", $lignes);
    }

    /**
     * POURQUOI UNE RUBRIQUE VISIBLE N'EST PAS ÉCRIVABLE — et ce n'est pas la même
     * chose que de ne pas savoir s'en servir.
     *
     * Ces quatre rubriques n'ont aucune classe Doctrine derrière elles : il n'y a
     * littéralement rien à y créer. Mais dire seulement cela induirait en erreur —
     * « Importation / Exportation » n'a pas d'enregistrement à écrire, et pourtant
     * c'est par là que Ket reprend un classeur entier. Chaque motif doit donc dire
     * ce qu'elle SAIT faire de la rubrique, pas seulement ce qu'elle n'y écrit pas.
     *
     * Écrits à la main parce qu'aucune métadonnée ne les porte — la même raison qui
     * fait écrire les motifs de `CouvertureDesEcrans`. Un test exige qu'ils soient là.
     */
    private const RUBRIQUES_SANS_ENREGISTREMENT = [
        'DocumentComptable' => 'états comptables calculés à l’instant de la demande : il n’y a '
            . 'aucun enregistrement à créer. Je les lis et je les restitue (document_comptable).',
        'ProductionIntermediaire' => 'rapport calculé par le moteur de partage : rien à y écrire. '
            . 'Je rends les mêmes chiffres dans le fil (retrocommissions, effort_commercial_agent).',
        'Echange' => 'rubrique d’échange de données : elle n’a pas de fiche à créer, mais je sais '
            . 'm’en servir — contrôler puis reprendre un classeur joint (importer_classeur), et '
            . 'produire celui du portefeuille (exporter_portefeuille).',
        'AssistantIa' => 'c’est moi. La rubrique porte la conversation, elle n’a pas '
            . 'd’enregistrement à créer.',
    ];

    private function pourquoiPasEcrivable(string $court): string
    {
        return self::RUBRIQUES_SANS_ENREGISTREMENT[$court]
            ?? 'rubrique de consultation : elle n’a pas d’enregistrement à créer.';
    }

    /**
     * Le nom lisible d'une rubrique fermée. Les cinq jeux de droits n'ont pas de
     * rubrique à eux, donc pas de libellé dans la carte d'accès : leur nom technique
     * (« RolesEnFinance ») ne dit rien à un courtier, alors que « Droits d'accès —
     * module Finances » nomme ce qu'il voit sur la fiche de son collaborateur.
     *
     * @param array<string, string> $libelles la carte d'accès, quand elle sait
     */
    private function nomLisible(string $court, array $libelles, ?string $repli = null): string
    {
        if (isset($libelles[$court])) {
            return $libelles[$court];
        }
        if (str_starts_with($court, 'RolesEn')) {
            return sprintf('Droits d’accès (module %s)', substr($court, strlen('RolesEn')));
        }

        return $repli ?? $court;
    }

    /**
     * LES BOUTONS. Chacun est nommé par SON libellé d'écran — celui que le courtier
     * lit — suivi de son nom technique, qui distingue deux boutons voisins.
     */
    private function sectionBoutons(): string
    {
        $lignes = ['## 2. Les boutons des barres d’outils et des menus contextuels', '', '### Ce que je sais refaire en conversation', ''];

        // GROUPÉ PAR OUTIL, et pas bouton par bouton : six actions de portefeuille
        // passent toutes par le plan d'écriture générique, trois actions de note par
        // le même export. Répéter le nom de l'outil six fois coûterait des jetons pour
        // dire une seule chose — et c'est la chose que le modèle doit retenir.
        $parOutil = [];
        foreach (CouvertureDesEcrans::COUVERTES as $evenement => $outil) {
            $parOutil[$outil][] = sprintf('« %s » (%s)', $this->libelles->pour($evenement), $evenement);
        }
        foreach ($parOutil as $outil => $boutons) {
            $lignes[] = sprintf('- %s ← %s', $outil, implode(', ', $boutons));
        }

        $lignes[] = '';
        $lignes[] = '### Ce qui n’existe QU’À L’ÉCRAN — dis-le, donne le chemin, arrête-toi';
        $lignes[] = '';
        foreach (CouvertureDesEcrans::ECRAN_SEULEMENT as $evenement => $motif) {
            $lignes[] = sprintf('- « %s » (%s) — %s', $this->libelles->pour($evenement), $evenement, $motif);
        }

        return implode("\n", $lignes);
    }

    /**
     * MES AUTRES OUTILS — ceux qu'aucun bouton déclaré ne double.
     *
     * Dérivé du catalogue de la console, par différence avec les actions couvertes.
     *
     * ⚠ « AUCUN BOUTON » NE VEUT PAS DIRE « RIEN À L'ÉCRAN », et la nuance compte :
     * ouvrir une rubrique ou visualiser une fiche se font aussi au menu et à la barre
     * d'outils — simplement, ce ne sont pas des `attribute_actions` déclarées, donc
     * elles n'ont pas d'entrée au manifeste. Écrire « aucun écran ne le fait » serait
     * faux, et Ket le répéterait à un courtier qui a le bouton sous les yeux.
     */
    private function sectionSansEcran(): string
    {
        $avecEcran = array_flip(array_values(CouvertureDesEcrans::COUVERTES));

        $lignes = [
            '## 3. Mes autres outils — ceux qu’aucun bouton déclaré ne double',
            '',
            '(Certains recoupent un geste ordinaire de l’écran — le menu, la barre d’outils —',
            'sans qu’un bouton nommé leur corresponde.)',
            '',
        ];
        foreach ($this->catalogueDesReglages->outils() as $outil) {
            if (isset($avecEcran[$outil['nom']])) {
                continue;
            }
            $lignes[] = sprintf('- %s (%s) — %s', $outil['libelle'], $outil['nom'], $outil['resume']);
        }

        return implode("\n", $lignes);
    }

    private function modeDEmploi(): string
    {
        return <<<'TXT'
        ## 4. Comment me servir de cet inventaire

        Quand l’utilisateur demande un geste de la section « qu’à l’écran » : nomme le bouton
        avec SON libellé, donne le chemin, et arrête-toi. N’écris JAMAIS dans une rubrique
        voisine à la place, et n’invente aucun champ obligatoire pour justifier la substitution.

        Quand il demande « peux-tu… ? » sur une rubrique de la section 1, la réponse est oui.
        La seule limite qu’il rencontrera, s’il en rencontre une, sera celle de SES droits — et
        cela se dit autrement : « ce droit ne vous est pas ouvert », pas « je ne sais pas faire ».

        Et si un geste ne figure nulle part ici, dis-le franchement plutôt que de proposer
        autre chose : cette page est l’inventaire complet, pas un échantillon.
        TXT;
    }

    /**
     * Les rubriques du menu, groupées par module, en {libellé affiché => nom court}.
     *
     * On lit le PARAMÈTRE de conteneur, pas le YAML : c'est la même source que le
     * menu réellement affiché. Une rubrique ajoutée demain entre ici sans qu'on y
     * touche — et le test échouera si elle n'apparaît pas dans le texte.
     *
     * @return array<string, array<string, string>>
     */
    private function rubriquesDuMenu(): array
    {
        $parModule = [];
        $groupes = $this->menuData['colonne_1']['groupes'] ?? [];

        foreach ($groupes as $module => $definition) {
            foreach ($definition['rubriques'] ?? [] as $nomAffiche => $rubrique) {
                $entite = $rubrique['entity_name'] ?? null;
                if (!is_string($entite) || $entite === '') {
                    continue;
                }
                $position = strrpos($entite, '\\');
                $court = $position === false ? $entite : substr($entite, $position + 1);
                $parModule[(string) $module][(string) $nomAffiche] = $court;
            }
        }

        return $parModule;
    }
}
