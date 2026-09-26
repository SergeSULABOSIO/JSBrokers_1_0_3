<?php

namespace App\Ai\Reglage;

use App\Ai\Tool\AiToolConditionnel;
use App\Ai\Tool\AiToolInterface;
use App\Ai\Trousse\AiToolDeComprehension;
use App\Ai\Trousse\AiToolEcriture;
use App\Ai\Trousse\Trousse;
use App\Ai\Trousse\TrousseCatalogue;

/**
 * L'INVENTAIRE DE CE QUE KET SAIT FAIRE, tel que la console l'affiche.
 *
 * ── CE QUI EST DÉRIVÉ, ET CE QUI EST DÉCLARÉ ────────────────────────────────
 * Tout ce que le code sait dire est DÉRIVÉ : la liste des outils vient de
 * `TrousseCatalogue::tous()`, l'appartenance aux trousses des marqueurs
 * `AiToolEcriture` / `AiToolDeComprehension`, la condition d'affichage de
 * `AiToolConditionnel`, le poids de la déclaration elle-même. Rien de tout cela
 * n'est recopié ici : un outil ajouté au projet apparaît sans qu'on y touche.
 *
 * Deux choses ne se dérivent pas et sont donc DÉCLARÉES dans `FICHES` :
 *  - le NOM COURTIER. `description()` est écrite pour le modèle — longue, technique,
 *    parfois deux paragraphes. Un agent qui ouvre l'écran a besoin d'une ligne.
 *  - la CLASSE. « Indispensable » veut dire « le prompt le nomme en dur, le couper
 *    produirait un outil fantôme ». Cela se constate en lisant AiContextBuilder, pas
 *    en interrogeant l'outil.
 *
 * ── LE GARDE-FOU ────────────────────────────────────────────────────────────
 * `ReglagesDeKetManifesteTest` échoue si un outil du conteneur n'a pas de fiche, ou
 * si une fiche nomme un outil qui n'existe plus. Ajouter un outil oblige donc à dire
 * ce qu'il fait et dans quelle classe il tombe — sinon la suite est rouge. C'est la
 * même discipline que `CouvertureDesEcrans` pour la parité écran ↔ Ket.
 *
 * ── LA FACTURATION ──────────────────────────────────────────────────────────
 * `facture` signale les outils dont l'usage DÉBITE des jetons au cabinet
 * (`meterDocumentIa`, `meterEchange`, `meterRead`, `meterFichierIa`). Ce drapeau est
 * CURATÉ : le métrage est déclenché par le contrôleur ou par le service appelé, pas
 * par l'outil, et rien dans le code ne permet de le déduire depuis ici. Il existe
 * parce que couper un tel outil ne retire pas seulement une capacité — il supprime
 * une recette, et cela doit se lire AVANT de cliquer.
 */
