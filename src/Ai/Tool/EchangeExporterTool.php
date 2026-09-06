<?php

namespace App\Ai\Tool;

use App\Ai\Action\TypeAction;
use App\Ai\AiText;
use App\Ai\Scope\AiScope;
use App\Echange\Etat\EtatDuPortefeuille;
use App\Echange\Etat\ExerciceDesTranches;
use App\Echange\Etat\ValiditeDesTranches;
use App\Services\Search\CotationSouscriptionScope;
use App\Echange\Service\CompteurDOccurrences;
use App\Entity\EchangeOccurrence;
use App\Service\Workspace\WorkspaceAccessResolver;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Outil d'ACTION UI : lance la génération d'un export de données.
 *
 * L'outil ne produit AUCUN fichier. Il émet une directive vers la route d'export
 * existante, dont l'URL est construite CÔTÉ SERVEUR — jamais dictée par le modèle. La
 * route porte alors sa propre garde de droits, son propre contrôle de solde et sa
 * propre facturation : il n'y a donc ni double débit, ni seconde implémentation à tenir
 * en phase avec la première. Même patron que ExporterEtatTool.
 *
 * PAS DE MARQUEUR DE TROUSSE, et c'est délibéré : « exporte-moi ça » suit presque
 * toujours une lecture, et l'outil doit être déclaré dans ce tour-là. Il ne produit pas
 * non plus de plan de mutation — il n'écrit rien.
 *
 * ⚠ CE QUI SORT EST UN ÉTAT DE LECTURE, PAS UN FICHIER D'ÉCHANGE. Une ligne par tranche,
 * des colonnes qui sont des RÉSULTATS — soldes, encaissements, exigibilités. Il ne se
 * redépose pas, et l'outil doit le dire : promettre une réimportation coûterait à
 * l'utilisateur une demi-journée avant qu'il ne le découvre seul. Le gabarit vierge de
 * l'onglet Importer est l'autre geste, celui qui prépare une saisie.
 *
 * LES COLONNES SONT RÉSOLUES, PAS SUPPOSÉES. Le modèle exprime la demande en langage
 * naturel — « sans les rétros », « juste les primes » — et les codes sont retrouvés dans
 * le catalogue, par code ou par libellé. Le contenu, lui, reste refiltré par le périmètre
 * du cabinet à la production : choisir une colonne n'ouvre aucun droit.
 */
final class EchangeExporterTool implements AiToolInterface
{
    private const ENTITE = 'Echange';

    private const LIBELLE = 'Importation / Exportation';

