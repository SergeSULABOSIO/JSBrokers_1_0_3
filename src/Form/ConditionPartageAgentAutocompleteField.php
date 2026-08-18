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
 * Rattachement d'une CONDITION DE PARTAGE AU PROFIT D'UN AGENT INTERNE à une affaire.
 *
 * On RATTACHE une condition existante, on ne la recrée pas : c'est ce qui distingue ce
 * champ de la collection `conditionsPartageExceptionnelles` juste à côté, où chaque piste
 * possède ses propres conditions (et où le renouvellement les clone). Ici, la règle
 * « prime apporteur 15 % » est écrite UNE fois et se retrouve à l'identique sur les dix
 * affaires de l'agent — modifier son taux les met toutes à jour, ce qui est précisément
 * l'intérêt d'une source unique.
 *
 * La liste ne propose QUE les conditions ayant un agent : celles des partenaires suivent
 * un tout autre circuit (elles se rattachent au partenaire ou à la piste) et n'auraient
 * aucun sens ici. Le filtre entreprise du projet est conservé au-dessus.
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
            'placeholder' => 'Rattacher une condition d\'agent',
            'query_builder' => static function (EntityRepository $repository) use ($filtreEntreprise): QueryBuilder {
                /** @var QueryBuilder $qb */
                $qb = $filtreEntreprise($repository);

                // Seules les conditions désignant un agent interne. `IS NOT NULL` et non
                // une jointure : une jointure écarterait aussi les conditions dont l'agent
                // a été supprimé, alors qu'on veut justement pouvoir les voir pour les
                // corriger.
                return $qb->andWhere($qb->getRootAliases()[0] . '.agent IS NOT NULL');
            },
            'searchable_fields' => ['nom'],
            'as_html' => true,
            'choice_label' => function (ConditionPartage $condition) {
                return sprintf(
                    '<div><strong>%s</strong><div style="color: #6c757d; font-size: 0.85em; padding-left: 2px; margin-top: 2px;">%s &middot; %s %%</div></div>',
                    htmlspecialchars($condition->getNom() ?? ''),
                    htmlspecialchars($condition->getAgent()?->getNom() ?? 'Agent supprimé'),
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
