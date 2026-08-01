<?php

namespace App\Services;

use App\Entity\Avenant;
use App\Entity\Entreprise;
use App\Entity\Piste;
use App\Form\PisteType;
use App\Repository\AvenantRepository;
use App\Repository\PisteRepository;

/**
 * QU'EST DEVENUE CETTE POLICE ? — source unique de la SUITE d'un avenant.
 *
 * RAISON D'ÊTRE (bug KIN AVIA). Interrogée sur « cet avenant est-il renouvelé ? »,
 * l'assistante répondait « pas encore renouvelé : une piste de renouvellement a
 * bien été initiée, mais la police n'a pas fait l'objet d'un avenant de
 * reconduction effectif » — alors que la piste dérivée avait bel et bien donné
 * naissance à un avenant, à l'identique. La fiche ne portait qu'un demi-fait
 * (`hasPisteDerivee: true` : une piste dérivée EXISTE) sans jamais dire ce
 * qu'elle avait PRODUIT. Le modèle a comblé le vide par une négation plausible.
 *
 * Aucune consigne de prompt ne corrige durablement cela : c'est la DONNÉE qui
 * manquait. Ce resolver parcourt la chaîne complète, une seule fois, pour tout
 * le monde :
 *
 *     police de base → opportunité dérivée → propositions → avenants issus
 *
 * et rend un fait rédigé qui NOMME la suite (« l'avenant #120, n° 1, couvre du
 * … au … ») ou AFFIRME son absence comme une vérification, jamais comme un
 * silence. Il alimente les indicateurs calculés de l'avenant
 * (AvenantIndicatorStrategy → fiches de l'assistant + dialogue d'analyse), les
 * résultats de recherche de l'assistant, la garde d'idempotence des mouvements
 * de police, et le statut affiché par Constante::Avenant_getRenewalStatus().
 *
 * DEUX SENS DE LECTURE DU LIEN. Les deux chemins d'écriture posent le double
 * lien Piste::avenantDeBase ⇄ Avenant::pisteDeRenouvellement (formulaire de
 * piste dérivée : PisteController::submitApi ; assistante :
 * MouvementAvenantBuilder). On lit néanmoins les DEUX sens : un lien à moitié
 * posé (données anciennes, dissociation partielle) ne doit pas se traduire par
 * « aucune suite ».
 *
 * PERF. Le sens « piste dont avenantDeBase = cette police » est chargé PAR LOT
 * et par entreprise (même patron que AvenantIndicatorStrategy::loadTypeAffaireBatch),
 * puis mémoïsé : une liste d'avenants ne déclenche pas une requête par ligne.
 * C'est aussi ce qui remplace le balayage de TOUTES les pistes de l'entreprise
 * que faisait Constante::Avenant_getRenewalStatus().
 */
final class AvenantRenouvellementResolver
{
    /**
     * Libellés du statut de la SUITE d'une police — au féminin (« la police »),
     * et sans équivoque pour un lecteur comme pour le modèle. Distincts des
     * libellés de AvenantIndicatorStrategy::getAvenantStatutRenouvellementString(),
     * qui décrivent la colonne STOCKÉE renewalStatus (une intention saisie),
     * là où ceux-ci décrivent l'état RÉEL de la chaîne.
     */
    private const STATUTS = [
        Avenant::RENEWAL_STATUS_LOST      => 'Non renouvelée',
        Avenant::RENEWAL_STATUS_ONCE_OFF  => 'Temporaire (non renouvelable)',
        Avenant::RENEWAL_STATUS_RENEWED   => 'Renouvelée',
        Avenant::RENEWAL_STATUS_EXTENDED  => 'Prorogée',
        Avenant::RENEWAL_STATUS_RUNNING   => 'En cours de couverture',
        Avenant::RENEWAL_STATUS_RENEWING  => 'Renouvellement en cours',
        Avenant::RENEWAL_STATUS_CANCELLED => 'Résiliée / annulée',
    ];

    /** Rappel de la vérification effectuée : ce qui distingue un fait d'un silence. */
    private const CHAINE_VERIFIEE = 'chaîne vérifiée : police → opportunité dérivée → propositions → avenants';

    /** @var array<int, Piste> pistes dérivées indexées par id d'avenant de base */
    private array $pisteParAvenant = [];

