<?php

namespace App\Service\Conge;

use App\Ai\AiText;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Repository\InviteRepository;

/**
 * DE QUEL COLLABORATEUR PARLE-T-ON ?
 *
 * L'assistant reçoit un NOM, jamais un identifiant : l'utilisateur dit « les congés de
 * Marie », pas « l'invité #7 ». Ce service traduit, et sait aussi dire qu'il ne sait pas.
 *
 * ── TROIS RÉPONSES, PAS DEUX ────────────────────────────────────────────────────────
 * Trouvé, introuvable, ou AMBIGU. Trancher au hasard entre deux « Marie » donnerait un
 * solde qui n'est pas le bon, et personne ne s'en apercevrait — c'est le genre d'erreur
 * qu'on ne découvre qu'au moment où quelqu'un réclame ses jours.
 *
 * Toujours SCOPÉ à l'entreprise : un nom ne peut désigner que quelqu'un du cabinet.
 */
class ResolveurDAgent
{
    /** Au-delà, on ne propose plus : on demande de préciser. */
    private const MAX_CANDIDATS = 8;

    public function __construct(
        private readonly InviteRepository $inviteRepository,
    ) {
    }

    /**
     * Les collaborateurs du cabinet dont le nom correspond.
     *
     * La correspondance passe par AiText::cle() — minuscules, sans accents, ponctuation
     * ramenée à une espace : « Jean-Paul MUKENDI » et « jean paul mukendi » désignent la
     * même personne. On accepte aussi la correspondance PARTIELLE, parce qu'on dit
     * « Mukendi » et rarement le nom complet.
     *
     * @return Invite[]
     */
    public function candidats(Entreprise $entreprise, string $nom): array
    {
        $recherche = AiText::cle($nom);
        if ($recherche === '') {
            return [];
        }

        $exacts = [];
        $partiels = [];

        foreach ($this->inviteRepository->findBy(['entreprise' => $entreprise]) as $invite) {
            $cle = AiText::cle((string) $invite->getNom());
            if ($cle === '') {
                continue;
            }

            if ($cle === $recherche) {
                $exacts[] = $invite;
            } elseif (str_contains($cle, $recherche) || str_contains($recherche, $cle)) {
                $partiels[] = $invite;
            }
        }

        // UN NOM EXACT L'EMPORTE SUR TOUTE CORRESPONDANCE PARTIELLE. Sans cette règle,
        // demander « Jean » dans une équipe qui compte un « Jean » et un « Jean-Paul »
        // rendrait deux candidats et forcerait une question inutile.
        $candidats = $exacts !== [] ? $exacts : $partiels;

        return array_slice($candidats, 0, self::MAX_CANDIDATS);
    }

    /**
     * L'agent désigné, ou le demandeur lui-même quand aucun nom n'est donné.
     *
     * @return array{agent: ?Invite, ambigu: Invite[], introuvable: bool}
     */
    public function resoudre(Entreprise $entreprise, ?string $nom, Invite $parDefaut): array
    {
        $nom = trim((string) $nom);

        // « Mes congés », « mon solde » : le demandeur parle de lui. C'est de loin le cas
        // le plus fréquent, et il ne doit coûter aucune question.
        if ($nom === '' || $this->designeLeDemandeur($nom)) {
            return ['agent' => $parDefaut, 'ambigu' => [], 'introuvable' => false];
        }

        $candidats = $this->candidats($entreprise, $nom);

        if ($candidats === []) {
            return ['agent' => null, 'ambigu' => [], 'introuvable' => true];
        }

        if (count($candidats) > 1) {
            return ['agent' => null, 'ambigu' => $candidats, 'introuvable' => false];
        }

        return ['agent' => $candidats[0], 'ambigu' => [], 'introuvable' => false];
    }

    /** « moi », « mes », « mon » : le demandeur se désigne lui-même. */
    private function designeLeDemandeur(string $nom): bool
    {
        return in_array(AiText::cle($nom), ['moi', 'me', 'mes', 'mon', 'ma', 'je'], true);
    }
}
