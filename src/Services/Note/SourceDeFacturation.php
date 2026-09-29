<?php

namespace App\Services\Note;

use App\Entity\Avenant;
use App\Entity\AutoriteFiscale;
use App\Entity\Entreprise;
use App\Entity\Note;
use App\Entity\RevenuPourCourtier;
use App\Entity\Taxe;
use App\Entity\Tranche;
use App\Services\CanvasBuilder;
use Doctrine\ORM\EntityManagerInterface;

/**
 * CE QU'ON PEUT FACTURER, ET À QUI — la règle, en un seul endroit.
 *
 * ── POURQUOI CE SERVICE EXISTE ──────────────────────────────────────────────
 * La règle était écrite, et elle ne servait à personne. Elle vivait en closure
 * privée dans `RevenuPourCourtierAutocompleteField::fetchAndFilterEligibleRevenus()`,
 * et un grep sur tout le dépôt — src, assets, templates, tests — n'en trouvait
 * AUCUN appel. Le `query_builder` réellement utilisé était nu : l'écran proposait
 * donc n'importe quel revenu, y compris ceux déjà intégralement encaissés.
 *
 * Et l'assistant, lui, n'y avait même pas accès : sommé de préparer une note de
 * débit sur une commission exigible, il a répondu qu'il ne trouvait « aucune
 * tranche de commission associée à cette police », alors que l'écran l'affichait.
 * Il ne savait pas où chercher, parce que la règle n'était nulle part appelable.
 *
 * ── CE QU'IL FAUT COMPRENDRE AVANT D'Y TOUCHER ──────────────────────────────
 * LE SOLDE NE SE LIT PAS EN SQL. `solde_restant_du`, `retroCommissionSolde`,
 * `taxeCourtierSolde` et `taxeAssureurSolde` sont des valeurs CALCULÉES, posées
 * sur l'entité par {@see CanvasBuilder::loadAllCalculatedValues()}. Aucune clause
 * WHERE ne peut les exprimer : le tri final se fait donc en PHP, après hydratation.
 *
 * C'EST TENABLE PARCE QUE LA LISTE EST BORNÉE. On n'hydrate jamais « tous les
 * revenus du cabinet » : le destinataire est connu, donc la requête ne ramène que
 * les revenus d'UN assureur ou d'UN client. Sans destinataire, on rend une liste
 * vide plutôt qu'une liste entière — fail-closed, et sans coût.
 *
 * ── LES QUATRE DESTINATAIRES, ET CE QU'ILS DOIVENT ──────────────────────────
 * Un revenu n'est pas « facturable » dans l'absolu : il l'est POUR QUELQU'UN.
 *  - assureur / client  → la COMMISSION du courtier reste due          (solde_restant_du)
 *  - partenaire         → la RÉTROCOMMISSION reste à reverser          (retroCommissionSolde)
 *  - autorité fiscale   → la TAXE reste à reverser, et c'est le REDEVABLE de la
 *    taxe qui dit laquelle : au courtier (taxeCourtierSolde) ou à l'assureur
 *    (taxeAssureurSolde). Deux mondes distincts, jamais interchangeables.
 *
 * ── CE SERVICE NE CONNAÎT PAS LA REQUÊTE HTTP ───────────────────────────────
 * Ses paramètres sont explicites. C'est le champ de formulaire qui lit la requête
 * (`live_assureur_id`…) et les lui passe ; l'assistant, lui, les tient de son plan.
 * Un service qui lirait `RequestStack` serait inutilisable depuis le worker.
 */
