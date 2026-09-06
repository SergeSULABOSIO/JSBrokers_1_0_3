<?php

namespace App\Echange\Etat;

use App\Entity\Note;
use App\Entity\Tranche;

/**
 * LES PIÈCES QUI ONT SOLDÉ UNE TRANCHE : dates, références, comptes.
 *
 * L'état du portefeuille pose, à côté de chaque montant payé, la date du dernier
 * mouvement et les références qui le justifient. Sans elles, un solde s'affirme ; avec
 * elles, il se vérifie — on retrouve la pièce, la banque, le bordereau.
 *
 * ── LA RÈGLE QUI GOUVERNE TOUT CE FICHIER ───────────────────────────────────────────
 * ⚠ ON EMPRUNTE LE CHEMIN QUI CALCULE DÉJÀ LE MONTANT, JAMAIS UN AUTRE.
 *
 * Chaque famille de règlement a, dans IndicatorCalculationHelper, un parcours et des
 * filtres précis — et ces filtres ne sont pas cosmétiques : ils portent des corrections
 * d'incidents. Une commission encaissée ne compte que les notes adressées au CLIENT ou à
 * l'ASSUREUR dont l'article facture un revenu ; une taxe ne compte que les notes
 * adressées à l'AUTORITÉ FISCALE, et seulement celles du redevable visé ; un versement
 * d'agent ne compte que les reversements dont l'agent est renseigné.
 *
 * Refaire ces parcours « à peu près » produirait une référence en face d'un montant
 * qu'elle ne justifie pas — l'erreur la plus difficile à voir, parce que les deux
 * cellules sont plausibles séparément. Les méthodes ci-dessous MIROITENT donc les
 * filtres du helper, et chacune dit lequel.
 *
 * ── PLUSIEURS RÈGLEMENTS, UNE SEULE LIGNE ───────────────────────────────────────────
 * Une tranche se règle souvent en plusieurs fois. On rend donc :
 *   — la date du DERNIER mouvement, qui répond à « quand cela a-t-il fini d'être payé »,
 *     la question qu'on se pose devant un solde ;
 *   — TOUTES les références, listées : c'est du texte, et l'on y retrouve chaque pièce.
 * Les libellés de colonnes portent cette règle, pour que personne n'ait à la deviner.
 */
final class PiecesDeReglement
{
    /** Séparateur des références multiples. Le point-virgule survit à l'ouverture en CSV. */
    public const SEPARATEUR = ' ; ';

    /**
     * Règlements de prime — déclaratifs, portés directement par la tranche.
     *
     * @return array{date: ?\DateTimeImmutable, references: string}
     */
    public function prime(Tranche $tranche): array
    {
        $dates = [];
        $references = [];

        foreach ($tranche->getPaiementsPrime() as $paiement) {
            $date = $paiement->getPaidAt();
            if ($date !== null) {
                $dates[] = $date;
            }
            $reference = trim((string) $paiement->getReference());
            if ($reference !== '') {
                $references[] = $reference;
            }
        }

        return $this->assembler($dates, $references);
    }

    /**
     * Encaissements de commission.
     *
     * ⚠ MIROIR de `getTrancheMontantCommissionEncaissee` : seules comptent les notes
     * adressées au CLIENT ou à l'ASSUREUR dont l'article facture un revenu. Sans ce
     * filtre, un règlement de taxe ou de rétrocession serait présenté comme un
     * encaissement de commission — c'est la correction que le helper porte déjà.
     *
     * @return array{date: ?\DateTimeImmutable, references: string, comptes: string}
     */
    public function commission(Tranche $tranche): array
    {
        $dates = [];
        $references = [];
        $comptes = [];

        foreach ($this->notesDeCommission($tranche) as $note) {
            foreach ($note->getPaiements() as $paiement) {
                $date = $paiement->getPaidAt();
                if ($date !== null) {
                    $dates[] = $date;
                }

                // La référence de la FACTURE d'abord : c'est elle qu'on cherche dans le
                // classeur du cabinet. Celle du paiement ne sert que si la note n'en a pas.
                $reference = trim((string) ($note->getReference() ?: $paiement->getReference()));
                if ($reference !== '') {
                    $references[] = $reference;
                }

                $compte = $paiement->getCompteBancaire();
                if ($compte !== null) {
                    $comptes[] = trim(sprintf(
                        '%s %s',
                        (string) $compte->getIntitule(),
                        (string) $compte->getBanque(),
                    ));
                }
            }
        }

        $assemble = $this->assembler($dates, $references);
        $assemble['comptes'] = $this->lister($comptes);

        return $assemble;
    }

