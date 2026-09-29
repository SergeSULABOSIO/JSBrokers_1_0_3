<?php

namespace App\Form;

use App\Entity\Article;
use App\Entity\Entreprise;
use App\Entity\Note;
use App\Entity\RevenuPourCourtier;
use App\Services\Note\SourceDeFacturation;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use App\Services\FormListenerFactory;
use App\Services\CanvasBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\UX\Autocomplete\Form\AsEntityAutocompleteField;
use Symfony\UX\Autocomplete\Form\BaseEntityAutocompleteType;
use Symfony\Component\HttpFoundation\RequestStack;

#[AsEntityAutocompleteField]
class RevenuPourCourtierAutocompleteField extends AbstractType
{
    public function __construct(
        private FormListenerFactory $ecouteurFormulaire,
        private RequestStack $requestStack,
        private EntityManagerInterface $em,
        private CanvasBuilder $canvasBuilder,
        private SourceDeFacturation $sourceDeFacturation
    ) {}

    // NOUVEAU : Propriété pour stocker les options résolues.
    private ?Options $currentOptions = null;
    
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'class' => RevenuPourCourtier::class,
            'placeholder' => 'Rechercher un revenu',
            'note_id' => null, 
            'searchable_fields' => ['nom'],
            'as_html' => true,
            'parent_article' => null, // NOUVEAU : On définit l'option personnalisée.
            'parent_note' => null, // NOUVEAU : Pour recevoir l'entité Note parente.
            