final class CatalogueDesReglages
{
    /**
     * Les outils que le prompt système NOMME EN DUR, hors des blocs dérivés du
     * catalogue. Les couper laisserait Ket promettre une capacité absente du tour.
     *
     * La trousse de COMPRÉHENSION y est en entier : c'est une liste blanche de trois
     * outils (TrousseCatalogue), et sans eux la phase qui lève les ambiguïtés de
     * référence partirait sans aucun outil.
     *
     * ⚠ CETTE LISTE N'EST PLUS UNE HYPOTHÈSE : `OutilDesactiveTest` l'ARBITRE. Il
     * coupe chaque outil DÉSACTIVABLE à tour de rôle et vérifie que son nom disparaît
     * du prompt. Tout outil qui y laisse une trace appartient ici, et la suite
     * devient rouge tant qu'il n'y est pas.
     */
    private const INDISPENSABLES = [
        // ── LE SOCLE : sans eux, Ket cesse de fonctionner ───────────────────────
        // La trousse de COMPRÉHENSION en entier (liste blanche de trois outils) :
        // sans elle, la phase qui lève les ambiguïtés de référence part sans aucun
        // outil. Puis le moteur générique d'écriture et ses compagnons, que les
        // protocoles nomment à chaque tour.
        'consulter_guide',
        'inventaire_champs',
        'lire_fiche',
        'ouvrir_dialogue',
        'parcours_saisie',
        'preparer_operations',
        'preparer_programme',
        'rechercher_entites',

        // ── LA TOILE DE RENVOIS : les couper produirait un outil fantôme ─────────
        //
        // Ceux-là ne sont pas vitaux au sens strict — Ket tournerait sans
        // `chronologie`. Mais son nom est écrit EN DUR à deux endroits : dans la
        // prose du prompt (glossaire financier, règle de boussole, périmètre), et
        // surtout dans la règle d'aiguillage D'AUTRES outils, qui s'y réfèrent pour
        // se distinguer — « ne confonds pas avec suivi_impayes », « pour un montant
        // par période, passe par indicateur_calcule ».
        //
        // C'est une bonne chose : cette toile de renvois est ce qui empêche le
        // modèle de choisir l'outil voisin. Mais elle interdit d'en retirer un
        // arbitrairement : le prompt continuerait de le nommer, le modèle croirait
        // pouvoir l'appeler, et l'on retomberait exactement sur le plan fantôme que
        // PromptSansOutilFantomeTest existe pour empêcher.
        //
        // LES LIBÉRER EST UN CHANTIER À PART, outil par outil : il faut réécrire
        // chaque règle qui le nomme du côté où elle appartient — ce que le message
        // d'échec de PromptSansOutilFantomeTest prescrit déjà — puis retirer le nom
        // d'ici et laisser le test confirmer. Le faire en bloc reviendrait à
        // remanier le prompt entier d'un seul geste, sur l'artefact le plus sensible
        // du projet.
        'analyse_portefeuille',
        'chronologie',
        'document_comptable',
        'fermer_rubrique',
        'indicateur_calcule',
        'modifier_composition_prime',
        'ouvrir_rubrique',
        'paiements_prime',
        'plan_du_jour',
        'preparer_envoi_soa',
        'retrocommissions',
        'saturation_portefeuille',
        'suivi_impayes',
        'vigie_echeances',
    ];

    /**
     * Outils dont l'usage DÉBITE des jetons au cabinet. Cf. le docblock de classe :
     * drapeau curaté, parce que le métrage n'est pas déclenché par l'outil.
     */
    private const FACTURENT = [
        'preparer_document'     => 'meterDocumentIa',
        'echange_exporter'      => 'meterEchange',
        'echange_importer'      => 'meterEchange',
        'lire_fiche'            => 'meterRead',
        'telecharger_documents' => 'meterRead',
        'attacher_fichier'      => 'meterFichierIa',
    ];

