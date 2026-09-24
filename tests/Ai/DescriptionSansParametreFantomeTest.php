<?php

namespace App\Tests\Ai;

use App\Ai\Trousse\TrousseCatalogue;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * UNE DESCRIPTION NE NOMME QUE DES PARAMÈTRES QUI EXISTENT.
 *
 * ── L'INCIDENT ──────────────────────────────────────────────────────────────────────
 *
 * Les trois outils de liste les plus appelés — rechercher_entites, compter_entites,
 * ouvrir_rubrique — annonçaient au modèle un paramètre « statutPaiement » pour filtrer
 * les tranches. Ce paramètre n'a jamais existé : il s'appelle « axes ». Le modèle qui
 * suivait la description émettait donc un argument que le serveur ignorait en silence,
 * et la liste rendue ne coïncidait plus avec les chips de la rubrique — précisément la
 * parité que ces descriptions promettent.
 *
 * Rien ne pouvait le voir : le schéma est valide, l'appel part, l'outil répond. Seul le
 * RAPPROCHEMENT entre la prose et le schéma le révèle, et c'est ce que fait ce test.
 *
 * ── MÉTHODE ─────────────────────────────────────────────────────────────────────────
 *
 * Tout identifiant en casse chameau (statutPaiement, lieA, dateEffet) trouvé dans la
 * description ou l'aiguillage d'un outil doit être SOIT une propriété de son propre
 * schéma, SOIT inscrit dans la liste ci-dessous — qui recense les noms de champs de
 * RÉSULTAT, légitimement cités puisque le modèle les relit dans la réponse.
 *
 * Inscrire un nom dans cette liste est un geste délibéré : c'est affirmer « ce n'est pas
 * un paramètre ». C'est exactement la décision qui manquait.
 */
class DescriptionSansParametreFantomeTest extends KernelTestCase
{
    /**
     * Identifiants cités à bon droit sans être un paramètre de l'outil qui les nomme.
     *
     * Chaque entrée est une affirmation : « ceci n'est pas un argument d'appel ». C'est
     * la décision qui manquait le jour où « statutPaiement » s'est glissé dans trois
     * descriptions sans que personne n'ait à se prononcer.
     */
    private const CHAMPS_DE_RESULTAT = [
        // ── CHAMPS DE RÉPONSE, que le modèle RELIT dans le résultat ─────────────────
        // rechercher_entites décrit les deux sens de la chaîne de renouvellement qu'il
        // rend sur chaque police, et le témoin qui dit ce qu'un filtre a réellement visé.
        'suiteDeLaPolice', 'avenantsIssusIds', 'origineDeLaPolice', 'avenantPrecedentId',
        'filtreInterpreteCommeLien',
        // Colonnes rendues par la chronologie et la vigie.
        'saisiLe', 'aVenir',

        // ── ARGUMENTS D'AUTRES OUTILS ───────────────────────────────────────────────
        // preparer_programme enchaîne des étapes dont le champ `arguments` porte les
        // paramètres de l'outil de CHAQUE étape : sa description les nomme forcément,
        // et ils n'ont aucune raison de figurer dans son propre schéma.
        'abandonnerMouvementExistant', 'agentId', 'assureurId', 'avenantId', 'cibleId',
        'compteBancaireId', 'dateDebut', 'dateEffet', 'dateFin', 'demandeId',
        'demiJourneeDebut', 'demiJourneeFin', 'dureeJours', 'fichierId', 'paidAt',
        'partenaireId', 'referencePolice', 'trancheId', 'typeAbsence',

        // Clés internes de la charge utile d'un reversement et d'un document.
        'sourceMessageId',
    ];

    public function testAucuneDescriptionNeNommeUnParametreInexistant(): void
    {
        self::bootKernel();
        $fantomes = [];

        foreach (static::getContainer()->get(TrousseCatalogue::class)->tous() as $outil) {
            $schema = $outil->schema();
            $proprietes = array_keys((array) ($schema['properties'] ?? []));
            // Les clés des objets imbriqués (lieA.entite, periode.debut) sont des noms
            // d'argument légitimes, au même titre que ceux du premier niveau.
            foreach ((array) ($schema['properties'] ?? []) as $propriete) {
                foreach (array_keys((array) ($propriete['properties'] ?? [])) as $imbriquee) {
                    $proprietes[] = $imbriquee;
                }
            }

            $texte = $outil->description() . ' ' . $outil->aiguillage();
            preg_match_all('/\b[a-z][a-zA-Z0-9]*[A-Z][a-zA-Z0-9]*\b/', $texte, $trouves);

            foreach (array_unique($trouves[0]) as $jeton) {
                if (in_array($jeton, $proprietes, true) || in_array($jeton, self::CHAMPS_DE_RESULTAT, true)) {
                    continue;
                }
                $fantomes[] = sprintf('%s : « %s »', $outil->name(), $jeton);
            }
        }

        self::assertSame([], $fantomes, sprintf(
            "Ces identifiants sont cités dans une description sans être un paramètre de l'outil.\n"
            . "Corrigez le nom, ou inscrivez-le dans CHAMPS_DE_RESULTAT si c'est un champ de réponse :\n  %s",
            implode("\n  ", $fantomes),
        ));
    }
}
