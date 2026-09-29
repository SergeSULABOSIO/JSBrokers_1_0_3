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
use App\Services\Canvas\Indicator\IndicatorCalculationHelper;
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
 *  - assureur / client  → la COMMISSION qui n'a pas encore été PORTÉE SUR UNE NOTE
 *    (cf. `montantFacturable()` : ce qui reste à facturer, et non ce qui reste à
 *    encaisser — la confusion des deux produisait une double facturation) ;
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
        // Le montant d'une ligne de note ne se lit pas : il se derive du revenu, de
        // la tranche et de la quantite. Une seule formule, celle que l'apercu et le
        // PDF appliquent deja.
        private readonly IndicatorCalculationHelper $calculs,
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
        int $type = Note::TYPE_NOTE_DE_DEBIT,
    ): array {
        return array_map(
            static fn (array $retenu): RevenuPourCourtier => $retenu['revenu'],
            $this->pesee($entreprise, $addressedTo, $cibleId, $tranche, $type)['retenus'],
        );
    }

    /**
     * CE QUI EST FACTURABLE, ET CE QUI NE L'EST PLUS — avec, pour chaque écarté, la
     * note qui le consomme.
     *
     * ── POURQUOI RENDRE AUSSI LES ÉCARTÉS ───────────────────────────────────────
     * Répondre « rien à facturer » à un courtier qui a l'échéance sous les yeux, et
     * dont l'écran annonce une commission exigible, est une énigme, pas une réponse.
     * Il lui faut la PIÈCE responsable : « déjà facturée par la note N… du 12/09 ».
     * Le service la connaît — c'est en parcourant les lignes qu'il a calculé le reste
     * —, il serait absurde de la jeter pour la faire rechercher ensuite.
     *
     * @return array{retenus: list<array{revenu: RevenuPourCourtier, montant: float}>,
     *               ecartes: list<array{revenu: RevenuPourCourtier, note: ?Note}>}
     */
    public function pesee(
        Entreprise $entreprise,
        int $addressedTo,
        ?int $cibleId = null,
        ?Tranche $tranche = null,
        int $type = Note::TYPE_NOTE_DE_DEBIT,
    ): array {
        // SANS DESTINATAIRE, ON NE PROPOSE RIEN. « Facturable » n'a pas de sens
        // dans l'absolu : c'est le destinataire qui dit quelle dette on regarde.
        if (!in_array($addressedTo, self::destinatairesConnus(), true)) {
            return ['retenus' => [], 'ecartes' => []];
        }

        $candidats = $this->candidats($entreprise, $addressedTo, $cibleId, $tranche);
        if ($candidats === []) {
            return ['retenus' => [], 'ecartes' => []];
        }

        $autorite = $addressedTo === Note::TO_AUTORITE_FISCALE && $cibleId !== null
            ? $this->em->getRepository(AutoriteFiscale::class)->find($cibleId)
            : null;

        $retenus = [];
        $ecartes = [];
        foreach ($candidats as $revenu) {
            $this->canvasBuilder->loadAllCalculatedValues($revenu);
            $montant = $this->montantFacturable($revenu, $addressedTo, $autorite, $type);

            if ($montant > self::SEUIL_SOLDE) {
                $retenus[] = ['revenu' => $revenu, 'montant' => round($montant, 2)];
                continue;
            }
            $ecartes[] = ['revenu' => $revenu, 'note' => $this->derniereNoteDe($revenu)];
        }

        return ['retenus' => $retenus, 'ecartes' => $ecartes];
    }

    /**
     * Ce qu'il reste à porter sur une note, pour CE destinataire et CE type.
     *
     * ── « FACTURABLE » NE VEUT PAS DIRE « IMPAYÉ » ──────────────────────────────
     * Cette règle lisait `solde_restant_du`, qui mesure ce qui n'a pas été ENCAISSÉ.
     * Une commission déjà facturée mais pas encore réglée gardait donc un solde
     * entier — et se proposait une seconde fois. Tant que la ligne se choisissait à
     * la main, cela passait ; un écran qui coche tout d'office en aurait fait une
     * double facturation en un clic.
     *
     * On compte donc ce qui a été FACTURÉ, pas ce qui a été payé.
     *
     * ── LE CRÉDIT EST LE MIROIR, ET IL N'EST PAS OPTIONNEL ──────────────────────
     * Un avoir n'annule pas ce qui reste à facturer : il annule ce qui l'a été. Sans
     * cette symétrie, un revenu intégralement facturé rendrait 0 pour un crédit — et
     * l'assistant, qui accepte `type: credit` depuis toujours, ne pourrait plus
     * produire aucun avoir. Ce n'est pas un ajout, c'est une non-régression.
     */
    private function montantFacturable(
        RevenuPourCourtier $revenu,
        int $addressedTo,
        ?AutoriteFiscale $autorite,
        int $type,
    ): float {
        // Les deux autres axes gardent leurs soldes propres : `retroCommissionSolde`
        // et les soldes de taxe tracent déjà le REVERSÉ, pas l'encaissé. Y toucher
        // serait une seconde correction, sans nécessité.
        if ($addressedTo === Note::TO_PARTENAIRE) {
            return (float) ($revenu->retroCommissionSolde ?? 0.0);
        }
        if ($addressedTo === Note::TO_AUTORITE_FISCALE) {
            // LE REDEVABLE DE LA TAXE DÉCIDE, jamais le type de note : une taxe sur la
            // commission due par le courtier et une taxe due par l'assureur ne se
            // reversent pas sur le même solde (cf. Taxe::REDEVABLE_*).
            return match ($autorite?->getTaxe()?->getRedevable()) {
                Taxe::REDEVABLE_COURTIER => (float) ($revenu->taxeCourtierSolde ?? 0.0),
                Taxe::REDEVABLE_ASSUREUR => (float) ($revenu->taxeAssureurSolde ?? 0.0),
                default => 0.0, // autorité inconnue ou sans taxe : on ne devine pas.
            };
        }

        // Assureur et client : la commission de courtage.
        $facture = $this->dejaFacture($revenu);

        return $type === Note::TYPE_NOTE_DE_CREDIT
            // On ne peut annuler que ce qu'on a émis.
            ? $facture
            // Ce qui n'a pas encore été porté sur une note.
            : (float) ($revenu->montantCalculeTTC ?? 0.0) - $facture;
    }

    /**
     * CE QUI A DÉJÀ ÉTÉ PORTÉ SUR UNE NOTE, tous états confondus.
     *
     * ── LES BROUILLONS COMPTENT, ET C'EST VOULU ─────────────────────────────────
     * On ne regarde pas `validated`. Une ligne qui existe, c'est un montant déjà
     * réclamé quelque part : le refacturer produirait deux pièces pour le même argent,
     * que quelqu'un ait appuyé sur « valider » ou non. Un brouillon est une note en
     * cours, pas une note nulle.
     *
     * Le risque symétrique — un brouillon oublié qui bloque une facturation légitime —
     * est visible et réparable : l'écran NOMME la note qui bloque, on la retrouve, on
     * la supprime. Le risque inverse, lui, se découvre chez l'assureur.
     *
     * ── UN AVOIR REND FACTURABLE ────────────────────────────────────────────────
     * Une ligne de note de CRÉDIT retranche : c'est exactement ce qu'un avoir fait.
     */
    private function dejaFacture(RevenuPourCourtier $revenu): float
    {
        $total = 0.0;
        foreach ($revenu->getArticles() as $article) {
            $note = $article->getNote();
            if (!$this->estUneNoteDeCommission($note)) {
                continue;
            }
            $montant = (float) $this->calculs->getArticleMontant($article);
            $total += $note->getType() === Note::TYPE_NOTE_DE_CREDIT ? -$montant : $montant;
        }

        return $total;
    }

    /**
     * La note la plus récente qui porte ce revenu — celle qu'on nomme au courtier
     * quand il n'y a plus rien à facturer. null si aucune.
     */
    private function derniereNoteDe(RevenuPourCourtier $revenu): ?Note
    {
        $derniere = null;
        foreach ($revenu->getArticles() as $article) {
            $note = $article->getNote();
            if (!$this->estUneNoteDeCommission($note)) {
                continue;
            }
            if ($derniere === null || (int) $note->getId() > (int) $derniere->getId()) {
                $derniere = $note;
            }
        }

        return $derniere;
    }

    /**
     * Une note de COMMISSION : adressée au client ou à l'assureur. Une note à un
     * partenaire ou à une autorité fiscale porte une rétrocommission ou une taxe —
     * la compter ici ferait disparaître une commission qui n'a jamais été réclamée.
     */
    private function estUneNoteDeCommission(?Note $note): bool
    {
        return $note !== null
            && in_array($note->getAddressedTo(), [Note::TO_CLIENT, Note::TO_ASSUREUR], true);
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
    /**
     * CE QU'IL Y A À FACTURER DANS UNE SÉLECTION D'ÉCHÉANCES, groupé par destinataire.
     *
     * ── POURQUOI GROUPER ────────────────────────────────────────────────────────
     * Une note a UN destinataire. Une sélection qui mêle deux assureurs ne peut donc
     * pas produire une seule pièce : on la sépare, l'écran en propose une à la fois
     * et annonce ce qui reste. Mélanger produirait une facture que personne ne peut
     * ni payer ni comptabiliser.
     *
     * Le groupe est indexé par la cible et ORDONNÉ PAR NOM : l'ordre doit être
     * déterministe, sinon deux ouvertures de la même sélection proposeraient des
     * assureurs différents, et le courtier ne saurait plus où il en est.
     *
     * @param list<int> $trancheIds les échéances cochées, déjà scopées par l'appelant
     *
     * @return list<array{cible: ?int, nom: string, lignes: list<array{trancheId: int,
     *         revenuId: int, libelle: string, police: string, echeance: string, montant: float}>,
     *         ecartes: list<array{police: string, noteId: ?int, noteReference: ?string, noteDate: ?string}>}>
     */
    public function facturableDansLaSelection(
        Entreprise $entreprise,
        array $tranches,
        int $addressedTo = Note::TO_ASSUREUR,
        int $type = Note::TYPE_NOTE_DE_DEBIT,
    ): array {
        $groupes = [];

        foreach ($tranches as $tranche) {
            if (!$tranche instanceof Tranche) {
                continue;
            }
            $cotation = $tranche->getCotation();
            $destinataire = $addressedTo === Note::TO_CLIENT
                ? $cotation?->getPiste()?->getClient()
                : $cotation?->getAssureur();

            // SANS DESTINATAIRE, PAS DE NOTE. Une police sans assureur enregistré ne
            // se facture pas : on ne devine pas à qui l'adresser.
            if ($destinataire === null) {
                continue;
            }

            $cle = (int) $destinataire->getId();
            $groupes[$cle] ??= [
                'cible' => $cle,
                'nom' => (string) $destinataire->getNom(),
                'lignes' => [],
                'ecartes' => [],
            ];

            $pesee = $this->pesee($entreprise, $addressedTo, $cle, $tranche, $type);
            $police = $this->policeDe($tranche);

            foreach ($pesee['retenus'] as $retenu) {
                $groupes[$cle]['lignes'][] = [
                    'trancheId' => (int) $tranche->getId(),
                    'revenuId' => (int) $retenu['revenu']->getId(),
                    'libelle' => (string) $retenu['revenu']->getNom(),
                    'police' => $police,
                    'echeance' => $tranche->getEcheanceAt()?->format('d/m/Y') ?? '',
                    'montant' => $retenu['montant'],
                ];
            }

            // CE QUI EST ÉCARTÉ SE DIT, AVEC LA PIÈCE QUI LE RETIENT. « Rien à
            // facturer » devant une échéance dont l'écran annonce une commission
            // exigible est une énigme, pas une réponse.
            foreach ($pesee['ecartes'] as $ecarte) {
                $note = $ecarte['note'];
                $groupes[$cle]['ecartes'][] = [
                    'police' => $police,
                    'noteId' => $note?->getId(),
                    'noteReference' => $note?->getReference(),
                    'noteDate' => $note?->getSentAt()?->format('d/m/Y'),
                ];
            }
        }

        // Ordre déterministe : le nom du destinataire, pas l'ordre de la sélection.
        $liste = array_values($groupes);
        usort($liste, static fn (array $a, array $b): int => strcasecmp($a['nom'], $b['nom']));

        return $liste;
    }

    /** La référence de police d'une échéance — ce que le courtier lit sur sa liste. */
    private function policeDe(Tranche $tranche): string
    {
        $cotation = $tranche->getCotation();
        $avenant = $cotation !== null && !$cotation->getAvenants()->isEmpty()
            ? $cotation->getAvenants()->first()
            : null;

        return (string) ($avenant?->getReferencePolice() ?? $tranche->getNom() ?? '');
    }

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
