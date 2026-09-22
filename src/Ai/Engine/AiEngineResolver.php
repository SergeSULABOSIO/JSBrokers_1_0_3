<?php

namespace App\Ai\Engine;

use App\Ai\AiReply;
use App\Ai\AiRequest;
use App\Ai\Fournisseur\OrdreDesFournisseurs;
use App\Ai\Fournisseur\PolitiqueDesFournisseurs;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Sélectionne le moteur de l'assistant à l'exécution — désormais comme une
 * CHAÎNE, exactement comme la voix et les oreilles de Ket.
 *
 * CE QUI A CHANGÉ, ET POURQUOI. C'était un `switch` en dur sur trois classes
 * injectées nommément, là où les deux autres familles de fournisseurs sont des
 * chaînes taggées ordonnées par une liste. Trois mécanismes proches pour un même
 * besoin finissent toujours par diverger, et surtout : une politique pilotable
 * depuis la console ne peut pas s'appuyer sur un `switch`. Ajouter un moteur
 * consiste maintenant à implémenter MoteurDeTexte, jamais à modifier ce fichier.
 *
 * L'ORDRE, puis la DISPONIBILITÉ. `KET_MOTEURS` dit la préférence ; un nom absent
 * de la liste n'est JAMAIS appelé, c'est ainsi qu'on coupe un fournisseur sans
 * toucher au code. Parmi ceux que la liste nomme, le premier dont la clé est
 * posée répond. Le simulé ferme toujours la marche : il n'interroge personne, ne
 * demande aucune clé, et garantit qu'il y a toujours un moteur.
 *
 * `AI_ENGINE` reste prioritaire sur tout le reste — c'est ce que fait `.env.test`
 * pour que les tests n'appellent JAMAIS une API réelle, même quand une clé traîne
 * en variable d'environnement du poste (qui prime sur les .env).
 *
 * À COMPORTEMENT CONSTANT : avec l'ordre par défaut « anthropic,gemini,simulated »,
 * la règle rendue est exactement celle d'avant — clé Anthropic d'abord, clé Gemini
 * ensuite, simulé sinon.
 *
 * C'est ce service que l'alias AiEngineInterface pointe (services.yaml), et il
 * n'est délibérément PAS un MoteurDeTexte : se tagger lui-même le ferait entrer
 * dans sa propre chaîne, et il s'appellerait en boucle.
 */
final class AiEngineResolver implements AiEngineInterface
{
    /** @param iterable<MoteurDeTexte> $moteurs */
    public function __construct(
        #[AutowireIterator('app.fournisseur_moteur')] private readonly iterable $moteurs,
        #[Autowire(env: 'KET_MOTEURS')] private readonly string $ordre = 'anthropic,gemini,simulated',
        #[Autowire(env: 'AI_ENGINE')] private readonly string $force = '',
        // LA POLITIQUE, quand elle existe, PRIME sur la liste du .env — c'est tout
        // l'objet de l'écran de console. Facultative : sans elle, rien ne change.
        private readonly ?PolitiqueDesFournisseurs $politique = null,
    ) {
    }

    public function name(): string
    {
        return $this->moteur()->name();
    }

    public function modelName(): string
    {
        return $this->moteur()->modelName();
    }

    public function reply(AiRequest $request): AiReply
    {
        return $this->moteur()->reply($request);
    }

    private function moteur(): MoteurDeTexte
    {
        $chaine = OrdreDesFournisseurs::ordonner($this->moteurs, $this->politique?->ordre('moteur') ?? $this->ordre);

        // Forçage explicite (AI_ENGINE) : prioritaire sur l'ordre comme sur les clés.
        // Un moteur forcé est pris même sans clé — c'est un choix d'exploitation,
        // pas une préférence, et le masquer rendrait le réglage incompréhensible.
        $force = strtolower(trim($this->force));
        if ($force !== '') {
            foreach ($chaine as $moteur) {
                if ($moteur->nom() === $force) {
                    return $moteur;
                }
            }
        }

        // UTILISABLES, pas seulement disponibles : un moteur qui s'est déclaré à sec
        // est écarté sans qu'on lui parle. C'est toute la différence entre payer une
        // attente pour un refus connu d'avance et aller droit à celui qui répondra.
        $disponibles = OrdreDesFournisseurs::utilisables($chaine);

        // Le simulé ferme la marche et se déclare toujours disponible : ce repli
        // n'est atteint que si la liste d'ordre ne le nomme pas — une configuration
        // qu'on ne veut pas voir tomber en panne sèche pour autant.
        return $disponibles[0] ?? $this->dernierRecours($chaine);
    }

    /**
     * @param list<MoteurDeTexte> $chaine
     */
    private function dernierRecours(array $chaine): MoteurDeTexte
    {
        foreach ($this->moteurs as $moteur) {
            if ($moteur instanceof SimulatedAiEngine) {
                return $moteur;
            }
        }

        return $chaine[0] ?? throw new \LogicException(
            'Aucun moteur de texte n’est déclaré : KET_MOTEURS ne nomme rien de connu.',
        );
    }
}
