<?php

namespace App\Ai\Fournisseur;

use App\Ai\Voix\VoixDeKet;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * CE QUE VALENT LES FOURNISSEURS, MAINTENANT — pour la console et pour la page.
 *
 * Deux consommateurs, deux besoins qui n'en font qu'un.
 *
 * LA PAGE, à l'ouverture du chat : savoir d'avance qu'aucune voix ne parlera lui
 * permet de brancher DIRECTEMENT la synthèse du navigateur, au lieu de commencer
 * chaque lecture par un aller-retour qui ne rendra qu'un refus. Mesuré le
 * 2026-09-21 : crédits ElevenLabs épuisés depuis le 17/09, trois modèles Gemini
 * depuis le 19/09 — toutes les lectures passaient déjà par le navigateur, mais
 * chacune attendait d'abord un « non ».
 *
 * LA CONSOLE : un agent doit voir, famille par famille, qui est configuré, qui a
 * du solde, et jusqu'à quand les autres sont écartés. Sans cette vue, une marque
 * posée à tort — un 429 mal interprété, une clé changée — met un fournisseur hors
 * jeu jusqu'à minuit heure du Pacifique sans que personne ne sache pourquoi.
 *
 * AUCUN APPEL RÉSEAU ICI : tout se lit dans la mémoire d'épuisement et dans la
 * configuration. Cette classe doit rester gratuite, sans quoi la page paierait à
 * l'ouverture ce qu'elle cherche justement à éviter.
 */
final class EtatDesFournisseurs
{
    /**
     * @param iterable<Fournisseur> $moteurs
     * @param iterable<Fournisseur> $voix
     * @param iterable<Fournisseur> $oreilles
     * @param iterable<Fournisseur> $comprenants
     * @param iterable<Fournisseur> $finisseurs
     */
    public function __construct(
        #[AutowireIterator('app.fournisseur_moteur')] private readonly iterable $moteurs,
        #[AutowireIterator('app.fournisseur_voix')] private readonly iterable $voix,
        #[AutowireIterator('app.fournisseur_oreille')] private readonly iterable $oreilles,
        #[AutowireIterator('app.fournisseur_comprehension')] private readonly iterable $comprenants,
        #[AutowireIterator('app.fournisseur_finition')] private readonly iterable $finisseurs,
        private readonly MemoireDEpuisement $epuisement,
    ) {
    }

    /**
     * L'état de TOUTES les familles, prêt à afficher.
     *
     * @return array<string, list<array{nom: string, disponible: bool, epuise: bool, modele: string|null, cle: string|null, echeance: string|null}>>
     */
    public function tout(): array
    {
        return [
            'moteur'        => $this->famille($this->moteurs),
            // LE NAVIGATEUR FIGURE DANS LA LISTE DES VOIX, bien qu'il ne soit pas un
            // service : il est une vraie option, et un écran qui ne la montre pas la
            // rend inexistante. Toujours prêt — toute page sait lire un texte — et
            // jamais à sec : il ne consomme ni clé ni quota. Le placer devant les
            // voix du serveur, depuis la console, c'est choisir de parler TOUT DE
            // SUITE plutôt que joliment.
            'voix'          => self::avecLeNavigateur($this->famille($this->voix)),
            'oreille'       => self::avecLeNavigateur($this->famille($this->oreilles)),
            'comprehension' => $this->famille($this->comprenants),
            'dictee'        => $this->famille($this->finisseurs),
        ];
    }

