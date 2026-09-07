<?php

namespace App\Command;

use App\Echange\Etat\EtatDuPortefeuille;
use App\Echange\Etat\ProducteurDeLEtat;
use App\Echange\Reprise\CleNaturelle;
use App\Echange\Reprise\LecteurDeLEtat;
use App\Echange\Reprise\ReconstitueurDeTranche;
use App\Entity\Entreprise;
use App\Repository\InviteRepository;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * VÉRIFIE LA RECONSTITUTION SUR LES VRAIES DONNÉES : l'export se relit-il, et en quoi ?
 *
 * ── CE QUE CETTE COMMANDE PROUVE, ET QU'AUCUN TEST NE PROUVE ────────────────────────
 * Les tests travaillent sur des cabinets fabriqués : une police, une tranche, un
 * chargement bien rangé. Le réel apporte ce qu'on n'écrit pas — un type de chargement en
 * double sur la même cotation, un revenu système à montant négatif, une tranche sans
 * intermédiaire. Ces trois cas ont été trouvés ici, et non dans la suite.
 *
 * ⚠ ELLE NE FACTURE JAMAIS, ET N'ÉCRIT RIEN EN BASE. Elle passe par `produire()`, jamais
 * par `exporter()`, et s'arrête AVANT le dry-run : elle rend compte des opérations que la
 * reconstitution produirait. La distinction n'est pas théorique — une commande de
 * diagnostic branchée sur le chemin complet a débité 23 400 tokens au propriétaire d'un
 * cabinet réel pour une vérification que personne n'avait demandée.
 *
 * ── LE CONTRÔLE QUI COMPTE ──────────────────────────────────────────────────────────
 * ⚠ LA CONVERGENCE. Quatre échéances d'une même police occupent quatre lignes ; elles
 * doivent produire UNE police, UNE proposition, UN client — et quatre tranches. C'est la
 * faute la plus probable de toute la reprise, et la plus coûteuse : elle ne casse rien,
 * elle DUPLIQUE, et l'on ne s'en aperçoit qu'aux totaux.
 */