    /** @var array<int, true> entreprises dont le lot est déjà chargé */
    private array $lotsCharges = [];

    public function __construct(
        private readonly PisteRepository $pisteRepository,
        private readonly AvenantRepository $avenantRepository,
    ) {
    }

    /**
     * @return array{
     *     code: int,
     *     statut: string,
     *     phrase: string,
     *     pisteDeriveeId: ?int,
     *     typeMouvement: ?string,
     *     avenantsIssus: array<int, array{id: int, numero: ?string, referencePolice: ?string, periode: ?string}>
     * }
     */
    public function resoudre(Avenant $base): array
    {
        // Volontairement NON mémoïsé par avenant : le résultat doit rester juste
        // après une écriture dans la même requête (l'assistante exécute un plan
        // puis reparle de la police). Le coût est celui du parcours de
        // collections déjà hydratées par Doctrine — le seul accès réellement
        // coûteux, la recherche de l'opportunité dérivée par lien inverse, est
        // lui chargé par lot une fois pour toutes.
        return $this->calculer($base);
    }

    /** Libellé court du statut de la suite (badge, ligne de fiche). */
    public function statut(Avenant $base): string
    {
        return $this->resoudre($base)['statut'];
    }

    /** Fait rédigé nommant la suite de la police — ou sa négation vérifiée. */
    public function phrase(Avenant $base): string
    {
        return $this->resoudre($base)['phrase'];
    }

    /**
     * Code Avenant::RENEWAL_STATUS_* de l'état RÉEL de la chaîne. Utilisé par
     * Constante::Avenant_getRenewalStatus() pour que le badge des listes, le
     * tableau de bord et l'assistante ne puissent pas diverger.
     */
    public function code(Avenant $base): int
    {
        return $this->resoudre($base)['code'];
    }

    // ------------------------------------------------------------------ calcul

    /** @return array<string, mixed> */
    private function calculer(Avenant $base): array
    {
        $piste = $this->pisteDerivee($base);

        if ($piste === null) {
            return $this->sansSuite($base);
        }

        $avenantsIssus = $this->avenantsIssus($piste, $base);
        $typeAvenant   = $piste->getTypeAvenant();
        $bound         = $avenantsIssus !== [];

        // Le type de la piste dérivée dit la NATURE du mouvement ; la présence
        // d'un avenant issu dit s'il est ABOUTI. Un bras par défaut est
        // obligatoire : une piste dérivée peut être typée « Souscription » /
        // « Incorporation » ou n'être pas typée du tout (le match sans défaut de
        // l'ancienne implémentation levait alors \UnhandledMatchError).
        $code = match ($typeAvenant) {
            Piste::AVENANT_ANNULATION,
            Piste::AVENANT_RESILIATION    => Avenant::RENEWAL_STATUS_CANCELLED,
            Piste::AVENANT_PROROGATION    => $bound ? Avenant::RENEWAL_STATUS_EXTENDED : Avenant::RENEWAL_STATUS_RENEWING,
            default                       => $bound ? Avenant::RENEWAL_STATUS_RENEWED : Avenant::RENEWAL_STATUS_RENEWING,
        };

        return [
            'code'           => $code,
            'statut'         => self::STATUTS[$code],
            'phrase'         => $this->phraseAvecSuite($code, $piste, $avenantsIssus),
            'pisteDeriveeId' => $piste->getId(),
            'typeMouvement'  => $this->libelleMouvement($typeAvenant),
            'avenantsIssus'  => $avenantsIssus,
        ];
    }

