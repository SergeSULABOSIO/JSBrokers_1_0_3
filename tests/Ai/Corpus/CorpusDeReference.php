<?php

namespace App\Tests\Ai\Corpus;

use App\Ai\Trousse\Trousse;

/**
 * LE CORPUS DE RÉFÉRENCE : des questions réellement posées à Ket, et l'outil qu'elles
 * appelaient. C'est contre lui que se mesure « bon outil au premier tour ».
 *
 * ── POURQUOI IL EST FIGÉ ICI, ET PAS LU EN BASE ─────────────────────────────────
 *
 * La vérité de terrain existait : 826 paires question → réponse dans `assistant_message`,
 * étiquetées par `meta.tool`. Elle était VOLATILE. Trente-trois des trente-neuf fils
 * n'avaient déjà plus de ligne `AssistantConversation` — les messages avaient survécu à
 * la disparition de leur conversation. Les journaux, eux, tournaient à quatorze jours :
 * huit journées de septembre 2026 étaient perdues avant qu'on les lise. Un corpus qui
 * s'efface ne permet aucune comparaison avant/après, c'est-à-dire aucune mesure.
 *
 * ── POURQUOI IL EST ANONYMISÉ, ET À LA MAIN ─────────────────────────────────────
 *
 * Les questions réelles nomment des clients, des assureurs, des intermédiaires et des
 * personnes. Rien de cela n'entre dans un dépôt de code. La substitution est faite cas
 * par cas plutôt qu'automatiquement : une liste de noms connus laisse toujours passer
 * ce qu'elle ne connaît pas, et ici un seul oubli suffit à publier le portefeuille d'un
 * courtier. `CorpusFigeTest` reprend derrière et échoue si un libellé d'entité réel
 * réapparaît.
 *
 * Les substitutions sont STABLES : le même client réel donne toujours le même pseudonyme,
 * sans quoi les cas qui s'enchaînent (« et son avenant ? ») perdraient leur fil.
 *
 * Ce qui N'EST PAS anonymisé, et volontairement : le vocabulaire métier. Incendie,
 * assurance voyage, ARCA, TVA, bordereau, avenant, tranche, rétrocommission. Ce sont
 * les mots sur lesquels Ket choisit son outil — les remplacer reviendrait à mesurer
 * autre chose.
 *
 * ── CE QUE LE CORPUS COUVRE ─────────────────────────────────────────────────────
 *
 * Les six familles de lecture qui portent 79,3 % des lectures, l'écriture, les actions
 * d'interface, la conversation pure, et deux catégories qu'on ne pouvait pas se
 * permettre d'omettre :
 *
 *  · LES RELANCES. « essaie encore », « la suivante », « vas y », « ok ». Elles sont
 *    massives dans le corpus réel et leur sens vient ENTIÈREMENT du fil : la même
 *    phrase apparaît sous `rechercher_entites`, sous `suivi_impayes` et sous aucun
 *    outil. C'est le piège du lot 6, et le corpus doit le porter.
 *  · LES NOMS INVENTÉS. Les six écorchures réellement relevées, avec l'outil qu'elles
 *    visaient. Trois sont rattrapables à distance 1, trois ne le sont pas.
 */
final class CorpusDeReference
{
    /**
     * LA TABLE DE SUBSTITUTION, publiée pour être VÉRIFIABLE.
     *
     * Elle ne sert pas à anonymiser (c'est fait, à la main, dans les cas ci-dessous) :
     * elle sert à relire. Un cas dont le pseudonyme n'est pas dans cette table est un
     * cas qu'on n'a pas relu.
     *
     * @var array<string, string> famille de donnée => pseudonymes employés
     */
    public const PSEUDONYMES = [
        'personnes'      => 'Mme Perrin, M. Delvaux, M. Hubert Perrin, agent Nadia, agent Boris',
        'clients'        => 'Mine du Haut-Plateau SA, Métallia, Télécom Azur SA, Novacom, Ondalis, '
            . 'Continental Négoce, Cimenterie du Levant, Val d\'Argent, Projet Comète',
        'fournisseurs'   => 'Garage Pramex',
        'assureurs'      => 'Assurica, Assurica IARD, Fidelis, Novara, Novara Vie, Ternova, '
            . 'Ternova Vie, Belmont, Solaris Assurances, Hélios',
        'intermediaires' => 'Altéa, Bricourt, Verdon SA',
        'references'     => '10001-20002-0003-111-00000001-2026, ASRVOY00000001, ASR21000001',
        'telephones'     => '+243810000001, +243810000002',
    ];

    /**
     * LES NOMS RÉELS QUI NE DOIVENT JAMAIS RÉAPPARAÎTRE.
     *
     * Relevés dans les 826 questions d'origine. La liste est là pour que le test puisse
     * échouer sur une reprise malheureuse — pas pour anonymiser : un corpus protégé par
     * une liste noire ne serait protégé que de ce que la liste connaît.
     *
     * @var list<string>
     */
    public const INTERDITS = [
        'Marlette', 'Sula', 'Mbusa', 'Nsudi', 'Ekoma',
        'Kibali', 'Chemaf', 'Orange RDC', 'Airtel', 'Vodacom', 'Lukala', 'Mont Blanc',
        'Loyken', 'Africa Global',
        'SUNU', 'Rawsur', 'Activa', 'Mayfair', 'Affrissur', 'SFA',
        'Olea', 'Lockton', 'Marsh',
        '12002-33002', 'SURDCVO', 'SUN21445578',
        '243828727706', '243844803514',
    ];

    /**
     * @return iterable<string, CasDuCorpus> libellé => cas
     */
    public static function cas(): iterable
    {
        foreach (self::tous() as $cas) {
            yield $cas->libelle => $cas;
        }
    }

    /** @return list<CasDuCorpus> */
    public static function tous(): array
    {
        return array_merge(
            self::liste(),
            self::impayes(),
            self::classement(),
            self::fiche(),
            self::fichiers(),
            self::guide(),
            self::ecriture(),
            self::ecran(),
            self::document(),
            self::conversationPure(),
            self::relances(),
        );
    }