    public function __construct(
        // Le catalogue de l'état : ce que Ket peut proposer de retenir.
        private readonly EtatDuPortefeuille $etat,
        private readonly CompteurDOccurrences $compteur,
        private readonly WorkspaceAccessResolver $accessResolver,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function name(): string
    {
        return 'echange_exporter';
    }

    public function description(): string
    {
        return 'Lance la génération de l\'ÉTAT DU PORTEFEUILLE : un classeur Excel d\'une ligne '
            . 'par tranche, portant la police, l\'assuré, l\'assureur, la prime et son solde, la '
            . 'commission, ses taxes, la réserve du courtier et les rétrocommissions. '
            . '⚠ C\'EST UN ÉTAT DE LECTURE : il ne se redépose PAS, ses colonnes étant des '
            . 'résultats (soldes, encaissements) et non des champs. Pour préparer des données à '
            . 'importer, c\'est le GABARIT VIERGE qu\'il faut, dans l\'onglet Importer. '
            . 'Le paramètre « colonnes » restreint le fichier à certaines colonnes (codes obtenus '
            . 'via echange_consulter) ; laissé vide, l\'état les porte toutes. Le paramètre '
            . '« validite » choisit entre les tranches des POLICES, celles des PROJETS et les '
            . 'CADUQUES ; « exercice » restreint à une année de souscription. Le coût est annoncé '
            . 'AVANT le déclenchement, et le téléchargement reste un geste de l\'utilisateur.';
    }

    public function aiguillage(): string
    {
        return '« exporte mes données », « je veux un fichier Excel de mon portefeuille », « sors-moi '
            . 'un classeur pour travailler hors ligne ».';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'colonnes' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Codes des colonnes à retenir dans l\'état (obtenus via '
                        . 'echange_consulter). Vide ou absent = toutes les colonnes. La colonne '
                        . 'd\'identité de la tranche est toujours présente.',
                ],
                'validite' => [
                    'type' => 'string',
                    'enum' => ['toutes', 'souscrites', 'en_attente', 'caduques'],
                    'description' => "Quelles tranches retenir : « souscrites » = celles des "
                        . "POLICES (le contrat existe) ; « en_attente » = celles des PROJETS non "
                        . "encore validés par le client ; « caduques » = celles des propositions "
                        . "perdues au profit d'une concurrente ; « toutes » (défaut) = tout "
                        . "confondu. ⚠ Un état des seuls projets n'est PAS le portefeuille réel.",
                ],
                'exercice' => [
                    'type' => 'string',
                    'description' => "Exercice retenu, au format d'une année (« 2026 ») : "
                        . "celui de la DATE D'EFFET des polices, donc de la souscription. "
                        . "« tous » (défaut) ne filtre rien. Les exercices réellement présents "
                        . "chez ce cabinet sont donnés par echange_consulter.",
                ],
            ],
        ];
    }

    public function match(string $question, AiScope $scope): ?array
    {
        $n = AiText::normalize($question);
        if (!preg_match('/\b(exporte[rz]?|telecharge[rz]?|sors|sortir)\b/', $n)) {
            return null;
        }
        if (!preg_match('/\b(donnees|portefeuille|cabinet|tout|excel|classeur|jsbx)\b/', $n)) {
            return null;
        }
        // Le classeur COMPTABLE a son propre outil : ne pas le lui prendre.
        if (preg_match('/\b(comptab\w*|balance|grand.?livre|bilan|journal|etats? financiers?)\b/', $n)) {
            return null;
        }

        return ['colonnes' => [], 'validite' => ValiditeDesTranches::normaliser(CotationSouscriptionScope::detecterDepuisTexte($n))];
    }

    public function execute(array $args, AiScope $scope): AiToolResult
    {
        // FAIL-CLOSED : même garde que la route d'export elle-même.
        if (!$this->accessResolver->canRead($scope->invite, self::ENTITE)) {
            return AiToolResult::horsPerimetre(self::LIBELLE);
        }

        // ⚠ LE PÉRIMÈTRE DE L'ÉTAT N'EST PLUS UNE AFFAIRE DE FAMILLES DE DONNÉES.
        // Le fichier a une maille FIXE — une ligne par tranche — et ce qu'on y choisit,
        // ce sont les COLONNES. Le droit de lecture sur la rubrique suffit donc, et le
        // contenu est refiltré par le périmètre du cabinet à la production.
        $catalogue = $this->etat->colonnes($scope->entreprise);

        $retenues = $this->resoudreColonnes($args['colonnes'] ?? [], $catalogue);
        if ($retenues === null) {
            return AiToolResult::introuvable(
                implode(', ', array_map('strval', (array) ($args['colonnes'] ?? []))),
                'Ces colonnes ne figurent pas dans l\'état. Appelle echange_consulter pour lui '
                . 'présenter la liste exacte des colonnes disponibles.',
            );
        }

        // LE COÛT VIENT DU COMPTEUR, jamais d'un calcul refait ici : c'est ce qui garantit
        // que le chiffre annoncé dans le chat est celui que la route débitera.
        $etat = $this->compteur->etat($scope->entreprise);
        if (!$etat['exportFinancable']) {
            return AiToolResult::ok([
                'pret' => false,
                'motif' => 'solde_insuffisant',
                'cout' => $etat['coutExport'],
                'solde' => $etat['soldeDisponible'],
                'note' => sprintf(
                    'Cette exportation coûte %d tokens et le solde du cabinet est de %d. Annonce-le '
                    . 'et propose de recharger : ne déclenche pas l\'export.',
                    $etat['coutExport'],
                    $etat['soldeDisponible'],
                ),
            ]);
        }

        // ⚠ LE VOCABULAIRE VIENT DE LA SOURCE UNIQUE, jamais d'une liste recopiée ici :
        // le chip de l'écran, celui de la rubrique Propositions et ce paramètre désignent
        // les mêmes ensembles, sous les mêmes noms.
        $validite = ValiditeDesTranches::normaliser(
            is_string($args['validite'] ?? null) ? $args['validite'] : null,
        );

        // ⚠ L'EXERCICE EST VÉRIFIÉ CONTRE LES DONNÉES, jamais accepté sur parole : une
        // année qu'aucune police ne porte rendrait un fichier vide, et le modèle
        // conclurait à un portefeuille inexistant plutôt qu'à une année mal comprise.
        $exercice = ExerciceDesTranches::normaliser(
            is_string($args['exercice'] ?? null) ? $args['exercice'] : null,
            $this->etat->exercices($scope->entreprise),
        );

        $parametres = ['idEntreprise' => $scope->entreprise->getId()];
        if ($retenues !== []) {
            $parametres['colonnes'] = implode(',', $retenues);
        }
        if ($validite !== ValiditeDesTranches::TOUTES) {
            $parametres['validite'] = $validite;
        }
        if ($exercice !== ExerciceDesTranches::TOUS) {
            $parametres['exercice'] = $exercice;
        }

        return AiToolResult::ok(
            [
                'pret'      => true,
                'maille'    => 'Une ligne par TRANCHE de prime.',
                'validite'  => ValiditeDesTranches::libelle($validite),
                'validite_sens' => ValiditeDesTranches::explication($validite),
                'exercice'  => ExerciceDesTranches::libelle($exercice),
                'exercice_sens' => ExerciceDesTranches::explication($exercice),
                'colonnes'  => array_values(array_map(
                    static fn ($colonne) => $colonne->libelle,
                    $retenues === [] ? $catalogue : array_intersect_key($catalogue, array_flip($retenues)),
                )),
                'nb_colonnes' => $retenues === [] ? \count($catalogue) : \count($retenues),
                'cout'      => $etat['coutExport'],
                'gratuites_restantes' => $etat['gratuitesRestantes'],
                // ⚠ NE JAMAIS PROMETTRE UNE RÉIMPORTATION. Ce fichier porte des
                // RÉSULTATS — soldes, encaissements, exigibilités — et non des champs :
                // il ne peut pas revenir dans la base. Le laisser croire coûterait à
                // l'utilisateur une demi-journée avant qu'il ne le découvre seul.
                'note' => sprintf(
                    'Le téléchargement s\'ouvre chez l\'utilisateur. %s C\'est un ÉTAT DE LECTURE : '
                    . 'dis-lui bien qu\'il ne se redépose pas, et que pour importer des données '
                    . 'c\'est le gabarit vierge de l\'onglet Importer qu\'il lui faut.',
                    $etat['coutExport'] > 0
                        ? sprintf('Cette opération lui est facturée %d tokens.', $etat['coutExport'])
                        : sprintf('Elle est gratuite (%d opérations offertes restantes).', $etat['gratuitesRestantes']),
                ),
            ],
            uiAction: [
                'type' => TypeAction::OUVRIR_URL->value,
                'url'  => $this->urlGenerator->generate('admin.echange.export', $parametres),
            ],
        );
    }

    /**
     * Colonnes demandées, ramenées au catalogue.
     *
     * Rend [] pour « toutes », ou null si RIEN de ce qui est demandé n'existe — un
     * silence vaudrait ici un état complet que personne n'a réclamé.
     *
     * @param array<string, \App\Echange\Etat\ColonneEtat> $catalogue
     *
     * @return string[]|null
     */
    private function resoudreColonnes(mixed $demandes, array $catalogue): ?array
    {
        if (!is_array($demandes) || $demandes === []) {
            return [];
        }

        $codes = [];
        foreach ($demandes as $demande) {
            if (!is_string($demande) || trim($demande) === '') {
                continue;
            }
            $demande = trim($demande);
            // Par le code, ou par le LIBELLÉ : l'utilisateur dit « la réserve », pas
            // « reserve ». Comparaison insensible à la casse et aux accents.
            foreach ($catalogue as $code => $colonne) {
                if ($code === $demande || AiText::normalize($colonne->libelle) === AiText::normalize($demande)) {
                    $codes[$code] = true;
                    break;
                }
            }
        }

        return $codes === [] ? null : array_keys($codes);
    }

}