    /**
     * Police sans aucune opportunité dérivée : le statut se déduit d'elle seule.
     * La phrase AFFIRME l'absence de suite comme le résultat d'une vérification —
     * c'est ce qui autorise l'assistante à dire « non renouvelée » sans deviner.
     *
     * @return array<string, mixed>
     */
    private function sansSuite(Avenant $base): array
    {
        $renewalCondition = $base->getCotation()?->getPiste()?->getRenewalCondition();
        $fin              = $base->getEndingAt();
        $expiree          = $fin !== null && $fin < new \DateTimeImmutable('now');

        $code = match (true) {
            $renewalCondition === Piste::RENEWAL_CONDITION_ONCE_OFF_AND_EXTENDABLE => Avenant::RENEWAL_STATUS_ONCE_OFF,
            !$expiree                                                              => Avenant::RENEWAL_STATUS_RUNNING,
            default                                                                => Avenant::RENEWAL_STATUS_LOST,
        };

        $phrase = match ($code) {
            Avenant::RENEWAL_STATUS_ONCE_OFF => sprintf(
                'Police TEMPORAIRE marquée non renouvelable : aucune suite n’est enregistrée (%s).',
                self::CHAINE_VERIFIEE,
            ),
            Avenant::RENEWAL_STATUS_RUNNING => sprintf(
                'Couverture EN COURS%s : aucun mouvement n’est encore enregistré sur cette police (%s).',
                $fin !== null ? ' jusqu’au ' . $fin->format('d/m/Y') : '',
                self::CHAINE_VERIFIEE,
            ),
            default => sprintf(
                'NON RENOUVELÉE : la police est expirée%s et AUCUNE suite n’est enregistrée — ni opportunité '
                . 'dérivée, ni avenant issu (%s).',
                $fin !== null ? ' depuis le ' . $fin->format('d/m/Y') : '',
                self::CHAINE_VERIFIEE,
            ),
        };

        return [
            'code'           => $code,
            'statut'         => self::STATUTS[$code],
            'phrase'         => $phrase,
            'pisteDeriveeId' => null,
            'typeMouvement'  => null,
            'avenantsIssus'  => [],
        ];
    }

    /**
     * Fait rédigé quand une opportunité dérivée existe. Le cas RENEWING est le
     * cœur du bug d'origine, dans les DEUX sens : une piste dérivée sans avenant
     * n'est PAS un renouvellement acquis, et un avenant issu n'est PAS une
     * absence de renouvellement.
     *
     * @param array<int, array{id: int, numero: ?string, referencePolice: ?string, periode: ?string}> $avenantsIssus
     */
    private function phraseAvecSuite(int $code, Piste $piste, array $avenantsIssus): string
    {
        $opportunite = sprintf(
            'opportunité dérivée #%d (%s « %s »)',
            (int) $piste->getId(),
            $this->libelleMouvement($piste->getTypeAvenant()) ?? 'type non précisé',
            (string) $piste->getNom(),
        );

        if ($code === Avenant::RENEWAL_STATUS_RENEWING) {
            return sprintf(
                'Mouvement AMORCÉ MAIS PAS ABOUTI : l’%s existe, mais AUCUN avenant n’en est encore issu — '
                . 'la police n’est donc pas encore reconduite (%s).',
                $opportunite,
                self::CHAINE_VERIFIEE,
            );
        }

        $verbe = match ($code) {
            Avenant::RENEWAL_STATUS_EXTENDED  => 'PROROGÉE',
            Avenant::RENEWAL_STATUS_CANCELLED => 'RÉSILIÉE / ANNULÉE',
            default                           => 'RENOUVELÉE',
        };

        if ($avenantsIssus === []) {
            // Annulation / résiliation enregistrée sans avenant d'acte : le
            // mouvement vaut décision, la police est bel et bien éteinte.
            return sprintf('Police %s via l’%s.', $verbe, $opportunite);
        }

        $suites = array_map(
            static fn (array $a): string => sprintf(
                'l’avenant #%d (n° %s%s)%s',
                $a['id'],
                $a['numero'] ?? '—',
                $a['referencePolice'] !== null ? ', réf. ' . $a['referencePolice'] : '',
                $a['periode'] !== null ? ' couvre ' . $a['periode'] : '',
            ),
            $avenantsIssus,
        );

        return sprintf('Police %s : %s. Mouvement enregistré via l’%s.', $verbe, implode(' ; ', $suites), $opportunite);
    }