    /**
     * LISTE ET RECHERCHE D'ENREGISTREMENTS — 146 appels réels, 40,8 % des lectures.
     *
     * La famille la plus lourde du corpus, et la plus stéréotypée après les classements :
     * « la liste de X », « avons-nous un X nommé Y ? », « les X de Y ».
     *
     * @return list<CasDuCorpus>
     */
    private static function liste(): array
    {
        return [
            new CasDuCorpus(
                'liste-polices-non-renouvelables',
                'Peux-tu me donner la liste des polices non renouvelables de mon portefeuille ?',
                ['rechercher_entites'], Trousse::LECTURE, CasDuCorpus::LISTE,
                note: 'La faute de frappe d\'origine (« non renouvellebale ») est corrigée : '
                    . 'le corpus mesure le choix d\'outil, pas la tolérance à la dactylographie.',
            ),
            new CasDuCorpus(
                'liste-avenants-echus',
                'Donne-moi la liste des avenants échus à ce jour.',
                ['rechercher_entites'], Trousse::LECTURE, CasDuCorpus::LISTE,
            ),
            new CasDuCorpus(
                'liste-avenants-en-cours',
                'Donne-moi la liste des avenants qui sont en cours.',
                ['rechercher_entites'], Trousse::LECTURE, CasDuCorpus::LISTE,
            ),
            new CasDuCorpus(
                'liste-propositions-en-attente',
                'Donne-moi la liste des propositions en attente.',
                ['rechercher_entites'], Trousse::LECTURE, CasDuCorpus::LISTE,
            ),
            new CasDuCorpus(
                'liste-propositions-caduques',
                'Donne-moi la liste des propositions tombées caduques.',
                ['rechercher_entites'], Trousse::LECTURE, CasDuCorpus::LISTE,
            ),
            new CasDuCorpus(
                'liste-partenaires',
                'Affiche-moi la liste des partenaires enregistrés.',
                ['rechercher_entites'], Trousse::LECTURE, CasDuCorpus::LISTE,
            ),
            new CasDuCorpus(
                'liste-partenaires-avec-taux',
                'Affiche-moi la liste des partenaires, et ajoute aussi leurs taux de rétrocommission.',
                ['rechercher_entites'], Trousse::LECTURE, CasDuCorpus::LISTE,
                note: 'Recouvrement avec retrocommissions : le courtier demande une LISTE '
                    . 'de partenaires, pas un décompte de rétrocessions.',
            ),
            new CasDuCorpus(
                'liste-taches-d-un-avenant',
                'Affiche-moi la liste des tâches liées à l\'avenant 42.',
                ['rechercher_entites'], Trousse::LECTURE, CasDuCorpus::LISTE,
            ),
            new CasDuCorpus(
                'liste-polices-d-un-client',
                'Liste les polices du client Métallia.',
                ['rechercher_entites'], Trousse::LECTURE, CasDuCorpus::LISTE,
            ),
            new CasDuCorpus(
                'liste-polices-en-vigueur-d-un-client',
                'Quelles polices sont en place pour Mme Perrin ?',
                ['rechercher_entites'], Trousse::LECTURE, CasDuCorpus::LISTE,
            ),
            new CasDuCorpus(
                'liste-avenants-par-reference',
                'Donne la liste des avenants portant cette référence : 10001-20002-0003-111-00000001-2026',
                ['rechercher_entites'], Trousse::LECTURE, CasDuCorpus::LISTE,
            ),
            new CasDuCorpus(
                'liste-avenants-primes-impayees',
                'Liste des avenants dont les primes sont impayées.',
                ['rechercher_entites'], Trousse::LECTURE, CasDuCorpus::LISTE,
                note: 'RECOUVREMENT DÉLIBÉRÉ avec suivi_impayes. La demande porte sur des '
                    . 'AVENANTS filtrés, pas sur le décompte des soldes par tranche.',
            ),
            new CasDuCorpus(
                'liste-types-de-conge',
                'Quels sont les types de congé qu\'on a dans la base ?',
                ['rechercher_entites'], Trousse::LECTURE, CasDuCorpus::LISTE,
            ),
            new CasDuCorpus(
                'liste-sinistres-d-un-client',
                'Toujours pour le même client : a-t-il des sinistres ?',
                ['rechercher_entites'], Trousse::LECTURE, CasDuCorpus::LISTE,
            ),
            new CasDuCorpus(
                'existence-client-particulier',
                'Avons-nous un client au nom de Perrin ?',
                ['rechercher_entites'], Trousse::LECTURE, CasDuCorpus::LISTE,
            ),
            new CasDuCorpus(
                'existence-client-entreprise',
                'Je voulais savoir si nous avons un client nommé Novacom.',
                ['rechercher_entites'], Trousse::LECTURE, CasDuCorpus::LISTE,
            ),
            new CasDuCorpus(
                'existence-risque',
                'Avons-nous un risque, un type de couverture d\'assurance, qui s\'appelle Assurance Voyage ?',
                ['rechercher_entites'], Trousse::LECTURE, CasDuCorpus::LISTE,
                note: 'RECOUVREMENT avec catalogue_des_risques : ici on vérifie une EXISTENCE '
                    . 'précise, pas le catalogue entier.',
            ),
            new CasDuCorpus(
                'pistes-d-un-client',
                'Parle-moi des pistes de Mme Perrin.',
                ['rechercher_entites'], Trousse::LECTURE, CasDuCorpus::LISTE,
            ),
            new CasDuCorpus(
                'piste-en-cours-d-un-client',
                'A-t-elle une piste en cours ?',
                ['rechercher_entites'], Trousse::LECTURE, CasDuCorpus::LISTE,
            ),
            new CasDuCorpus(
                'offres-d-une-piste',
                'Est-ce que la piste 107 a déjà des offres ?',
                ['rechercher_entites'], Trousse::LECTURE, CasDuCorpus::LISTE,
            ),
            new CasDuCorpus(
                'cotations-d-une-piste',
                'La piste 1 a combien de cotations ?',
                ['rechercher_entites'], Trousse::LECTURE, CasDuCorpus::LISTE,
                note: 'Un COMPTAGE exprimé en « combien » mais qui appelle la liste : le courtier '
                    . 'veut voir les cotations, pas leur nombre. C\'est l\'hésitation entre les deux '
                    . 'outils d\'alors qui a produit le nom inventé « lister_entites » ; ils n\'en '
                    . 'font plus qu\'un depuis le 2026-09-26, et ce cas mesure désormais le choix '
                    . 'du MODE plutôt que celui de l\'outil.',
            ),
            new CasDuCorpus(
                'clients-du-cabinet',
                'Peux-tu me donner la liste des assurés que nous avons dans la base ?',
                ['rechercher_entites'], Trousse::LECTURE, CasDuCorpus::LISTE,
            ),
            new CasDuCorpus(
                'bordereaux-recus',
                'Tu peux vérifier dans la base si nous avons reçu les bordereaux des assurés ?',
                ['rechercher_entites'], Trousse::LECTURE, CasDuCorpus::LISTE,
            ),
            new CasDuCorpus(
                'compter-clients',
                'Combien de clients avons-nous dans ce cabinet ?',
                ['rechercher_entites'], Trousse::LECTURE, CasDuCorpus::LISTE,
                note: 'Le comptage franc — mode=compte depuis la fusion du 2026-09-26 —, à opposer '
                    . 'à « la piste 1 a combien de cotations », qui appelle une liste.',
            ),
            new CasDuCorpus(
                'compter-clients-court',
                'Nombre de nos clients ?',
                ['rechercher_entites'], Trousse::LECTURE, CasDuCorpus::LISTE,
            ),
            new CasDuCorpus(
                'catalogue-risques-liste',
                'Donne-moi la liste des risques.',
                ['catalogue_des_risques'], Trousse::LECTURE, CasDuCorpus::LISTE,
                note: 'RECOUVREMENT avec rechercher_entites : le CATALOGUE du cabinet, pas '
                    . 'une recherche filtrée.',
            ),
            new CasDuCorpus(
                'catalogue-risques-conseil-transport',
                'Pour une société spécialisée dans le transport, quels types d\'assurance peut-on proposer ?',
                ['catalogue_des_risques'], Trousse::LECTURE, CasDuCorpus::LISTE,
            ),
            new CasDuCorpus(
                'catalogue-risques-conseil-aviation',
                'Liste les couvertures d\'assurance du catalogue adaptées à une société d\'aviation.',
                ['catalogue_des_risques'], Trousse::LECTURE, CasDuCorpus::LISTE,
            ),
            new CasDuCorpus(
                'catalogue-risques-conseil-import-export',
                'J\'ai un client commerçant qui fait de l\'import-export. Que puis-je lui proposer ?',
                ['catalogue_des_risques'], Trousse::LECTURE, CasDuCorpus::LISTE,
            ),
            new CasDuCorpus(
                'echeances-polices-echues',
                'Il reste combien de polices échues chez moi ?',
                ['vigie_echeances'], Trousse::LECTURE, CasDuCorpus::LISTE,
                note: 'RECOUVREMENT avec rechercher_entites (échéance=echus) : la vigie '
                    . 'répond, la recherche liste. Les deux sont défendables — d\'où le cas.',
            ),
            new CasDuCorpus(
                'echeances-a-renouveler',
                'Établis le plan de renouvellement des polices arrivant à échéance.',
                ['vigie_echeances'], Trousse::LECTURE, CasDuCorpus::LISTE,
                note: 'Le mot « plan » ne fait PAS de cette demande une écriture : le courtier '
                    . 'demande la liste de ce qui arrive à échéance.',
            ),
            new CasDuCorpus(
                'plan-du-jour',
                'Redonne-moi mon plan de la journée.',
                ['plan_du_jour'], Trousse::LECTURE, CasDuCorpus::LISTE,
            ),
            new CasDuCorpus(
                'plan-du-jour-mes-taches',
                'Quelles sont mes tâches à moi ?',
                ['plan_du_jour'], Trousse::LECTURE, CasDuCorpus::LISTE,
            ),
            new CasDuCorpus(
                'calendrier-conges',
                'Donne-moi le calendrier des congés.',
                ['conges'], Trousse::LECTURE, CasDuCorpus::LISTE,
            ),
            new CasDuCorpus(
                'chronologie-dossier',
                'Donne-moi une chronologie de tout ce qui a été fait pour ce client depuis la création de son compte.',
                ['chronologie'], Trousse::LECTURE, CasDuCorpus::LISTE,
            ),
            new CasDuCorpus(
                'chronologie-etat-dossier',
                'Qu\'est-ce qui est fait en base jusque-là pour le client Delvaux ?',
                ['chronologie'], Trousse::LECTURE, CasDuCorpus::LISTE,
            ),
            new CasDuCorpus(
                'depenses-d-un-exercice',
                'Peux-tu vérifier si nous avons enregistré des dépenses pour 2025 ?',
                ['detail_depenses'], Trousse::LECTURE, CasDuCorpus::LISTE,
            ),
        ];
    }

