<?php

namespace App\Services;

use App\Entity\AutoriteFiscale;
use App\Entity\Chargement;
use App\Entity\Entreprise;
use App\Entity\Groupe;
use App\Entity\Invite;
use App\Entity\Monnaie;
use App\Entity\MouvementConge;
use App\Entity\Risque;
use App\Entity\Taxe;
use App\Entity\TypeAbsence;
use App\Entity\TypeRevenu;
use App\Service\Conge\CongeParametres;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Sème les paramètres de configuration par défaut d'une entreprise nouvellement
 * créée, afin que l'espace de travail soit immédiatement exploitable
 * (« dès la persistance, le travail dans le workspace est possible »).
 *
 * Source de vérité unique des défauts (DRY) : appelé par
 * App\Controller\Admin\EntrepriseController::create() (création réelle) et par
 * App\DataFixtures\AppFixtures (jeu de démonstration).
 *
 * Ce service ne fait AUCUN flush : il se contente de persist(). L'appelant
 * maîtrise la transaction et flush une seule fois. Toutes les entités semées
 * ne portent que des relations ManyToOne (setEntreprise / setInvite) — aucune
 * cascade ni boucle de sérialisation.
 */
class ServiceInitialisationEntreprise
{
    /**
     * Libellés des codes ISO 4217 les plus courants du dataset géographique.
     * Repli sur le code lui-même si inconnu.
     *
     * @var array<string, string>
     */
    private const NOMS_MONNAIES = [
        'USD' => 'Dollar Américain',
        'EUR' => 'Euro',
        'CDF' => 'Franc Congolais',
        'XAF' => 'Franc CFA (BEAC)',
        'XOF' => 'Franc CFA (BCEAO)',
        'MAD' => 'Dirham Marocain',
        'DZD' => 'Dinar Algérien',
        'TND' => 'Dinar Tunisien',
        'NGN' => 'Naira Nigérian',
        'GHS' => 'Cedi Ghanéen',
        'ZAR' => 'Rand Sud-Africain',
        'KES' => 'Shilling Kényan',
        'RWF' => 'Franc Rwandais',
        'GBP' => 'Livre Sterling',
        'CHF' => 'Franc Suisse',
        'CAD' => 'Dollar Canadien',
    ];

    /**
     * LES CINQ TYPES D'ABSENCE semés à la création de tout cabinet.
     *
     * Le couple (code, décompté) est la seule chose qui compte vraiment : `decompte`
     * décide si une demande approuvée retire des jours au compteur. Maladie et événement
     * familial ne décomptent pas — un arrêt de travail n'est pas un congé.
     *
     * @var array<int, array{code: string, libelle: string, decompte: bool, justificatif: bool, demiJournee: bool, couleur: string}>
     */
    private const TYPES_ABSENCE_DEFAUT = [
        ['code' => TypeAbsence::CODE_CONGE_ANNUEL,       'libelle' => 'Congé annuel',       'decompte' => true,  'justificatif' => false, 'demiJournee' => true,  'couleur' => '#0047AB'],
        ['code' => TypeAbsence::CODE_SANS_SOLDE,         'libelle' => 'Congé sans solde',   'decompte' => false, 'justificatif' => false, 'demiJournee' => true,  'couleur' => '#6c757d'],
        ['code' => TypeAbsence::CODE_MALADIE,            'libelle' => 'Maladie',            'decompte' => false, 'justificatif' => true,  'demiJournee' => false, 'couleur' => '#c0392b'],
        ['code' => TypeAbsence::CODE_EVENEMENT_FAMILIAL, 'libelle' => 'Événement familial', 'decompte' => false, 'justificatif' => true,  'demiJournee' => false, 'couleur' => '#8e44ad'],
        ['code' => TypeAbsence::CODE_RECUPERATION,       'libelle' => 'Récupération',       'decompte' => true,  'justificatif' => false, 'demiJournee' => true,  'couleur' => '#16a085'],
    ];

