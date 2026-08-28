<?php

namespace App\Form;

use App\Entity\ConditionPartage;
use App\Services\FormListenerFactory;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\UX\Autocomplete\Form\AsEntityAutocompleteField;
use Symfony\UX\Autocomplete\Form\BaseEntityAutocompleteType;

/**
 * Rattachement d'une CONDITION DE PARTAGE — d'agent interne OU de partenaire externe.
 *
 * On RATTACHE une condition existante, on ne la recrée pas : c'est ce qui distingue ce
 * champ de la collection `conditionsPartageExceptionnelles` juste à côté, où chaque piste
 * possède ses propres conditions (et où le renouvellement les clone). Ici, la règle
 * « prime apporteur 15 % » est écrite UNE fois et se retrouve à l'identique sur les dix
 * affaires du bénéficiaire — modifier son taux les met toutes à jour, ce qui est
 * précisément l'intérêt d'une source unique.
 *
 * ── LES DEUX FAMILLES, DÉSORMAIS ────────────────────────────────────────────────────
 * La liste ne proposait QUE les conditions ayant un agent : celles des partenaires
 * « suivaient un tout autre circuit » — elles se rattachaient au partenaire (donc à
 * TOUTES ses affaires) ou à la piste (donc à une seule). On ne pouvait pas dire « ces
 * trois affaires-ci relèvent de l'accord SUNU 20 % ».
 *
 * Ce filtre était donc le dernier verrou de l'asymétrie, et il fermait le geste À TOUS
 * LES CHEMINS : formulaire, picker et assistant passent par ce champ, l'écriture d'un
 * ManyToMany allant toujours par le FormType. Le retirer les ouvre tous les trois d'un
 * coup — et les règles, elles, restent tenues par RattachementDuPartage, qui les
 * applique quel que soit le chemin.
 *
 * Le filtre entreprise du projet est conservé au-dessus.
 */
#[AsEntityAutocompleteField]
class ConditionPartageAgentAutocompleteField extends AbstractType
{
    public function __construct(
        private FormListenerFactory $ecouteurFormulaire,
    ) {
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $filtreEntreprise = $this->ecouteurFormulaire->setFiltreEntreprise();

        $resolver->setDefaults([
            'class' => ConditionPartage::class,
            'placeholder' => 'Rattacher une condition de partage',
            'query_builder' => static function (EntityRepository $repository) use ($filtreEntreprise): QueryBuilder {
                /** @var QueryBuilder $qb */
                $qb = $filtreEntreprise($repository);

                // AUCUN FILTRE DE FAMILLE : les deux se rattachent du même geste, et la
                // famille se lit sur la condition choisie. On ne trie pas non plus les
                // conditions dont le bénéficiaire a été supprimé : on veut justement
                // pouvoir les voir pour les corriger.
                return $qb->orderBy($qb->getRootAliases()[0] . '.nom', 'ASC');
            },
            'searchable_fields' => ['nom'],
            'as_html' => true,
            'choice_label' => function (ConditionPartage $condition) {
                return sprintf(
                    '<div><strong>%s</strong><div style="color: #6c757d; font-size: 0.85em; padding-left: 2px; margin-top: 2px;">%s &middot; %s %%</div></div>',
                    htmlspecialchars($condition->getNom() ?? ''),
                    // LE BÉNÉFICIAIRE, quelle que soit sa famille : lire `getAgent()` seul
                    // aurait affiché « Agent supprimé » sur toutes les conditions de
                    // partenaire — un libellé qui ment sur ce qu'on s'apprête à rattacher.
                    htmlspecialchars(
                        ($condition->estPourAgent()
                            ? $condition->getAgent()?->getNom()
                            : $condition->getPartenaire()?->getNom())
                        ?? 'Bénéficiaire supprimé',
                    ),
                    htmlspecialchars(rtrim(rtrim(number_format((float) $condition->getTaux(), 2, ',', ' '), '0'), ',')),
                );
            },
        ]);
    }

    public function getParent(): string
    {
        return BaseEntityAutocompleteType::class;
    }
}
