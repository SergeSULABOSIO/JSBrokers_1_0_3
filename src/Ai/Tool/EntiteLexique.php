<?php

namespace App\Ai\Tool;

use App\Ai\AiText;
use App\Service\Workspace\WorkspaceAccessResolver;

/**
 * Lexique partagé des entités interrogeables par l'assistant : mots-clés
 * (dérivés des libellés de la carte de permissions, DRY) => nom court d'entité.
 * Les pseudo-entités sans classe Doctrine (DocumentComptable) sont exclues.
 * Utilisé par les outils de données pour leur enum de schéma (tool-calling)
 * et leur matching par mots-clés (moteur simulé).
 */
final class EntiteLexique
{
    public function __construct(
        private readonly WorkspaceAccessResolver $accessResolver,
    ) {
    }

    /**
     * @return array<string, string[]> nom court => mots-clés normalisés
     */
    public function lexique(): array
    {
        $lexique = [];
        $alias = $this->accessResolver->aliasEntites();
        foreach ($this->accessResolver->libellesEntites() as $shortName => $label) {
            if (!class_exists('App\\Entity\\' . $shortName)) {
                continue;
            }

            // Les libellés ANTÉRIEURS comptent autant que l'actuel : renommer une rubrique
            // ne renomme pas le vocabulaire des courtiers, et un terme retiré du lexique
            // devient une demande refusée.
            $candidats = [AiText::normalize($label), AiText::normalize($shortName)];
            foreach ($alias[$shortName] ?? [] as $ancien) {
                $candidats[] = AiText::normalize($ancien);
            }

            $keywords = [];
            foreach ($candidats as $candidate) {
                $keywords[] = $candidate;
                // Variante singulier/pluriel naïve, suffisante pour un lexique FR.
                $keywords[] = str_ends_with($candidate, 's') ? rtrim($candidate, 's') : $candidate . 's';
            }
            $lexique[$shortName] = array_values(array_unique($keywords));
        }

        return $lexique;
    }

    /** @return string[] noms courts des entités interrogeables (enum des schémas d'outils) */
    public function nomsCourts(): array
    {
        return array_keys($this->lexique());
    }

    /**
     * Fragment de schéma JSON décrivant l'argument `lieA` — « les enregistrements
     * RATTACHÉS à celui-ci ».
     *
     * SOURCE UNIQUE. `rechercher_entites` et `ouvrir_rubrique` le déclaraient chacun
     * de son côté, avec deux textes différents pour un paramètre dont `ouvrir_rubrique`
     * précisait pourtant lui-même qu'« il faut y mettre la même chose ». Deux prose
     * pour une règle identique, c'est une divergence qui attend son heure — et elle
     * porterait ici sur la façon dont le modèle désigne un client.
     *
     * LE FOND DE LA RÈGLE, et la raison pour laquelle elle mérite d'être écrite une
     * seule fois : le NOM suffit. Le serveur résout le nom et trouve seul le chemin de
     * relations, direct ou à plusieurs niveaux. Sans cette phrase, le modèle fait une
     * recherche préalable pour obtenir un identifiant — un tour entier, quarante mille
     * jetons d'entrée, pour une donnée dont le serveur n'avait pas besoin.
     *
     * @return array<string, mixed>
     */
    public function lieASchema(): array
    {
        return [
            'type' => 'object',
            'description' => 'Restreint aux enregistrements LIÉS à celui-ci, même indirectement : '
                . 'les avenants du client « Dupont » → entite=Avenant, lieA={entite:"Client",nom:"Dupont"}. '
                . 'Donne "id" si tu le connais, SINON "nom" : le serveur résout le nom et trouve seul '
                . 'le chemin. Tu n\'as JAMAIS à chercher un identifiant au préalable.',
            'properties' => [
                'entite' => [
                    'type' => 'string',
                    'enum' => $this->nomsCourts(),
                    'description' => "Nom court de l'enregistrement de rattachement.",
                ],
                'id' => [
                    'type' => 'integer',
                    'description' => "Identifiant de l'enregistrement de rattachement, si tu le connais déjà.",
                ],
                'nom' => [
                    'type' => 'string',
                    'description' => 'À défaut d\'identifiant : le nom dicté par l\'utilisateur.',
                ],
            ],
            'required' => ['entite'],
        ];
    }

    /**
     * Libellés d'écran par nom court, tels que l'utilisateur les voit dans le menu
     * (« Propositions » pour Cotation, « Paiements de prime » pour PaiementPrime).
     * Simple passe-plat vers la carte de permissions : ce lexique est déjà le seul
     * point du domaine IA qui la lise, et EntiteCanonique a besoin des libellés
     * pour NOMMER ce qu'il accepte quand il refuse un terme.
     *
     * @return array<string, string>
     */
    public function libelles(): array
    {
        return $this->accessResolver->libellesEntites();
    }

    /**
     * Entité sur laquelle porte la question : celle dont un mot-clé apparaît le PLUS TÔT
     * dans la phrase, le mot-clé le plus long l'emportant à position égale.
     *
     * L'ordre de la phrase prime sur l'ordre du lexique : « combien d'avenants dans mon
     * portefeuille ? » interroge les AVENANTS — le portefeuille n'est là que pour désigner
     * un périmètre. Retenir la première entité du lexique (son ordre vient de la carte de
     * permissions, sans rapport avec la question) répondait sur la mauvaise rubrique.
     */
    public function matchEntite(string $normalizedQuestion): ?string
    {
        $meilleur = null;
        foreach ($this->lexique() as $shortName => $keywords) {
            foreach ($keywords as $keyword) {
                if (!preg_match('/\b' . preg_quote($keyword, '/') . '\b/', $normalizedQuestion, $m, PREG_OFFSET_CAPTURE)) {
                    continue;
                }

                $position = $m[0][1];
                $longueur = strlen($keyword);
                if ($meilleur === null
                    || $position < $meilleur['position']
                    || ($position === $meilleur['position'] && $longueur > $meilleur['longueur'])) {
                    $meilleur = ['nom' => $shortName, 'position' => $position, 'longueur' => $longueur];
                }
            }
        }

        return $meilleur['nom'] ?? null;
    }
}
