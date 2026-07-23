<?php

namespace App\Service\Workspace;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Symfony\Component\Form\FormFactoryInterface;

/**
 * Source UNIQUE de la notion « champ obligatoire » d'une entité, dérivée des
 * métadonnées Doctrine (nullabilité de colonne, défaut BDD/PHP) plutôt que de
 * contraintes #[Assert] écrites entité par entité (couverture aujourd'hui trop
 * clairsemée). Les prédicats sont partagés :
 *  - par l'assistant IA (Ket) via {@see WorkspaceMutationService} — invariant
 *    « annoncé = exigé » de l'inventaire des champs ;
 *  - par le CRUD HTTP interactif via ControllerUtilsTrait::handleFormSubmission,
 *    pour transformer un champ obligatoire vide en erreur 422 propre (au lieu
 *    d'un 500 au flush Doctrine).
 *
 * Fournit aussi le libellé LISIBLE d'un champ (lu depuis le FormType) pour que
 * les messages d'erreur nomment le champ tel que l'utilisateur le voit.
 */
class ChampsObligatoiresInspector
{
    /** Champs jamais exigés à l'utilisateur (système + scoping auto). */
    public const CHAMPS_SYSTEME = ['id', 'createdAt', 'updatedAt'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FormFactoryInterface $formFactory,
    ) {
    }

    /**
     * Champs obligatoires laissés VIDES sur une entité déjà hydratée (typiquement
     * après {@see \Symfony\Component\Form\FormInterface::submit()}). Renvoie une
     * carte `champ => [message]` directement fusionnable avec les erreurs Symfony.
     *
     * @param string[]|null $champsPilotables Si fourni, restreint le contrôle aux
     *        seuls champs exposés par le formulaire : un champ obligatoire renseigné
     *        AILLEURS (ex. par un beforePersist, un listener ou l'auto-scoping) n'est
     *        alors jamais signalé à tort. Passer `array_keys($form->all())`.
     *
     * @return array<string, string[]>
     */
    public function champsManquants(object $entity, ?array $champsPilotables = null): array
    {
        try {
            $meta = $this->em->getClassMetadata($entity::class);
        } catch (\Throwable) {
            return [];
        }

        $limiter = $champsPilotables !== null;
        $manquants = [];

        // Colonnes scalaires non-nullables, sans défaut BDD/PHP, laissées vides.
        foreach ($meta->getFieldNames() as $field) {
            if (in_array($field, self::CHAMPS_SYSTEME, true) || $meta->isNullable($field)) {
                continue;
            }
            if ($this->aUnDefaut($meta, $field)) {
                continue;
            }
            if ($limiter && !in_array($field, $champsPilotables, true)) {
                continue;
            }
            if ($this->estVide($entity, $meta, $field)) {
                $manquants[$field] = ['Ce champ est obligatoire.'];
            }
        }

        // Relations ManyToOne obligatoires (hors entreprise/invite auto-scopés).
        foreach ($meta->getAssociationMappings() as $field => $mapping) {
            if (!$this->relationRequise($field, $mapping)) {
                continue;
            }
            if ($limiter && !in_array($field, $champsPilotables, true)) {
                continue;
            }
            if (!$this->valeurNonNulle($entity, $meta, $field)) {
                $manquants[$field] = ['Ce champ est obligatoire.'];
            }
        }

        return $manquants;
    }

    /** Un champ scalaire est-il OBLIGATOIRE (non-nullable, sans défaut BDD/PHP, hors système) ? */
    public function scalaireRequis(ClassMetadata $meta, object $entity, string $field): bool
    {
        if (in_array($field, self::CHAMPS_SYSTEME, true) || $meta->isNullable($field)) {
            return false;
        }

        return !$this->aUnDefaut($meta, $field) && !$this->valeurNonNulle($entity, $meta, $field);
    }

    /** Une relation ManyToOne est-elle OBLIGATOIRE (colonne non-null, hors entreprise/invite auto) ? */
    public function relationRequise(string $field, object $mapping): bool
    {
        if (!$mapping->isManyToOne() || !$mapping->isOwningSide() || in_array($field, ['entreprise', 'invite'], true)) {
            return false;
        }
        foreach (($mapping->joinColumns ?? []) as $jc) {
            if (($jc->nullable ?? true) === false) {
                return true;
            }
        }

        return false;
    }

    /** Libellé LISIBLE d'un champ (lu depuis le FormType), repli sur l'humanisation. */
    public function libelleChamp(string $fqcn, string $field): string
    {
        $pos = strrpos($fqcn, '\\');
        $shortName = $pos !== false ? substr($fqcn, $pos + 1) : $fqcn;

        return $this->libellesFormulaire($shortName, $fqcn)[$field] ?? $this->humaniser($field);
    }

    /** Libellés lisibles des champs, lus depuis le FormType (jamais les listes de choix). */
    public function libellesFormulaire(string $shortName, string $fqcn): array
    {
        $labels = [];
        try {
            $form = $this->formFactory->create('App\\Form\\' . $shortName . 'Type', new $fqcn());
            foreach ($form->all() as $child) {
                $lbl = $child->getConfig()->getOption('label');
                if (is_string($lbl) && trim($lbl) !== '') {
                    $labels[$child->getName()] = $lbl;
                }
            }
        } catch (\Throwable) {
            // Pas de FormType exploitable : on retombera sur l'humanisation.
        }

        return $labels;
    }

    /** Humanise un nom de champ technique (fallback quand le FormType n'a pas de libellé). */
    public function humaniser(string $field): string
    {
        $s = (string) preg_replace('/(?<!^)[A-Z]/', ' $0', $field);
        $s = str_replace('_', ' ', $s);

        return ucfirst(mb_strtolower(trim($s)));
    }

    /** La valeur de l'entité pour ce champ est-elle déjà non nulle (défaut PHP) ? */
    public function valeurNonNulle(object $entity, ClassMetadata $meta, string $field): bool
    {
        try {
            return $meta->getFieldValue($entity, $field) !== null;
        } catch (\Throwable) {
            return false; // propriété typée non initialisée => considérée manquante.
        }
    }

    /** Le champ a-t-il un défaut au niveau de la colonne (rempli par la BDD) ? */
    public function aUnDefaut(ClassMetadata $meta, string $field): bool
    {
        try {
            $mapping = $meta->getFieldMapping($field);
        } catch (\Throwable) {
            return false;
        }
        $options = is_object($mapping) ? ($mapping->options ?? []) : ($mapping['options'] ?? []);

        return is_array($options) && array_key_exists('default', $options);
    }

    /** Une valeur scalaire est-elle « vide » (null ou chaîne blanche) ? */
    private function estVide(object $entity, ClassMetadata $meta, string $field): bool
    {
        try {
            $v = $meta->getFieldValue($entity, $field);
        } catch (\Throwable) {
            return true; // propriété typée non initialisée => manquante.
        }
        if ($v === null) {
            return true;
        }

        return is_string($v) && trim($v) === '';
    }
}
