<?php

namespace App\Ai\Fournisseur;

use App\Ai\Debit\BudgetDebit;
use App\Ai\Engine\GeminiAiEngine;
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
        /**
         * LA TROISIÈME CAUSE DE SILENCE, celle que l'écran ignorait.
         *
         * « À sec » est une marque durable — quota du jour, crédits du mois. Le débit par
         * minute est tout autre chose : une fenêtre glissante, partagée par tout le
         * cabinet, qui se remplit et se vide en soixante secondes. Un modèle peut être
         * parfaitement « prêt » et refuser le tour suivant.
         */
        private readonly BudgetDebit $budget,
        /**
         * LA QUATRIÈME CAUSE DE SILENCE, et la dernière que l'écran ignorait : le
         * fournisseur lui-même refuse. Un 503 « high demand » ne pose aucune marque
         * durable et ne laissait de trace que dans le journal, que la console ne lit pas.
         */
        private readonly MemoireDesRefus $refus,
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
            // ⚠ LE SECOND ARGUMENT DIT SI LA FENÊTRE DE DÉBIT S'APPLIQUE.
            //
            // Elle compte des JETONS D'ENTRÉE par minute : cela n'a de sens que pour les
            // familles qui appellent un modèle de texte. La voix compte en caractères,
            // les oreilles en secondes d'audio, et le navigateur ne compte rien du tout —
            // il tourne dans la page. Afficher « minute 100 % » sur ces trois-là serait
            // un chiffre inventé, et un écran qui invente est pire qu'un écran muet.
            'moteur'        => $this->famille($this->moteurs, true),
            // LE NAVIGATEUR FIGURE DANS LA LISTE DES VOIX, bien qu'il ne soit pas un
            // service : il est une vraie option, et un écran qui ne la montre pas la
            // rend inexistante. Toujours prêt — toute page sait lire un texte — et
            // jamais à sec : il ne consomme ni clé ni quota. Le placer devant les
            // voix du serveur, depuis la console, c'est choisir de parler TOUT DE
            // SUITE plutôt que joliment.
            'voix'          => self::avecLeNavigateur($this->famille($this->voix)),
            'oreille'       => self::avecLeNavigateur($this->famille($this->oreilles)),
            'comprehension' => $this->famille($this->comprenants, true),
            'dictee'        => $this->famille($this->finisseurs, true),
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
            'repondAvec' => null,
            'chaine'     => [],
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
    /**
     * EN DEÇÀ, LA MINUTE EST PLEINE EN PRATIQUE.
     *
     * Zéro serait le seuil naïf : un modèle à qui il reste mille jetons n'en refuse pas
     * moins le tour suivant, le plus léger en demandant dix fois plus. On annonce donc
     * « minute pleine » dès qu'il ne reste plus de quoi passer un tour de lecture.
     */
    private const DEBIT_MINIMAL_UTILE = 20000;

    /**
     * CE QUE COÛTE UN TOUR, tel que mesuré sur la trousse la plus lourde.
     *
     * Relevé le 2026-09-28 sur une conversation réelle : prompt 95 394 o, déclarations
     * 114 702 o, historique 6 492 o — 58 537 jetons d'entrée. C'est ce chiffre qu'on
     * oppose à la fenêtre, et non un ordre de grandeur : demander la place pour un tour
     * de lecture rendrait un verdict optimiste que le premier message d'écriture
     * démentirait.
     */
    private const COUT_TOUR_TYPIQUE = 58537;

    /**
     * ⚠ AU-DELÀ, LE MOTEUR RENONCE. Il patiente au plus quinze secondes avant de rendre
     * la main (OrchestrateurDeMessage::MAX_ATTENTE_SECONDES). Un écran qui annoncerait
     * « disponible dans 40 s » mentirait deux fois : Ket n'attendra pas, et elle
     * répondra « limite de débit » bien avant.
     */
    private const ATTENTE_TOLEREE = 15;

    private function famille(iterable $fournisseurs, bool $suitLeDebit = false): array
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

            // LA CHAÎNE DE SECOURS, ET LE MODÈLE QUI RÉPONDRAIT VRAIMENT.
            //
            // Le moteur Gemini ne s'arrête pas à son modèle principal : un 503 ou un
            // quota atteint le fait basculer sur le suivant, et il continue de
            // répondre. L'écran n'affichait que le premier et le présentait comme LE
            // modèle : on cherchait alors la cause d'une réponse lente ou médiocre
            // du côté d'un modèle qui n'avait pas parlé.
            //
            // On rend donc la chaîne ENTIÈRE, chaque maillon avec son état, et le
            // premier qui a du souffle — celui auquel Ket est RÉELLEMENT branchée.
            // Aucun appel réseau : on interroge la mémoire d'épuisement, modèle par
            // modèle, exactement comme le fait le moteur avant de choisir.
            $chaine = [];
            if ($modele !== '') {
                $chaine[] = [
                    'nom' => $modele,
                    'principal' => true,
                    'epuise' => $fournisseur->estEpuise(),
                    // PORTÉE PAR LE MAILLON, et pas seulement par le fournisseur : c'est
                    // le maillon qu'on lit, et « indisponible » est un état comme les
                    // autres. Sans lui, l'écran n'avait pas de mot à mettre.
                    'indisponible' => !$fournisseur->estDisponible(),
                    // LE REFUS DU FOURNISSEUR LUI-MÊME, quatrième cause de silence. Il
                    // ne se déduit d'aucun compteur local : notre fenêtre de débit est
                    // même PARFAITEMENT VIDE quand Google refuse, puisque rien ne passe.
                    // C'est ce qui faisait dire « a la place pour un tour » à un écran
                    // dont les trois modèles venaient de répondre 503 (2026-09-28).
                    'refus' => $this->refus->dernier($modele),
                ] + $this->debitDe($modele, $suitLeDebit);
            }
            if ($fournisseur instanceof FournisseurAReplis) {
                foreach ($fournisseur->modelesDeRepli() as $repli) {
                    $aSec = $fournisseur instanceof GeminiAiEngine
                        && $this->epuisement->estEpuise($fournisseur->cleDEpuisementDe($repli));
                    $chaine[] = [
                        'nom' => $repli,
                        'principal' => false,
                        'epuise' => $aSec,
                        'indisponible' => !$fournisseur->estDisponible(),
                        'refus'        => $this->refus->dernier($repli),
                    ] + $this->debitDe($repli, $suitLeDebit);
                }
            }

            // ⚠ « QUI RÉPOND » TIENT COMPTE DES QUATRE REFUS POSSIBLES, et il a fallu
            // trois corrections pour les réunir tous.
            //
            //  · INDISPONIBLE — pas de clé d'API sur ce serveur. Le fournisseur ne peut
            //    répondre à rien, et l'écran l'annonçait pourtant comme répondant : un
            //    « anthropic · non configuré » affichait « claude-haiku-4-5 répond ».
            //  · À SEC — marque durable posée quand le fournisseur se déclare épuisé.
            //  · MINUTE PLEINE — notre fenêtre de débit, qui se vide toute seule.
            //  · REFUSÉ — le fournisseur a dit non il y a quelques minutes. AUCUN
            //    compteur local ne le sait : quand Google répond 503, notre fenêtre
            //    reste vide puisque rien ne part. C'est la cause qui manquait, et la
            //    console affichait « gemini-flash-lite-latest répond » à la minute même
            //    où ce modèle renvoyait « high demand » (2026-09-28, vu sur pièces).
            //
            // Les quatre se ressemblent à l'écran et n'appellent pas le même geste. Les
            // confondre, c'est envoyer l'agent réarmer un fournisseur qui n'a pas de clé.
            $repondAvec = null;
            if ($fournisseur->estDisponible()) {
                foreach ($chaine as $maillon) {
                    if ($maillon['epuise'] || ($maillon['minutePleine'] ?? false) || ($maillon['refus'] ?? null) !== null) {
                        continue;
                    }
                    $repondAvec = $maillon['nom'];
                    break;
                }
            }

            $etat[] = [
                'nom'        => $fournisseur->nom(),
                'disponible' => $fournisseur->estDisponible(),
                'epuise'     => $fournisseur->estEpuise(),
                'modele'     => $modele !== '' ? $modele : null,
                // Ce à quoi Ket est branchée à cet instant : le premier maillon qui a
                // du souffle. `null` quand toute la chaîne est à sec — et c'est une
                // information, pas une absence.
                'repondAvec' => $repondAvec,
                // LE VERDICT, ET SA RAISON. Cf. verdictDe().
                'verdict'    => $this->verdictDe($fournisseur, $chaine, $repondAvec, $suitLeDebit),
                // LA CHAÎNE PART ENTIÈRE, MÊME À UN SEUL MAILLON. Elle était vidée dans
                // ce cas — « inutile d'afficher une chaîne d'un élément » —, si bien que
                // les familles à modèle unique (compréhension, finition de dictée)
                // n'affichaient ni leur modèle, ni son débit, ni qui répondrait. Une
                // chaîne d'un maillon reste l'information : c'est LUI qui répond, et
                // c'est sa minute qu'il faut lire.
                'chaine'     => $chaine,
                'cle'        => $cle,
                'echeance'   => $cle !== null ? $this->epuisement->echeance($cle)?->format(\DateTimeInterface::ATOM) : null,
            ];
        }

        return $etat;
    }

    /**
     * CE QU'IL RESTE À CE MODÈLE DANS LA MINUTE EN COURS.
     *
     * Aucun appel réseau : la fenêtre glissante vit dans le cache partagé, et c'est
     * exactement celle que le moteur interroge avant chaque tour.
     *
     * ⚠ LE DÉBIT N'EST PAS L'ÉPUISEMENT, et l'écran doit montrer les deux. « À sec »
     * est une marque DURABLE — quota du jour, crédits du mois, plafond de dépense. Le
     * débit est une fenêtre de soixante secondes, partagée par tout le cabinet : un
     * modèle sans aucune marque d'épuisement peut refuser le tour suivant parce que la
     * minute est pleine.
     *
     * C'est la contradiction qu'un agent a rencontrée le 2026-09-28 : la console
     * annonçait « quota : ok » pendant que Ket répondait au courtier « limite de débit
     * pour la minute en cours, relancez dans 28 secondes ». Les deux disaient vrai.
     *
     * @return array{debitRestant: int, debitPlafond: int, debitPart: int}
     */
    /**
     * KET PEUT-ELLE RÉPONDRE MAINTENANT, ET SINON POURQUOI ?
     *
     * ── POURQUOI CE N'EST PAS DÉDUCTIBLE DU RESTE DE L'ÉCRAN ────────────────────
     *
     * L'agent lisait « prêt » et « minute 100 % », et concluait que Ket répondrait.
     * C'était faux, et la console n'y pouvait rien : elle montrait des INGRÉDIENTS de
     * la décision, pas la décision. Or le moteur ne se demande pas « reste-t-il du
     * débit », il se demande « y a-t-il la place pour CE tour, et puis-je l'attendre
     * en moins de quinze secondes ». Trois issues, trois gestes différents :
     *
     *  · `absent`   — pas de clé sur ce serveur. Rien à faire ici.
     *  · `a_sec`    — marque durable. Attendre l'échéance, ou réarmer.
     *  · `sature`   — la minute est pleine. Se résout seul, sans intervention.
     *  · `trop_gros`— un seul tour dépasse le plafond. Attendre n'y changera JAMAIS
     *                 rien, et c'est le cas que personne ne voyait : Ket annonçait
     *                 « relancez dans 47 secondes » sur un blocage qu'aucune attente
     *                 ne lève.
     *
     * @param list<array<string, mixed>> $chaine
     *
     * @return array{peut: bool, cause: string, detail: string}
     */
    private function verdictDe(object $fournisseur, array $chaine, ?string $repondAvec, bool $suitLeDebit): array
    {
        if (!$fournisseur->estDisponible()) {
            return ['peut' => false, 'cause' => 'absent', 'detail' => 'Aucune clé d\'API sur ce serveur.'];
        }
        if ($chaine !== [] && !array_filter($chaine, static fn (array $m): bool => !$m['epuise'])) {
            return ['peut' => false, 'cause' => 'a_sec', 'detail' => 'Tous les modèles se sont déclarés épuisés.'];
        }
        if (!$suitLeDebit) {
            // Voix, oreilles : la fenêtre de jetons ne les gouverne pas. Le dire, plutôt
            // que de rendre un verdict calculé sur une règle qui ne s'applique pas.
            return ['peut' => $repondAvec !== null, 'cause' => 'hors_debit', 'detail' => 'Non soumis à la fenêtre de jetons par minute.'];
        }

        // ⚠ UNE CHAÎNE VIDE N'EST PAS UN TOUR TROP GROS. Sans ce garde, la boucle
        // ci-dessous ne s'exécute jamais, `$meilleure` reste null, et le verdict tombait
        // sur `trop_gros` — « un seul tour coûte ~58 537 jetons, au-delà du plafond de
        // 212 500 ». Deux chiffres qui se contredisent dans leur propre phrase, servis
        // au sujet d'un fournisseur qui n'annonce aucun modèle (le simulateur).
        if ($chaine === []) {
            return ['peut' => false, 'cause' => 'absent', 'detail' => 'Aucun modèle déclaré pour ce fournisseur.'];
        }

        $meilleure = null;
        $refusRecent = null;
        foreach ($chaine as $maillon) {
            if ($maillon['epuise']) {
                continue;
            }
            // ⚠ LE FOURNISSEUR A-T-IL REFUSÉ RÉCEMMENT ? Notre garde-fou peut très bien
            // laisser passer un tour que Google renverra en 503. C'est le cas vérifié le
            // 2026-09-28 : fenêtre vide, verdict « peut répondre », et les trois modèles
            // répondant « high demand ». Un modèle qui vient de refuser ne compte pas
            // comme disponible, si libre que soit sa minute.
            $dernier = $maillon['refus'] ?? null;
            if ($dernier !== null) {
                $refusRecent ??= $maillon['nom'] . ' : ' . $dernier['motif']
                    . sprintf(' (il y a %d s)', $dernier['secondes']);
                continue;
            }
            $attente = $this->budget->secondesAvantLiberation($maillon['nom'], self::COUT_TOUR_TYPIQUE);
            if ($attente === 0) {
                return ['peut' => true, 'cause' => 'ok', 'detail' => sprintf('%s a la place pour un tour.', $maillon['nom'])];
            }
            if ($attente !== null && ($meilleure === null || $attente < $meilleure)) {
                $meilleure = $attente;
            }
        }

        if ($meilleure === null && $refusRecent !== null) {
            return [
                'peut'   => false,
                'cause'  => 'refuse',
                'detail' => 'Le fournisseur refuse — ' . $refusRecent . '. Cela passe en général en quelques minutes.',
            ];
        }

        if ($meilleure === null) {
            return [
                'peut'   => false,
                'cause'  => 'trop_gros',
                'detail' => sprintf(
                    'Un seul tour coûte ~%s jetons, au-delà du plafond de %s par minute : aucune attente ne le fera passer.',
                    number_format(self::COUT_TOUR_TYPIQUE, 0, ',', ' '),
                    number_format($this->budget->plafondUtile(null), 0, ',', ' '),
                ),
            ];
        }

        return [
            'peut'   => $meilleure <= self::ATTENTE_TOLEREE,
            'cause'  => $meilleure <= self::ATTENTE_TOLEREE ? 'ok' : 'sature',
            'detail' => sprintf(
                'La fenêtre se libère dans %d s ; le moteur n\'attend que %d s avant de renoncer.',
                $meilleure,
                self::ATTENTE_TOLEREE,
            ),
        ];
    }

    private function debitDe(string $modele, bool $suitLeDebit): array
    {
        // PAS DE CHIFFRE INVENTÉ. La fenêtre compte des jetons d'entrée par minute :
        // elle ne veut rien dire pour une voix qui compte en caractères, pour des
        // oreilles qui comptent en secondes d'audio, ni pour le navigateur qui ne
        // compte rien. On rend `null`, et l'écran affiche une absence plutôt qu'un 100 %
        // rassurant et faux.
        if (!$suitLeDebit) {
            return ['debitPart' => null, 'debitRestant' => null, 'debitPlafond' => null, 'minutePleine' => false];
        }

        $plafond = $this->budget->plafondUtile($modele);
        $restant = $this->budget->restant($modele);
        $part = $plafond > 0 ? (int) round(100 * $restant / $plafond) : 100;

        return [
            'debitRestant' => $restant,
            'debitPlafond' => $plafond,
            'debitPart'    => $part,
            // LE SEUIL EST LE COÛT D'UN TOUR, pas zéro. Un modèle à qui il reste mille
            // jetons est vide en pratique : le tour le plus léger en demande dix fois
            // plus. Annoncer « il reste de la place » serait exact et inutile.
            'minutePleine' => $restant < self::DEBIT_MINIMAL_UTILE,
        ];
    }
}