#[AsCommand(
    name: 'app:reprise:smoke',
    description: 'Relit l\'état d\'un cabinet et rend compte de ce que la reconstitution produirait.',
)]
final class RepriseSmokeCommand extends Command
{
    public function __construct(
        private readonly ProducteurDeLEtat $producteur,
        private readonly EtatDuPortefeuille $etat,
        private readonly LecteurDeLEtat $lecteur,
        private readonly ReconstitueurDeTranche $reconstitueur,
        private readonly InviteRepository $invites,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('idEntreprise', InputArgument::REQUIRED, 'Identifiant du cabinet');
        $this->addOption('fichier', null, InputOption::VALUE_REQUIRED, 'Relit ce classeur au lieu d\'en produire un');
        $this->addOption('gabarit', null, InputOption::VALUE_NONE, 'Produit le gabarit vierge et le relit');
        // ⚠ LE CONTRÔLE QUI COMPTE VRAIMENT. Un export porte ses identifiants : relu tel
        // quel, il ne modifie que les échéances, l'ascendance étant déjà en base — et la
        // reconstitution n'est jamais exercée. Vider la colonne « id » reproduit
        // exactement le cas de la REPRISE : un gabarit rempli à la main, sur une base qui
        // ne connaît encore rien.
        $this->addOption('sans-identifiants', null, InputOption::VALUE_NONE, 'Ignore la colonne « id » : simule une reprise sur base vierge');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $entreprise = $this->em->find(Entreprise::class, (int) $input->getArgument('idEntreprise'));
        if ($entreprise === null) {
            $io->error('Aucun cabinet ne porte cet identifiant.');

            return Command::FAILURE;
        }

        $invite = $this->invites->findOneBy(['entreprise' => $entreprise]);
        if ($invite === null) {
            $io->error('Ce cabinet n\'a aucun invité : impossible de résoudre un périmètre de lecture.');

            return Command::FAILURE;
        }

        $gabarit = (bool) $input->getOption('gabarit');
        $chemin = $input->getOption('fichier');

        if ($chemin === null) {
            $io->section($gabarit ? 'Production du gabarit vierge' : 'Production de l\'état');
            [$classeur, , $total] = $this->producteur->produire(
                $entreprise,
                $invite,
                $entreprise->getUtilisateur(),
                gabarit: $gabarit,
            );

            $chemin = (string) tempnam(sys_get_temp_dir(), 'jsbx_reprise_');
            $this->producteur->ecrireSur($classeur, $chemin);
            $io->listing([sprintf('%d ligne(s) écrite(s)', $total)]);
        }

        $io->section('Relecture');
        $relu = IOFactory::createReader('Xlsx')->load((string) $chemin);

        if (!LecteurDeLEtat::estUnClasseurDEtat($relu)) {
            $io->error(sprintf('Ce classeur ne porte pas de feuille « %s ».', EtatDuPortefeuille::FEUILLE));

            return Command::FAILURE;
        }

        $colonnes = $this->etat->colonnes($entreprise);
        $lignes = $this->lecteur->lignes($relu, $colonnes);
        $absentes = $this->lecteur->codesAbsents($relu, $colonnes);

        $io->listing([
            sprintf('cabinet déclaré : %s (attendu : %s)', $this->lecteur->cabinet($relu) ?: '—', (string) $entreprise->getId()),
            sprintf('%d ligne(s) relue(s)', \count($lignes)),
            sprintf('%d colonne(s) du catalogue absente(s) du fichier', \count($absentes)),
        ]);

        if ($lignes === []) {
            $io->warning('Aucune ligne à reconstituer : la vérification s\'arrête là.');

            return Command::SUCCESS;
        }

        $io->section('Reconstitution');
        $this->reconstitueur->reinitialiser();

        $parEntite = [];
        $refus = [];
        $policesVues = [];
        $actesVus = [];
        $acceptees = 0;

        $sansIdentifiants = (bool) $input->getOption('sans-identifiants');

        foreach ($lignes as $ligne) {
            if ($sansIdentifiants) {
                $ligne = $this->sansIdentifiant($ligne);
            }

            $anomalies = [];
            $operations = $this->reconstitueur->pour($ligne, $colonnes, $entreprise, $anomalies);

            foreach ($anomalies as $anomalie) {
                $refus[] = sprintf('ligne %d — %s', $ligne->numero, $anomalie->message);
            }

            foreach ($operations as $operation) {
                $cle = $operation->entityShortName . ' · ' . $operation->op;
                $parEntite[$cle] = ($parEntite[$cle] ?? 0) + 1;
            }

            $cle = CleNaturelle::cleDeLaPolice($ligne->texte('policeReference'));
            if ($cle !== null) {
                $policesVues[$cle] = ($policesVues[$cle] ?? 0) + 1;
                // ⚠ LE NUMÉRO D'AVENANT FAIT PARTIE DE LA CLÉ DE LA POLICE. Une police et
                // son avenant n° 2 partagent la référence : compter les seules références
                // ferait croire à une duplication là où il y a deux actes distincts.
                $actesVus[(string) CleNaturelle::pourAvenant(
                    $ligne->texte('policeReference'),
                    $ligne->texte('policeNumeroAvenant'),
                )] = true;
            }

            $aUneErreur = false;
            foreach ($anomalies as $anomalie) {
                if ($anomalie->gravite === \App\Echange\Service\Anomalie::ERREUR) {
                    $aUneErreur = true;
                    break;
                }
            }
            if (!$aUneErreur) {
                ++$acceptees;
            }
        }

        ksort($parEntite);
        $detail = [];
        foreach ($parEntite as $quoi => $nombre) {
            $detail[] = sprintf('%-38s %d', $quoi, $nombre);
        }
        $io->listing($detail);

        // ⚠ LE CONTRÔLE DE CONVERGENCE, ET C'EST LUI QUI COMPTE. Autant de propositions
        // créées que de polices DISTINCTES dans le fichier, autant d'avenants que d'ACTES
        // distincts (référence + numéro). Si l'un dépasse l'autre, une affaire a été
        // dupliquée — et rien d'autre ne le dirait.
        $distinctes = \count($policesVues);
        $actes = \count($actesVus);
        $avenants = $parEntite['Avenant · create'] ?? 0;
        $cotations = $parEntite['Cotation · create'] ?? 0;
        $pistes = $parEntite['Piste · create'] ?? 0;
        $tranches = ($parEntite['Tranche · create'] ?? 0) + ($parEntite['Tranche · edit'] ?? 0);
        $repetees = \count(array_filter($policesVues, static fn (int $n): bool => $n > 1));

        $controles = [
            'une police distincte = une proposition' => $cotations <= $distinctes,
            'une police distincte = une opportunité' => $pistes <= $distinctes,
            'un acte distinct = un avenant' => $avenants <= $actes,
            // Une ligne refusée ne produit rien : on compare donc aux lignes ACCEPTÉES,
            // et non au total. Sur le cabinet réel, quatre tranches sont des projets sans
            // police : elles n'ont pas de référence, donc pas de clé, et sont refusées —
            // ce qui est le comportement voulu, pas un défaut.
            'une ligne acceptée = une échéance' => $tranches === $acceptees,
        ];

        $lignesDeControle = [];
        $tout = true;
        foreach ($controles as $quoi => $bon) {
            $lignesDeControle[] = sprintf('%s — %s', $bon ? 'OK ' : 'ÉCHEC', $quoi);
            $tout = $tout && $bon;
        }
        $lignesDeControle[] = sprintf(
            '%d police(s) distincte(s), dont %d portant plusieurs échéances ; %d acte(s) distinct(s)',
            $distinctes,
            $repetees,
            $actes,
        );
        $lignesDeControle[] = sprintf(
            '%d ligne(s) acceptée(s) sur %d ; %d anomalie(s) signalée(s)',
            $acceptees,
            \count($lignes),
            \count($refus),
        );
        $io->listing($lignesDeControle);

        if ($refus !== []) {
            $io->section('Refus (les dix premiers)');
            $io->listing(array_slice($refus, 0, 10));
        }

        if (!$tout) {
            $io->error('La reconstitution ne converge pas : des lignes d\'une même police se dupliquent.');

            return Command::FAILURE;
        }

        $io->success('La reconstitution converge.');

        return Command::SUCCESS;
    }

    /**
     * LA MÊME LIGNE, PRIVÉE DE SON IDENTIFIANT.
     *
     * ⚠ C'EST CE QUI EXERCE LA RECONSTITUTION. Avec son identifiant, une ligne ne touche
     * que l'échéance : son ascendance existe déjà, et rien de la chaîne n'est éprouvé.
     * Sans lui, la ligne redevient ce qu'elle est dans une reprise — une affaire à
     * rebâtir de bout en bout.
     */
    private function sansIdentifiant(\App\Echange\Classeur\LigneLue $ligne): \App\Echange\Classeur\LigneLue
    {
        $valeurs = $ligne->valeurs;
        $valeurs[EtatDuPortefeuille::COLONNE_IDENTITE] = null;

        return new \App\Echange\Classeur\LigneLue(
            $ligne->feuille,
            $ligne->codeRessource,
            $ligne->numero,
            $valeurs,
            $ligne->colonnes,
        );
    }
}
