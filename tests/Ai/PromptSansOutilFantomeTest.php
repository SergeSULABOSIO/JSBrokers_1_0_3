<?php

namespace App\Tests\Ai;

use App\Ai\AiContextBuilder;
use App\Ai\Scope\AiScope;
use App\Ai\Trousse\AiToolEcriture;
use App\Ai\Trousse\Trousse;
use App\Ai\Trousse\TrousseCatalogue;
use App\Ai\Tool\AiToolInterface;
use App\Ai\Tool\AiToolProduisantUnPlan;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Repository\EntrepriseRepository;
use App\Repository\InviteRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * LA CONTRAINTE DURE DU CHANTIER : le prompt système ne doit JAMAIS pouvoir nommer
 * un outil qui n'est pas déclaré au tour en cours.
 *
 * Ce n'est pas une élégance. Un prompt qui nomme un outil absent produit exactement
 * les pathologies que les autres garde-fous cherchent à empêcher : le modèle croit
 * disposer d'une capacité qu'il n'a pas, « appelle » un outil inexistant, puis —
 * n'obtenant rien — décrit en prose un plan et un bouton de validation qui
 * n'apparaîtront jamais. C'est le plan fantôme, par un autre chemin.
 *
 * La garantie est structurelle : le prompt et les déclarations envoyées au
 * fournisseur sont dérivés du MÊME tableau (TrousseCatalogue). Ce test vérifie que
 * la structure tient réellement, y compris pour les règles rédigées à la main dans
 * les blocs conditionnels.
 */
class PromptSansOutilFantomeTest extends KernelTestCase
{
    private function scope(): AiScope
    {
        $conteneur = static::getContainer();
        $entreprise = $conteneur->get(EntrepriseRepository::class)->findOneBy([]);
        self::assertNotNull($entreprise, 'Le jeu de test doit comporter au moins une entreprise.');
        $invite = $conteneur->get(InviteRepository::class)->findOneBy(['entreprise' => $entreprise]);
        self::assertNotNull($invite, 'Le jeu de test doit comporter au moins un invité.');

        return new AiScope($entreprise, $invite);
    }

    /** @return list<string> tous les noms d'outils du code, toutes trousses confondues */
    private function tousLesNoms(): array
    {
        $noms = [];
        foreach (static::getContainer()->get(TrousseCatalogue::class)->tous() as $outil) {
            $noms[] = $outil->name();
        }

        return $noms;
    }

    /**
     * @return array{0: string, 1: list<string>} le prompt rendu, et les outils déclarés
     */
    private function prompt(Trousse $trousse): array
    {
        static::bootKernel();
        $conteneur = static::getContainer();
        $scope = $this->scope();

        $requete = $conteneur->get(AiContextBuilder::class)->build(
            $scope->entreprise,
            $scope->invite,
            new \App\Entity\AssistantConversation(),
        );

        return [
            $conteneur->get(AiContextBuilder::class)->toSystemPrompt($requete, $trousse),
            $conteneur->get(TrousseCatalogue::class)->nomsDe($trousse, $requete->scope),
        ];
    }

    /**
     * @return iterable<string, array{0: Trousse}>
     */
    public static function trousses(): iterable
    {
        yield 'lecture' => [Trousse::LECTURE];
        yield 'écriture' => [Trousse::ECRITURE];
    }

    /**
     * @dataProvider trousses
     */
    public function testAucunOutilNonDeclareNEstNommeDansLePrompt(Trousse $trousse): void
    {
        [$prompt, $declares] = $this->prompt($trousse);

        $fantomes = [];
        foreach ($this->tousLesNoms() as $nom) {
            if (in_array($nom, $declares, true)) {
                continue;
            }
            // Frontières de mot : « parcours_saisie » ne doit pas matcher dans un
            // mot plus long, et inversement.
            if (preg_match('/\b' . preg_quote($nom, '/') . '\b/', $prompt) === 1) {
                $fantomes[] = $nom;
            }
        }

        self::assertSame(
            [],
            $fantomes,
            sprintf(
                "Le prompt de la trousse « %s » nomme des outils qui ne lui sont PAS déclarés : %s.\n"
                . "Réécris la règle concernée EN ENTIER du côté où elle appartient (dans aiguillage() de "
                . "l'outil, ou dans le bloc conditionnel de la trousse) — ne l'ampute pas.",
                $trousse->value,
                implode(', ', $fantomes),
            ),
        );
    }