            // ⚠ CE CHAMP LISTAIT LES REVENUS DE TOUS LES CABINETS.
            //
            // La closure posée ici était nue — `createQueryBuilder('r')`, sans la moindre
            // clause WHERE. L'endpoint /autocomplete/revenu_pour_courtier_autocomplete_field
            // renvoyait donc les revenus de n'importe quelle entreprise, et le dialogue de
            // ligne de note laissait en sélectionner un. Dix-sept autres champs de ce
            // dossier scopaient pourtant depuis toujours ; celui-ci et la tranche étaient
            // les deux seuls à ne pas le faire.
            //
            // setFiltreEntreprise() est fail-closed : sans identité (ligne de commande,
            // FormTreeInspector qui monte le formulaire pour en lire l'arborescence,
            // worker de Ket), il filtre sur l'entreprise -1 et rend une liste vide. Sans
            // identité, on ne propose rien — jamais tout.
            //
            // ── ET LA RÈGLE MÉTIER PAR-DESSUS ────────────────────────────────────
            // Le cabinet ne suffit pas : on ne facture pas un revenu déjà soldé. Cette
            // règle vit dans SourceDeFacturation, et c'est la MÊME que l'assistant
            // applique — une seule règle, deux surfaces.
            //
            // ⚠ ELLE PASSE PAR LE QUERY_BUILDER, ET C'EST OBLIGÉ. L'endpoint
            // d'autocomplétion ne lit QUE cette option (cf.
            // WrappedEntityTypeAutocompleter::createFilteredQueryBuilder) : une liste
            // posée en « choices » serait simplement ignorée par la recherche AJAX.
            // Les soldes étant CALCULÉS, on ne peut pas les exprimer en DQL — on
            // calcule donc les identifiants éligibles, et on restreint dessus.
            'query_builder' => function (EntityRepository $er): QueryBuilder {
                $qb = ($this->ecouteurFormulaire->setFiltreEntreprise())($er);
                $eligibles = $this->identifiantsFacturables();

                // null = contexte inconnu (construction du formulaire, validation d'une
                // valeur soumise). On s'en tient alors au cabinet : être fail-closed ICI
                // refuserait une valeur parfaitement légitime au moment de l'enregistrer.
                if ($eligibles !== null) {
                    $qb->andWhere('e.id IN (:facturables)')
                       ->setParameter('facturables', $eligibles ?: [0]);
                }

                return $qb;
            },
        ]);

        // CORRECTION POUR LE SURLIGNAGE :
        // On utilise un normaliseur sur 'choice_label'. C'est la méthode la plus fiable
        // pour capturer le contexte (les Options) AVANT que le choice_label ne soit utilisé,
        // que ce soit au rendu initial ou lors des requêtes AJAX d'autocomplétion.
        $resolver->setNormalizer('choice_label', function (Options $options, $value) {
            // 1. On stocke les options pour que `renderChoiceLabel` y ait accès.
            $this->currentOptions = $options;

            // 2. On retourne la fonction qui sera réellement utilisée comme 'choice_label'.
            return fn(RevenuPourCourtier $revenu) => $this->renderChoiceLabel($revenu);
        });
    }

    /**
     * Construit le QueryBuilder pour ne récupérer que les revenus éligibles.
     *
     * @param Options $options
     * @return \Doctrine\ORM\QueryBuilder
     */
    public function getParent(): string
    {
        return BaseEntityAutocompleteType::class;
    }

    /**
     * LES REVENUS QU'ON PEUT ENCORE FACTURER, ou null quand le contexte ne le dit pas.
     *
     * Le destinataire vient soit du formulaire en cours de saisie (les paramètres
     * `live_*` que pose le contrôleur Stimulus), soit de la note déjà enregistrée
     * qu'on édite. Sans lui, « facturable » n'a pas de sens : on rend null, et
     * l'appelant s'en tient au cloisonnement par cabinet.
     *
     * L'ÉLÉMENT DÉJÀ CHOISI RESTE TOUJOURS PROPOSÉ. Sans cela, rouvrir une ligne de
     * facture dont le revenu a été soldé depuis viderait le champ, et l'utilisateur
     * perdrait la donnée en enregistrant une correction sans rapport.
     *
     * @return list<int>|null identifiants facturables, null si le contexte est muet
     */
    private function identifiantsFacturables(): ?array
    {
        $contexte = $this->destinataireCourant();
        if ($contexte === null) {
            return null;
        }

        try {
            $entreprise = $this->em->getRepository(Entreprise::class)
                ->find($this->ecouteurFormulaire->getCurrentEntrepriseId());
        } catch (\Throwable) {
            return null; // pas d'utilisateur courant : le cloisonnement fera seul le travail.
        }
        if ($entreprise === null) {
            return null;
        }

        [$addressedTo, $cibleId] = $contexte;

        $ids = [];
        foreach ($this->sourceDeFacturation->revenusFacturables($entreprise, $addressedTo, $cibleId) as $revenu) {
            $ids[] = (int) $revenu->getId();
        }

        $dejaChoisi = $this->currentOptions['parent_article'] ?? null;
        $idChoisi = $dejaChoisi instanceof Article ? $dejaChoisi->getRevenuFacture()?->getId() : null;
        if ($idChoisi !== null && !in_array((int) $idChoisi, $ids, true)) {
            $ids[] = (int) $idChoisi;
        }

        return $ids;
    }

    /**
     * À QUI CETTE NOTE S'ADRESSE, et sur qui elle porte — lu dans la requête en cours
     * de saisie, à défaut sur la note déjà enregistrée. null quand ni l'un ni l'autre
     * ne le dit.
     *
     * @return array{0: int, 1: ?int}|null [destinataire, identifiant de la cible]
     */
    private function destinataireCourant(): ?array
    {
        $requete = $this->requestStack->getCurrentRequest();

        // LE FORMULAIRE EN COURS D'ABORD : il porte ce que l'utilisateur vient de
        // choisir, là où la note enregistrée porte encore l'état d'avant.
        $live = [
            'live_assureur_id' => Note::TO_ASSUREUR,
            'live_client_id' => Note::TO_CLIENT,
            'live_partenaire_id' => Note::TO_PARTENAIRE,
            'live_autorite_id' => Note::TO_AUTORITE_FISCALE,
        ];
        foreach ($live as $parametre => $destinataire) {
            $valeur = $requete?->query->get($parametre);
            if ($valeur !== null && $valeur !== '') {
                return [$destinataire, (int) $valeur];
            }
        }

        $note = $this->currentOptions['parent_note'] ?? null;
        if (!$note instanceof Note || $note->getAddressedTo() === null) {
            return null;
        }

        return [$note->getAddressedTo(), match ($note->getAddressedTo()) {
            Note::TO_ASSUREUR => $note->getAssureur()?->getId(),
            Note::TO_CLIENT => $note->getClient()?->getId(),
            Note::TO_PARTENAIRE => $note->getPartenaire()?->getId(),
            Note::TO_AUTORITE_FISCALE => $note->getAutoritefiscale()?->getId(),
            default => null,
        }];
    }

    /**
     * Génère le label HTML pour une option d'autocomplétion.
     *
     * @param RevenuPourCourtier $revenu
     * @return string
     */
    private function renderChoiceLabel(RevenuPourCourtier $revenu): string
    {
        $cotation = $revenu->getCotation();
        $avenant = ($cotation && !$cotation->getAvenants()->isEmpty()) ? $cotation->getAvenants()->first() : null;
        $piste = $cotation ? $cotation->getPiste() : null;
        
        $policeRef = ($avenant && $avenant->getReferencePolice()) ? $avenant->getReferencePolice() : 'N/A';
        $assureurNom = ($cotation && $cotation->getAssureur()) ? $cotation->getAssureur()->getNom() : 'N/A';
        $clientNom = ($piste && $piste->getClient()) ? $piste->getClient()->getNom() : 'N/A';
        
        // L'hydratation est déjà faite dans getEligibleRevenus, mais on s'assure qu'elle est là.
        $this->canvasBuilder->loadAllCalculatedValues($revenu);
        
        $comTTC = $revenu->montantCalculeTTC ?? 0.0;
        $comPayee = $revenu->montant_paye ?? 0.0;
        $comSolde = $revenu->solde_restant_du ?? 0.0;
        $comHT = $revenu->montantCalculeHT ?? 0.0;
        $comPure = $revenu->montantPur ?? 0.0;
        $reserve = $revenu->reserve ?? 0.0;
        $retroDue = $revenu->retroCommission ?? 0.0;
        $retroPayee = $revenu->retroCommissionReversee ?? 0.0;
        $retroSolde = $revenu->retroCommissionSolde ?? 0.0;
        $taxeCMontant = $revenu->taxeCourtierMontant ?? 0.0;
        $taxeCPayee = $revenu->taxeCourtierPayee ?? 0.0;
        $taxeCSolde = $revenu->taxeCourtierSolde ?? 0.0;
        $taxeAMontant = $revenu->taxeAssureurMontant ?? 0.0;
        $taxeAPayee = $revenu->taxeAssureurPayee ?? 0.0;
        $taxeASolde = $revenu->taxeAssureurSolde ?? 0.0;

        $partenaireNom = $revenu->partenaireNom ?? $revenu->partenaire_nom ?? 'N/A';
        $nombreTranches = $cotation ? $cotation->getTranches()->count() : 0;

        $clsCS = abs($comSolde) < 0.01 ? 'text-success' : 'text-danger';
        $clsRS = abs($retroSolde) < 0.01 ? 'text-success' : 'text-danger';
        $clsTCS = abs($taxeCSolde) < 0.01 ? 'text-success' : 'text-danger';
        $clsTAS = abs($taxeASolde) < 0.01 ? 'text-success' : 'text-danger';

        // NOUVEAU : Logique pour déterminer les colonnes à surligner
        $request = $this->requestStack->getCurrentRequest();
        $highlightClass = 'jsb-indicator-highlight';
        $col1_class = '';
        $col2_class = '';
        $col3_class = '';
        $col4_class = '';
        $col5_class = '';

        // Contexte live (AJAX)
        $liveAssureurId = $request?->query->get('live_assureur_id');
        $liveClientId = $request?->query->get('live_client_id');
        $livePartenaireId = $request?->query->get('live_partenaire_id');
        $liveAutoriteId = $request?->query->get('live_autorite_id');

        // Contexte statique (chargement initial en mode édition)
        /** @var \App\Entity\Note|null $parentNote */
        $parentNote = $this->currentOptions ? $this->currentOptions['parent_note'] : null;
        $noteAddressedTo = $parentNote?->getAddressedTo();

        // Logique de surlignage unifiée
        if ($liveAssureurId || $liveClientId || ($parentNote && in_array($noteAddressedTo, [Note::TO_ASSUREUR, Note::TO_CLIENT]))) {
            // Facturation de commission (due par assureur ou client)
            $col1_class = $highlightClass;
            $col2_class = $highlightClass;
        } elseif ($livePartenaireId || ($parentNote && $noteAddressedTo === Note::TO_PARTENAIRE)) {
            // Facturation de rétro-commission
            $col3_class = $highlightClass;
        } elseif ($liveAutoriteId || ($parentNote && $noteAddressedTo === Note::TO_AUTORITE_FISCALE)) {
            $autoriteId = $liveAutoriteId ?? $parentNote?->getAutoritefiscale()?->getId();
            if ($autoriteId) {
                $autorite = $this->em->getRepository(\App\Entity\AutoriteFiscale::class)->find($autoriteId);
                if ($autorite && $taxe = $autorite->getTaxe()) {
                    if ($taxe->getRedevable() === \App\Entity\Taxe::REDEVABLE_COURTIER) {
                        $col4_class = $highlightClass;
                    } elseif ($taxe->getRedevable() === \App\Entity\Taxe::REDEVABLE_ASSUREUR) {
                        $col5_class = $highlightClass;
                    }
                }
            }
        }

        return sprintf(
            '<div class="jsb-autocomplete-item" data-id="%d">
                <div class="jsb-autocomplete-title">%s</div>
                <div class="jsb-autocomplete-context">
                    <span>Police: <strong>%s</strong></span>
                    <span class="jsb-context-separator">|</span>
                    <span>Assureur: <strong>%s</strong></span>
                    <span class="jsb-context-separator">|</span>
                    <span>Client: <strong>%s</strong></span>
                    <span class="jsb-context-separator">|</span>
                    <span class="badge bg-secondary">%d tranches</span>
                </div>
                <div class="jsb-autocomplete-indicators">
                    <div class="%s">
                        <div><span class="jsb-indicator-label">Com. TTC</span><span class="jsb-indicator-value">%s</span></div>
                        <div><span class="jsb-indicator-label">Encaissée</span><span class="jsb-indicator-value">%s</span></div>
                        <div><span class="jsb-indicator-label">Solde</span><span class="jsb-indicator-value %s">%s</span></div>
                    </div>
                    <div class="%s">
                        <div><span class="jsb-indicator-label">Com. HT</span><span class="jsb-indicator-value">%s</span></div>
                        <div><span class="jsb-indicator-label">Com. Pure</span><span class="jsb-indicator-value text-cobalt">%s</span></div>
                        <div><span class="jsb-indicator-label">Réserve</span><span class="jsb-indicator-value">%s</span></div>
                    </div>
                    <div class="%s">
                        <div><span class="jsb-indicator-label">Rétro (%s)</span><span class="jsb-indicator-value">%s</span></div>
                        <div><span class="jsb-indicator-label">Rétro Payée</span><span class="jsb-indicator-value">%s</span></div>
                        <div><span class="jsb-indicator-label">Solde</span><span class="jsb-indicator-value %s">%s</span></div>
                    </div>
                    <div class="%s">
                        <div><span class="jsb-indicator-label">T. Courtier</span><span class="jsb-indicator-value">%s</span></div>
                        <div><span class="jsb-indicator-label">Taxe Payée</span><span class="jsb-indicator-value">%s</span></div>
                        <div><span class="jsb-indicator-label">Solde</span><span class="jsb-indicator-value %s">%s</span></div>
                    </div>
                    <div class="%s">
                        <div><span class="jsb-indicator-label">T. Assureur</span><span class="jsb-indicator-value">%s</span></div>
                        <div><span class="jsb-indicator-label">Taxe Payée</span><span class="jsb-indicator-value">%s</span></div>
                        <div><span class="jsb-indicator-label">Solde</span><span class="jsb-indicator-value %s">%s</span></div>
                    </div>
                </div>
            </div>',
            $revenu->getId(),
            htmlspecialchars($revenu->getNom() ?? 'Sans nom'),
            htmlspecialchars($policeRef),
            htmlspecialchars($assureurNom),
            htmlspecialchars($clientNom),
            $nombreTranches,
            $col1_class,
            number_format($comTTC, 2, ',', ' '),
            number_format($comPayee, 2, ',', ' '),
            $clsCS, number_format($comSolde, 2, ',', ' '),
            $col2_class,
            number_format($comHT, 2, ',', ' '),
            number_format($comPure, 2, ',', ' '),
            number_format($reserve, 2, ',', ' '),
            $col3_class,
            htmlspecialchars($partenaireNom),
            number_format($retroDue, 2, ',', ' '),
            number_format($retroPayee, 2, ',', ' '),
            $clsRS, number_format($retroSolde, 2, ',', ' '),
            $col4_class,
            number_format($taxeCMontant, 2, ',', ' '),
            number_format($taxeCPayee, 2, ',', ' '),
            $clsTCS, number_format($taxeCSolde, 2, ',', ' '),
            $col5_class,
            number_format($taxeAMontant, 2, ',', ' '),
            number_format($taxeAPayee, 2, ',', ' '),
            $clsTAS, number_format($taxeASolde, 2, ',', ' ')
        );
    }
}