    public function __construct(
        private EntityManagerInterface $manager,
        private ServiceGeographie $serviceGeographie,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
    ) {}

    /**
     * Instancie et persiste (sans flush) les paramètres par défaut de l'entreprise.
     */
    public function initialiser(Entreprise $entreprise, Invite $proprietaire): void
    {
        $this->initialiserMonnaies($entreprise, $proprietaire);
        $this->initialiserTaxes($entreprise, $proprietaire);
        $this->initialiserChargementsEtRevenus($entreprise, $proprietaire);
        $this->initialiserRisques($entreprise, $proprietaire);
        $this->initialiserGroupes($entreprise, $proprietaire);
        $this->initialiserTypesAbsence($entreprise, $proprietaire);
        $this->initialiserCongesExerciceCourant($entreprise, $proprietaire);
    }

    /**
     * LES CINQ TYPES D'ABSENCE, dès la création du cabinet.
     *
     * Un cabinet neuf doit pouvoir poser un congé sans qu'aucun paramétrage manuel ait eu
     * lieu : sans ces types, le premier collaborateur qui essaie se heurte à une liste
     * déroulante vide, et rien ne lui dit ce qui manque.
     *
     * IDEMPOTENT : un type déjà présent n'est pas recréé — la commande de reprise
     * `app:conges:provisionner` rejoue exactement ce semis sur les cabinets existants.
     * On regarde AUSSI les types désactivés : recréer un type que le cabinet a
     * volontairement retiré de la saisie serait défaire son réglage.
     */
    private function initialiserTypesAbsence(Entreprise $entreprise, Invite $proprietaire): void
    {
        foreach (self::TYPES_ABSENCE_DEFAUT as $defaut) {
            if ($this->typeAbsenceExiste($entreprise, $defaut['code'])) {
                continue;
            }

            $type = (new TypeAbsence())
                ->setCode($defaut['code'])
                ->setLibelle($defaut['libelle'])
                ->setDecompte($defaut['decompte'])
                ->setJustificatifRequis($defaut['justificatif'])
                ->setAutoriseDemiJournee($defaut['demiJournee'])
                ->setCouleur($defaut['couleur'])
                ->setActif(true);

            $this->attacher($type, $entreprise, $proprietaire);
        }
    }

    /**
     * LA DOTATION DE L'EXERCICE EN COURS, pour les collaborateurs déjà rattachés.
     *
     * Sans elle, la rubrique s'ouvre sur un solde à zéro et la première demande est
     * refusée par le contrôle de solde — ce qui ressemble à une panne, pas à un
     * paramétrage manquant.
     *
     * Le prorata suit les MOIS ENTIERS de présence (cf. CongeParametres) : c'est la
     * maille dans laquelle les congés se discutent, et elle évite d'avoir à trancher ce
     * que vaut une arrivée le 17.
     *
     * IDEMPOTENT : un agent qui a déjà une dotation sur cet exercice n'en reçoit pas une
     * seconde. Rejouer ce semis doublerait sinon, en silence, le droit de chacun.
     */
    private function initialiserCongesExerciceCourant(Entreprise $entreprise, Invite $proprietaire): void
    {
        $exercice = (int) (new \DateTimeImmutable('now'))->format('Y');
        $congeAnnuel = $this->typeAbsenceAnnuel($entreprise);

        foreach ($entreprise->getInvites() as $agent) {
            if ($this->dotationExiste($agent, $exercice)) {
                continue;
            }

            // L'entrée du collaborateur est la date de création de sa fiche d'invité :
            // c'est la seule que le cabinet connaisse à ce stade.
            $entree = $agent->getCreatedAt() ?? new \DateTimeImmutable('now');
            $jours = CongeParametres::dotationAuProrata(
                CongeParametres::DOTATION_ANNUELLE_DEFAUT,
                $entree,
                $exercice,
            );

            if ($jours <= 0.0) {
                continue;
            }

            $mouvement = (new MouvementConge())
                ->setAgent($agent)
                ->setExercice($exercice)
                ->setTypeAbsence($congeAnnuel)
                ->setNature(MouvementConge::NATURE_DOTATION)
                ->setQuantite(number_format($jours, 1, '.', ''))
                ->setAuteur($proprietaire)
                ->setCommentaire(sprintf(
                    "Dotation %d attribuée à l'ouverture de la rubrique, au prorata des mois de présence.",
                    $exercice,
                ));

            $this->attacher($mouvement, $entreprise, $proprietaire);
        }
    }