    /**
     * @dataProvider trousses
     */
    public function testChaqueOutilDeclareEstEffectivementAiguille(Trousse $trousse): void
    {
        [$prompt, $declares] = $this->prompt($trousse);

        $muets = [];
        foreach (static::getContainer()->get(TrousseCatalogue::class)->outilsDe($trousse, $this->scope()) as $outil) {
            if (trim($outil->aiguillage()) === '') {
                continue; // sa description suffit, c'est un choix assumé
            }
            if (!str_contains($prompt, $outil->name())) {
                $muets[] = $outil->name();
            }
        }

        self::assertSame([], $muets, 'Ces outils sont déclarés mais leur règle d’aiguillage n’est pas rendue : '
            . implode(', ', $muets));
    }

    /**
     * La trousse de lecture doit dire à Ket quoi faire d'une demande d'écriture :
     * répondre à ce qui est demandé, puis PROPOSER l'enregistrement et laisser
     * l'utilisateur relancer. Il n'y a plus d'échappatoire technique — elle
     * demanderait un troisième appel, que la règle interdit.
     *
     * Ce qui est interdit ici, et vérifié : répondre « je ne peux pas créer ».
     * C'est faux, et c'est le refus que la règle historique proscrit.
     */
    public function testLaTrousseDeLectureDitCommentPoursuivreSansRefuser(): void
    {
        [$prompt] = $this->prompt(Trousse::LECTURE);

        self::assertStringContainsString('CONSULTATION', $prompt);
        self::assertStringContainsString('PROPOSE l\'écriture en une phrase', $prompt);
        self::assertStringContainsString('Voulez-vous que je l\'enregistre ?', $prompt);
        self::assertStringContainsString('Ne dis JAMAIS que tu ne peux pas', $prompt);
    }

    /**
     * Un outil qui produit un plan mais ne serait pas rangé dans la trousse
     * d'écriture serait déclaré en lecture : le modèle pourrait préparer une
     * écriture dans un tour censé n'en porter aucune.
     */
    public function testToutOutilDePlanAppartientALaTrousseDEcriture(): void
    {
        static::bootKernel();

        $manquants = [];
        foreach (static::getContainer()->get(TrousseCatalogue::class)->tous() as $outil) {
            if ($outil instanceof AiToolProduisantUnPlan && !$outil instanceof AiToolEcriture) {
                $manquants[] = $outil->name();
            }
        }

        self::assertSame([], $manquants, 'Outils de plan absents de la trousse d’écriture : '
            . implode(', ', $manquants));
    }

    /** La trousse de lecture ne déclare aucun outil capable de préparer une écriture. */
    public function testLaTrousseDeLectureNeDeclareAucunOutilDEcriture(): void
    {
        static::bootKernel();
        $catalogue = static::getContainer()->get(TrousseCatalogue::class);

        $intrus = [];
        foreach ($catalogue->outilsDe(Trousse::LECTURE, $this->scope()) as $outil) {
            if ($outil instanceof AiToolEcriture) {
                $intrus[] = $outil->name();
            }
        }

        self::assertSame([], $intrus);
    }

    /** Chaque outil doit dire s'il a une règle d'aiguillage — même pour dire « aucune ». */
    public function testChaqueOutilImplementeAiguillage(): void
    {
        static::bootKernel();

        foreach (static::getContainer()->get(TrousseCatalogue::class)->tous() as $outil) {
            self::assertInstanceOf(AiToolInterface::class, $outil);
            // Le contrat est respecté par construction (interface) ; on vérifie que
            // l'implémentation ne renvoie pas autre chose qu'une chaîne.
            self::assertIsString($outil->aiguillage(), $outil->name());
        }
    }
}