    /**
     * SUIVI DES IMPAYÉS ET DES COMMISSIONS — 36 appels réels, 10,1 % des lectures.
     *
     * Famille très stéréotypée, et très recouvrante : `paiements_prime` et `lire_soa`
     * parlent du même argent sous un autre angle. C'est le groupe où le lot 5 devra
     * écrire « ne pas utiliser pour…, utiliser plutôt… ».
     *
     * @return list<CasDuCorpus>
     */
    private static function impayes(): array
    {
        return [
            new CasDuCorpus(
                'impayes-liste',
                'Donne-moi la liste des primes impayées de mon portefeuille.',
                ['suivi_impayes'], Trousse::LECTURE, CasDuCorpus::IMPAYES,
            ),
            new CasDuCorpus(
                'impayes-liste-tableau',
                'Donne-moi toute la liste des primes dues et impayées de mon portefeuille, dans un tableau.',
                ['suivi_impayes'], Trousse::LECTURE, CasDuCorpus::IMPAYES,
            ),
            new CasDuCorpus(
                'impayes-echues-seulement',
                'Dans cela, affiche uniquement celles dont les primes sont échues.',
                ['suivi_impayes'], Trousse::LECTURE, CasDuCorpus::IMPAYES,
            ),
            new CasDuCorpus(
                'impayes-non-echues',
                'Celles dont les primes ne sont pas encore échues.',
                ['suivi_impayes'], Trousse::LECTURE, CasDuCorpus::IMPAYES,
            ),
            new CasDuCorpus(
                'impayes-tranches-commission-payee',
                'Donne-moi les tranches dont les commissions ont été payées.',
                ['suivi_impayes'], Trousse::LECTURE, CasDuCorpus::IMPAYES,
            ),
            new CasDuCorpus(
                'impayes-tranches-commission-partielle',
                'Tranches à commissions partiellement encaissées.',
                ['suivi_impayes'], Trousse::LECTURE, CasDuCorpus::IMPAYES,
            ),
            new CasDuCorpus(
                'impayes-tranches-prime-payee-echue',
                'Tranches à primes payées, échues, à commission payée.',
                ['suivi_impayes'], Trousse::LECTURE, CasDuCorpus::IMPAYES,
            ),
            new CasDuCorpus(
                'impayes-compter-tranches',
                'Combien de tranches affichent une prime impayée actuellement ? Peux-tu me donner la liste numérotée ?',
                ['suivi_impayes'], Trousse::LECTURE, CasDuCorpus::IMPAYES,
            ),
            new CasDuCorpus(
                'impayes-commissions-facturables',
                'Quelle est la somme des commissions que je peux déjà facturer aux assureurs ?',
                ['suivi_impayes'], Trousse::LECTURE, CasDuCorpus::IMPAYES,
            ),
            new CasDuCorpus(
                'impayes-primes-a-suivre',
                'Donne-moi la liste des paiements de prime que je dois suivre.',
                ['suivi_impayes'], Trousse::LECTURE, CasDuCorpus::IMPAYES,
            ),
            new CasDuCorpus(
                'paiements-liste',
                'Affiche-moi la liste des paiements de prime.',
                ['paiements_prime'], Trousse::LECTURE, CasDuCorpus::IMPAYES,
                note: 'RECOUVREMENT avec suivi_impayes : ici ce sont les SIGNALEMENTS déjà '
                    . 'enregistrés, pas les soldes restants.',
            ),
            new CasDuCorpus(
                'paiements-recents',
                'Peux-tu me faire un tableau détaillé de mes clients qui ont récemment payé leur prime, ces trois derniers mois ?',
                ['paiements_prime'], Trousse::LECTURE, CasDuCorpus::IMPAYES,
            ),
            new CasDuCorpus(
                'paiements-deux-derniers-mois',
                'Donne-moi la liste détaillée de tous les paiements de primes que nous avons signalés ces deux derniers mois.',
                ['paiements_prime'], Trousse::LECTURE, CasDuCorpus::IMPAYES,
            ),
            new CasDuCorpus(
                'paiement-prime-encore-impayee',
                'Cette prime est-elle encore impayée ?',
                ['paiements_prime'], Trousse::LECTURE, CasDuCorpus::IMPAYES,
            ),
            new CasDuCorpus(
                'soa-etat-client',
                'Qu\'est-ce que tu sais aujourd\'hui sur le client Cimenterie du Levant ?',
                ['lire_soa'], Trousse::LECTURE, CasDuCorpus::IMPAYES,
                note: 'Cas limite assumé : la question est ouverte, et lire_soa n\'est qu\'une '
                    . 'réponse défendable parmi plusieurs. Il est au corpus POUR ÇA.',
            ),
            new CasDuCorpus(
                'retrocommissions-agents',
                'Donne-moi les rétrocommissions des agents.',
                ['retrocommissions'], Trousse::LECTURE, CasDuCorpus::IMPAYES,
            ),
            new CasDuCorpus(
                'retrocommissions-partenaire',
                'Y a-t-il une rétrocommission pour un partenaire comme Altéa ?',
                ['retrocommissions'], Trousse::LECTURE, CasDuCorpus::IMPAYES,
            ),
            new CasDuCorpus(
                'retrocommissions-total-intermediaire',
                'Calcule le montant total des rétrocommissions dues à l\'intermédiaire Verdon SA.',
                ['retrocommissions'], Trousse::LECTURE, CasDuCorpus::IMPAYES,
            ),
            new CasDuCorpus(
                'retrocommissions-decompte-agent',
                'Affiche le décompte de la rétrocommission due à l\'agent Nadia.',
                ['retrocommissions'], Trousse::LECTURE, CasDuCorpus::IMPAYES,
                note: 'ÉCORCHURE RÉELLE : cette demande a produit « retrocommissions_agent », '
                    . 'un nom qui n\'existe pas et qui est à distance 6 du vrai — hors de '
                    . 'portée du rattrapage. Deux appels perdus.',
            ),
            new CasDuCorpus(
                'retrocommissions-dues-a-ce-jour',
                'Donne tous les détails depuis la prime. Lesquelles sont dues à ce jour ?',
                ['retrocommissions'], Trousse::LECTURE, CasDuCorpus::IMPAYES,
            ),
        ];
    }

    /**
     * CLASSEMENTS ET VENTILATIONS — 30 appels réels, 8,4 % des lectures.
     *
     * LA FAMILLE LA PLUS STÉRÉOTYPÉE DU CORPUS, et c'est pourquoi le pilote du
     * chantier G porte sur elle : « top 5 de nos clients » apparaît sous cinq
     * formulations quasi identiques, y compris dictées et mal transcrites.
     *
     * @return list<CasDuCorpus>
     */
    private static function classement(): array
    {
        return [
            new CasDuCorpus(
                'classement-top-clients',
                'Donne-moi le top 5 de nos clients.',
                ['analyse_portefeuille'], Trousse::LECTURE, CasDuCorpus::CLASSEMENT,
            ),
            new CasDuCorpus(
                'classement-top-clients-variante-dictee',
                'top 5 de nos clients',
                ['analyse_portefeuille'], Trousse::LECTURE, CasDuCorpus::CLASSEMENT,
                note: 'Variante dictée, sans verbe ni ponctuation. Quatre formulations du '
                    . 'même besoin ont été relevées dans une seule conversation.',
            ),
            new CasDuCorpus(
                'classement-top-clients-detaille',
                'Affiche le top 5 des clients, et pour chaque client la prime et la commission générées.',
                ['analyse_portefeuille'], Trousse::LECTURE, CasDuCorpus::CLASSEMENT,
            ),
            new CasDuCorpus(
                'classement-meilleurs-assureurs',
                'Donne-moi les meilleurs assureurs de notre portefeuille, celui qui a généré la prime la plus élevée.',
                ['analyse_portefeuille'], Trousse::LECTURE, CasDuCorpus::CLASSEMENT,
            ),
            new CasDuCorpus(
                'classement-repartition-par-risque',
                'Donne-moi la répartition des primes par risque.',
                ['analyse_portefeuille'], Trousse::LECTURE, CasDuCorpus::CLASSEMENT,
            ),
            new CasDuCorpus(
                'classement-commissions-par-risque-et-assureur',
                'Donne-moi un tableau des volumes de commissions exigibles par risque et par assureur.',
                ['analyse_portefeuille'], Trousse::LECTURE, CasDuCorpus::CLASSEMENT,
            ),
            new CasDuCorpus(
                'classement-client-prime-la-plus-haute',
                'Quel est le client ayant généré la prime la plus élevée ?',
                ['analyse_portefeuille'], Trousse::LECTURE, CasDuCorpus::CLASSEMENT,
            ),
            new CasDuCorpus(
                'classement-risque-prime-la-plus-haute',
                'Quel est le risque ayant porté la prime la plus haute ?',
                ['analyse_portefeuille'], Trousse::LECTURE, CasDuCorpus::CLASSEMENT,
            ),
            new CasDuCorpus(
                'classement-prime-la-plus-basse',
                'Donne-moi la prime la plus basse du portefeuille et le nom du client qui la porte.',
                ['analyse_portefeuille'], Trousse::LECTURE, CasDuCorpus::CLASSEMENT,
            ),
            new CasDuCorpus(
                'classement-saturation',
                'Analyse le taux de couverture de mon portefeuille.',
                ['saturation_portefeuille'], Trousse::LECTURE, CasDuCorpus::CLASSEMENT,
                note: 'Un des quinze outils jamais vus comme dernier outil sur 826 messages. '
                    . 'Le cas existe pour que le corpus ne mesure pas seulement ce qui marche.',
            ),
            new CasDuCorpus(
                'statistiques-police-prime-la-plus-elevee',
                'Dans mon portefeuille, donne-moi la police qui a généré la prime la plus élevée, à partir des avenants.',
                ['statistiques'], Trousse::LECTURE, CasDuCorpus::CLASSEMENT,
                note: 'RECOUVREMENT FRONTAL avec analyse_portefeuille : « la prime la plus '
                    . 'élevée » est servie par les deux. Le corpus porte les deux versions '
                    . 'exprès, pour que le lot 5 ait de quoi trancher sur pièces.',
            ),
            new CasDuCorpus(
                'statistiques-prime-par-element',
                'Pour chaque élément, donne-moi la prime générée.',
                ['statistiques'], Trousse::LECTURE, CasDuCorpus::CLASSEMENT,
            ),
            new CasDuCorpus(
                'indicateur-commission-exigible-exercice',
                'Quel est le montant total de la commission exigible pour l\'exercice en cours ?',
                ['indicateur_calcule'], Trousse::LECTURE, CasDuCorpus::CLASSEMENT,
            ),
            new CasDuCorpus(
                'indicateur-prime-du-portefeuille',
                'Quelle est la prime totale du portefeuille ?',
                ['indicateur_calcule'], Trousse::LECTURE, CasDuCorpus::CLASSEMENT,
            ),
            new CasDuCorpus(
                'indicateur-reserve',
                'La réserve est de combien ?',
                ['indicateur_calcule'], Trousse::LECTURE, CasDuCorpus::CLASSEMENT,
            ),
            new CasDuCorpus(
                'indicateur-ca-commission-retro-taxes',
                'Donne le volume de chiffre d\'affaires, de commission, de rétrocommission et de taxes '
                    . '(ARCA et TVA) que ces affaires pourraient générer.',
                ['indicateur_calcule'], Trousse::LECTURE, CasDuCorpus::CLASSEMENT,
            ),
            new CasDuCorpus(
                'indicateur-prime-de-renouvellement',
                'Combien le client doit-il payer comme prime pour ce renouvellement ?',
                ['indicateur_calcule'], Trousse::LECTURE, CasDuCorpus::CLASSEMENT,
            ),
            new CasDuCorpus(
                'document-comptable-tva',
                'Sur la commission de courtage, quelle est la part de l\'ARCA et quelle est la part de la TVA ?',
                ['document_comptable'], Trousse::LECTURE, CasDuCorpus::CLASSEMENT,
                note: 'RECOUVREMENT avec indicateur_calcule et lire_fiche. Dans le corpus réel, '
                    . 'cette question a été servie par lire_fiche — un choix discutable, conservé '
                    . 'tel quel dans la note mais pas dans l\'attendu.',
            ),
        ];
    }