    /** Un type d'absence de ce code existe-t-il déjà dans ce cabinet ? */
    private function typeAbsenceExiste(Entreprise $entreprise, string $code): bool
    {
        if ($entreprise->getId() === null) {
            return false; // Cabinet pas encore en base : rien ne peut préexister.
        }

        return $this->manager->getRepository(TypeAbsence::class)
            ->findOneBy(['entreprise' => $entreprise, 'code' => $code]) !== null;
    }

    /**
     * Le type « Congé annuel » du cabinet, celui que la dotation crédite.
     *
     * À la CRÉATION d'un cabinet, ce type vient d'être instancié dans le même flux et
     * n'est pas encore interrogeable en base : on le retrouve alors dans l'unité de
     * travail. Sans cela, la toute première dotation naîtrait sans type — et le solde
     * qu'elle crédite serait invisible du calcul, qui ne compte que le congé annuel.
     */
    private function typeAbsenceAnnuel(Entreprise $entreprise): ?TypeAbsence
    {
        if ($entreprise->getId() !== null) {
            $type = $this->manager->getRepository(TypeAbsence::class)
                ->findOneBy(['entreprise' => $entreprise, 'code' => TypeAbsence::CODE_CONGE_ANNUEL]);
            if ($type instanceof TypeAbsence) {
                return $type;
            }
        }

        foreach ($this->manager->getUnitOfWork()->getScheduledEntityInsertions() as $entite) {
            if ($entite instanceof TypeAbsence
                && $entite->getCode() === TypeAbsence::CODE_CONGE_ANNUEL
                && $entite->getEntreprise() === $entreprise) {
                return $entite;
            }
        }

        return null;
    }

    /** Cet agent a-t-il déjà reçu sa dotation sur cet exercice ? */
    private function dotationExiste(Invite $agent, int $exercice): bool
    {
        if ($agent->getId() === null) {
            return false;
        }

        return $this->manager->getRepository(MouvementConge::class)
            ->findOneBy([
                'agent' => $agent,
                'exercice' => $exercice,
                'nature' => MouvementConge::NATURE_DOTATION,
            ]) !== null;
    }

    /**
     * USD sert de monnaie d'affichage de référence (taux 1.00). La monnaie locale,
     * dérivée du pays de l'entreprise, est ajoutée pour la saisie ; son taux est un
     * placeholder (1.00) que le courtier ajuste ensuite dans l'espace de travail.
     */
    private function initialiserMonnaies(Entreprise $entreprise, Invite $proprietaire): void
    {
        $usd = (new Monnaie())
            ->setNom(self::NOMS_MONNAIES['USD'])
            ->setCode('USD')
            ->setTauxusd('1.00')
            ->setLocale(false)
            ->setFonction(Monnaie::FONCTION_SAISIE_ET_AFFICHAGE);
        $this->attacher($usd, $entreprise, $proprietaire);

        $codeLocal = $entreprise->getPays() !== null
            ? $this->serviceGeographie->getMonnaie($entreprise->getPays())
            : null;

        // On n'ajoute la monnaie locale que si elle existe et diffère de l'USD.
        if ($codeLocal !== null && $codeLocal !== 'USD') {
            $locale = (new Monnaie())
                ->setNom(self::NOMS_MONNAIES[$codeLocal] ?? $codeLocal)
                ->setCode($codeLocal)
                ->setTauxusd('1.00') // placeholder : taux de change à ajuster par le courtier
                ->setLocale(true)
                ->setFonction(Monnaie::FONCTION_SAISIE_UNIQUEMENT);
            $this->attacher($locale, $entreprise, $proprietaire);
        }
    }

