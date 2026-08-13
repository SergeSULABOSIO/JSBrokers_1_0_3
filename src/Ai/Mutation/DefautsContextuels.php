<?php

namespace App\Ai\Mutation;

/**
 * CE QUE LE DOSSIER DIT DÉJÀ — les champs obligatoires qu'on peut DÉDUIRE au lieu
 * de les demander.
 *
 * POURQUOI CE SERVICE EXISTE (2026-08-12). Sur un dossier d'assurance voyage
 * complet — contrat joint, client, risque, période, prime, police —, Ket a réclamé
 * cinq précisions de plus : le nom à donner à la piste, le type d'avenant, la
 * description du risque, le nom et la durée de la proposition. Toutes étaient
 * déductibles, et Ket venait elle-même d'en proposer la plupart. L'utilisateur a
 * eu le sentiment, légitime, de répéter ce qu'il avait déjà donné.
 *
 * Le mécanisme de VALEURS PAR DÉFAUT existait déjà (inventaire → « defaut », que le
 * modèle doit appliquer et annoncer), mais il ne sait lire qu'un défaut STATIQUE :
 * l'option `data` d'un formulaire, la valeur initiale d'une entité, le `default`
 * d'une colonne. Or « le nom de la piste » n'est pas une constante — il se DÉDUIT du
 * client et du risque de cette piste-là. D'où ce complément, contextuel, appliqué au
 * plan avant sa présentation.
 *
 * DEUX RÈGLES QUI NE SE NÉGOCIENT PAS :
 *  1. ON NE DEVINE JAMAIS. Un défaut n'est posé que si sa SOURCE est présente dans
 *     le plan. Sans source, le champ reste manquant et Ket pose la question — c'est
 *     très exactement ce qu'on veut : mieux vaut une question qu'une donnée fausse
 *     dans le dossier d'un client.
 *  2. ON NE POSE JAMAIS EN SILENCE. Les valeurs déduites entrent dans les champs de
 *     l'opération, donc dans le tableau du plan que l'utilisateur relit avant de
 *     valider, et la liste des déductions est rendue à part pour être annoncée.
 *     Un défaut invisible serait une écriture qu'on n'a pas montrée.
 */
final class DefautsContextuels
{
    /**
     * Applique les déductions au plan et rend le plan complété + ce qui a été déduit.
     *
     * @return array{plan: MutationPlan, defauts: list<string>}
     */
    public function appliquer(MutationPlan $plan): array
    {
        $defauts = [];
        // Index des créations par étiquette : « @client » doit pouvoir retrouver le
        // nom du client créé plus haut dans le MÊME plan.
        $parRef = [];
        foreach ($plan->operations as $op) {
            if ($op->ref !== null && $op->ref !== '') {
                $parRef[$op->ref] = $op;
            }
        }

        $operations = [];
        foreach ($plan->operations as $op) {
            $complete = $op->isCreate()
                ? $this->completer($op, $op->entityShortName, $parRef, $defauts)
                : $op;
            // Le registre suit les opérations COMPLÉTÉES : le nom d'une piste déduit
            // à l'étape 3 doit être lisible par la proposition de l'étape 4, qui y
            // renvoie par « @piste ». Sans cela, la déduction s'arrêterait au premier
            // maillon de la chaîne.
            if ($complete->ref !== null && $complete->ref !== '') {
                $parRef[$complete->ref] = $complete;
            }
            $operations[] = $complete;
        }

        return ['plan' => new MutationPlan($operations), 'defauts' => $defauts];
    }

    /**
     * L'entité d'un ENFANT de collection n'est pas dictée : le serveur la dérive du
     * FormType parent, bien plus tard. À ce stade son `entityShortName` est vide —
     * c'est donc le NOM DE LA COLLECTION qui dit de quoi il s'agit.
     */
    private const ENTITE_PAR_COLLECTION = [
        'avenants' => 'Avenant',
    ];

    /**
     * @param array<string, MutationOperation> $parRef
     * @param list<string>                     $defauts
     */
    private function completer(
        MutationOperation $op,
        string $entite,
        array $parRef,
        array &$defauts,
    ): MutationOperation {
        // D'ABORD les enfants : la durée d'une proposition se lit sur la période de
        // SON avenant, il faut donc que l'avenant soit complété (et lisible) avant.
        $collections = [];
        $changeEnfant = false;
        foreach ($op->collections as $nom => $enfants) {
            $entiteEnfant = self::ENTITE_PAR_COLLECTION[$nom] ?? '';
            $collections[$nom] = [];
            foreach ($enfants as $enfant) {
                $completeEnfant = ($entiteEnfant !== '' && $enfant->isCreate())
                    ? $this->completer($enfant, $entiteEnfant, $parRef, $defauts)
                    : $enfant;
                $changeEnfant = $changeEnfant || $completeEnfant !== $enfant;
                $collections[$nom][] = $completeEnfant;
            }
        }
        if ($changeEnfant) {
            $op = new MutationOperation(
                op: $op->op,
                entityShortName: $op->entityShortName,
                targetId: $op->targetId,
                fields: $op->fields,
                collections: $collections,
                ref: $op->ref,
                etape: $op->etape,
            );
        }

        $champs = $op->fields;

        foreach ($this->deductions($entite) as $champ => $deduire) {
            if ($this->estFourni($champs, $champ)) {
                continue;
            }
            $valeur = $deduire($op, $parRef);
            if ($valeur === null || $valeur === '') {
                continue; // source absente : on laisse le champ MANQUANT, et Ket demandera.
            }
            $champs[$champ] = $valeur;
            $defauts[] = sprintf('%s — « %s » : %s (déduit du dossier).', $entite, $champ, $valeur);
        }

        if ($champs === $op->fields) {
            return $op;
        }

        return new MutationOperation(
            op: $op->op,
            entityShortName: $op->entityShortName,
            targetId: $op->targetId,
            fields: $champs,
            collections: $op->collections,
            ref: $op->ref,
            etape: $op->etape,
        );
    }

