<?php

namespace App\Ai\Voix;

use App\Ai\Fournisseur\OrdreDesFournisseurs;
use App\Ai\Fournisseur\PolitiqueDesFournisseurs;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * LA VOIX DE KET, quel que soit le fournisseur.
 *
 * Les fournisseurs sont essayés dans l'ordre de KET_VOIX_FOURNISSEURS (par défaut
 * ElevenLabs puis Gemini). Tant qu'aucun son n'est sorti, un fournisseur indisponible,
 * épuisé ou en panne passe la main au suivant. Dès qu'un fournisseur a parlé, il va au
 * bout : reprendre une phrase à zéro avec une autre voix serait pire qu'un arrêt. Quand
 * aucun ne parle, la route répond 503 et le navigateur lit avec sa propre voix.
 */
final class VoixDeKet
{
    private ?FournisseurDeVoix $dernier = null;

    /** @param iterable<FournisseurDeVoix> $fournisseurs */
    public function __construct(
        #[AutowireIterator('app.fournisseur_voix')] private readonly iterable $fournisseurs,
        #[Autowire(env: 'KET_VOIX_FOURNISSEURS')] private readonly string $ordreParDefaut = 'elevenlabs,gemini',
        // Facultative : sans elle, l'ordre reste celui du .env, exactement comme
        // avant l'existence de l'écran de console.
        private readonly ?PolitiqueDesFournisseurs $politique = null,
    ) {
    }

    /**
     * Les fournisseurs appelables, dans l'ordre de préférence.
     *
     * ⚠ L'ORDRE SE RÉSOUT À CHAQUE APPEL, et ce n'est pas un détail de style. Il
     * était calculé dans le CONSTRUCTEUR : un service partagé, construit une fois
     * par processus, n'aurait jamais relu une politique changée en console — le
     * réglage aurait semblé pris en compte et n'aurait rien fait jusqu'au
     * redémarrage du worker. Le coût est nul : ordonner cinq noms ne se mesure pas.
     *
     * L'ordre et le filtre sont les mêmes pour la bouche et pour les oreilles :
     * ils vivent dans OrdreDesFournisseurs, jamais en double.
     *
     * @return list<FournisseurDeVoix>
     */
    public function fournisseurs(): array
    {
        $ordre = $this->politique?->ordre('voix') ?? $this->ordreParDefaut;

        return OrdreDesFournisseurs::disponibles(OrdreDesFournisseurs::ordonner($this->fournisseurs, $ordre));
    }

    public function estDisponible(): bool
    {
        return $this->fournisseurs() !== [];
    }

    /**
     * RESTE-T-IL UNE VOIX QUI PARLERA ? Configurée, appelable, ET pas encore à sec.
     *
     * C'est cette réponse que la page reçoit à l'ouverture : quand elle est « non », la
     * synthèse du navigateur prend la main SANS qu'on demande d'abord au serveur un
     * refus qu'on connaît déjà. La lecture démarre alors sur l'API native, ce qui est
     * exactement le régime dans lequel le cabinet se trouve dès que les paliers gratuits
     * sont consommés.
     */
    /**
     * LE NAVIGATEUR EST UN FOURNISSEUR DE VOIX COMME UN AUTRE — simplement, il n'est
     * pas ici. Il vit dans la page, il ne coûte rien, il ne s'épuise jamais, et il
     * PARLE TOUT DE SUITE là où une voix de serveur demande un aller-retour. Mesuré
     * en production le 2026-09-23 : jusqu'à dix secondes avant le premier son.
     *
     * On ne peut donc pas l'instancier comme service, mais on peut le NOMMER dans la
     * chaîne. Placé devant les voix du serveur, il les court-circuite : la page ne
     * demande plus rien et lit elle-même. C'est un réglage de console, pas une
     * décision figée dans le code — certains cabinets préféreront la plus belle voix,
     * d'autres la plus rapide.
     */
    public const NAVIGATEUR = 'navigateur';

    /**
     * Le navigateur est-il placé DEVANT toute voix de serveur utilisable ?
     *
     * On compare des rangs, pas des présences : `navigateur` placé en dernier reste
     * ce qu'il a toujours été, le filet de sécurité quand plus rien ne répond.
     */
    public function leNavigateurDAbord(): bool
    {
        $ordre = array_values(array_filter(array_map(
            'trim',
            explode(',', $this->politique?->ordre('voix') ?? $this->ordreParDefaut),
        )));

        $rang = array_search(self::NAVIGATEUR, $ordre, true);
        if ($rang === false) {
            return false;
        }

        foreach ($this->fournisseurs() as $fournisseur) {
            $rangDuServeur = array_search($fournisseur->nom(), $ordre, true);
            if ($rangDuServeur !== false && $rangDuServeur < $rang) {
                return false;
            }
        }

        return true;
    }

    public function uneVoixPeutParler(): bool
    {
        // Le navigateur passe devant : inutile de déranger le serveur, et surtout
        // inutile d'attendre sa réponse avant le premier mot.
        if ($this->leNavigateurDAbord()) {
            return false;
        }

        foreach ($this->fournisseurs() as $fournisseur) {
            if (!$fournisseur->estEpuise()) {
                return true;
            }
        }

        return false;
    }

    /**
     * L'identité d'une voix dans le cache audio : deux fournisseurs, ou deux voix d'un même
     * fournisseur, ne partagent jamais un enregistrement.
     */
    public static function identite(FournisseurDeVoix $fournisseur, bool $vitesse = false): string
    {
        return $fournisseur->nom() . ':' . $fournisseur->voix() . ':' . $fournisseur->modele($vitesse);
    }

    /** Le fournisseur qui a parlé lors du dernier flux (celui dont l'audio se met en cache). */
    public function dernierFournisseur(): ?FournisseurDeVoix
    {
        return $this->dernier;
    }

    /**
     * @return \Generator<int, string, mixed, string>
     */
    public function flux(string $texte, bool $vitesse = false): \Generator
    {
        $this->dernier = null;
        $statuts = [];
        foreach ($this->fournisseurs() as $fournisseur) {
            $flux = $fournisseur->flux($texte, $vitesse);
            $aParle = false;
            foreach ($flux as $morceau) {
                if (!$aParle) {
                    $aParle = true;
                    $this->dernier = $fournisseur;
                }
                yield $morceau;
            }
            if ($aParle) {
                return $flux->getReturn();
            }
            $statuts[] = $flux->getReturn();
        }

        if ($statuts === []) {
            return FournisseurDeVoix::INDISPONIBLE;
        }

        return \in_array(FournisseurDeVoix::QUOTA, $statuts, true) ? FournisseurDeVoix::QUOTA : FournisseurDeVoix::ECHEC;
    }
}