    /**
     * Taxes réglementaires par défaut :
     *  - TVA : 16 % IARD, 0 % VIE (vie exonérée), à la charge de l'assureur → DGI.
     *  - ARCA : 2 % IARD, 2 % VIE, à la charge du courtier → ARCA.
     */
    private function initialiserTaxes(Entreprise $entreprise, Invite $proprietaire): void
    {
        $tva = (new Taxe())
            ->setCode('TVA')
            ->setDescription('Taxe sur la valeur ajoutée')
            ->setTauxIARD('16.00')
            ->setTauxVIE('0.00')
            ->setRedevable(Taxe::REDEVABLE_ASSUREUR);
        $this->attacher($tva, $entreprise, $proprietaire);

        $dgi = (new AutoriteFiscale())
            ->setNom('Direction Générale des Impôts')
            ->setAbreviation('DGI')
            ->setTaxe($tva);
        $this->attacher($dgi, $entreprise, $proprietaire);

        $arca = (new Taxe())
            ->setCode('ARCA')
            ->setDescription('Frais de surveillance')
            ->setTauxIARD('2.00')
            ->setTauxVIE('2.00')
            ->setRedevable(Taxe::REDEVABLE_COURTIER);
        $this->attacher($arca, $entreprise, $proprietaire);

        $autoriteArca = (new AutoriteFiscale())
            ->setNom("Autorité de Régulation et de Contrôle des Assurances")
            ->setAbreviation('ARCA')
            ->setTaxe($arca);
        $this->attacher($autoriteArca, $entreprise, $proprietaire);
    }

    /**
     * Chargements (composition de la prime) et types de revenu (modes de commission)
     * par défaut, repris du jeu de référence des fixtures.
     */
    private function initialiserChargementsEtRevenus(Entreprise $entreprise, Invite $proprietaire): void
    {
        $primeNette = (new Chargement())
            ->setNom('Prime nette')
            ->setFonction(Chargement::FONCTION_PRIME_NETTE)
            ->setDescription('La part de la prime destinée à couvrir le risque pur.');
        $this->attacher($primeNette, $entreprise, $proprietaire);

        $fronting = (new Chargement())
            ->setNom('Fronting')
            ->setFonction(Chargement::FONCTION_FRONTING)
            ->setDescription('Frais liés aux opérations de fronting.');
        $this->attacher($fronting, $entreprise, $proprietaire);

        $frais = (new Chargement())
            ->setNom('Frais accessoires')
            ->setFonction(Chargement::FONCTION_FRAIS_ADMIN)
            ->setDescription('Frais de gestion, accessoires ou de police.');
        $this->attacher($frais, $entreprise, $proprietaire);

        // Chargements de type taxe : composantes de la prime globale payée par le client.
        $tvaChargement = (new Chargement())
            ->setNom('TVA')
            ->setFonction(Chargement::FONCTION_TAXE)
            ->setDescription('Taxe sur la valeur ajoutée.');
        $this->attacher($tvaChargement, $entreprise, $proprietaire);

        $arcaChargement = (new Chargement())
            ->setNom('ARCA')
            ->setFonction(Chargement::FONCTION_TAXE)
            ->setDescription("Frais de surveillance de l'autorité de régulation (ARCA).");
        $this->attacher($arcaChargement, $entreprise, $proprietaire);

        $commOrdinaire = (new TypeRevenu())
            ->setNom('Commission Ordinaire')
            ->setAppliquerPourcentageDuRisque(true)
            ->setRedevable(TypeRevenu::REDEVABLE_ASSUREUR)
            ->setShared(true)
            ->setMultipayments(true)
            ->setTypeChargement($primeNette);
        $this->attacher($commOrdinaire, $entreprise, $proprietaire);

        // Taux en POINTS (30 = 30 %), cf. TypeRevenu::getFraction.
        $commFronting = (new TypeRevenu())
            ->setNom('Commission sur Fronting')
            ->setPourcentage(30)
            ->setRedevable(TypeRevenu::REDEVABLE_ASSUREUR)
            ->setShared(false)
            ->setMultipayments(true)
            ->setTypeChargement($fronting);
        $this->attacher($commFronting, $entreprise, $proprietaire);

        $consultance = (new TypeRevenu())
            ->setNom('Frais de consultance')
            ->setPourcentage(5)
            ->setRedevable(TypeRevenu::REDEVABLE_CLIENT)
            ->setShared(false)
            ->setMultipayments(false)
            ->setTypeChargement($primeNette);
        $this->attacher($consultance, $entreprise, $proprietaire);

        $gestion = (new TypeRevenu())
            ->setNom('Honoraire de gestion')
            ->setPourcentage(2)
            ->setRedevable(TypeRevenu::REDEVABLE_CLIENT)
            ->setShared(false)
            ->setMultipayments(true)
            ->setTypeChargement($primeNette);
        $this->attacher($gestion, $entreprise, $proprietaire);
    }