    /**
     * Règlements d'une taxe SUR LA COMMISSION, pour un redevable donné.
     *
     * ⚠ MIROIR de `getTrancheMontantTaxePayee` : notes adressées à l'AUTORITÉ FISCALE,
     * et taxe du redevable visé — le courtier et l'assureur ne règlent pas la même.
     *
     * @param int $redevable une constante `Taxe::REDEVABLE_*` (0 courtier, 1 assureur)
     *
     * @return array{date: ?\DateTimeImmutable, references: string}
     */
    public function taxe(Tranche $tranche, int $redevable): array
    {
        $dates = [];
        $references = [];

        foreach ($this->notesDe($tranche) as $note) {
            if ($note->getAddressedTo() !== Note::TO_AUTORITE_FISCALE) {
                continue;
            }

            $taxe = $note->getAutoritefiscale()?->getTaxe();
            if ($taxe === null || $taxe->getRedevable() !== $redevable) {
                continue;
            }

            foreach ($note->getPaiements() as $paiement) {
                $date = $paiement->getPaidAt();
                if ($date !== null) {
                    $dates[] = $date;
                }
                $reference = trim((string) ($paiement->getReference() ?: $note->getReference()));
                if ($reference !== '') {
                    $references[] = $reference;
                }
            }
        }

        return $this->assembler($dates, $references);
    }

    /**
     * Versements de rétrocommission, pour une famille de bénéficiaire.
     *
     * ⚠ LES DEUX FAMILLES VIVENT SUR LE MÊME ENREGISTREMENT, en XOR : c'est le champ
     * rempli — `agent` ou `partenaire` — qui les sépare. Le helper porte la trace de
     * l'incident : sans la garde sur `getAgent()`, un versement de PARTENAIRE gonflait le
     * versé des AGENTS « dans toutes les vues du cabinet, et sans jamais lever d'erreur ».
     * Une date pêchée sans ce filtre afficherait le paiement d'un partenaire en face du
     * solde d'un agent.
     *
     * @param bool $cotéAgent true = versements aux agents internes ; false = aux partenaires
     *
     * @return array{date: ?\DateTimeImmutable, references: string}
     */
    public function retro(Tranche $tranche, bool $coteAgent): array
    {
        $dates = [];
        $references = [];

        foreach ($tranche->getReversementsRetroAgent() as $reversement) {
            $estAgent = $reversement->getAgent() !== null;
            if ($estAgent !== $coteAgent) {
                continue;
            }

            $date = $reversement->getPaidAt();
            if ($date !== null) {
                $dates[] = $date;
            }
            $reference = trim((string) ($reversement->getReference() ?: $reversement->getLotReference()));
            if ($reference !== '') {
                $references[] = $reference;
            }
        }

        return $this->assembler($dates, $references);
    }

    /**
     * Les notes atteintes depuis les articles de la tranche, dédoublonnées.
     *
     * Une même note porte souvent plusieurs articles de la même tranche : sans ce
     * dédoublonnage, sa référence apparaîtrait autant de fois qu'elle a de lignes.
     *
     * @return Note[]
     */
    private function notesDe(Tranche $tranche): array
    {
        $notes = [];
        foreach ($tranche->getArticles() as $article) {
            $note = $article->getNote();
            if ($note !== null) {
                $notes[spl_object_id($note)] = $note;
            }
        }

        return array_values($notes);
    }

    /**
     * Les notes qui valent ENCAISSEMENT DE COMMISSION.
     *
     * ⚠ DEUX FILTRES, ET ILS NE PORTENT PAS SUR LE MÊME OBJET. Le destinataire est une
     * propriété de la NOTE ; « facture un revenu » est une propriété de l'ARTICLE. Le
     * helper les applique ensemble, et c'est ce qui empêche de compter le règlement d'une
     * taxe ou d'une rétrocession comme un encaissement de commission.
     *
     * @return Note[]
     */
    private function notesDeCommission(Tranche $tranche): array
    {
        $notes = [];
        foreach ($tranche->getArticles() as $article) {
            $note = $article->getNote();
            if ($note === null || $article->getRevenuFacture() === null) {
                continue;
            }
            if (!\in_array($note->getAddressedTo(), [Note::TO_ASSUREUR, Note::TO_CLIENT], true)) {
                continue;
            }
            $notes[spl_object_id($note)] = $note;
        }

        return array_values($notes);
    }

    /**
     * @param \DateTimeImmutable[] $dates
     * @param string[]             $references
     *
     * @return array{date: ?\DateTimeImmutable, references: string}
     */
    private function assembler(array $dates, array $references): array
    {
        $derniere = null;
        foreach ($dates as $date) {
            if ($derniere === null || $date > $derniere) {
                $derniere = $date;
            }
        }

        return ['date' => $derniere, 'references' => $this->lister($references)];
    }

    /** @param string[] $valeurs */
    private function lister(array $valeurs): string
    {
        $uniques = array_values(array_unique(array_filter($valeurs, static fn (string $v) => $v !== '')));

        return implode(self::SEPARATEUR, $uniques);
    }
}
