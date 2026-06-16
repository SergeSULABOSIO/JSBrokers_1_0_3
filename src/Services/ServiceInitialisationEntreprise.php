<?php

namespace App\Services;

use App\Entity\AutoriteFiscale;
use App\Entity\Chargement;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Monnaie;
use App\Entity\Risque;
use App\Entity\Taxe;
use App\Entity\TypeRevenu;
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

        $commOrdinaire = (new TypeRevenu())
            ->setNom('Commission Ordinaire')
            ->setAppliquerPourcentageDuRisque(true)
            ->setRedevable(TypeRevenu::REDEVABLE_ASSUREUR)
            ->setShared(true)
            ->setMultipayments(true)
            ->setTypeChargement($primeNette);
        $this->attacher($commOrdinaire, $entreprise, $proprietaire);

        $commFronting = (new TypeRevenu())
            ->setNom('Commission sur Fronting')
            ->setPourcentage(0.30)
            ->setRedevable(TypeRevenu::REDEVABLE_ASSUREUR)
            ->setShared(false)
            ->setMultipayments(true)
            ->setTypeChargement($fronting);
        $this->attacher($commFronting, $entreprise, $proprietaire);

        $consultance = (new TypeRevenu())
            ->setNom('Frais de consultance')
            ->setPourcentage(0.05)
            ->setRedevable(TypeRevenu::REDEVABLE_CLIENT)
            ->setShared(false)
            ->setMultipayments(false)
            ->setTypeChargement($primeNette);
        $this->attacher($consultance, $entreprise, $proprietaire);

        $gestion = (new TypeRevenu())
            ->setNom('Honoraire de gestion')
            ->setPourcentage(0.02)
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
                ->setImposable(true);
            $this->attacher($risque, $entreprise, $proprietaire);
        }
    }

    /**
     * @return array<int, array{code: string, nom: string, branche: int, commission: float}>
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