    /**
     * Risques par défaut chargés depuis assets/data/risques_defaut.json
     * (catégories réglementaires, taux de commission Maxima en HT).
     */
    private function initialiserRisques(Entreprise $entreprise, Invite $proprietaire): void
    {
        foreach ($this->chargerRisquesDefaut() as $data) {
            $risque = (new Risque())
                ->setCode($data['code'])
                ->setNomComplet($data['nom'])
                ->setBranche((int) $data['branche'])
                ->setPourcentageCommissionSpecifiqueHT((float) $data['commission'])
                ->setDescription($data['description'] ?? null)
                ->setImposable(true);
            $this->attacher($risque, $entreprise, $proprietaire);
        }
    }

    /**
     * Groupes de clients par défaut, organisés par secteur d'activité.
     * Servent à classer les clients dès le démarrage de l'espace de travail.
     */
    private function initialiserGroupes(Entreprise $entreprise, Invite $proprietaire): void
    {
        $groupes = [
            ['Banques & Microfinance', "Banques, établissements de crédit et institutions de microfinance."],
            ['Télécommunications', "Opérateurs télécoms, fournisseurs d'accès et services numériques."],
            ['Transports & Logistique', "Transport routier, aérien, maritime, fluvial et services logistiques."],
            ['ONG & Organisations internationales', "Organisations non gouvernementales, agences et missions internationales."],
            ['Mines & Carrières', "Sociétés minières, d'extraction et d'exploitation de carrières."],
            ['Pétrole & Énergie', "Hydrocarbures, production et distribution d'énergie."],
            ['Commerce & Distribution', "Grossistes, détaillants et chaînes de distribution."],
            ['Industrie & Agro-industrie', "Industries manufacturières, transformation et agro-industrie."],
            ['Santé & Établissements médicaux', "Hôpitaux, cliniques, laboratoires et professionnels de santé."],
            ['BTP & Construction', "Bâtiment, travaux publics et entreprises de construction."],
        ];

        foreach ($groupes as [$nom, $description]) {
            $groupe = (new Groupe())
                ->setNom($nom)
                ->setDescription($description);
            $this->attacher($groupe, $entreprise, $proprietaire);
        }
    }

    /**
     * @return array<int, array{code: string, nom: string, branche: int, commission: float, description?: string}>
     */
    private function chargerRisquesDefaut(): array
    {
        $chemin = $this->projectDir . '/assets/data/risques_defaut.json';
        $contenu = is_file($chemin) ? file_get_contents($chemin) : false;

        return $contenu === false ? [] : (json_decode($contenu, true) ?? []);
    }

    /**
     * Rattache une entité auditable à l'entreprise et à son invité propriétaire,
     * puis la programme pour persistance.
     */
    private function attacher(object $entite, Entreprise $entreprise, Invite $proprietaire): void
    {
        $entite->setEntreprise($entreprise);
        $entite->setInvite($proprietaire);
        $this->manager->persist($entite);
    }
}