    /**
     * Nom courtier, une phrase, et l'ALIAS D'ICÔNE, par outil. L'ordre n'a aucune
     * importance : la liste est triée à l'affichage.
     *
     * ── L'ICÔNE EST CHOISIE, PAS DÉCORATIVE ─────────────────────────────────
     * Sur une liste de cinquante-deux lignes, c'est elle que l'œil attrape avant le
     * nom. Chaque outil reprend donc l'icône de l'OBJET MÉTIER qu'il touche quand
     * elle existe — `risque` pour le catalogue des couvertures, `avenant` pour les
     * mouvements de police, `conge` pour les congés — de sorte que l'écran parle la
     * même langue que les rubriques de l'espace de travail. À défaut d'objet, c'est
     * le GESTE qui est dessiné : une loupe pour chercher, un pied pour un parcours,
     * une alerte pour les impayés.
     *
     * Les alias viennent tous de `IconCanvasProvider` et rien d'autre : un nom
     * absent de cette carte ne lève AUCUNE erreur, il laisse un trou dans
     * l'alignement — ce qui se lit comme une anomalie de la ligne, pas comme un
     * oubli de configuration. `ManifesteDesOutilsTest` vérifie donc que chaque alias
     * se résout.
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    private const FICHES = [
        'analyse_portefeuille'               => ['Classements du portefeuille', 'Les meilleurs assureurs, clients, risques et intermédiaires, et la production mois par mois.', 'action:analyser'],
        'analyser_fichier_pour_saisie'       => ['Saisir depuis un fichier joint', 'Lit une pièce jointe et en tire les éléments d’un enregistrement à créer.', 'piece-sinistre'],
        'attacher_fichier'                   => ['Rattacher une pièce', 'Conserve une pièce jointe du chat sur un enregistrement du portefeuille.', 'action:attach'],
        'catalogue_des_risques'              => ['Catalogue des couvertures', 'Le catalogue complet des risques configurés, descriptions et taux compris — la base du conseil.', 'risque'],
        'chronologie'                        => ['Chronologie d’un dossier', 'Ce qui s’est passé sur un client, dans l’ordre.', 'jour-ferie'],
        'conges'                             => ['Congés d’un collaborateur', 'Solde, demandes et absences à venir.', 'conge'],
        'consulter_guide'                    => ['Consulter une fiche métier', 'Charge une fiche de connaissance (boussole, cycle de production, rétrocommissions…).', 'action:information'],
        'detail_depenses'                    => ['Détail des dépenses', 'Dépenses et charges ligne à ligne, au plan comptable OHADA.', 'depense'],
        'document_comptable'                 => ['États comptables', 'Trésorerie, résultat, TVA et les autres états SYSCOHADA, à l’instant.', 'document-comptable'],
        'echange_consulter'                  => ['Renseigner sur l’import/export', 'Explique la rubrique d’échange et le format du classeur attendu.', 'echange'],
        'echange_exporter'                   => ['Exporter le portefeuille', 'Produit le classeur Excel de l’état du portefeuille.', 'echange-export'],
        'echange_importer'                   => ['Importer un classeur', 'Contrôle puis reprend un classeur Excel joint à la conversation.', 'echange-import'],
        'effort_commercial_agent'            => ['Rattacher un partage', 'Rattache ou détache une condition de partage à des affaires.', 'condition'],
        'envoyer_message_par_email'          => ['Envoyer la réponse par e-mail', 'Expédie la réponse précédente à un destinataire.', 'action:send-email'],
        'etat_configuration'                 => ['Complétude du cabinet', 'Ce qui manque encore pour que le cabinet soit opérationnel (propriétaire seulement).', 'action:settings'],
        'exporter_etat'                      => ['Télécharger un état', 'Déclenche le téléchargement d’un état, d’un PDF ou d’un classeur.', 'action:download'],
        'fermer_rubrique'                    => ['Fermer un onglet', 'Ferme des onglets de rubrique dans l’espace de travail.', 'action:close'],
        'indicateur_calcule'                 => ['Indicateur financier', 'La valeur d’un indicateur calculé : commission générée, exigible, encaissée…', 'revenu'],
        'inventaire_champs'                  => ['Champs d’un formulaire', 'Décrit les champs d’une rubrique avant une création ou une édition.', 'collection'],
        'lire_fiche'                         => ['Lire une fiche', 'La fiche complète d’un enregistrement.', 'action:description'],
        'lire_soa'                           => ['Relevé de compte client', 'Le relevé (SOA) d’un client : dû, payé, solde.', 'note'],
        'modifier_composition_prime'         => ['Corriger la composition d’une prime', 'Rectifie la ventilation de prime d’une cotation.', 'tranche'],
        'ouvrir_dialogue'                    => ['Ouvrir un formulaire', 'Ouvre chez l’utilisateur un formulaire de création ou d’édition, prérempli.', 'action:edit'],
        'ouvrir_rubrique'                    => ['Ouvrir une rubrique', 'Ouvre une liste ou le tableau de bord dans l’espace de travail.', 'action:open'],
        'paiements_prime'                    => ['Paiements de prime signalés', 'Les signalements de règlement de prime déjà enregistrés.', 'paiement'],
        'parcours_saisie'                    => ['Parcours de saisie', 'La trame métier à suivre avant une création structurante.', 'piste'],
        'plan_du_jour'                       => ['Programme du jour', 'Ce que le courtier a de plus urgent à traiter aujourd’hui.', 'tache'],
        'preparer_decision_conge'            => ['Décider d’un congé', 'Prépare l’approbation, le refus ou l’annulation d’une demande.', 'action:check'],
        'preparer_demande_conge'             => ['Demander un congé', 'Prépare et soumet une demande de congé.', 'action:calendar'],
        'preparer_document'                  => ['Produire un document', 'Fabrique un document officiel : Word, Excel, PDF, Markdown ou HTML.', 'document'],
        'preparer_envoi_soa'                 => ['Envoyer un relevé de compte', 'Ouvre la boîte d’envoi du relevé, destinataires déjà ciblés.', 'contact'],
        'preparer_marquage_non_renouvelable' => ['Signaler une police non renouvelable', 'Marque une police comme sans suite, avec son motif.', 'action:no-renew'],
        'preparer_mouvement_avenant'         => ['Mouvement de police', 'Renouvellement, prorogation, annulation, résiliation.', 'avenant'],
        'preparer_operations'                => ['Créer, modifier, supprimer', 'Le chemin général de toute écriture préparée par Ket.', 'action:add'],
        'preparer_programme'                 => ['Enchaîner plusieurs plans', 'Une série d’écritures à valider l’une après l’autre.', 'groupe'],
        'quitter_workspace'                  => ['Quitter l’espace de travail', 'Propose de fermer l’espace de travail.', 'action:exit'],
        'rechercher_entites'                 => ['Rechercher', 'Listes et recherches filtrées dans tout le portefeuille.', 'action:search'],
        'retrocommissions'                   => ['Rétrocommissions', 'Ce qui est dû aux agents internes et aux partenaires externes : dû, payé, solde, exigible.', 'partenaire'],
        'saisir_proposition'                 => ['Saisir une cotation', 'Enregistre une proposition complète en une fois.', 'cotation'],
        'saturation_portefeuille'            => ['Saturation du portefeuille', 'Le taux de couverture et les risques qui manquent encore à chaque client.', 'portefeuille'],
        'signaler_paiement_prime'            => ['Signaler un paiement de prime', 'Trace le règlement d’une prime par le client.', 'action:completed'],
        'signaler_reversement_retro_agent'   => ['Signaler un reversement', 'Enregistre le versement d’une rétrocommission à un agent.', 'action:transfer'],
        'simuler_conge'                      => ['Simuler un congé', 'Calcule les jours ouvrables d’une période d’absence.', 'regime-travail'],
        'solde_tokens'                       => ['Solde de jetons', 'Le solde de jetons du cabinet.', 'monnaie'],
        'souscrire_cotation'                 => ['Souscrire une cotation', 'Transforme une proposition acceptée en police (avenant).', 'assureur'],
        'statistiques'                       => ['Statistiques', 'Sommes, moyennes et regroupements sur les champs enregistrés.', 'dashboard'],
        'suivi_impayes'                      => ['Suivi des impayés', 'Primes, commissions et rétrocommissions restant dues, par échéance.', 'action:alert'],
        'telecharger_documents'              => ['Chercher un document', 'Retrouve des fichiers dans un dossier et propose leur téléchargement.', 'classeur'],
        'telecharger_fichiers'               => ['Reprendre une pièce jointe', 'Propose au téléchargement les pièces jointes de la conversation.', 'fichier:autre'],
        'vigie_echeances'                    => ['Vigie des échéances', 'Polices échues, polices à renouveler, tâches en retard.', 'action:renew'],
        'visualiser_fiche'                   => ['Afficher une fiche à l’écran', 'Ouvre une fiche dans la colonne de visualisation.', 'action:view'],
    ];

    public function __construct(
        private readonly TrousseCatalogue $trousseCatalogue,
    ) {
    }

    /**
     * L'inventaire complet, trié par nom courtier — outils désactivés compris, c'est
     * tout l'intérêt d'un inventaire.
     *
     * @return list<array{
     *     nom: string, libelle: string, resume: string, icone: string, classe: Classe,
     *     trousses: list<string>, conditionnel: bool, facture: ?string,
     *     octets: int, jetons: int, description: string, aiguillage: string,
     *     source: string
     * }>
     */
    public function outils(): array
    {
        $lignes = [];

        foreach ($this->trousseCatalogue->tous() as $outil) {
            $nom = $outil->name();
            [$libelle, $resume, $icone] = self::FICHES[$nom] ?? [$nom, '', 'default'];
            $octets = PoidsDesDeclarations::octetsDe($outil);

            $lignes[] = [
                'nom'          => $nom,
                'libelle'      => $libelle,
                'resume'       => $resume,
                'icone'        => $icone,
                'classe'       => self::classeDe($nom),
                'trousses'     => self::troussesDe($outil),
                'conditionnel' => $outil instanceof AiToolConditionnel,
                'facture'      => self::FACTURENT[$nom] ?? null,
                'octets'       => $octets,
                'jetons'       => PoidsDesDeclarations::jetons($octets),
                'description'  => $outil->description(),
                'aiguillage'   => $outil->aiguillage(),
                'source'       => self::sourceDe($outil),
            ];
        }

        usort($lignes, static fn (array $a, array $b): int => strcoll($a['libelle'], $b['libelle']));

        return $lignes;
    }

