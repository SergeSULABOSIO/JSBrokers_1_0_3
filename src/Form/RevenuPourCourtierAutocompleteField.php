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
use App\Services\Canvas\Autocomplete\RevenuPourCourtierAutocompleteCanvasProvider;

#[AsEntityAutocompleteField]
class RevenuPourCourtierAutocompleteField extends AbstractType
{
    public function __construct(
        private FormListenerFactory $ecouteurFormulaire,
        private RequestStack $requestStack,
        private EntityManagerInterface $em,
        private CanvasBuilder $canvasBuilder,
        private SourceDeFacturation $sourceDeFacturation,
        private RevenuPourCourtierAutocompleteCanvasProvider $canvasProvider,
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
            return fn(RevenuPourCourtier $revenu) => $this->canvasProvider->getChoiceLabel($revenu, $this->currentOptions['parent_note'] ?? null);
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

}