    /**
     * Avenants NÉS de l'opportunité dérivée, via ses propositions. La police de
     * base est exclue par précaution : un lien mal posé ne doit pas donner
     * l'illusion qu'elle est sa propre suite.
     *
     * INTERROGÉS EN BASE, et non lus dans les collections en mémoire
     * Piste::cotations → Cotation::avenants. Ces deux relations ne sont pas
     * maintenues à l'aller par leurs setters (Cotation::setPiste et
     * Avenant::setCotation sont unidirectionnels) : juste après l'exécution d'un
     * plan de renouvellement, la collection de la piste NEUVE est vide alors que
     * l'avenant est bel et bien enregistré. La lecture en mémoire aurait donc
     * répondu « pas encore renouvelée » à l'instant même où elle venait de
     * l'être — exactement le défaut que ce service existe pour supprimer.
     *
     * @return array<int, array{id: int, numero: ?string, referencePolice: ?string, periode: ?string}>
     */
    private function avenantsIssus(Piste $piste, Avenant $base): array
    {
        $candidats = $piste->getId() !== null
            ? $this->avenantRepository->createQueryBuilder('a')
                ->join('a.cotation', 'c')
                ->andWhere('c.piste = :piste')
                ->setParameter('piste', $piste)
                ->orderBy('a.id', 'ASC')
                ->getQuery()
                ->getResult()
            // Piste pas encore persistée (plan en cours de construction) : seule
            // la mémoire peut répondre.
            : $this->avenantsEnMemoire($piste);

        $issus = [];
        foreach ($candidats as $avenant) {
            if ($avenant->getId() === null || $avenant->getId() === $base->getId()) {
                continue;
            }
            $debut = $avenant->getStartingAt();
            $fin   = $avenant->getEndingAt();
            $issus[] = [
                'id'              => $avenant->getId(),
                'numero'          => $avenant->getNumero(),
                'referencePolice' => $avenant->getReferencePolice(),
                'periode'         => $debut !== null && $fin !== null
                    ? sprintf('du %s au %s', $debut->format('d/m/Y'), $fin->format('d/m/Y'))
                    : null,
            ];
        }

        return $issus;
    }

    /** @return array<int, Avenant> */
    private function avenantsEnMemoire(Piste $piste): array
    {
        $avenants = [];
        foreach ($piste->getCotations() as $cotation) {
            foreach ($cotation->getAvenants() as $avenant) {
                $avenants[] = $avenant;
            }
        }

        return $avenants;
    }

    /**
     * Opportunité dérivée de cette police, dans les DEUX sens du double lien.
     * Le sens porté par l'avenant est gratuit (relation déjà mappée) ; l'autre
     * passe par le lot de l'entreprise.
     */
    private function pisteDerivee(Avenant $base): ?Piste
    {
        $piste = $base->getPisteDeRenouvellement();
        if ($piste !== null) {
            return $piste;
        }

        $entreprise = $base->getEntreprise();
        if ($entreprise === null || $entreprise->getId() === null) {
            // Avenant hors entreprise (cas résiduel) : requête ciblée.
            return $this->pisteRepository->findOneBy(['avenantDeBase' => $base]);
        }

        $this->chargerLot($entreprise);

        return $base->getId() !== null ? ($this->pisteParAvenant[$base->getId()] ?? null) : null;
    }

    /**
     * Toutes les opportunités dérivées de l'entreprise, en UNE requête, indexées
     * par l'avenant qu'elles font évoluer. Leur nombre est celui des mouvements
     * jamais enregistrés par le cabinet : sans commune mesure avec le nombre de
     * lignes d'une liste d'avenants, que ce lot dispense d'interroger une à une.
     */
    private function chargerLot(Entreprise $entreprise): void
    {
        $entrepriseId = $entreprise->getId();
        if (isset($this->lotsCharges[$entrepriseId])) {
            return;
        }
        $this->lotsCharges[$entrepriseId] = true;

        $pistes = $this->pisteRepository->createQueryBuilder('p')
            ->join('p.avenantDeBase', 'a')
            ->andWhere('a.entreprise = :entreprise')
            ->setParameter('entreprise', $entreprise)
            ->getQuery()
            ->getResult();

        /** @var Piste $piste */
        foreach ($pistes as $piste) {
            // getAvenantDeBase() est ici un proxy non chargé : lire son id ne
            // déclenche AUCUNE requête (l'identifiant est déjà connu).
            $avenantId = $piste->getAvenantDeBase()?->getId();
            if ($avenantId !== null) {
                $this->pisteParAvenant[$avenantId] = $piste;
            }
        }
    }

    /** Libellé métier du type d'avenant — source unique PisteType::TYPE_AVENANT_LABELS. */
    private function libelleMouvement(?int $typeAvenant): ?string
    {
        return $typeAvenant !== null ? (PisteType::TYPE_AVENANT_LABELS[$typeAvenant] ?? null) : null;
    }
}