    /**
     * Ce que pèsent les déclarations d'une trousse, TOUTES CONDITIONS RÉUNIES.
     *
     * ⚠ C'EST UN PLAFOND, ET L'ÉCRAN LE DIT. `AiToolConditionnel` écarte des outils
     * selon l'invité, ses droits et son terminal : le payload réel d'un tour est donc
     * toujours inférieur ou égal à ce chiffre, et varie d'un cabinet à l'autre.
     *
     * On ne peut pas faire mieux ici, et il ne FAUT pas essayer : la console n'a aucun
     * périmètre — pas d'entreprise, pas d'invité. Choisir un cabinet au hasard pour
     * « avoir un vrai chiffre » donnerait un nombre exact… pour quelqu'un d'autre que
     * celui qui regarde, ce qui est pire qu'un plafond annoncé comme tel. Le plafond,
     * lui, répond à la question que se pose l'administrateur : combien la PLATEFORME
     * envoie-t-elle au maximum, et combien économise-t-on en coupant cet outil.
     *
     * @return array<string, array{octets: int, jetons: int, outils: int}>
     */
    public function poidsDesTrousses(): array
    {
        $poids = [];

        foreach ([Trousse::LECTURE, Trousse::ECRITURE] as $trousse) {
            $outils = [];
            foreach ($this->trousseCatalogue->tous() as $outil) {
                if (!$trousse->estEcriture() && $outil instanceof AiToolEcriture) {
                    continue;
                }
                $outils[] = $outil;
            }

            $octets = PoidsDesDeclarations::octetsDeLaListe($outils);

            $poids[$trousse->value] = [
                'octets' => $octets,
                'jetons' => PoidsDesDeclarations::jetons($octets),
                'outils' => \count($outils),
            ];
        }

        return $poids;
    }

