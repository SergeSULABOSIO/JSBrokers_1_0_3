<?php

namespace App\Service\Workspace;

use App\Entity\Note;

/**
 * CE QU'UNE ENTITÉ PORTE DÈS SA NAISSANCE, ET QUE SON FORMULAIRE NE DÉCLARE PAS.
 *
 * ── LE PROBLÈME QUE CETTE CLASSE RÉSOUT ─────────────────────────────────────────────
 * ⚠ QUELQUES COLONNES SONT « NOT NULL » SANS ÊTRE DES QUESTIONS. `Note::$signature` en est
 * l'exemple parfait : la base l'exige, aucun formulaire ne la propose, et l'écran y met
 * simplement l'horodatage du moment. Ce n'est pas une signature au sens du métier — c'est
 * un jeton technique, et personne n'a jamais eu à le saisir.
 *
 * Tant que ces valeurs vivaient dans le contrôleur d'écran, elles étaient hors de portée
 * de tout ce qui écrit AUTREMENT que par l'écran : l'assistant, et surtout la reprise de
 * données. Le circuit d'écriture commun réclamait alors un champ que rien ne pouvait
 * fournir — le formulaire ignore ce qu'il ne déclare pas — et la seule issue était de
 * renoncer à créer l'entité. C'est ce qui empêchait une reprise d'enregistrer les
 * commissions déjà encaissées : cinquante refus, un par échéance, pour deux colonnes.
 *
 * ── CE QUI A DROIT DE FIGURER ICI ───────────────────────────────────────────────────
 * ⚠ UNE VALEUR DE NAISSANCE N'EST PAS UN DÉFAUT DE SAISIE, et la distinction commande
 * tout. Un défaut propose ce que l'utilisateur pourra changer, et se pose donc dans le
 * FORMULAIRE (option `data`) ou par déduction contextuelle ({@see \App\Ai\Mutation\DefautsContextuels}).
 * Ce qui se pose ici, au contraire, ne se choisit pas : c'est une exigence technique de la
 * base à laquelle aucune interface n'a jamais donné de case.
 *
 * Le jour où l'un de ces champs devient une vraie question — quelqu'un signe vraiment, ou
 * valide vraiment —, sa place n'est plus ici mais dans le formulaire, et la règle
 * disparaît de cette table. C'est le test à s'appliquer avant d'y ajouter quoi que ce soit.
 *
 * ⚠ ET C'EST UN SEUL ENDROIT POUR TOUS LES ÉCRIVAINS. L'écran, l'assistant et la reprise
 * empruntent la même règle : une note née d'un import est en tout point une note née d'un
 * clic. Recopier ces trois lignes dans chaque appelant, c'était s'engager à les maintenir
 * en autant d'exemplaires — et le premier oubli aurait produit une erreur SQL brute.
 */
final class ValeursDeNaissance
{
    /**
     * APPLIQUE les valeurs de naissance à une entité NEUVE, sans écraser ce qui est posé.
     *
     * Sans effet sur une entité qui n'en déclare aucune : la très grande majorité.
     */
    public function poser(object $entity): void
    {
        foreach ($this->pour($entity) as $champ => $valeur) {
            $ecrivain = 'set' . ucfirst($champ);
            // ⚠ UN BOOLÉEN SE LIT PAR `is`, PAS PAR `get` — `Note::isValidated()`. Chercher
            // le seul `get` aurait fait passer la règle SANS RIEN POSER, et le champ serait
            // reparti NULL vers une colonne qui le refuse : une erreur SQL brute, à
            // l'écriture, sur un contrôle qui avait dit oui.
            $lecteur = $this->lecteur($entity, $champ);

            if (!method_exists($entity, $ecrivain) || $lecteur === null) {
                continue;
            }
            // ⚠ ON NE REMPLACE JAMAIS UNE VALEUR EXISTANTE. Un appelant qui a déjà tranché
            // — une note importée d'un bordereau, un statut voulu — doit garder la main :
            // ces valeurs comblent un vide, elles n'imposent rien.
            if ($entity->{$lecteur}() !== null) {
                continue;
            }

            $entity->{$ecrivain}($valeur);
        }
    }

    /** Le nom du getter de ce champ — `getX`, ou `isX` pour un booléen. */
    private function lecteur(object $entity, string $champ): ?string
    {
        foreach (['get' . ucfirst($champ), 'is' . ucfirst($champ)] as $candidat) {
            if (method_exists($entity, $candidat)) {
                return $candidat;
            }
        }

        return null;
    }

    /**
     * Ce que cette entité doit porter en naissant.
     *
     * @return array<string, mixed> nom de propriété => valeur
     */
    private function pour(object $entity): array
    {
        return match (true) {
            $entity instanceof Note => [
                // ⚠ « GÉNÉRÉE AUTOMATIQUEMENT », dit le formulaire — et il le fait en
                // désactivant le champ, donc en IGNORANT toute valeur soumise. Une note
                // créée autrement que par l'écran repartait sans référence vers une colonne
                // qui la refuse : l'import échouait en SQL après un contrôle qui avait dit
                // oui.
                //
                // ⚠ ET ELLE DISTINGUE. L'écran en crée une à la fois, si bien qu'un
                // horodatage à la seconde suffisait ; une reprise en crée des centaines
                // dans la même seconde, et autant de pièces au même numéro seraient
                // impossibles à rapprocher d'une échéance. Le format ne change pas pour
                // autant — c'est toujours « N » suivi de chiffres.
                'reference' => 'N' . time() . random_int(100, 999),
                // ⚠ CE N'EST PAS UNE SIGNATURE, malgré son nom : la colonne est NOT NULL et
                // aucun formulaire ne la propose. L'écran y met l'horodatage du moment
                // depuis toujours ; qui signe vraiment se lit dans `signedBy`, lui
                // facultatif et bien présent au formulaire.
                'signature' => (string) time(),
                // Une note naît NON VALIDÉE. La validation est un geste posé ensuite, par
                // quelqu'un : la présumer faite reviendrait à sauter ce geste.
                'validated' => false,
                // La date d'émission, faute de mieux : une note existe parce qu'on l'émet.
                'sentAt' => new \DateTimeImmutable(),
            ],
            default => [],
        };
    }
}
