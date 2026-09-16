<?php

namespace App\Tests\Ai;

use App\Ai\AiContextBuilder;
use App\Ai\Parite\CouvertureDesEcrans;
use App\Ai\Trousse\Trousse;
use App\Entity\AssistantConversation;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * LA FRONTIÈRE EST DITE À KET — dans LES DEUX trousses.
 *
 * ── POURQUOI CE FICHIER EXISTE ──────────────────────────────────────────────
 * Déclarer une frontière dans un manifeste que seul un test lit ne change rien à
 * ce que Ket répond. Le 2026-09-14, l'entité qui porte les collaborateurs était
 * déjà fermée par trois verrous ; ce qui manquait n'était pas la fermeture, c'était
 * que Ket le SACHE et le DISE.
 *
 * Elle l'ignorait d'autant plus que le prompt lui interdit, deux fois et avec
 * emphase, de répondre qu'elle ne sait pas créer — une règle écrite pour une bonne
 * raison (le modèle s'excusait de ne pas pouvoir enregistrer alors qu'il le
 * pouvait), mais qui, sans exception nommée, la pousse à prendre l'entité voisine
 * qu'elle PEUT écrire. C'est exactement ce qu'elle a fait.
 *
 * ── POURQUOI LES DEUX TROUSSES ──────────────────────────────────────────────
 * « Enregistre-moi un collaborateur » arrive en écriture ; « est-ce que tu peux
 * créer un collaborateur ? » arrive en consultation. Une frontière connue une fois
 * sur deux ne protège de rien : la moitié des tours produirait la substitution.
 * Ce bloc part donc HORS du ternaire qui choisit les protocoles d'écriture.
 */
class FrontiereDiteAKetTest extends KernelTestCase
{
    use JeuDeTestKetTrait;

    /**
     * @return array<string, string> trousse => prompt système
     */
    private function promptsParTrousse(): array
    {
        $conteneur = static::getContainer();
        [$entreprise, $invite] = $this->jeuDeTestKet();

        $builder = $conteneur->get(AiContextBuilder::class);
        $requete = $builder->build($entreprise, $invite, new AssistantConversation());

        return [
            'lecture'  => $builder->toSystemPrompt($requete, Trousse::LECTURE),
            'ecriture' => $builder->toSystemPrompt($requete, Trousse::ECRITURE),
        ];
    }

    /**
     * CHAQUE ENTITÉ DE LA FRONTIÈRE EST NOMMÉE À KET, AVEC SON MOTIF ET SON CHEMIN.
     *
     * On assert le MOTIF lui-même, mot pour mot : c'est la seule façon de garantir
     * que le prompt DÉRIVE du manifeste au lieu d'en paraphraser une copie qui
     * divergera. Reformuler un motif oblige donc à le reformuler à un seul endroit.
     */
    public function testChaqueFrontiereEstNommeeDansLesDeuxTrousses(): void
    {
        static::bootKernel();
        $prompts = $this->promptsParTrousse();

        self::assertNotEmpty(CouvertureDesEcrans::ENTITES_ECRAN_SEULEMENT);

        foreach ($prompts as $trousse => $prompt) {
            foreach (CouvertureDesEcrans::ENTITES_ECRAN_SEULEMENT as $shortName => $motif) {
                self::assertStringContainsString($shortName, $prompt, sprintf(
                    'La trousse « %s » ne nomme pas l\'entité « %s » de la frontière : Ket ne peut donc pas '
                    . 'savoir qu\'elle ne doit pas l\'écrire, et prendra l\'entité voisine.',
                    $trousse,
                    $shortName,
                ));
                self::assertStringContainsString($motif, $prompt, sprintf(
                    'La trousse « %s » ne porte pas le motif déclaré pour « %s ». Le prompt doit DÉRIVER de '
                    . 'CouvertureDesEcrans::ENTITES_ECRAN_SEULEMENT, jamais en recopier une paraphrase.',
                    $trousse,
                    $shortName,
                ));
            }
        }
    }

    /**
     * LE CHEMIN D'ÉCRAN EST DIT, PAS SEULEMENT LE REFUS.
     *
     * Un refus sans chemin laisse l'utilisateur exactement où il était. La règle du
     * projet vaut ici comme ailleurs : un message qui bloque doit nommer ce qu'on
     * peut faire à la place.
     */
    public function testLaFrontiereDonneLeCheminDEcran(): void
    {
        static::bootKernel();

        foreach ($this->promptsParTrousse() as $trousse => $prompt) {
            self::assertStringContainsString('Administration → Invités', $prompt, sprintf(
                'La trousse « %s » doit dire OÙ se crée un collaborateur. Sans le chemin, Ket refuse sans '
                . 'aider — et le courtier reste bloqué.',
                $trousse,
            ));
        }
    }

    /**
     * L'INTERDICTION DE REFUSER EST QUALIFIÉE.
     *
     * Le prompt interdit de répondre « je ne peux pas créer ». Cette règle doit
     * désormais porter son exception, sinon elle continue de pousser le modèle à
     * substituer une entité qu'il peut écrire à celle qu'on lui demande.
     */
    public function testLaRegleQuiInterditDeRefuserPorteSonException(): void
    {
        static::bootKernel();

        foreach ($this->promptsParTrousse() as $trousse => $prompt) {
            if (!str_contains($prompt, 'tu ne peux pas créer')) {
                continue;
            }
            // On exige l'exception À PROXIMITÉ de la règle, sans imposer de frontière
            // de phrase : les deux trousses la formulent différemment — l'une après un
            // point, l'autre après un tiret — et c'est le VOISINAGE qui compte, pas la
            // ponctuation. Une règle dont l'exception vit trois paragraphes plus loin
            // ne serait pas lue comme une exception.
            self::assertMatchesRegularExpression(
                '/tu ne peux pas créer.{0,400}(SAUF|sauf|FRONTIÈRE|frontière)/us',
                $prompt,
                sprintf(
                    'La trousse « %s » interdit de répondre « tu ne peux pas créer » sans nommer l\'exception. '
                    . 'Telle quelle, cette règle pousse le modèle à écrire dans l\'entité voisine plutôt qu\'à '
                    . 'nommer la frontière.',
                    $trousse,
                ),
            );
        }
    }
}
