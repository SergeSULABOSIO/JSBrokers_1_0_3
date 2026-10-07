<?php

namespace App\Validator;

use App\Entity\NotificationSinistre;
use App\Services\ReferencesDePolice;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/**
 * LA RÈGLE EST ICI, ET ELLE EST SEULE.
 *
 * ── POURQUOI L'ENTITÉ ET NON LE FORMULAIRE ──────────────────────────────────────
 * L'écran et l'assistant écrivent tous deux par le FormType, mais une règle posée sur le
 * formulaire ne vaudrait que pour eux. Sur l'entité, elle tient aussi pour un import, une
 * reprise, une commande — tout ce qui écrira demain.
 *
 * ── LA RÈGLE NE PORTE QUE SUR CE QU'ON ÉCRIT ────────────────────────────────────
 * Des sinistres anciens portent une référence saisie à la main, qui ne correspond à aucun
 * avenant. Les refuser en bloc rendrait leur fiche INÉDITABLE : on ne pourrait même plus
 * y corriger un numéro de téléphone. On contrôle donc à la CRÉATION, et à la modification
 * SEULEMENT si la référence a changé. Le dossier ancien reste modifiable ; quiconque
 * touche à sa référence doit en choisir une valide.
 */
class ReferenceDePoliceExistanteValidator extends ConstraintValidator
{
    public function __construct(
        private readonly ReferencesDePolice $references,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ReferenceDePoliceExistante) {
            throw new UnexpectedValueException($constraint, ReferenceDePoliceExistante::class);
        }
        if (!$value instanceof NotificationSinistre) {
            return;
        }

        $reference = trim((string) $value->getReferencePolice());
        if ($reference === '') {
            return; // L'obligation de saisie est portée par le formulaire, pas par cette règle.
        }

        $entreprise = $value->getEntreprise();
        if ($entreprise === null) {
            return; // Pas encore de cabinet posé : rien à confronter.
        }

        if (!$this->laReferenceEstEnJeu($value, $reference)) {
            return;
        }

        // D'ABORD L'EXISTENCE DANS LE CABINET, SANS REGARDER L'ASSURÉ : sinon une police
        // bien réelle mais mal attribuée serait annoncée « introuvable », ce qui enverrait
        // le courtier chercher une erreur de saisie au lieu d'une erreur de dossier.
        if (!$this->references->existe($entreprise, $reference)) {
            $this->context->buildViolation($constraint->messageIntrouvable)
                ->setParameter('{{ reference }}', $reference)
                ->atPath('referencePolice')
                ->addViolation();

            return;
        }

        $assure = $value->getAssure();
        if ($assure === null) {
            return; // Sans assuré, il n'y a pas de cohérence à vérifier.
        }

        if (!$this->references->existe($entreprise, $reference, $assure)) {
            $this->context->buildViolation($constraint->messageAutreClient)
                ->setParameter('{{ reference }}', $reference)
                ->setParameter('{{ titulaire }}', $this->titulaireDeLaPolice($value, $reference))
                ->setParameter('{{ assure }}', (string) $assure->getNom())
                ->atPath('referencePolice')
                ->addViolation();
        }
    }

    /**
     * La référence est-elle NEUVE ou MODIFIÉE ? C'est la seule chose qu'on juge.
     *
     * Doctrine garde l'état d'origine de l'entité chargée : c'est lui qui dit si le champ
     * a bougé. Une entité inconnue de l'unité de travail est une création.
     */
    private function laReferenceEstEnJeu(NotificationSinistre $sinistre, string $reference): bool
    {
        if ($sinistre->getId() === null) {
            return true;
        }

        $origine = $this->em->getUnitOfWork()->getOriginalEntityData($sinistre);
        if ($origine === [] || !array_key_exists('referencePolice', $origine)) {
            return true; // On ne sait pas d'où elle vient : on la juge.
        }

        return trim((string) $origine['referencePolice']) !== $reference;
    }

    /** Le nom du client que cette police couvre réellement, ou « un autre client ». */
    private function titulaireDeLaPolice(NotificationSinistre $sinistre, string $reference): string
    {
        $entreprise = $sinistre->getEntreprise();
        if ($entreprise === null) {
            return 'un autre client';
        }

        foreach ($this->references->pourLeCabinet($entreprise) as $libelle => $valeur) {
            if ($valeur !== $reference) {
                continue;
            }
            // Le libellé est « RÉFÉRENCE — Client · Risque · Assureur » : le client est le
            // premier élément du contexte, et il peut manquer.
            $morceaux = explode(' — ', $libelle, 2);
            if (count($morceaux) === 2) {
                return trim(explode(' · ', $morceaux[1])[0]);
            }
        }

        return 'un autre client';
    }
}
