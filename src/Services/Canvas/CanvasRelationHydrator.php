<?php

namespace App\Services\Canvas;

use Doctrine\Common\Collections\Collection;
use Symfony\Component\PropertyAccess\Exception\ExceptionInterface as PropertyAccessException;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Payload d'une FICHE (colonne de visualisation) : l'entité normalisée, complétée
 * des relations que le canevas déclare mais que la sérialisation ne fournit pas.
 *
 * POURQUOI. Le canevas d'entité décrit les relations par leur CHEMIN dans le graphe
 * (« cotation.piste.client », « cotation.assureur »). Ce chemin est parfait pour la
 * recherche — le moteur en fait des jointures DQL — mais il ne dit rien de ce que le
 * sérialiseur accepte de publier. Trois cas le mettaient en défaut, et l'accordéon
 * affichait « N/A » sur des données pourtant présentes en base :
 *
 *  1. un maillon hors des groupes de sérialisation (Piste::$risque) ;
 *  2. une relation entière hors groupes (Tranche::$cotation, Feedback::$invite) —
 *     l'y ajouter alourdirait CHAQUE ligne des listes, voire créerait un cycle
 *     (Piste → Risque → pistes → Piste), que le sérialiseur refuse ;
 *  3. une collection non exposée (Taxe::$autoriteFiscales).
 *
 * COMMENT. On lit le chemin directement sur l'OBJET, et on injecte le strict
 * nécessaire à l'affichage — l'identifiant (pour ouvrir la fiche liée) et le champ
 * d'affichage — sous la clé littérale du canevas. Aucun risque de cycle, aucun
 * gonflement des listes : ce travail n'a lieu que pour UNE entité, celle qu'on ouvre.
 *
 * Ce que la sérialisation fournit déjà n'est jamais écrasé : l'objet imbriqué complet
 * reste plus riche que le résumé produit ici.
 */
final class CanvasRelationHydrator
{
    public function __construct(
        private readonly NormalizerInterface $normalizer,
        private readonly PropertyAccessorInterface $accessor,
    ) {
    }

    /**
     * Entité normalisée (groupes `list:read`) + relations du canevas résolues.
     *
     * @param array<string, mixed> $entityCanvas
     * @return array<string, mixed>
     */
    public function payload(object $entity, array $entityCanvas): array
    {
        /** @var array<string, mixed> $payload */
        $payload = (array) $this->normalizer->normalize($entity, null, ['groups' => ['list:read']]);

        foreach (($entityCanvas['liste'] ?? []) as $attribut) {
            $type = (string) ($attribut['type'] ?? '');
            $code = (string) ($attribut['code'] ?? '');

            if ($code === '' || ($type !== 'Relation' && $type !== 'Collection')) {
                continue;
            }
            if ($this->dejaDansLePayload($payload, $code)) {
                continue;
            }

            $valeur = $this->lireSurEntite($entity, $code);
            if ($valeur === null) {
                continue;
            }

            $champ = (string) ($attribut['displayField'] ?? 'nom');

            if ($type === 'Collection') {
                $items = $valeur instanceof Collection ? $valeur->toArray() : (is_iterable($valeur) ? iterator_to_array($valeur) : []);
                $resumes = [];
                foreach ($items as $item) {
                    $resume = is_object($item) ? $this->resume($item, $champ) : null;
                    if ($resume !== null) {
                        $resumes[] = $resume;
                    }
                }
                if ($resumes !== []) {
                    $payload[$code] = $resumes;
                }
                continue;
            }

            $resume = is_object($valeur) ? $this->resume($valeur, $champ) : null;
            if ($resume !== null) {
                $payload[$code] = $resume;
            }
        }

        return $payload;
    }

    /**
     * La valeur est-elle DÉJÀ atteignable, à plat (« cotation ») ou par le chemin
     * sérialisé (« cotation.piste.client ») ? Auquel cas on n'y touche pas.
     *
     * @param array<string, mixed> $payload
     */
    private function dejaDansLePayload(array $payload, string $code): bool
    {
        if (array_key_exists($code, $payload)) {
            return $payload[$code] !== null;
        }

        $valeur = $payload;
        foreach (explode('.', $code) as $segment) {
            if (!is_array($valeur) || !array_key_exists($segment, $valeur)) {
                return false;
            }
            $valeur = $valeur[$segment];
        }

        return $valeur !== null;
    }

    /**
     * Valeur au bout du chemin, lue sur l'objet. Un maillon nul, une relation non
     * initialisée ou un getter absent ramènent null : l'attribut affichera « N/A »,
     * exactement comme avant — jamais d'erreur pour un simple affichage.
     */
    private function lireSurEntite(object $entity, string $code): mixed
    {
        try {
            return $this->accessor->getValue($entity, $code);
        } catch (PropertyAccessException | \Throwable) {
            return null;
        }
    }

    /**
     * Résumé d'affichage d'une entité liée : de quoi l'AFFICHER et l'OUVRIR, rien de
     * plus. `null` si elle n'a pas d'identifiant (objet non persisté).
     *
     * @return array<string, mixed>|null
     */
    private function resume(object $objet, string $champ): ?array
    {
        $id = $this->lireSurEntite($objet, 'id');
        if ($id === null) {
            return null;
        }

        $resume = ['id' => $id];
        $affichage = $this->lireSurEntite($objet, $champ);
        if ($affichage !== null && !is_object($affichage) && !is_array($affichage)) {
            $resume[$champ] = $affichage;
        }

        return $resume;
    }
}