    /**
     * FICHE D'UN ENREGISTREMENT — 27 appels réels, 7,5 % des lectures.
     *
     * Famille marquée par les relances : « redonne-moi les détails », « tu n'as pas
     * donné tous les détails ». Le courtier réclame le MÊME outil, plus complet.
     *
     * @return list<CasDuCorpus>
     */
    private static function fiche(): array
    {
        return [
            new CasDuCorpus(
                'fiche-piste',
                'Donne-moi les détails de la piste 109.',
                ['lire_fiche'], Trousse::LECTURE, CasDuCorpus::FICHE,
            ),
            new CasDuCorpus(
                'fiche-proposition',
                'Donne plus de détails sur la proposition 126.',
                ['lire_fiche'], Trousse::LECTURE, CasDuCorpus::FICHE,
            ),
            new CasDuCorpus(
                'fiche-partenaire',
                'Donne-moi les détails sur le partenaire 10. Tous les détails.',
                ['lire_fiche'], Trousse::LECTURE, CasDuCorpus::FICHE,
            ),
            new CasDuCorpus(
                'fiche-client-nomme',
                'Donne-moi les détails de Val d\'Argent.',
                ['lire_fiche'], Trousse::LECTURE, CasDuCorpus::FICHE,
            ),
            new CasDuCorpus(
                'fiche-avenant-derive',
                'Donne-moi les détails du nouvel avenant dérivé, celui qui est en place actuellement.',
                ['lire_fiche'], Trousse::LECTURE, CasDuCorpus::FICHE,
            ),
            new CasDuCorpus(
                'fiche-risque',
                'Donne-moi tous les détails du risque.',
                ['lire_fiche'], Trousse::LECTURE, CasDuCorpus::FICHE,
            ),
            new CasDuCorpus(
                'fiche-taux-enregistre',
                'Ce taux de 20 % est-il correctement enregistré en base ?',
                ['lire_fiche'], Trousse::LECTURE, CasDuCorpus::FICHE,
                note: 'PIÈGE DE VERBE : « enregistré » a longtemps armé la trousse d\'écriture. '
                    . 'C\'est une VÉRIFICATION, elle n\'écrit rien.',
            ),
            new CasDuCorpus(
                'fiche-police-encore-en-cours',
                'Cette police est-elle encore en cours, et quand expire-t-elle ?',
                ['lire_fiche'], Trousse::LECTURE, CasDuCorpus::FICHE,
            ),
            new CasDuCorpus(
                'fiche-commission-d-une-affaire',
                'Donne-moi le montant de commission que cette affaire a généré.',
                ['lire_fiche'], Trousse::LECTURE, CasDuCorpus::FICHE,
            ),
            new CasDuCorpus(
                'fiche-ventilation-commission',
                'Fais-moi la ventilation de cette commission.',
                ['lire_fiche'], Trousse::LECTURE, CasDuCorpus::FICHE,
            ),
            new CasDuCorpus(
                'fiche-cotation-d-un-assureur',
                'Donne-moi les détails de la cotation de Assurica.',
                ['lire_fiche'], Trousse::LECTURE, CasDuCorpus::FICHE,
            ),
            new CasDuCorpus(
                'fiche-etat-configuration',
                'Où en est la configuration de mon cabinet ? Que me manque-t-il ?',
                ['etat_configuration'], Trousse::LECTURE, CasDuCorpus::FICHE,
                note: 'Outil jamais appelé sur la période, et dont la description (1 041 o) '
                    . 'est disproportionnée à son schéma (175 o) : candidat du lot 5.',
            ),
            new CasDuCorpus(
                'fiche-solde-tokens',
                'Combien me reste-t-il de jetons ?',
                ['solde_tokens'], Trousse::LECTURE, CasDuCorpus::FICHE,
            ),
        ];
    }

    /**
     * FICHIERS ET DOCUMENTS — 26 appels réels, 7,3 % des lectures.
     *
     * Le recouvrement le plus net du catalogue : `telecharger_documents` cherche dans
     * le DOSSIER, `telecharger_fichiers` rend les pièces jointes DU CHAT. Deux noms
     * presque identiques pour deux portées sans rapport.
     *
     * @return list<CasDuCorpus>
     */
    private static function fichiers(): array
    {
        return [
            new CasDuCorpus(
                'fichiers-d-un-client',
                'Affiche-moi tous les fichiers du client Mme Perrin.',
                ['telecharger_documents'], Trousse::LECTURE, CasDuCorpus::FICHIERS,
            ),
            new CasDuCorpus(
                'fichiers-du-portefeuille',
                'Donne-moi les fichiers de tout mon portefeuille client.',
                ['telecharger_documents'], Trousse::LECTURE, CasDuCorpus::FICHIERS,
            ),
            new CasDuCorpus(
                'fichiers-filtres-par-nom',
                'Tous les fichiers de mon portefeuille dont le nom contient le mot « police ».',
                ['telecharger_documents'], Trousse::LECTURE, CasDuCorpus::FICHIERS,
            ),
            new CasDuCorpus(
                'documents-d-un-client-entreprise',
                'Donne-moi les documents du client Télécom Azur SA.',
                ['telecharger_documents'], Trousse::LECTURE, CasDuCorpus::FICHIERS,
            ),
            new CasDuCorpus(
                'document-contient-un-fichier',
                'Le document 12 contient-il un fichier ?',
                ['telecharger_documents'], Trousse::LECTURE, CasDuCorpus::FICHIERS,
            ),
            new CasDuCorpus(
                'documents-avec-fichiers-telechargeables',
                'Lesquels de ces documents contiennent des fichiers téléchargeables ?',
                ['telecharger_documents'], Trousse::LECTURE, CasDuCorpus::FICHIERS,
            ),
            new CasDuCorpus(
                'fichier-d-une-police',
                'Donne le fichier de la police ASRVOY00000001.',
                ['telecharger_documents'], Trousse::LECTURE, CasDuCorpus::FICHIERS,
            ),
            new CasDuCorpus(
                'polices-et-fichiers-d-un-client',
                'Liste les polices et les fichiers du client Mine du Haut-Plateau SA.',
                ['telecharger_documents'], Trousse::LECTURE, CasDuCorpus::FICHIERS,
                note: 'Demande COMPOSÉE : deux portées dans une phrase. À ne jamais router '
                    . 'directement (chantier G, risque n° 2).',
            ),
            new CasDuCorpus(
                'documents-liste-courte',
                'liste documents',
                ['telecharger_documents'], Trousse::LECTURE, CasDuCorpus::FICHIERS,
                note: 'Formulation télégraphique réelle. Deux mots, aucun verbe conjugué.',
            ),
            new CasDuCorpus(
                'fichiers-du-chat',
                'J\'ai un fichier ici, la copie de la police. Que faire ?',
                ['telecharger_fichiers'], Trousse::LECTURE, CasDuCorpus::FICHIERS,
                contexte: CasDuCorpus::CONTEXTE_PIECE_JOINTE,
                note: 'RECOUVREMENT avec telecharger_documents, tranché par le contexte : une '
                    . 'pièce jointe du CHAT, pas un document du dossier.',
            ),
            new CasDuCorpus(
                'export-portefeuille-excel',
                'Génère l\'état de mon portefeuille en classeur Excel.',
                ['echange_exporter'], Trousse::LECTURE, CasDuCorpus::FICHIERS,
                note: 'Outil à renommer au lot 4 : « echange_exporter » est à distance 2 de '
                    . '« echange_importer », qui est de l\'autre côté de la frontière — le '
                    . 'rattrapage refuse donc de trancher sur cette famille.',
            ),
            new CasDuCorpus(
                'echange-consulter',
                'Comment fonctionne la rubrique Importation / Exportation des données ?',
                ['echange_consulter'], Trousse::LECTURE, CasDuCorpus::FICHIERS,
            ),
        ];
    }

