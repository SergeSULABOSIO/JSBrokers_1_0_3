<?php

namespace App\Ai\Tool;

use App\Ai\Action\TypeAction;
use App\Ai\AiText;
use App\Ai\Scope\AiScope;
use App\Echange\Canevas\CanevasDEchange;
use App\Echange\Canevas\RessourceDEchange;
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
 * LE PÉRIMÈTRE EST RÉSOLU, PAS SUPPOSÉ. Le modèle exprime la demande en langage
 * naturel ; les codes sont retrouvés dans le canevas, et ce que l'utilisateur n'a pas
 * le droit de lire n'y figure tout simplement pas. Une demande « exporte tout » ne
 * donne donc jamais plus que ce que son auteur peut déjà voir à l'écran.
 */
final class EchangeExporterTool implements AiToolInterface
{
    private const ENTITE = 'Echange';

    private const LIBELLE = 'Importation / Exportation';

    public function __construct(
        private readonly CanevasDEchange $canevas,
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
        return 'Lance la génération d\'un export Excel des données du cabinet (classeur unique, qui '
            . 'sert aussi de gabarit de réimportation). Le paramètre « donnees » restreint le '
            . 'périmètre à certaines données (codes obtenus via echange_consulter) ; laissé vide, '
            . 'l\'export couvre tout ce que l\'utilisateur a le droit de lire. Les dépendances d\'une '
            . 'donnée demandée sont ajoutées automatiquement. Le coût est annoncé AVANT le '
            . 'déclenchement, et le téléchargement reste un geste de l\'utilisateur.';
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
                'donnees' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Codes des données à exporter. Vide ou absent = tout le périmètre '
                        . 'lisible. Les dépendances sont ajoutées d\'office.',
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

        return ['donnees' => []];
    }

    public function execute(array $args, AiScope $scope): AiToolResult
    {
        // FAIL-CLOSED : même garde que la route d'export elle-même.
        if (!$this->accessResolver->canRead($scope->invite, self::ENTITE)) {
            return AiToolResult::horsPerimetre(self::LIBELLE);
        }

        $lisibles = $this->canevas->ressourcesLisibles($scope->invite);
        if ($lisibles === []) {
            return AiToolResult::introuvable(
                self::LIBELLE,
                'Le périmètre d\'accès de cet utilisateur ne couvre aucune donnée exportable. '
                . 'Dis-le simplement ; ne propose pas de contourner ses droits.',
            );
        }

        $demandes = $this->resoudre($args['donnees'] ?? [], $lisibles);
        if ($demandes === null) {
            return AiToolResult::introuvable(
                implode(', ', array_map('strval', (array) ($args['donnees'] ?? []))),
                'Ces données ne sont pas au périmètre d\'échange de cet utilisateur. Appelle '
                . 'echange_consulter pour lui présenter la liste exacte de ce qu\'il peut exporter.',
            );
        }

        // Fermeture sur les dépendances : cocher une donnée coche celles dont elle a
        // besoin, sans quoi le fichier contiendrait des renvois vers des lignes absentes.
        $retenus = $demandes === []
            ? array_keys($lisibles)
            : $this->canevas->fermerSurLesDependances($demandes, $lisibles);

        $ajoutees = array_values(array_diff($retenus, $demandes === [] ? $retenus : $demandes));

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

        $parametres = ['idEntreprise' => $scope->entreprise->getId()];
        if ($demandes !== []) {
            $parametres['donnees'] = implode(',', $retenus);
        }

        return AiToolResult::ok(
            [
                'pret'               => true,
                'donnees_exportees'  => array_values(array_map(
                    static fn (string $code) => $lisibles[$code]->libelle,
                    $retenus,
                )),
                'nb_donnees'         => count($retenus),
                'dependances_ajoutees' => array_values(array_map(
                    static fn (string $code) => $lisibles[$code]->libelle,
                    array_filter($ajoutees, static fn (string $c) => isset($lisibles[$c])),
                )),
                'cout'               => $etat['coutExport'],
                'gratuites_restantes' => $etat['gratuitesRestantes'],
                'note' => sprintf(
                    'Le téléchargement s\'ouvre chez l\'utilisateur. %s Précise-lui que le fichier '
                    . 'produit sert aussi de gabarit pour réimporter ses modifications.',
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
     * Codes demandés, ramenés au périmètre lisible.
     *
     * Renvoie [] pour « tout », ou null si RIEN de ce qui est demandé n'existe — un
     * silence vaudrait ici un export intégral que personne n'a réclamé.
     *
     * @param mixed                            $demandes
     * @param array<string, RessourceDEchange> $lisibles
     *
     * @return string[]|null
     */
    private function resoudre(mixed $demandes, array $lisibles): ?array
    {
        if (!is_array($demandes) || $demandes === []) {
            return [];
        }

        $codes = [];
        foreach ($demandes as $demande) {
            if (!is_string($demande) || trim($demande) === '') {
                continue;
            }
            $code = $this->reconnaitre(trim($demande), $lisibles);
            if ($code !== null) {
                $codes[$code] = true;
            }
        }

        return $codes === [] ? null : array_keys($codes);
    }

    /**
     * Reconnaît un code de ressource, son libellé de rubrique, ou une forme approchante
     * (casse et accents ôtés) — le modèle rend « Clients » là où le code est « Client ».
     *
     * @param array<string, RessourceDEchange> $lisibles
     */
    private function reconnaitre(string $demande, array $lisibles): ?string
    {
        if (isset($lisibles[$demande])) {
            return $demande;
        }

        $cible = AiText::normalize($demande);
        foreach ($lisibles as $code => $ressource) {
            if (AiText::normalize($code) === $cible || AiText::normalize($ressource->libelle) === $cible) {
                return $code;
            }
        }

        // Repli sur le préfixe : « client » pour « Clients », « propositions » pour
        // « Propositions ». Ambigu = refusé, jamais deviné.
        $candidats = [];
        foreach ($lisibles as $code => $ressource) {
            foreach ([AiText::normalize($code), AiText::normalize($ressource->libelle)] as $forme) {
                if ($forme !== '' && (str_starts_with($forme, $cible) || str_starts_with($cible, $forme))) {
                    $candidats[$code] = true;
                }
            }
        }

        return count($candidats) === 1 ? array_key_first($candidats) : null;
    }
}