    /**
     * LE NAVIGATEUR : un fournisseur qui n'est pas un service, et une RÈGLE.
     *
     * ⚠ LE REPLI NE SE CONFIGURE PAS. Dès que plus aucun fournisseur du serveur n'a
     * de souffle, le navigateur prend la main — sans rien demander à personne, qu'il
     * soit coché ici ou non. Un quota épuisé est un fait, pas une décision, et
     * demander son avis à l'utilisateur au moment où Ket devrait parler lui ferait
     * payer deux fois le même incident. C'est verrouillé par
     * `VoixDeKetTest::testAUnQuotaEpuiseLeNavigateurPrendLaMainSansEtreConfigure`.
     *
     * CE QUI SE CONFIGURE, C'EST DE LE METTRE EN PREMIER — donc de ne plus jamais
     * appeler le serveur, même quand il répond. C'est un choix de latence contre
     * timbre, et il appartient au cabinet.
     *
     * Le drapeau `repli` porte cette différence jusqu'à l'écran : sans lui, l'éditeur
     * affichait « écarté » à côté du navigateur, ce qui laissait croire que le repli
     * était désactivé.
     *
     * @return array{nom: string, disponible: bool, epuise: bool, modele: string|null, cle: string|null, echeance: string|null, repli: bool}
     */
    /**
     * La famille, suivie du navigateur — qui SAIT S'IL EST DÉJÀ EN SERVICE.
     *
     * L'ÉCRAN DOIT DIRE LA VÉRITÉ. Afficher « repli automatique » quand le repli est
     * en train de parler, c'est décrire un dispositif au lieu de décrire la situation :
     * l'agent qui ouvre la console un jour de quota épuisé doit LIRE que c'est le
     * navigateur qui parle, et pourquoi. Exigence de l'exploitant, 2026-09-23.
     *
     * @param list<array{nom: string, disponible: bool, epuise: bool, modele: string|null, cle: string|null, echeance: string|null}> $duServeur
     *
     * @return list<array<string, mixed>>
     */
    private static function avecLeNavigateur(array $duServeur): array
    {
        // EN SERVICE quand PLUS AUCUN fournisseur du serveur ne peut répondre : ni
        // configuré, ni avec du souffle. C'est exactement la condition qui déclenche
        // la bascule côté page (VoixDeKet::uneVoixPeutParler).
        $unServeurPeutRepondre = false;
        foreach ($duServeur as $fournisseur) {
            $unServeurPeutRepondre = $unServeurPeutRepondre
                || ($fournisseur['disponible'] && !$fournisseur['epuise']);
        }

        return [...$duServeur, self::leNavigateur(!$unServeurPeutRepondre)];
    }

    private static function leNavigateur(bool $enService = false): array
    {
        return [
            'enService'  => $enService,
            'nom'        => VoixDeKet::NAVIGATEUR,
            // Toujours prêt : toute page sait lire un texte et écouter un micro.
            'disponible' => true,
            // Jamais à sec : il ne consomme ni clé, ni crédit, ni quota.
            'epuise'     => false,
            'modele'     => null,
            'cle'        => null,
            'echeance'   => null,
            'repli'      => true,
        ];
    }

    /**
     * Reste-t-il, dans cette famille, un fournisseur qui rendra quelque chose ?
     *
     * C'est la question que la page pose à l'ouverture, et la seule dont elle a
     * besoin : « oui » et elle demande, « non » et elle se replie sans attendre.
     */
    public function quelquUnPeutRepondre(string $famille): bool
    {
        foreach ($this->tout()[$famille] ?? [] as $fournisseur) {
            // Le navigateur ne compte pas : la question posée ici est « le SERVEUR
            // peut-il répondre ? ». Le compter rendrait la réponse toujours oui, et
            // la page cesserait de savoir qu'elle doit se replier.
            if (($fournisseur['repli'] ?? false) === true) {
                continue;
            }
            if ($fournisseur['disponible'] && !$fournisseur['epuise']) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param iterable<Fournisseur> $fournisseurs
     *
     * @return list<array{nom: string, disponible: bool, epuise: bool, modele: string|null, cle: string|null, echeance: string|null}>
     */
    private function famille(iterable $fournisseurs): array
    {
        $etat = [];
        foreach ($fournisseurs as $fournisseur) {
            // Jamais d'échéance inventée : seuls les fournisseurs qui savent nommer
            // leur marque en ont une (cf. FournisseurDatable). La clé accompagne
            // l'échéance parce que c'est elle que le bouton « Réarmer » efface —
            // sans elle, l'écran saurait dire « à sec » sans pouvoir y remédier.
            $cle = $fournisseur instanceof FournisseurDatable ? $fournisseur->cleDEpuisement() : null;

            // LE MODÈLE EST AFFICHÉ EN CLAIR. Ce n'est pas un secret — la console est
            // réservée aux agents Joseara et ce nom est public — et sans lui le champ
            // « Modèle » de l'écran ne disait pas ce qu'il remplacerait.
            $modele = $fournisseur instanceof FournisseurAModele ? trim($fournisseur->modeleEnVigueur()) : '';

            $etat[] = [
                'nom'        => $fournisseur->nom(),
                'disponible' => $fournisseur->estDisponible(),
                'epuise'     => $fournisseur->estEpuise(),
                'modele'     => $modele !== '' ? $modele : null,
                'cle'        => $cle,
                'echeance'   => $cle !== null ? $this->epuisement->echeance($cle)?->format(\DateTimeInterface::ATOM) : null,
            ];
        }

        return $etat;
    }
}