    /**
     * CONNAISSANCE MÉTIER — 19 appels réels, 5,3 % des lectures.
     *
     * `consulter_guide` est l'outil dont la description est la plus lourde du catalogue
     * (1 956 o pour 328 o de schéma), parce qu'elle énumère dynamiquement le catalogue
     * des fiches. C'est la cible n° 1 du lot 5.
     *
     * @return list<CasDuCorpus>
     */
    private static function guide(): array
    {
        return [
            new CasDuCorpus(
                'guide-retrocommission-calcul',
                'Comment se calcule la rétrocommission d\'un agent ?',
                ['consulter_guide'], Trousse::LECTURE, CasDuCorpus::GUIDE,
            ),
            new CasDuCorpus(
                'guide-retrocommission-exigibilite',
                'Avant que la rétrocommission soit exigible, il faut que la commission soit encaissée, non ?',
                ['consulter_guide'], Trousse::LECTURE, CasDuCorpus::GUIDE,
            ),
            new CasDuCorpus(
                'guide-bordereau',
                'C\'est quoi un bordereau de production ?',
                ['consulter_guide'], Trousse::LECTURE, CasDuCorpus::GUIDE,
            ),
            new CasDuCorpus(
                'guide-sinistre',
                'Qu\'est-ce que je fais en cas de sinistre ?',
                ['consulter_guide'], Trousse::LECTURE, CasDuCorpus::GUIDE,
            ),
            new CasDuCorpus(
                'guide-capacites',
                'En quoi et comment penses-tu pouvoir m\'aider dans cette plateforme ?',
                ['consulter_guide'], Trousse::LECTURE, CasDuCorpus::GUIDE,
            ),
            new CasDuCorpus(
                'guide-capacites-court',
                'Que peux-tu faire pour moi ici ?',
                ['consulter_guide'], Trousse::LECTURE, CasDuCorpus::GUIDE,
            ),
            new CasDuCorpus(
                'guide-prudentiel',
                'Tu peux aussi gérer les états prudentiels ?',
                ['consulter_guide'], Trousse::LECTURE, CasDuCorpus::GUIDE,
            ),
            new CasDuCorpus(
                'guide-signaler-paiement',
                'Les clients les ont payées. Que faire pour les signaler sur l\'application ?',
                ['consulter_guide'], Trousse::LECTURE, CasDuCorpus::GUIDE,
                note: 'PIÈGE DE VERBE : « signaler » arme l\'écriture, alors que la demande est '
                    . 'une question de MODE D\'EMPLOI. Le corpus attend la lecture — c\'est un '
                    . 'des cas qui mesurent le sur-armement.',
            ),
            new CasDuCorpus(
                'guide-attacher-fichiers-capacite',
                'Peux-tu attacher des fichiers à n\'importe quel objet de la plateforme ? Si oui, lesquels ?',
                ['consulter_guide'], Trousse::LECTURE, CasDuCorpus::GUIDE,
                note: 'ÉCORCHURE RÉELLE : cette demande a produit « consultar_guide », en '
                    . 'espagnol. Distance 1 — le rattrapage la sauve.',
            ),
            new CasDuCorpus(
                'guide-identite-cabinet',
                'Comment s\'appelle mon cabinet, et quel est ton rôle ici ?',
                ['consulter_guide'], Trousse::LECTURE, CasDuCorpus::GUIDE,
            ),
            new CasDuCorpus(
                'guide-reste-a-faire',
                'Qu\'est-ce qui me reste à faire pour ce compte ?',
                ['consulter_guide'], Trousse::LECTURE, CasDuCorpus::GUIDE,
                note: 'RECOUVREMENT avec plan_du_jour et chronologie. Trois outils répondent '
                    . 'à « et maintenant ? ».',
            ),
        ];
    }