    /**
     * Les règles de déduction, par entité. Chacune ne lit que le plan : jamais la
     * base, jamais une convention inventée.
     *
     * @return array<string, callable(MutationOperation, array<string, MutationOperation>): (string|int|null)>
     */
    private function deductions(string $entite): array
    {
        return match ($entite) {
            'Piste' => [
                // « Voyage — Mr. Jean de Dieu » : le risque dit QUOI, le client POUR QUI.
                'nom' => fn (MutationOperation $op, array $refs) => $this->joindre([
                    $this->nomDe($op->fields['risque'] ?? null, $refs),
                    $this->nomDe($op->fields['client'] ?? null, $refs),
                ], ' — '),
                // La description du risque, à défaut de mieux, EST le risque.
                'descriptionDuRisque' => fn (MutationOperation $op, array $refs)
                    => $this->nomDe($op->fields['risque'] ?? null, $refs),
            ],
            'Cotation' => [
                // « SUNU — Voyage » : l'assureur qui porte, l'objet couvert.
                'nom' => fn (MutationOperation $op, array $refs) => $this->joindre([
                    $this->nomDe($op->fields['assureur'] ?? null, $refs),
                    $this->nomDe($op->fields['piste'] ?? null, $refs),
                ], ' — '),
                // La durée se LIT dans la période, elle ne se suppose pas : un contrat
                // de 22 jours n'est pas une police annuelle, et écrire « 12 » d'office
                // aurait été faux en silence. La période n'est pas portée par la
                // cotation mais par SON AVENANT — c'est là qu'il faut aller la lire.
                'duree' => fn (MutationOperation $op) => $this->dureeDepuisAvenant($op),
            ],
            'Avenant' => [
                // La description d'un avenant, quand rien n'est dit, c'est la police
                // qu'il porte — plus utile qu'un champ vide dans une liste.
                'description' => static fn (MutationOperation $op) => isset($op->fields['referencePolice'])
                    ? sprintf('Police %s', (string) $op->fields['referencePolice'])
                    : null,
            ],
            default => [],
        };
    }

    /**
     * La durée de la proposition, lue sur la période de l'avenant qu'elle porte.
     * L'avenant vit dans la collection « avenants » de la cotation (parité avec
     * l'écran) : la période n'est donc pas dans les champs de la cotation, mais un
     * niveau plus bas.
     */
    private function dureeDepuisAvenant(MutationOperation $op): ?int
    {
        foreach ($op->collections['avenants'] ?? [] as $avenant) {
            $duree = $this->dureeEnMois(
                $avenant->fields['startingAt'] ?? null,
                $avenant->fields['endingAt'] ?? null,
            );
            if ($duree !== null) {
                return $duree;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $champs */
    private function estFourni(array $champs, string $champ): bool
    {
        return array_key_exists($champ, $champs)
            && $champs[$champ] !== null
            && trim((string) (is_array($champs[$champ]) ? '' : $champs[$champ])) !== '';
    }

    /**
     * Le NOM derrière une valeur de relation : soit elle est déjà un nom dicté
     * (« SUNU »), soit c'est un renvoi « @etiquette » vers une création du même plan
     * — dont on va lire le nom. Un identifiant nu ne dit rien : on rend null, et le
     * champ restera manquant plutôt que d'être rempli d'un numéro.
     *
     * @param array<string, MutationOperation> $parRef
     */
    private function nomDe(mixed $valeur, array $parRef): ?string
    {
        if (!is_string($valeur) || trim($valeur) === '') {
            return null;
        }
        $valeur = trim($valeur);

        if (!MutationReferences::estReference($valeur)) {
            return ctype_digit($valeur) ? null : $valeur;
        }

        $cible = $parRef[MutationReferences::etiquette($valeur)] ?? null;
        if ($cible === null) {
            return null;
        }

        foreach (['nomComplet', 'nom'] as $champ) {
            $nom = $cible->fields[$champ] ?? null;
            if (is_string($nom) && trim($nom) !== '') {
                return trim($nom);
            }
        }

        return null;
    }

    /** @param list<string|null> $morceaux */
    private function joindre(array $morceaux, string $liant): ?string
    {
        $retenus = array_values(array_filter($morceaux, static fn ($m) => is_string($m) && trim($m) !== ''));

        return $retenus === [] ? null : implode($liant, $retenus);
    }

    /**
     * Durée en MOIS entamés, déduite de la période. 22 jours font 1 mois : la durée
     * facturée d'une police ne descend pas en dessous du mois entamé.
     */
    private function dureeEnMois(mixed $debut, mixed $fin): ?int
    {
        $d = $this->date($debut);
        $f = $this->date($fin);
        if ($d === null || $f === null || $f < $d) {
            return null;
        }

        $jours = (int) $d->diff($f)->days;

        return max(1, (int) ceil($jours / 30));
    }

    private function date(mixed $valeur): ?\DateTimeImmutable
    {
        if (!is_string($valeur) || trim($valeur) === '') {
            return null;
        }
        // Les dates arrivent ici NORMALISÉES (NormaliseurDeDates a déjà tourné) ;
        // on reste tolérant, et surtout on refuse plutôt que d'inventer.
        try {
            return new \DateTimeImmutable(trim($valeur));
        } catch (\Throwable) {
            return null;
        }
    }
}