    /**
     * Ce que la plateforme épargne à chaque échange grâce aux outils coupés.
     *
     * Mesuré comme le reste : la différence entre la liste complète et la liste
     * amputée, sérialisées l'une et l'autre. On ne somme pas les poids un à un — un
     * tableau JSON porte ses virgules, et l'écran annonce un gain de PAYLOAD.
     *
     * @param list<string> $coupes
     *
     * @return array{octets: int, jetons: int, outils: int}
     */
    public function gainDesOutilsCoupes(array $coupes): array
    {
        if ($coupes === []) {
            return ['octets' => 0, 'jetons' => 0, 'outils' => 0];
        }

        $tous = $this->trousseCatalogue->tous();
        $restants = array_filter($tous, static fn ($o): bool => !\in_array($o->name(), $coupes, true));

        $octets = PoidsDesDeclarations::octetsDeLaListe($tous)
            - PoidsDesDeclarations::octetsDeLaListe($restants);

        return [
            'octets' => $octets,
            'jetons' => PoidsDesDeclarations::jetons($octets),
            'outils' => \count($tous) - \count($restants),
        ];
    }

    /** Les noms techniques déclarés dans les fiches — pour le test de couverture. */
    public static function nomsDecrits(): array
    {
        return array_keys(self::FICHES);
    }

    /**
     * Les alias d'icône déclarés, par outil — pour le test qui vérifie qu'ils se
     * résolvent tous.
     *
     * @return array<string, string>
     */
    public static function iconesDecrites(): array
    {
        return array_map(static fn (array $f): string => $f[2], self::FICHES);
    }

    public static function classeDe(string $nom): Classe
    {
        return \in_array($nom, self::INDISPENSABLES, true)
            ? Classe::INDISPENSABLE
            : Classe::DESACTIVABLE;
    }

    /**
     * Dans quelles trousses l'outil peut être déclaré — dérivé des marqueurs, jamais
     * d'une liste tenue à la main.
     *
     * @return list<string>
     */
    private static function troussesDe(AiToolInterface $outil): array
    {
        if ($outil instanceof AiToolDeComprehension) {
            // La compréhension est une liste BLANCHE : y figurer n'exclut pas les
            // deux autres trousses, où ces outils de lecture restent déclarés.
            return ['Compréhension', 'Lecture', 'Écriture'];
        }

        return $outil instanceof AiToolEcriture ? ['Écriture'] : ['Lecture', 'Écriture'];
    }

    /** Chemin du fichier, relatif à la racine du projet. */
    private static function sourceDe(AiToolInterface $outil): string
    {
        $chemin = (new \ReflectionClass($outil))->getFileName();
        if ($chemin === false) {
            return '';
        }

        $chemin = str_replace('\\', '/', $chemin);
        $position = strpos($chemin, '/src/');

        return $position === false ? basename($chemin) : ltrim(substr($chemin, $position + 1), '/');
    }
}