    /**
     * ÉCRITURE — 46 appels de `preparer_operations` à eux seuls, plus les outils dédiés.
     *
     * RAPPEL DU CHANTIER PRÉCÉDENT : le besoin réel d'écriture est de 35,5 %, et
     * l'aiguillage en arme environ 1,5 fois trop. Ce n'est donc PAS le gisement — mais
     * le corpus doit porter l'écriture, sans quoi il ne mesurerait qu'une moitié du
     * catalogue, et la frontière ne serait éprouvée nulle part.
     *
     * @return list<CasDuCorpus>
     */
    private static function ecriture(): array
    {
        return [
            new CasDuCorpus(
                'ecriture-creer-client',
                'Crée-moi le compte client de Mme Perrin.',
                ['preparer_operations'], Trousse::ECRITURE, CasDuCorpus::ECRITURE,
            ),
            new CasDuCorpus(
                'ecriture-creer-client-depuis-kyc',
                'Voici le KYC. Crée-moi le compte de ce client.',
                ['preparer_operations'], Trousse::ECRITURE, CasDuCorpus::ECRITURE,
                contexte: CasDuCorpus::CONTEXTE_PIECE_JOINTE,
            ),
            new CasDuCorpus(
                'ecriture-modifier-prenom',
                'Modifie son prénom. C\'est « Claire ».',
                ['preparer_operations'], Trousse::ECRITURE, CasDuCorpus::ECRITURE,
            ),
            new CasDuCorpus(
                'ecriture-corriger-taux-risque',
                'Corrige le taux de commission du risque Assurance Voyage à 20 %.',
                ['preparer_operations'], Trousse::ECRITURE, CasDuCorpus::ECRITURE,
            ),
            new CasDuCorpus(
                'ecriture-creer-piste',
                'Mme Perrin m\'a demandé de lui trouver une assurance voyage. Crée une piste pour cela.',
                ['preparer_operations'], Trousse::ECRITURE, CasDuCorpus::ECRITURE,
            ),
            new CasDuCorpus(
                'ecriture-creer-fournisseur-puis-depense',
                'Commençons par créer ce fournisseur, puis nous enregistrerons la dépense.',
                ['preparer_operations'], Trousse::ECRITURE, CasDuCorpus::ECRITURE,
            ),
            new CasDuCorpus(
                'ecriture-enregistrer-depense',
                'Le 11/08/2026, 250 $ d\'entretien de véhicule. Le fournisseur : Garage Pramex. Payé depuis la banque.',
                ['preparer_operations'], Trousse::ECRITURE, CasDuCorpus::ECRITURE,
            ),
            new CasDuCorpus(
                'ecriture-ajouter-contact',
                'Ajoute un contact au compte de Mme Perrin : son mari, M. Hubert Perrin, téléphone +243810000001.',
                ['preparer_operations'], Trousse::ECRITURE, CasDuCorpus::ECRITURE,
            ),
            new CasDuCorpus(
                'ecriture-marquer-tache-close',
                'Sa tâche de suivi de paiement de la prime : marque-la comme terminée.',
                ['preparer_operations'], Trousse::ECRITURE, CasDuCorpus::ECRITURE,
            ),
            new CasDuCorpus(
                'ecriture-saisir-proposition',
                'Voici la cotation de Assurica, enregistre-la. Prime TTC 1 200 $, durée 12 mois, une seule tranche.',
                ['saisir_proposition'], Trousse::ECRITURE, CasDuCorpus::ECRITURE,
            ),
            new CasDuCorpus(
                'ecriture-saisir-proposition-ventilation',
                'On va créer une proposition venant de Assurica. Prime 200 $ : prime nette 170, accessoires 10, '
                    . 'TVA 20. Payable en une tranche.',
                ['saisir_proposition'], Trousse::ECRITURE, CasDuCorpus::ECRITURE,
            ),
            new CasDuCorpus(
                'ecriture-saisir-proposition-concurrente',
                'Fidelis vient aussi de proposer son offre, 15 % plus chère.',
                ['saisir_proposition'], Trousse::ECRITURE, CasDuCorpus::ECRITURE,
                contexte: CasDuCorpus::CONTEXTE_DERNIER_TOUR_A_ECRIT,
            ),
            new CasDuCorpus(
                'ecriture-souscrire-cotation',
                'Je te donne le feu vert. La couverture débute aujourd\'hui pour 12 mois. '
                    . 'Référence de la police : ASR21000001.',
                ['souscrire_cotation'], Trousse::ECRITURE, CasDuCorpus::ECRITURE,
                contexte: CasDuCorpus::CONTEXTE_A_PROPOSE_D_ECRIRE,
            ),
            new CasDuCorpus(
                'ecriture-souscrire-offre-validee',
                'La proposition de Assurica est validée par le client. C\'est donc Assurica qui va assurer ce risque.',
                ['souscrire_cotation'], Trousse::ECRITURE, CasDuCorpus::ECRITURE,
            ),
            new CasDuCorpus(
                'ecriture-renouveler-avenant',
                'Renouvelle cet avenant pour 12 mois, à l\'identique.',
                ['preparer_mouvement_avenant'], Trousse::ECRITURE, CasDuCorpus::ECRITURE,
            ),
            new CasDuCorpus(
                'ecriture-renouveler-police-par-reference',
                'La police 10001-20002-0003-111-00000001-2026 doit être renouvelée à l\'identique, aux mêmes termes.',
                ['preparer_mouvement_avenant'], Trousse::ECRITURE, CasDuCorpus::ECRITURE,
            ),
            new CasDuCorpus(
                'ecriture-remettre-en-couverture',
                'On va la remettre en couverture à partir du 15/08/2026 pour 12 mois, avec le même assureur.',
                ['preparer_mouvement_avenant'], Trousse::ECRITURE, CasDuCorpus::ECRITURE,
            ),
            new CasDuCorpus(
                'ecriture-renouveler-toutes-les-echues',
                'Polices échues : on va toutes les renouveler pour une période de 12 mois.',
                ['preparer_mouvement_avenant'], Trousse::ECRITURE, CasDuCorpus::ECRITURE,
            ),
            new CasDuCorpus(
                'ecriture-marquer-non-renouvelable',
                'Celle-ci, il faut la marquer non renouvelable. Fais cette action pour moi, pas par l\'interface.',
                ['preparer_marquage_non_renouvelable'], Trousse::ECRITURE, CasDuCorpus::ECRITURE,
            ),
            new CasDuCorpus(
                'ecriture-signaler-paiement-prime',
                'Je voudrais signaler le paiement des tranches 60, 64 et 74. Donne-moi le plan.',
                ['signaler_paiement_prime'], Trousse::ECRITURE, CasDuCorpus::ECRITURE,
            ),
            new CasDuCorpus(
                'ecriture-signaler-paiement-simple',
                'Le client vient de payer sa prime, enregistre-la.',
                ['signaler_paiement_prime'], Trousse::ECRITURE, CasDuCorpus::ECRITURE,
            ),
            new CasDuCorpus(
                'ecriture-programme-plusieurs-tranches',
                'Je voudrais signaler les paiements des primes pour les tranches 64, 74 et 135.',
                ['preparer_programme'], Trousse::ECRITURE, CasDuCorpus::ECRITURE,
                note: 'PLUSIEURS écritures enchaînées : c\'est le déclencheur de preparer_programme, '
                    . 'et non une répétition de signaler_paiement_prime.',
            ),
            new CasDuCorpus(
                'ecriture-programme-creer-assureurs',
                'Crée-moi les assureurs suivants : Novara, Novara Vie, Assurica, Ternova, Ternova Vie, Belmont et Fidelis.',
                ['preparer_programme'], Trousse::ECRITURE, CasDuCorpus::ECRITURE,
            ),
            new CasDuCorpus(
                'ecriture-attacher-fichier',
                'Attache ce fichier à la proposition Fidelis de la couverture Santé.',
                ['attacher_fichier'], Trousse::ECRITURE, CasDuCorpus::ECRITURE,
                contexte: CasDuCorpus::CONTEXTE_PIECE_JOINTE,
            ),
            new CasDuCorpus(
                'ecriture-attacher-document-au-client',
                'Attache ce document au client 96 et dis-moi si c\'est fait correctement.',
                ['attacher_fichier'], Trousse::ECRITURE, CasDuCorpus::ECRITURE,
                contexte: CasDuCorpus::CONTEXTE_PIECE_JOINTE,
            ),
            new CasDuCorpus(
                'ecriture-analyser-fichier-proposition',
                'J\'ai une proposition qui vient de Fidelis, mais c\'est dans un fichier. Que faire ?',
                ['analyser_fichier_pour_saisie'], Trousse::ECRITURE, CasDuCorpus::ECRITURE,
                contexte: CasDuCorpus::CONTEXTE_PIECE_JOINTE,
            ),
            new CasDuCorpus(
                'ecriture-analyser-contrat-souscrit',
                'Je te fournis un contrat. C\'est une souscription, et les informations sont dans le fichier ci-joint.',
                ['analyser_fichier_pour_saisie'], Trousse::ECRITURE, CasDuCorpus::ECRITURE,
                contexte: CasDuCorpus::CONTEXTE_PIECE_JOINTE,
            ),
            new CasDuCorpus(
                'ecriture-importer-classeur',
                'Importe les données de ce classeur Excel dans mon portefeuille.',
                ['echange_importer'], Trousse::ECRITURE, CasDuCorpus::ECRITURE,
                contexte: CasDuCorpus::CONTEXTE_PIECE_JOINTE,
                note: 'Le jumeau de echange_exporter, dont il est à distance 2 — et de l\'autre '
                    . 'côté de la frontière. Les deux cas se lisent ensemble.',
            ),
            new CasDuCorpus(
                'ecriture-parcours-avant-creation',
                'Je voudrais ajouter un client. Par où commence-t-on ?',
                ['parcours_saisie'], Trousse::ECRITURE, CasDuCorpus::ECRITURE,
            ),
            new CasDuCorpus(
                'ecriture-parcours-cotation',
                'J\'ai une offre venant de Fidelis. Que faire ?',
                ['parcours_saisie'], Trousse::ECRITURE, CasDuCorpus::ECRITURE,
            ),
            new CasDuCorpus(
                'ecriture-inventaire-champs-client',
                'Enregistrer un nouveau client dans le portefeuille : de quoi as-tu besoin venant de moi ?',
                ['inventaire_champs'], Trousse::ECRITURE, CasDuCorpus::ECRITURE,
                note: 'RECOUVREMENT avec parcours_saisie : « de quoi as-tu besoin » appelle les '
                    . 'CHAMPS, « par où commence-t-on » appelle le PARCOURS.',
            ),
            new CasDuCorpus(
                'ecriture-inventaire-champs-risque',
                'Que faut-il te fournir pour créer ce risque ?',
                ['inventaire_champs'], Trousse::ECRITURE, CasDuCorpus::ECRITURE,
            ),
            new CasDuCorpus(
                'ecriture-inventaire-champs-invite',
                'De quoi auras-tu besoin pour me créer un collaborateur dans mon espace de travail ?',
                ['inventaire_champs'], Trousse::ECRITURE, CasDuCorpus::ECRITURE,
            ),
            new CasDuCorpus(
                'ecriture-etapes-possibles-d-une-piste',
                'Pour cette piste, quels sont les différents types d\'étapes possibles ?',
                ['inventaire_champs'], Trousse::ECRITURE, CasDuCorpus::ECRITURE,
            ),
            new CasDuCorpus(
                'ecriture-modifier-composition-prime',
                'Corrige la ventilation de la prime de cette cotation : prime nette 900, accessoires 50, TVA 50.',
                ['modifier_composition_prime'], Trousse::ECRITURE, CasDuCorpus::ECRITURE,
            ),
            new CasDuCorpus(
                'ecriture-revenus-de-courtage',
                'Tu as oublié de mettre les revenus de courtage, ajoute-les aussi.',
                ['modifier_composition_prime'], Trousse::ECRITURE, CasDuCorpus::ECRITURE,
                contexte: CasDuCorpus::CONTEXTE_DERNIER_TOUR_A_ECRIT,
            ),
            new CasDuCorpus(
                'ecriture-demande-conge',
                'Je voudrais poser mes congés du 12 au 20 octobre.',
                ['preparer_demande_conge'], Trousse::ECRITURE, CasDuCorpus::ECRITURE,
            ),
            new CasDuCorpus(
                'ecriture-decision-conge',
                'Approuve la demande de congé de l\'agent Boris.',
                ['preparer_decision_conge'], Trousse::ECRITURE, CasDuCorpus::ECRITURE,
            ),
            new CasDuCorpus(
                'ecriture-simuler-conge',
                'Combien de jours ouvrables me coûterait un congé du 12 au 20 octobre ?',
                ['simuler_conge'], Trousse::LECTURE, CasDuCorpus::ECRITURE,
                note: 'SIMULER N\'EST PAS ÉCRIRE. L\'outil est en trousse de LECTURE, et le '
                    . 'verbe « coûter » ne doit pas armer l\'écriture. Cas de contrôle du lot 6.',
            ),
            new CasDuCorpus(
                'ecriture-reversement-retro',
                'Enregistre le reversement de la rétrocommission à l\'agent Nadia.',
                ['signaler_reversement_retro_agent'], Trousse::ECRITURE, CasDuCorpus::ECRITURE,
            ),
            new CasDuCorpus(
                'ecriture-effort-commercial',
                'Rattache la condition de partage de Verdon SA à cette affaire.',
                ['effort_commercial_agent'], Trousse::ECRITURE, CasDuCorpus::ECRITURE,
            ),
        ];
    }

