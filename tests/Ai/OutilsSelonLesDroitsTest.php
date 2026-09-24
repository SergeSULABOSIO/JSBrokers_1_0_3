<?php

namespace App\Tests\Ai;

use App\Ai\Scope\AiScope;
use App\Ai\Trousse\Trousse;
use App\Ai\Trousse\TrousseCatalogue;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Service\Terminal\Terminal;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * ON NE PAIE PAS POUR UN OUTIL QUI NE POURRA QUE REFUSER.
 *
 * ── CE QUI COÛTE ────────────────────────────────────────────────────────────────────
 *
 * Les déclarations d'outils pèsent 55 % du payload envoyé à CHAQUE tour (123 890 o,
 * ≈ 33 500 jetons sur la conversation de référence), et le quota du fournisseur se
 * compte en jetons d'ENTRÉE par minute. Déclarer un outil dont `execute()` rendra un
 * hors-périmètre certain, c'est payer son schéma plusieurs fois par message pour un
 * refus connu d'avance.
 *
 * ── LES TROIS OUTILS ────────────────────────────────────────────────────────────────
 *
 * - `conges` et `simuler_conge` exigent la lecture des congés. Leur frère d'écriture
 *   (`preparer_demande_conge`) posait la condition depuis le début ; la lecture ne
 *   l'avait pas. Un cabinet sans module de congés payait donc les deux à chaque tour.
 * - `etat_configuration` refuse tout invité non propriétaire, et la boussole dit
 *   pourquoi : « Un invité ne peut pas configurer le cabinet ; le lui dire serait lui
 *   demander ce qu'il ne peut pas faire. »
 *
 * ── CE N'EST PAS UNE SÉCURITÉ ───────────────────────────────────────────────────────
 *
 * La garde reste dans `execute()`, fail-closed. `estDisponible()` évite seulement de
 * parler d'un outil inutile. Les deux doivent néanmoins dire la MÊME chose, sans quoi
 * on présenterait au modèle un outil qu'on refuserait ensuite : c'est ce que ce test
 * vérifie, dans les deux sens.
 */
class OutilsSelonLesDroitsTest extends KernelTestCase
{
    private const OUTILS_SOUS_DROIT = ['conges', 'simuler_conge', 'etat_configuration'];

    protected function setUp(): void
    {
        self::bootKernel();
    }

    /**
     * Entités NUES, comme OutilsSelonTerminalTest : ce test ne touche pas la base.
     * Un invité sans rôle n'a aucun droit, et n'est pas propriétaire.
     */
    private function outilsPour(bool $proprietaire): array
    {
        $invite = (new Invite())->setProprietaire($proprietaire);

        return static::getContainer()->get(TrousseCatalogue::class)->nomsDe(
            Trousse::ECRITURE,
            new AiScope(new Entreprise(), $invite, null, Terminal::ORDINATEUR),
        );
    }

    /**
     * LE RETRAIT A LIEU. Sans droit ni propriété, les trois outils ne sont pas déclarés.
     */
    public function testUnInviteSansDroitNeSeVoitPasDeclarerCesOutils(): void
    {
        $declares = $this->outilsPour(false);

        foreach (self::OUTILS_SOUS_DROIT as $outil) {
            self::assertNotContains($outil, $declares, sprintf(
                '« %s » ne peut que refuser pour cet invité : le déclarer coûte son schéma à chaque tour.',
                $outil,
            ));
        }
    }

    /**
     * LE RETRAIT N'EST PAS DÉFINITIF. C'est le sens même d'une condition : le
     * propriétaire, lui, garde les trois — sinon on aurait supprimé une capacité au
     * lieu de l'avoir conditionnée.
     */
    public function testLeProprietaireGardeLesTroisOutils(): void
    {
        $declares = $this->outilsPour(true);

        foreach (self::OUTILS_SOUS_DROIT as $outil) {
            self::assertContains($outil, $declares, sprintf(
                '« %s » doit rester déclaré au propriétaire, qui a le droit de l’exécuter.',
                $outil,
            ));
        }
    }

    /**
     * ⚠ LE RETRAIT NE DÉBORDE PAS — la vérification qui compte le plus.
     *
     * L'écart entre un invité sans droit et le propriétaire doit se limiter aux outils
     * réellement conditionnés. Une condition posée trop large priverait le modèle de
     * capacités sans rapport, et le défaut ne se verrait que chez un invité restreint —
     * jamais chez le développeur, qui est propriétaire de son cabinet de test.
     */
    public function testAucunOutilSansConditionNeDisparaitAvecLesDroits(): void
    {
        $retires = array_values(array_diff($this->outilsPour(true), $this->outilsPour(false)));
        sort($retires);

        // Tous les outils retirés doivent l'être DÉLIBÉRÉMENT : soit les trois ci-dessus,
        // soit un outil qui portait déjà sa propre condition de droit avant ce chantier.
        foreach ($retires as $outil) {
            self::assertTrue(
                in_array($outil, self::OUTILS_SOUS_DROIT, true) || $this->estDejaConditionne($outil),
                sprintf('« %s » disparaît selon les droits sans porter de condition assumée.', $outil),
            );
        }

        // Et rien ne doit APPARAÎTRE en perdant des droits.
        self::assertSame(
            [],
            array_values(array_diff($this->outilsPour(false), $this->outilsPour(true))),
            'Perdre un droit ne peut pas faire naître un outil.',
        );
    }

    private function estDejaConditionne(string $nom): bool
    {
        foreach (static::getContainer()->get(TrousseCatalogue::class)->tous() as $outil) {
            if ($outil->name() === $nom) {
                return $outil instanceof \App\Ai\Tool\AiToolConditionnel;
            }
        }

        return false;
    }
}
