<?php

namespace App\Twig\Extension;

use App\Entity\Avenant;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Feedback;
use App\Entity\Piste;
use App\Entity\Tache;
use App\Token\ParametresTokenService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Expose le plan tarifaire des tokens aux templates (vitrine publique, page
 * « Fonctionnement des tokens »…). Le but est que TOUT affichage de tarif lise
 * la même source de vérité que le backend de facturation : App\Token\
 * ParametresTokenService, qui résout les valeurs depuis la BDD (entité
 * PlateformeParametres, éditable via la Console) avec repli sur les constantes
 * TokenPricing. Ainsi, toute édition du plan en Console se reflète partout, et
 * le prix AFFICHÉ correspond toujours au prix FACTURÉ.
 */
class PlanTarifaireExtension extends AbstractExtension
{
    /**
     * Libellés bilingues des entités dont le poids d'écriture est affiché dans
     * la table publique. Toute entité ajoutée via le JSON du plan et absente de
     * cette carte retombe sur le nom court de sa classe (cf. getEntiteLabel()).
     */
    private const LABELS = [
        Entreprise::class => ['fr' => 'Entreprise', 'en' => 'Company'],
        Avenant::class    => ['fr' => 'Avenant',    'en' => 'Endorsement'],
        Cotation::class   => ['fr' => 'Cotation',   'en' => 'Quote'],
        Piste::class      => ['fr' => 'Piste',      'en' => 'Lead'],
        Tache::class      => ['fr' => 'Tâche',      'en' => 'Task'],
        Feedback::class   => ['fr' => 'Feedback',   'en' => 'Feedback'],
    ];

    public function __construct(private ParametresTokenService $parametres) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('plan_tarifaire', [$this, 'getPlanTarifaire']),
            new TwigFunction('token_entite_label', [$this, 'getEntiteLabel']),
        ];
    }

    /**
     * Donne accès au service de plan tarifaire ; les templates appellent ses
     * méthodes publiques : plan_tarifaire().freeAllowance, .freeWindowHours,
     * .readWeight, .defaultWriteWeight, .packs, .pack('intermediaire'),
     * .writeWeights…
     */
    public function getPlanTarifaire(): ParametresTokenService
    {
        return $this->parametres;
    }

    /** Libellé d'affichage d'une entité (par FQCN) dans la langue demandée. */
    public function getEntiteLabel(string $fqcn, string $lang = 'fr'): string
    {
        if (isset(self::LABELS[$fqcn])) {
            return self::LABELS[$fqcn][$lang] ?? self::LABELS[$fqcn]['fr'];
        }

        // Entité inconnue (ajoutée via le JSON) : on affiche le nom court de la classe.
        $parts = explode('\\', $fqcn);

        return end($parts) ?: $fqcn;
    }
}