final class SourceDeFacturation
{
    /**
     * En deçà, un solde relève de l'arrondi comptable et non d'une créance.
     * Même seuil que le champ d'origine, et que {@see NoteRecouvrementService}.
     */
    private const SEUIL_SOLDE = 0.01;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CanvasBuilder $canvasBuilder,
    ) {
    }

    /**
     * Les revenus réellement facturables à ce destinataire, dans ce cabinet.
     *
     * @param int  $addressedTo une des constantes `Note::TO_*`
     * @param ?int $cibleId     l'assureur, le client, le partenaire ou l'autorité
     *                          fiscale visé — selon `$addressedTo`
     * @param ?Tranche $tranche restreint à la cotation de cette tranche, quand la
     *                          facturation part d'une échéance précise
     *
     * @return list<RevenuPourCourtier> hydratés de leurs valeurs calculées
     */
    public function revenusFacturables(
        Entreprise $entreprise,
        int $addressedTo,
        ?int $cibleId = null,
        ?Tranche $tranche = null,
    ): array {
        // SANS DESTINATAIRE, ON NE PROPOSE RIEN. « Facturable » n'a pas de sens
        // dans l'absolu : c'est le destinataire qui dit quelle dette on regarde.
        if (!in_array($addressedTo, self::destinatairesConnus(), true)) {
            return [];
        }

        $candidats = $this->candidats($entreprise, $addressedTo, $cibleId, $tranche);
        if ($candidats === []) {
            return [];
        }

        $autorite = $addressedTo === Note::TO_AUTORITE_FISCALE && $cibleId !== null
            ? $this->em->getRepository(AutoriteFiscale::class)->find($cibleId)
            : null;

        $retenus = [];
        foreach ($candidats as $revenu) {
            $this->canvasBuilder->loadAllCalculatedValues($revenu);
            if ($this->soldeDu($revenu, $addressedTo, $autorite) > self::SEUIL_SOLDE) {
                $retenus[] = $revenu;
            }
        }

        return $retenus;
    }

    /**
     * Le solde que CE destinataire doit encore sur ce revenu. 0 quand il ne doit
     * rien — ou quand on ne sait pas dire quoi, ce qui revient au même ici.
     */
    private function soldeDu(RevenuPourCourtier $revenu, int $addressedTo, ?AutoriteFiscale $autorite): float
    {
        return match (true) {
            // La rétrocommission due à l'intermédiaire, pas la commission du cabinet.
            $addressedTo === Note::TO_PARTENAIRE => (float) ($revenu->retroCommissionSolde ?? 0.0),

            // LE REDEVABLE DE LA TAXE DÉCIDE, jamais le type de note : une taxe sur la
            // commission due par le courtier et une taxe due par l'assureur ne se
            // reversent pas sur le même solde (cf. Taxe::REDEVABLE_*).
            $addressedTo === Note::TO_AUTORITE_FISCALE => match ($autorite?->getTaxe()?->getRedevable()) {
                Taxe::REDEVABLE_COURTIER => (float) ($revenu->taxeCourtierSolde ?? 0.0),
                Taxe::REDEVABLE_ASSUREUR => (float) ($revenu->taxeAssureurSolde ?? 0.0),
                default => 0.0, // autorité inconnue ou sans taxe : on ne devine pas.
            },

            // Assureur et client : la commission de courtage restant due.
            default => (float) ($revenu->solde_restant_du ?? 0.0),
        };
    }

    /**
     * Les revenus que la base peut rendre pour ce destinataire, AVANT le tri sur
     * les soldes calculés. C'est ici, et seulement ici, que la liste se borne.
     *
     * @return RevenuPourCourtier[]
     */
    private function candidats(Entreprise $entreprise, int $addressedTo, ?int $cibleId, ?Tranche $tranche): array
    {
        $qb = $this->em->getRepository(RevenuPourCourtier::class)
            ->createQueryBuilder('r')
            ->addSelect('tr', 'c', 'assureur', 'piste', 'client')
            ->join('r.typeRevenu', 'tr')
            ->join('r.cotation', 'c')
            ->leftJoin('c.assureur', 'assureur')
            ->leftJoin('c.piste', 'piste')
            ->leftJoin('piste.client', 'client')
            // ⚠ SUR `r.entreprise`, PAS SUR CELLE DU TYPE DE REVENU. La requête
            // d'origine passait par `tr.entreprise` — un détour qui ne vaut que tant
            // qu'aucun type n'est partagé, et qui écarte au passage tout revenu
            // auquel il manquerait son type.
            ->andWhere('r.entreprise = :entreprise')
            ->setParameter('entreprise', $entreprise);

        // LE DESTINATAIRE BORNE LA LISTE, et c'est ce qui rend l'hydratation
        // abordable. Le partenaire et l'autorité fiscale, eux, ne se lisent pas sur
        // la cotation : leur dette dépend d'un calcul de partage, donc le tri se
        // fera plus loin, en PHP — d'où l'importance de la tranche quand elle est là.
        if ($addressedTo === Note::TO_ASSUREUR && $cibleId !== null) {
            $qb->andWhere('assureur.id = :cible')->setParameter('cible', $cibleId);
        } elseif ($addressedTo === Note::TO_CLIENT && $cibleId !== null) {
            $qb->andWhere('client.id = :cible')->setParameter('cible', $cibleId);
        }

        if ($tranche?->getCotation() !== null) {
            $qb->andWhere('c.id = :cotation')->setParameter('cotation', $tranche->getCotation()->getId());
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * CE QUE LE DOSSIER DIT DÉJÀ — l'en-tête d'une note déduit de ce qu'on facture.
     *
     * Le type (débit ou crédit) et le destinataire sont des discriminants comptables
     * que {@see \App\Service\Workspace\ChampsObligatoiresInspector} interdit de
     * deviner. Ici on ne devine pas : on DÉDUIT d'une source nommée. Facturer la
     * commission d'une échéance, c'est la réclamer à l'assureur de sa police — et
     * l'assureur, la police et le client sont sur l'objet qu'on nous donne.
     *
     * Le type reste dicté par l'appelant : une ristourne se fait au crédit sur la
     * même tranche, et rien dans le dossier ne permet de trancher à sa place.
     *
     * @param Tranche|Avenant $source ce qu'on facture
     * @param int             $type   `Note::TYPE_NOTE_DE_DEBIT` ou `..._CREDIT`
     * @param int             $addressedTo `Note::TO_ASSUREUR` ou `Note::TO_CLIENT`
     *
     * @return array{type: int, addressedTo: int, cible: ?int, nom: string} `cible` =
     *         identifiant de l'assureur ou du client, null s'il est introuvable
     */
    public function entetePour(Tranche|Avenant $source, int $type, int $addressedTo = Note::TO_ASSUREUR): array
    {
        $cotation = $source->getCotation();
        $avenant = $source instanceof Avenant
            ? $source
            : ($cotation !== null && !$cotation->getAvenants()->isEmpty() ? $cotation->getAvenants()->first() : null);

        $assureur = $cotation?->getAssureur();
        $client = $cotation?->getPiste()?->getClient();

        $cible = $addressedTo === Note::TO_CLIENT ? $client?->getId() : $assureur?->getId();

        return [
            'type' => $type,
            'addressedTo' => $addressedTo,
            'cible' => $cible,
            'nom' => $this->objet($type, $avenant?->getReferencePolice()),
        ];
    }

    /**
     * L'OBJET DE LA NOTE, lisible par celui qui la recevra. « Commission — Police
     * XCDDD41457845-2026 » dit ce qu'on réclame et sur quoi ; sans référence de
     * police, on ne fabrique pas un faux numéro, on s'en tient au motif.
     */
    private function objet(int $type, ?string $referencePolice): string
    {
        $motif = $type === Note::TYPE_NOTE_DE_CREDIT ? 'Avoir sur commission' : 'Commission';

        return $referencePolice !== null && trim($referencePolice) !== ''
            ? sprintf('%s — Police %s', $motif, trim($referencePolice))
            : $motif;
    }

    /** @return list<int> */
    private static function destinatairesConnus(): array
    {
        return [Note::TO_CLIENT, Note::TO_ASSUREUR, Note::TO_PARTENAIRE, Note::TO_AUTORITE_FISCALE];
    }
}
