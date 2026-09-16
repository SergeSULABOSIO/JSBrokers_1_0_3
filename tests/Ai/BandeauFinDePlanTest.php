<?php

namespace App\Tests\Ai;

use App\Ai\Mutation\FinDePlan;
use PHPUnit\Framework\TestCase;

/**
 * LE BANDEAU D'UN PLAN MORT DIT LA VÉRITÉ — en direct ET après F5.
 *
 * ── POURQUOI CE FICHIER EXISTE ──────────────────────────────────────────────
 * Le fil affiche un feedback PERMANENT sous un plan tranché. Il n'en connaissait
 * qu'un seul pour les trois façons de mourir : « Plan annulé — aucune donnée n'a
 * été modifiée. » Un courtier a donc vu, le 2026-09-14, ce bandeau se poser sous
 * des plans qu'il n'avait jamais annulés — Ket venait simplement d'en présenter
 * une version complétée après qu'il eut donné un renseignement de plus.
 *
 * Attribuer à quelqu'un un geste qu'il n'a pas fait est le plus sûr moyen de lui
 * faire perdre confiance dans ce que le fil raconte. {@see FinDePlan} distingue
 * désormais les trois fins ; ce test vérifie que l'affichage les distingue aussi.
 *
 * ── LE MIROIR, ET POURQUOI IL SE TESTE ──────────────────────────────────────
 * Un bandeau est rendu DEUX fois dans ce projet : par le contrôleur Stimulus au
 * moment où la décision tombe, et par le gabarit Twig au rechargement de la page.
 * La règle du projet est qu'un chip survit au F5 — donc que les deux rendus disent
 * la même chose. Modifier l'un sans l'autre ferait diverger l'affichage direct et
 * l'affichage après rechargement, et c'est le genre d'écart que personne ne voit
 * avant qu'un utilisateur ne le signale.
 */
class BandeauFinDePlanTest extends TestCase
{
    private const TWIG = __DIR__ . '/../../templates/components/_assistant_ia_chat.html.twig';
    private const CHAT = __DIR__ . '/../../assets/controllers/assistant-chat_controller.js';

    private function twig(): string
    {
        self::assertFileExists(self::TWIG);

        return (string) file_get_contents(self::TWIG);
    }

    private function js(): string
    {
        self::assertFileExists(self::CHAT);

        return (string) file_get_contents(self::CHAT);
    }

    /**
     * CHAQUE FIN A SON LIBELLÉ DANS LES DEUX RENDUS. On assert le texte déclaré en
     * PHP, mot pour mot : c'est ce qui garantit que les trois surfaces dérivent
     * d'une seule source au lieu de trois copies qui divergeront.
     */
    public function testChaqueFinPorteSonLibelleDansLesDeuxRendus(): void
    {
        $twig = $this->twig();
        $js = $this->js();

        foreach (FinDePlan::cases() as $fin) {
            // LE GABARIT LES REND TOUTES : après un rechargement, n'importe quel plan
            // mort peut réapparaître dans le fil, quelle qu'ait été sa fin.
            self::assertStringContainsString($fin->libelle(), $twig, sprintf(
                'Le gabarit Twig ne sait pas annoncer la fin « %s » : après un F5, le courtier lirait '
                . 'autre chose que ce qu\'il a vu sur le moment.',
                $fin->value,
            ));

            // LE CONTRÔLEUR, LUI, N'EN REND QUE CE QU'IL VOIT ARRIVER. La péremption
            // a lieu entre deux messages, côté serveur, et rien ne la diffuse au
            // navigateur : lui écrire un libellé créerait une constante qu'aucun
            // code n'émettrait. Le contrat des actions d'interface l'interdit — du
            // code mort qui simule une capacité est pire qu'une capacité absente,
            // parce qu'il fait croire qu'elle existe.
            if ($fin->aUnEmetteurEnDirect()) {
                self::assertStringContainsString($fin->libelle(), $js, sprintf(
                    'Le contrôleur de chat ne sait pas annoncer la fin « %s » en direct.',
                    $fin->value,
                ));
                continue;
            }

            self::assertStringNotContainsString($fin->libelle(), $js, sprintf(
                'La fin « %s » n\'a aucun émetteur en direct : son libellé n\'a rien à faire dans le '
                . 'contrôleur de chat, où il serait du code mort.',
                $fin->value,
            ));
        }
    }

    /**
     * CHAQUE FIN A SON APPARENCE, ET ELLE EXISTE EN CSS. Un suffixe déclaré sans
     * règle correspondante donnerait un bandeau transparent — un défaut muet.
     */
    public function testChaqueApparenceEstDefinieEnCss(): void
    {
        $twig = $this->twig();

        foreach (FinDePlan::cases() as $fin) {
            self::assertStringContainsString(
                '.aic-plan-status--' . $fin->classeCss(),
                $twig,
                sprintf(
                    'La règle CSS « aic-plan-status--%s » manque : le bandeau de la fin « %s » s\'afficherait '
                    . 'sans fond ni couleur.',
                    $fin->classeCss(),
                    $fin->value,
                ),
            );
        }
    }

    /**
     * LE CŒUR DU CORRECTIF : « annulé » n'appartient qu'au clic.
     *
     * Les libellés du remplacement et de la péremption ne doivent pas contenir ce
     * mot — sans quoi on retomberait exactement dans le défaut corrigé, en ayant
     * seulement déplacé le texte.
     */
    public function testSeuleLaDecisionDeLUtilisateurEmploieLeMotAnnule(): void
    {
        foreach ([FinDePlan::REMPLACE, FinDePlan::PERIME] as $fin) {
            self::assertStringNotContainsString('annulé', $fin->libelle());
        }

        // Et le gabarit ne doit plus décider du libellé sur le seul « planAnnule » :
        // il lui faut lire le MOTIF, sinon les trois fins se confondent à nouveau.
        self::assertStringContainsString(
            FinDePlan::CLE_META,
            $this->twig(),
            'Le gabarit doit lire « ' . FinDePlan::CLE_META . ' » pour choisir son libellé. Sans cela, il '
            . 'continue d\'appeler « annulation » une fin que personne n\'a décidée.',
        );
    }
}