    /**
     * ACTIONS D'INTERFACE.
     *
     * Elles ne répondent pas dans le chat : elles ouvrent ou ferment quelque chose à
     * l'écran. C'est la raison pour laquelle `ouvrir_rubrique` N'EST PAS fusionné avec
     * `rechercher_entites` au lot 4, malgré un bloc de filtres recopié mot pour mot.
     *
     * @return list<CasDuCorpus>
     */
    private static function ecran(): array
    {
        return [
            new CasDuCorpus(
                'ecran-ouvrir-formulaire-avenant',
                'Ouvre-moi le formulaire d\'édition de l\'avenant 130.',
                ['ouvrir_dialogue'], Trousse::ECRITURE, CasDuCorpus::ECRAN,
            ),
            new CasDuCorpus(
                'ecran-ouvrir-formulaire-client',
                'Ouvre-moi le formulaire d\'édition de ce client, je vais modifier son nom.',
                ['ouvrir_dialogue'], Trousse::ECRITURE, CasDuCorpus::ECRAN,
            ),
            new CasDuCorpus(
                'ecran-ouvrir-formulaire-assureur',
                'Ouvre la fiche d\'édition pour Solaris Assurances, l\'assureur.',
                ['ouvrir_dialogue'], Trousse::ECRITURE, CasDuCorpus::ECRAN,
            ),
            new CasDuCorpus(
                'ecran-ouvrir-formulaire-risque',
                'Ouvre-moi le formulaire d\'édition du risque Assurance Voyage.',
                ['ouvrir_dialogue'], Trousse::ECRITURE, CasDuCorpus::ECRAN,
            ),
            new CasDuCorpus(
                'ecran-ouvrir-rubrique-assureurs',
                'Ouvre-moi la liste des assureurs dans l\'espace de travail.',
                ['ouvrir_rubrique'], Trousse::LECTURE, CasDuCorpus::ECRAN,
                note: 'RECOUVREMENT FRONTAL avec rechercher_entites, tranché par « dans l\'espace '
                    . 'de travail » : il faut un ONGLET, pas une réponse.',
            ),
            new CasDuCorpus(
                'ecran-ouvrir-rubrique-client',
                'Ouvre-moi la rubrique Client.',
                ['ouvrir_rubrique'], Trousse::LECTURE, CasDuCorpus::ECRAN,
            ),
            new CasDuCorpus(
                'ecran-liste-et-ouverture',
                'Donne-moi la liste des pistes de Mme Perrin, et ouvre cette liste dans l\'espace de travail.',
                ['rechercher_entites', 'ouvrir_rubrique'], Trousse::LECTURE, CasDuCorpus::ECRAN,
                note: 'LECTURE MULTI-OUTILS : répondre ET ouvrir. Le cas qui prouve que la '
                    . 'fusion des deux outils serait une perte.',
            ),
            new CasDuCorpus(
                'ecran-fermer-rubriques',
                'Ferme la rubrique Proposition, ainsi que les rubriques Piste et Avenant.',
                ['fermer_rubrique'], Trousse::LECTURE, CasDuCorpus::ECRAN,
            ),
            new CasDuCorpus(
                'ecran-visualiser-fiche',
                'Rouvre la fiche.',
                ['visualiser_fiche'], Trousse::LECTURE, CasDuCorpus::ECRAN,
            ),
            new CasDuCorpus(
                'ecran-preparer-envoi-soa',
                'Envoie le relevé de compte à ce client.',
                ['preparer_envoi_soa'], Trousse::LECTURE, CasDuCorpus::ECRAN,
            ),
            new CasDuCorpus(
                'ecran-envoyer-reponse-par-email',
                'Envoie cette réponse par e-mail à contact@exemple.test.',
                ['envoyer_message_par_email'], Trousse::LECTURE, CasDuCorpus::ECRAN,
                note: 'PIÈGE DE VERBE : « envoie » n\'écrit rien en base. L\'outil est en trousse '
                    . 'de lecture, et l\'armement de l\'écriture serait un coût pur.',
            ),
            new CasDuCorpus(
                'ecran-quitter-workspace',
                'Ferme mon espace de travail.',
                ['quitter_workspace'], Trousse::LECTURE, CasDuCorpus::ECRAN,
            ),
        ];
    }

    /**
     * PRODUCTION DE DOCUMENT — 15 appels réels.
     *
     * Famille remarquablement uniforme : « produis-moi un rapport à partir de cette
     * réponse ». Elle n'interroge AUCUNE donnée nouvelle — elle met en forme ce qui
     * est déjà dans le fil.
     *
     * @return list<CasDuCorpus>
     */
    private static function document(): array
    {
        return [
            new CasDuCorpus(
                'document-rapport-depuis-reponse',
                'Produis-moi un rapport à partir de cette réponse.',
                ['preparer_document'], Trousse::LECTURE, CasDuCorpus::DOCUMENT,
            ),
            new CasDuCorpus(
                'document-rapport-html',
                'Donne-moi ça sous forme de rapport en HTML.',
                ['preparer_document'], Trousse::LECTURE, CasDuCorpus::DOCUMENT,
            ),
            new CasDuCorpus(
                'document-rapport-word',
                'Fais-moi un rapport en Word sur la base de ces données.',
                ['preparer_document'], Trousse::LECTURE, CasDuCorpus::DOCUMENT,
            ),
            new CasDuCorpus(
                'document-exporter-etat',
                'Exporte-moi cet état comptable en Excel.',
                ['exporter_etat'], Trousse::LECTURE, CasDuCorpus::DOCUMENT,
                note: 'RECOUVREMENT avec preparer_document : FABRIQUER un document vs. '
                    . 'TÉLÉCHARGER un état déjà défini.',
            ),
        ];
    }

