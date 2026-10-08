<?php

namespace App\Echange\Etat;

use App\Entity\Article;
use App\Entity\Bordereau;
use App\Entity\CompteBancaire;
use App\Entity\Note;
use App\Services\Canvas\Indicator\IndicatorCalculationHelper;
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

    public function __construct(
        // Lui seul sait quels bordereaux ont fait rentrer de l'argent sur une tranche :
        // cette attribution est le fruit d'une imputation délicate, mémoïsée par cabinet.
        private readonly IndicatorCalculationHelper $helper,
    ) {
    }

    /**
     * Règlements de prime.
     *
     * ⚠ TROIS CIRCUITS, COMME LE MONTANT — et c'est tout l'enjeu, exactement comme pour
     * la commission juste en dessous. Une prime est réputée payée de trois façons
     * (`getTranchePrimePayee`) : par un PAIEMENT DE PRIME SIGNALÉ, porté par la tranche ;
     * par une FACTURE CLIENT encaissée, portée par les articles ; ou par un BORDEREAU de
     * production réconcilié, dans lequel l'assureur déclare détenir la prime.
     *
     * Ce fichier n'en lisait qu'un. Conséquence : une affaire dont la prime était réglée
     * par facture ou attestée par bordereau affichait un montant payé et une colonne
     * « payée le » VIDE — un solde qui s'affirme sans pièce en face, précisément ce que
     * l'en-tête de ce fichier existe pour empêcher. Le même angle mort a fait répondre à
     * l'assistant, le 2026-09-25, qu'aucune date n'était associée à un règlement.
     *
     * Le bordereau donne une date d'ATTESTATION, pas de règlement ; il n'est donc retenu
     * qu'en dernier ressort, quand aucune pièce datée à la main n'existe.
     *
     * @return array{date: ?\DateTimeImmutable, references: string}
     */
    public function prime(Tranche $tranche): array
    {
        $dates = [];
        $references = [];

        // ── CIRCUIT 1 : le signalement, déclaratif, porté par la tranche ────────────
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

        // ── CIRCUIT 2 : la facture CLIENT encaissée ────────────────────────────────
        // ⚠ MIROIR de la première boucle de `getTranchePrimePayee` : notes adressées au
        // CLIENT, et elles seules. Une note à l'ASSUREUR ou à l'AUTORITÉ FISCALE règle
        // une commission ou une taxe — jamais la prime de l'assuré.
        foreach ($this->notesDe($tranche) as $note) {
            if ($note->getAddressedTo() !== Note::TO_CLIENT) {
                continue;
            }
            foreach ($note->getPaiements() as $paiement) {
                $date = $paiement->getPaidAt();
                if ($date !== null) {
                    $dates[] = $date;
                }
                // La référence de la FACTURE d'abord, celle du règlement en repli — même
                // ordre que la colonne « références de facture » de la commission.
                $reference = trim((string) ($note->getReference() ?: $paiement->getReference()));
                if ($reference !== '') {
                    $references[] = $reference;
                }
            }
        }

        // ── CIRCUIT 3 : l'attestation de l'assureur, en DERNIER RESSORT ────────────
        // Une date de réception de bordereau ne vaut pas un reçu : on ne l'affiche que
        // lorsque rien d'autre ne date ce règlement, et sa référence dit d'où elle vient.
        if ($dates === []) {
            foreach ($this->helper->getBordereauxAttestantTranche($tranche) as $bordereau) {
                $date = $bordereau->getReceivedAt() ?? $bordereau->getPeriodeFin();
                if ($date !== null) {
                    $dates[] = $date;
                }
                $reference = trim((string) ($bordereau->getReference() ?: $bordereau->getNom()));
                if ($reference !== '') {
                    $references[] = 'Bordereau ' . $reference;
                }
            }
        }

        return $this->assembler($dates, $references);
    }

    /**
     * Encaissements de commission.
     *
     * ⚠ DEUX CIRCUITS, ET C'EST TOUT L'ENJEU.
     *
     * Une commission se facture par les ARTICLES d'une note — seules comptent alors les
     * notes adressées au CLIENT ou à l'ASSUREUR dont l'article facture un revenu, sans
     * quoi un règlement de taxe passerait pour un encaissement de commission. Mais elle
     * s'encaisse tout aussi bien par BORDEREAU, dont les notes liées portent les
     * paiements et dont la note ne porte souvent AUCUN article.
     *
     * Ne lire que le premier circuit laissait « payée le », « références de facture » et
     * « compte bancaire » vides sur toute affaire réglée par bordereau : un montant
     * encaissé, et pas une pièce en face. On réunit donc les notes des deux, dédoublonnées
     * — les deux chemins décrivent le même argent, le helper les réconcilie d'ailleurs
     * par un `max()`.
     *
     * @return array{date: ?\DateTimeImmutable, references: string, comptes: string, bordereaux: string}
     */
    public function commission(Tranche $tranche): array
    {
        $dates = [];
        $references = [];
        $comptes = [];

        // ⚠ LE BORDEREAU EST UNE PIÈCE À PART ENTIÈRE, et il mérite sa colonne : c'est
        // par lui qu'un courtier retrouve ce que l'assureur a déclaré régler, police par
        // police. Le mêler aux références de facture aurait fondu deux notions dans une
        // case — un bordereau n'est pas une facture, il en porte plusieurs.
        $bordereaux = [];
        foreach ($this->helper->getBordereauxCouvrantTranche($tranche) as $bordereau) {
            $reference = trim((string) ($bordereau->getReference() ?: $bordereau->getNom()));
            if ($reference !== '') {
                $bordereaux[] = $reference;
            }
        }

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
        $assemble['bordereaux'] = $this->lister($bordereaux);

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
     * @param bool $coteAgent true = versements aux agents internes ; false = aux partenaires
     *
     * @return array{date: ?\DateTimeImmutable, references: string, lots: string, comptes: string}
     */
    public function retro(Tranche $tranche, bool $coteAgent): array
    {
        $dates = [];
        $references = [];
        $lots = [];
        $comptes = [];

        foreach ($tranche->getReversementsRetroAgent() as $reversement) {
            $estAgent = $reversement->getAgent() !== null;
            if ($estAgent !== $coteAgent) {
                continue;
            }

            $date = $reversement->getPaidAt();
            if ($date !== null) {
                $dates[] = $date;
            }

            // ⚠ DEUX NOTIONS, DEUX COLONNES. La référence désigne LE VIREMENT — c'est
            // elle qu'on cherche sur un relevé bancaire ; le lot désigne l'ORDRE DE
            // PAIEMENT qui en regroupe plusieurs — c'est lui qu'on cherche dans le
            // dossier de reversement. Les fondre dans une case, avec repli de l'une sur
            // l'autre, aurait rendu la colonne intotalisable et surtout ambiguë : on ne
            // saurait plus, en la lisant, laquelle des deux on tient.
            $reference = trim((string) $reversement->getReference());
            if ($reference !== '') {
                $references[] = $reference;
            }
            $lot = trim((string) $reversement->getLotReference());
            if ($lot !== '') {
                $lots[] = $lot;
            }

            $compte = $reversement->getCompteBancaire();
            if ($compte !== null) {
                $comptes[] = trim(sprintf('%s %s', (string) $compte->getIntitule(), (string) $compte->getBanque()));
            }
        }

        // ⚠ LE FILTRE XOR VAUT POUR LES TROIS COLONNES, pas seulement pour la date : une
        // référence de partenaire posée en face du solde d'un agent serait aussi fausse,
        // et bien plus difficile à repérer qu'un montant.
        $assemble = $this->assembler($dates, $references);
        $assemble['lots'] = $this->lister($lots);
        $assemble['comptes'] = $this->lister($comptes);

        return $assemble;
    }

    // ═══════════════════════ LES MÊMES PIÈCES, EN LIGNES ═══════════════════════════
    //
    // Le relevé d'une tranche (onglets financiers de sa fiche) montre chaque règlement
    // sur sa ligne, au lieu d'une date et d'une liste de références. Mêmes parcours, mêmes
    // filtres que ci-dessus — et que le helper : c'est ce qui permet au pied d'un onglet
    // d'égaler, au centime, le « payé » de la fiche.
    //
    // ⚠ LA PART, PAS LE BRUT. Un règlement porte sur une NOTE, qui couvre souvent
    // plusieurs tranches. La ligne montre la part imputée à celle-ci, par le prorata du
    // helper : règlement × (articles de la tranche ÷ montant payable de la note). Sommée sur
    // les règlements, elle redonne exactement (payé ÷ payable) × articles. Le brut reste
    // lisible à côté.

    /**
     * Règlements de prime, une ligne chacun — les trois circuits de `prime()`.
     *
     * @return list<MouvementDeReglement>
     */
    public function lignesPrime(Tranche $tranche): array
    {
        $lignes = [];

        // ── CIRCUIT 1 : les signalements, portés par la tranche ────────────────────
        foreach ($tranche->getPaiementsPrime() as $paiement) {
            $lignes[] = new MouvementDeReglement(
                MouvementDeReglement::NATURE_SIGNALEMENT,
                'Paiement de prime signalé',
                $paiement->getPaidAt(),
                $this->texte($paiement->getReference()),
                (float) ($paiement->getMontant() ?? 0.0),
                pieceEntite: 'PaiementPrime',
                pieceId: $paiement->getId(),
            );
        }

        // ── CIRCUIT 2 : les factures CLIENT — miroir de la 1re boucle du helper ─────
        array_push($lignes, ...$this->partsDesNotes(
            $tranche,
            static fn (Note $note): bool => $note->getAddressedTo() === Note::TO_CLIENT,
            static fn (Article $article): bool => true,
            MouvementDeReglement::NATURE_FACTURE,
        ));

        // ── CIRCUIT 3 : l'inférence, et seulement ce qu'elle AJOUTE ──────────────────
        // Le helper relève la prime payée au niveau de la prime due (`max`) quand la
        // commission de l'assureur est soldée ou qu'un bordereau atteste la police. Ce
        // complément n'a pas de pièce derrière lui : il se montre INFÉRÉ.
        $commissionSoldee = $this->helper->isTrancheCommissionAssureurSoldee($tranche);
        if ($commissionSoldee || $this->helper->isTrancheCouverteParBordereau($tranche)) {
            $prime = $this->helper->getCotationMontantPrimePayableParClient($tranche->getCotation())
                * $this->helper->getTrancheTauxFactor($tranche);
            $complement = $prime - $this->somme($lignes);
            if ($complement > 0.0) {
                $bordereaux = $this->helper->getBordereauxAttestantTranche($tranche);
                $references = $this->references($bordereaux);
                $lignes[] = new MouvementDeReglement(
                    MouvementDeReglement::NATURE_INFEREE,
                    $bordereaux !== [] ? 'Prime attestée par bordereau' : 'Prime réputée payée : commission de l\'assureur soldée',
                    $this->derniereReception($bordereaux),
                    $references !== '' ? $references : null,
                    $complement,
                );
            }
        }

        return $lignes;
    }

    /**
     * Encaissements de commission, une ligne chacun — les deux circuits de `commission()`.
     *
     * @return list<MouvementDeReglement>
     */
    public function lignesCommission(Tranche $tranche): array
    {
        // ── CIRCUIT 1 : la facture d'articles (CLIENT ou ASSUREUR, article facturant un revenu)
        $lignes = $this->partsDesNotes(
            $tranche,
            static fn (Note $note): bool => \in_array($note->getAddressedTo(), [Note::TO_ASSUREUR, Note::TO_CLIENT], true),
            static fn (Article $article): bool => $article->getRevenuFacture() !== null,
            MouvementDeReglement::NATURE_FACTURE,
        );

        // ── CIRCUIT 2 : le bordereau, par imputation sur les plus anciennes ─────────
        // `max()` dans le helper : les deux circuits décrivent le même argent. Seul ce que
        // l'imputation ajoute au-delà des factures paraît, en une ligne INFÉRÉE — c'est le
        // fruit d'une règle de calcul, pas un règlement qu'on retrouverait sur un relevé.
        $complement = $this->helper->getTrancheCommissionAllouee($tranche) - $this->somme($lignes);
        if ($complement > 0.0) {
            $bordereaux = $this->helper->getBordereauxCouvrantTranche($tranche);
            $dates = [];
            foreach ($bordereaux as $bordereau) {
                foreach ($bordereau->getNotes() as $note) {
                    foreach ($note->getPaiements() as $paiement) {
                        if ($paiement->getPaidAt() !== null) {
                            $dates[] = $paiement->getPaidAt();
                        }
                    }
                }
            }
            $references = $this->references($bordereaux);
            $lignes[] = new MouvementDeReglement(
                MouvementDeReglement::NATURE_INFEREE,
                'Imputation du règlement de bordereau',
                $this->assembler($dates, [])['date'],
                $references !== '' ? 'Bordereau ' . $references : null,
                $complement,
            );
        }

        return $lignes;
    }

    /**
     * Règlements d'une taxe sur la commission, pour un redevable — le miroir de `taxe()`.
     *
     * @param int $redevable une constante `Taxe::REDEVABLE_*`
     *
     * @return list<MouvementDeReglement>
     */
    public function lignesTaxe(Tranche $tranche, int $redevable): array
    {
        return $this->partsDesNotes(
            $tranche,
            static fn (Note $note): bool => $note->getAddressedTo() === Note::TO_AUTORITE_FISCALE
                && $note->getAutoritefiscale()?->getTaxe()?->getRedevable() === $redevable,
            static fn (Article $article): bool => true,
            MouvementDeReglement::NATURE_TAXE,
        );
    }

    /**
     * Versements de rétrocommission, une ligne chacun — le filtre XOR de `retro()`.
     *
     * @return list<MouvementDeReglement>
     */
    public function lignesRetro(Tranche $tranche, bool $coteAgent): array
    {
        $lignes = [];
        foreach ($tranche->getReversementsRetroAgent() as $reversement) {
            // Le XOR du helper : côté agent, `agent` renseigné ; côté partenaire,
            // `partenaire` renseigné. Un versement sans l'un ni l'autre ne compte nulle part.
            $retenu = $coteAgent ? $reversement->getAgent() !== null : $reversement->getPartenaire() !== null;
            if (!$retenu) {
                continue;
            }
            $lignes[] = new MouvementDeReglement(
                MouvementDeReglement::NATURE_VIREMENT,
                'Versement à ' . $reversement->beneficiaireNom(),
                $reversement->getPaidAt(),
                $this->texte($reversement->getReference()),
                (float) ($reversement->getMontant() ?? 0.0),
                compte: $this->compte($reversement->getCompteBancaire()),
                lot: $this->texte($reversement->getLotReference()),
                pieceEntite: 'ReversementRetroAgent',
                pieceId: $reversement->getId(),
            );
        }

        return $lignes;
    }

    /**
     * Une ligne par règlement des notes retenues, à la part de la tranche.
     *
     * ⚠ DEUX FILTRES, SUR DEUX OBJETS — comme dans le helper : le destinataire est une
     * propriété de la NOTE, « facture un revenu » une propriété de l'ARTICLE.
     *
     * ⚠ AUCUNE DIVISION PAR ZÉRO. Une note dont le payable est nul (ou négatif) n'impute
     * rien dans le helper (`if ($montantPayableNote > 0)`) ; son règlement est montré à
     * part nulle et marqué non imputable, plutôt que de disparaître.
     *
     * @param callable(Note): bool    $noteRetenue
     * @param callable(Article): bool $articleRetenu
     *
     * @return list<MouvementDeReglement>
     */
    private function partsDesNotes(Tranche $tranche, callable $noteRetenue, callable $articleRetenu, string $nature): array
    {
        $parNote = [];
        foreach ($tranche->getArticles() as $article) {
            $note = $article->getNote();
            if ($note === null || !$noteRetenue($note) || !$articleRetenu($article)) {
                continue;
            }
            $cle = spl_object_id($note);
            $parNote[$cle] ??= ['note' => $note, 'articles' => 0.0];
            $parNote[$cle]['articles'] += $this->helper->getArticleMontant($article);
        }

        $lignes = [];
        foreach ($parNote as ['note' => $note, 'articles' => $articles]) {
            $payable = $this->helper->getNoteMontantPayable($note);
            $imputable = $payable > 0.0;
            $part = $imputable ? $articles / $payable : 0.0;
            $libelle = 'Note ' . ($this->texte($note->getReference()) ?? ('#' . $note->getId()));
            foreach ($note->getPaiements() as $paiement) {
                $brut = (float) ($paiement->getMontant() ?? 0.0);
                $lignes[] = new MouvementDeReglement(
                    $nature,
                    $libelle,
                    $paiement->getPaidAt(),
                    $this->texte($paiement->getReference()),
                    $brut * $part,
                    montantBrut: $brut,
                    compte: $this->compte($paiement->getCompteBancaire()),
                    pieceEntite: 'Note',
                    pieceId: $note->getId(),
                    imputable: $imputable,
                );
            }
        }

        return $lignes;
    }

    /** @param list<MouvementDeReglement> $lignes */
    private function somme(array $lignes): float
    {
        return array_sum(array_map(static fn (MouvementDeReglement $l): float => $l->montant, $lignes));
    }

    /** @param Bordereau[] $bordereaux */
    private function references(array $bordereaux): string
    {
        return $this->lister(array_map(
            static fn (Bordereau $b): string => trim((string) ($b->getReference() ?: $b->getNom())),
            $bordereaux,
        ));
    }

    /** @param Bordereau[] $bordereaux */
    private function derniereReception(array $bordereaux): ?\DateTimeImmutable
    {
        $dates = array_filter(array_map(static fn (Bordereau $b) => $b->getReceivedAt() ?? $b->getPeriodeFin(), $bordereaux));

        return $this->assembler(array_values($dates), [])['date'];
    }

    private function texte(?string $valeur): ?string
    {
        $valeur = trim((string) $valeur);

        return $valeur === '' ? null : $valeur;
    }

    private function compte(?CompteBancaire $compte): ?string
    {
        return $compte === null ? null : trim(sprintf('%s %s', (string) $compte->getIntitule(), (string) $compte->getBanque()));
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

        // ── CIRCUIT 1 : la facture d'articles ───────────────────────────────────────
        // ⚠ DEUX FILTRES, ET ILS NE PORTENT PAS SUR LE MÊME OBJET. Le destinataire est
        // une propriété de la NOTE ; « facture un revenu » est une propriété de
        // l'ARTICLE. Le helper les applique ensemble, et c'est ce qui empêche de compter
        // le règlement d'une taxe ou d'une rétrocession comme un encaissement.
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

        // ── CIRCUIT 2 : le bordereau ────────────────────────────────────────────────
        // La note d'un bordereau ne porte souvent AUCUN article : le circuit 1 ne la voit
        // pas. C'est le helper qui dit quels bordereaux ont réellement fait rentrer de
        // l'argent sur CETTE tranche — jamais une recherche refaite ici, l'imputation
        // étant la règle la plus délicate du calcul d'encaissement.
        foreach ($this->helper->getBordereauxCouvrantTranche($tranche) as $bordereau) {
            foreach ($bordereau->getNotes() as $note) {
                $notes[spl_object_id($note)] = $note;
            }
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
