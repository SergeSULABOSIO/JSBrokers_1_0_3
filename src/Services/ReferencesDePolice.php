<?php

namespace App\Services;

use App\Entity\Client;
use App\Entity\Entreprise;
use App\Repository\AvenantRepository;

/**
 * LES POLICES QUE LE CABINET A RÉELLEMENT SOUSCRITES.
 *
 * ── CE QUE CE SERVICE FERME ─────────────────────────────────────────────────────
 * La référence de police d'un sinistre était un champ de texte libre : on pouvait y
 * écrire n'importe quoi, et rien ne le relevait jamais. Un sinistre finissait rattaché
 * à une police qui n'existait pas — on ne s'en apercevait qu'au moment de réclamer à
 * l'assureur.
 *
 * ── POURQUOI UN SERVICE, ET PAS DEUX REQUÊTES ───────────────────────────────────
 * Le formulaire PROPOSE une liste, le validateur VÉRIFIE une valeur. Écrire deux
 * requêtes, c'était se condamner à ce qu'elles divergent : une référence proposée que
 * l'enregistrement refuse ensuite est pire que pas de liste du tout. Les deux passent
 * donc par le même code, et le filtre par assuré s'applique des deux côtés.
 *
 * ── UNE POLICE N'EST PAS UNE ENTITÉ ─────────────────────────────────────────────
 * C'est un GROUPE d'avenants qui partagent la même `referencePolice` — plusieurs actes
 * d'une même affaire. D'où le DISTINCT : on désigne une police, pas l'un de ses actes.
 */
class ReferencesDePolice
{
    public function __construct(
        private readonly AvenantRepository $avenantRepository,
    ) {
    }

    /**
     * Les références proposables, prêtes pour un ChoiceType (libellé => valeur).
     *
     * @param Client|null $assure quand il est connu, on ne propose QUE ses polices
     * @return array<string, string>
     */
    public function pourLeCabinet(Entreprise $entreprise, ?Client $assure = null): array
    {
        $choix = [];
        foreach ($this->avenantRepository->referencesDePolice($entreprise, $assure) as $ligne) {
            $reference = (string) $ligne['reference'];
            $choix[$this->libelle($ligne)] = $reference;
        }

        return $choix;
    }

    /**
     * Cette référence existe-t-elle ? Et, si l'assuré est fourni, est-elle BIEN LA SIENNE ?
     *
     * Même requête que la liste : ce qui n'est pas proposé n'est pas acceptable.
     */
    public function existe(Entreprise $entreprise, string $reference, ?Client $assure = null): bool
    {
        $reference = trim($reference);
        if ($reference === '') {
            return false;
        }

        return in_array($reference, $this->pourLeCabinet($entreprise, $assure), true);
    }

    /**
     * CE QUE LE COURTIER LIT DANS LA LISTE.
     *
     * La référence seule ne dit pas de quel dossier il s'agit : « POL-2026-14 » ne se
     * distingue de « POL-2026-15 » que par un chiffre. Le client et le risque, eux, se
     * reconnaissent. Les maillons manquants sont simplement omis — jamais remplacés par
     * un mot qui laisserait croire à une donnée.
     *
     * @param array{reference: string, client: ?string, risque: ?string, assureur: ?string} $ligne
     */
    private function libelle(array $ligne): string
    {
        $contexte = array_filter([
            $ligne['client'] ?? null,
            $ligne['risque'] ?? null,
            $ligne['assureur'] ?? null,
        ], static fn (?string $v): bool => $v !== null && trim($v) !== '');

        $reference = (string) $ligne['reference'];

        return $contexte === [] ? $reference : $reference . ' — ' . implode(' · ', $contexte);
    }
}