    /**
     * CONVERSATION PURE — le gisement du lot 6.
     *
     * 280 messages sur 826 (33,9 %) n'appellent aucun outil. Tous ne sont pas de la
     * conversation pure : beaucoup sont des relances (famille suivante). Ce qui suit
     * est le sous-ensemble NON AMBIGU, celui sur lequel une trousse minimale peut
     * partir sans risquer un tour de recours.
     *
     * @return list<CasDuCorpus>
     */
    private static function conversationPure(): array
    {
        return [
            new CasDuCorpus(
                'aucun-merci',
                'Très bien, merci.',
                [], Trousse::LECTURE, CasDuCorpus::AUCUN,
            ),
            new CasDuCorpus(
                'aucun-ok',
                'Ok',
                [], Trousse::LECTURE, CasDuCorpus::AUCUN,
                note: '⚠ NON AMBIGU SEULEMENT HORS CONTEXTE. Le même « Ok » après une '
                    . 'proposition d\'écriture est une CONFIRMATION — cf. ambigu-ok-apres-proposition.',
            ),
            new CasDuCorpus(
                'aucun-salutation',
                'Salut Ket',
                [], Trousse::LECTURE, CasDuCorpus::AUCUN,
            ),
            new CasDuCorpus(
                'aucun-bonjour',
                'Bonjour',
                [], Trousse::LECTURE, CasDuCorpus::AUCUN,
            ),
            new CasDuCorpus(
                'aucun-remise-en-forme',
                'Refais le même tableau, mais trie-le par montant décroissant.',
                [], Trousse::LECTURE, CasDuCorpus::AUCUN,
                note: 'REMETTRE EN FORME N\'EXIGE AUCUNE DONNÉE NOUVELLE : tout est déjà dans '
                    . 'le fil. Un des cas les plus rentables du lot 6.',
            ),
            new CasDuCorpus(
                'aucun-ajouter-colonne',
                'Ajoute aussi une colonne pour le taux.',
                [], Trousse::LECTURE, CasDuCorpus::AUCUN,
                note: 'Limite fine : si le taux n\'est pas dans le fil, il faut un outil. '
                    . 'Le cas est au corpus pour mesurer ce faux positif, pas pour le nier.',
            ),
            new CasDuCorpus(
                'aucun-total',
                'Fais-moi le total de cette colonne.',
                [], Trousse::LECTURE, CasDuCorpus::AUCUN,
            ),
            new CasDuCorpus(
                'aucun-traduire',
                'Traduis-moi ce tableau en anglais.',
                [], Trousse::LECTURE, CasDuCorpus::AUCUN,
            ),
            new CasDuCorpus(
                'aucun-resume',
                'Résume-moi tout ça en trois phrases.',
                [], Trousse::LECTURE, CasDuCorpus::AUCUN,
            ),
            new CasDuCorpus(
                'aucun-explication-de-soi',
                'Explique-moi ce que tu as fait au juste, concrètement.',
                [], Trousse::LECTURE, CasDuCorpus::AUCUN,
            ),
        ];
    }

    /**
     * ⚠ LES RELANCES — LE PIÈGE DU LOT 6, ET IL EST MESURÉ.
     *
     * Ces phrases sont massives dans le corpus réel et leur sens ne vient QUE du fil.
     * « essaie encore » apparaît sous `rechercher_entites`, sous `suivi_impayes`, sous
     * `lire_fiche` et sous aucun outil. Les traiter comme de la conversation pure
     * coûterait un tour de recours à chaque fois ; les traiter comme des demandes
     * coûterait la grosse trousse pour un « merci ».
     *
     * C'est pourquoi le déclencheur `acquiescement` du lot 6 se place APRÈS les six
     * signaux structurels, et non en tête : le contexte doit parler le premier.
     *
     * @return list<CasDuCorpus>
     */
    private static function relances(): array
    {
        return [
            new CasDuCorpus(
                'ambigu-essaie-encore',
                'Essaie encore.',
                ['rechercher_entites'], Trousse::LECTURE, CasDuCorpus::AMBIGU,
                note: 'RELANCE D\'UNE LECTURE : l\'outil du tour précédent doit repartir. '
                    . 'Ne doit JAMAIS donner la trousse minimale.',
            ),
            new CasDuCorpus(
                'ambigu-essaie-encore-stp',
                'Essaie encore stp',
                ['suivi_impayes'], Trousse::LECTURE, CasDuCorpus::AMBIGU,
            ),
            new CasDuCorpus(
                'ambigu-vas-y',
                'Vas-y',
                ['rechercher_entites'], Trousse::LECTURE, CasDuCorpus::AMBIGU,
            ),
            new CasDuCorpus(
                'ambigu-la-suivante',
                'La suivante.',
                ['preparer_mouvement_avenant'], Trousse::ECRITURE, CasDuCorpus::AMBIGU,
                contexte: CasDuCorpus::CONTEXTE_PROGRAMME_EN_COURS,
                note: 'La MÊME phrase, dans un programme en cours, désigne l\'étape suivante '
                    . 'd\'une série d\'écritures.',
            ),
            new CasDuCorpus(
                'ambigu-le-suivant',
                'Le suivant.',
                ['signaler_paiement_prime'], Trousse::ECRITURE, CasDuCorpus::AMBIGU,
                contexte: CasDuCorpus::CONTEXTE_PROGRAMME_EN_COURS,
            ),
            new CasDuCorpus(
                'ambigu-ok-apres-proposition',
                'Ok',
                ['preparer_operations'], Trousse::ECRITURE, CasDuCorpus::AMBIGU,
                contexte: CasDuCorpus::CONTEXTE_A_PROPOSE_D_ECRIRE,
                note: '⚠ LE CAS QUI PROUVE L\'ORDRE DES DÉCLENCHEURS. Le même « Ok » que '
                    . 'aucun-ok, mais après une proposition d\'écrire : c\'est une CONFIRMATION. '
                    . 'Si le déclencheur `acquiescement` passait en tête, cette validation '
                    . 'partirait sans outil d\'écriture et le courtier n\'aurait jamais son bouton.',
            ),
            new CasDuCorpus(
                'ambigu-je-confirme',
                'Je confirme.',
                ['preparer_operations'], Trousse::ECRITURE, CasDuCorpus::AMBIGU,
                contexte: CasDuCorpus::CONTEXTE_PLAN_EN_ATTENTE,
            ),
            new CasDuCorpus(
                'ambigu-oui-merci',
                'Oui, merci.',
                ['preparer_operations'], Trousse::ECRITURE, CasDuCorpus::AMBIGU,
                contexte: CasDuCorpus::CONTEXTE_A_PROPOSE_D_ECRIRE,
                note: 'Un remerciement qui est une validation. Le mot « merci » ne suffit '
                    . 'jamais à conclure qu\'aucun outil n\'est nécessaire.',
            ),
            new CasDuCorpus(
                'ambigu-continuons',
                'Ok, continuons.',
                ['preparer_operations'], Trousse::ECRITURE, CasDuCorpus::AMBIGU,
                contexte: CasDuCorpus::CONTEXTE_PROGRAMME_EN_COURS,
            ),
            new CasDuCorpus(
                'ambigu-c-est-fait',
                'C\'est fait ?',
                ['lire_fiche'], Trousse::LECTURE, CasDuCorpus::AMBIGU,
                contexte: CasDuCorpus::CONTEXTE_DERNIER_TOUR_A_ECRIT,
                note: '⚠ RETIRÉ DES MARQUEURS D\'ACQUIESCEMENT du lot 6. C\'est une QUESTION : '
                    . 'le courtier demande une vérification en base, et elle appelle une lecture.',
            ),
            new CasDuCorpus(
                'ambigu-verifie-et-confirme',
                'C\'est fait ? Vérifie et confirme-moi.',
                ['lire_fiche'], Trousse::LECTURE, CasDuCorpus::AMBIGU,
                contexte: CasDuCorpus::CONTEXTE_DERNIER_TOUR_A_ECRIT,
            ),
            new CasDuCorpus(
                'ambigu-prouve-le',
                'Est-ce correctement enregistré ? Si oui, prouve-le.',
                ['lire_fiche'], Trousse::LECTURE, CasDuCorpus::AMBIGU,
                contexte: CasDuCorpus::CONTEXTE_DERNIER_TOUR_A_ECRIT,
            ),
            new CasDuCorpus(
                'ambigu-redonne-les-details',
                'Redonne-moi les détails ici, dans le chat.',
                ['lire_fiche'], Trousse::LECTURE, CasDuCorpus::AMBIGU,
            ),
            new CasDuCorpus(
                'ambigu-refais',
                'Refais, s\'il te plaît.',
                ['preparer_operations'], Trousse::ECRITURE, CasDuCorpus::AMBIGU,
                contexte: CasDuCorpus::CONTEXTE_A_PROPOSE_D_ECRIRE,
            ),
            new CasDuCorpus(
                'ambigu-donne-moi-le-plan',
                'Donne-moi maintenant le plan pour validation.',
                ['preparer_operations'], Trousse::ECRITURE, CasDuCorpus::AMBIGU,
                contexte: CasDuCorpus::CONTEXTE_A_PROPOSE_D_ECRIRE,
            ),
            new CasDuCorpus(
                'ambigu-reponds-stp',
                'Réponds, s\'il te plaît.',
                ['consulter_guide'], Trousse::LECTURE, CasDuCorpus::AMBIGU,
                note: 'Une relance sur une question restée sans réponse. Jamais de trousse '
                    . 'minimale : c\'est la question d\'avant qu\'il faut servir.',
            ),
            new CasDuCorpus(
                'ambigu-abandonne',
                'Abandonne, on repart à zéro.',
                [], Trousse::LECTURE, CasDuCorpus::AMBIGU,
                contexte: CasDuCorpus::CONTEXTE_PLAN_EN_ATTENTE,
                note: 'Un abandon n\'écrit rien et ne lit rien — mais il survient sur un plan '
                    . 'en attente, donc dans le contexte le plus armé qui soit.',
            ),
        ];
    }
}
