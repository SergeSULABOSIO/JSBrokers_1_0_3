<?php

namespace App\Tests\Ai;

use App\Ai\Scope\AiScope;
use App\Ai\Tool\AiToolResult;
use App\Ai\Tool\OuvrirRubriqueTool;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Partenaire;
use App\Entity\Utilisateur;
use App\Services\Search\ReversementScope;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * KET OUVRE LA RUBRIQUE DES REVERSEMENTS, FILTRÉE — comme l'écran.
 *
 * Le volet dédié a disparu : le bouton « Versements enregistrés » du rapport de production
 * ouvre la rubrique filtrée sur son agent. Si l'assistant ne savait pas en faire autant,
 * « ouvre-moi les versements d'Alice » ouvrirait la liste ENTIÈRE pendant que le chat
 * annoncerait ceux d'une seule personne — la contradiction que `OuvrirRubriqueTool` a été
 * écrit pour éliminer.
 *
 * Trois propriétés :
 *
 *  1. LE MÊME CRITÈRE QUE L'ÉCRAN. Le chip, le bouton du rapport et l'assistant posent
 *     rigoureusement le même filtre — sinon deux surfaces montrent deux listes.
 *  2. UN AGENT SE DÉSIGNE PAR SON NOM, et un nom inconnu pose une QUESTION plutôt que
 *     d'ouvrir tout.
 *  3. LE FILTRE EST ANNONCÉ. L'assistant doit dire ce que l'écran montre.
 */
class OuvrirRubriqueReversementsTest extends KernelTestCase
{
    private const OWNER_EMAIL = 'phpunit-ouvrir-reversements@test.local';
    private const ENT = 'PHPUnit Ouvrir Reversements SARL';

    protected function setUp(): void
    {
        static::bootKernel();
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        parent::tearDown();
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function outil(): OuvrirRubriqueTool
    {
        return static::getContainer()->get(OuvrirRubriqueTool::class);
    }

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email = :e', ['e' => self::OWNER_EMAIL]);
        // Les DEUX familles sont semées : la purge doit les couvrir toutes deux, sinon la
        // suppression de l'entreprise butera sur une clé étrangère et le test suivant
        // retrouvera les données du précédent.
        foreach (['invite', 'partenaire'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :nom",
                ['nom' => self::ENT],
            );
        }
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :nom', ['nom' => self::ENT]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :email', ['email' => self::OWNER_EMAIL]);
        $this->em()->clear();
    }

    /**
     * @return array{scope: AiScope, agent: Invite, homonyme: Invite, partenaire: Partenaire,
     *               partenaireHomonyme: Partenaire}
     */
    private function semer(): array
    {
        $em = $this->em();

        $owner = (new Utilisateur())->setEmail(self::OWNER_EMAIL)->setNom('Ouvrir')->setVerified(true)->setPassword('x');
        $em->persist($owner);

        $ent = (new Entreprise())->setNom(self::ENT)->setLicence('LIC')->setAdresse('1 rue')
            ->setTelephone('+2430000')->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($owner);
        $em->persist($ent);
        $owner->setConnectedTo($ent);

        $gestionnaire = (new Invite())->setNom('Gestionnaire')->setProprietaire(true);
        $gestionnaire->setUtilisateur($owner)->setEntreprise($ent);
        $em->persist($gestionnaire);

        $agent = (new Invite())->setNom('Alice')->setProprietaire(false);
        $agent->setEntreprise($ent);
        $em->persist($agent);

        // Un nom qui CONTIENT le précédent : « Alice » ne doit pas être écrasé par
        // « Alice Dupont », même règle que la résolution des bénéficiaires ailleurs.
        $homonyme = (new Invite())->setNom('Alice Dupont')->setProprietaire(false);
        $homonyme->setEntreprise($ent);
        $em->persist($homonyme);

        // UN PARTENAIRE EXTERNE, et un HOMONYME d'agent parmi les partenaires. La rubrique
        // porte les deux familles : sans partenaire, ni l'alignement du type ni le refus du
        // couple contradictoire ne peuvent être éprouvés.
        $partenaire = (new Partenaire())->setNom('SUNU Courtage')->setPart(20.0);
        $partenaire->setEntreprise($ent);
        $em->persist($partenaire);

        // « Alice » existe donc DANS LES DEUX FAMILLES : c'est ce qui rend le paramètre
        // `type` utile comme désambiguïsateur, et non seulement comme filtre.
        $partenaireHomonyme = (new Partenaire())->setNom('Alice')->setPart(10.0);
        $partenaireHomonyme->setEntreprise($ent);
        $em->persist($partenaireHomonyme);

        $em->flush();

        return [
            'scope' => new AiScope($ent, $gestionnaire),
            'agent' => $agent,
            'homonyme' => $homonyme,
            'partenaire' => $partenaire,
            'partenaireHomonyme' => $partenaireHomonyme,
        ];
    }

    // ===================== 1. Le même critère que l'écran =====================

    /**
     * LE CRITÈRE POSÉ EST CELUI DU CHIP ET DU BOUTON DU RAPPORT — au caractère près.
     *
     * C'est ce qui rend la parité structurelle plutôt que promise : les trois surfaces
     * appellent la MÊME fabrique de critère.
     */
    public function testLeCritereDeBeneficiaireEstCeluiDeLEcran(): void
    {
        $s = $this->semer();

        $resultat = $this->outil()->execute([
            'entite' => 'ReversementRetroAgent',
            'beneficiaire' => 'Alice',
        ], $s['scope']);

        self::assertSame(AiToolResult::STATUS_OK, $resultat->status);
        $criteres = $resultat->uiAction['criteres'] ?? [];

        // LE CHIP-SÉLECTEUR POSE DEUX CRITÈRES, pas un : le bénéficiaire, et le TYPE que
        // sa famille implique (R3). Ce test n'en attendait qu'un, et il avait alors raison
        // — les deux chips ne se parlaient pas encore, au prix d'un couple contradictoire
        // que rien n'empêchait. La parité se mesure désormais sur les deux.
        self::assertSame(
            ReversementScope::critereBeneficiaire((int) $s['agent']->getId(), 'Alice')
            + ReversementScope::critereRecherche(
                ReversementScope::ENTITE,
                ReversementScope::CLE_TYPE,
                ReversementScope::TYPE_AGENT,
            ),
            $criteres,
            'Le critère de l’assistant doit être exactement celui du chip-sélecteur.',
        );
        self::assertStringContainsString('Alice', implode(' ', $resultat->data['filtres'] ?? []));
    }

    /** Les trois chips de la rubrique, par le même vocabulaire que l'écran. */
    public function testLesTroisChipsSontDisponiblesEtAnnonces(): void
    {
        $s = $this->semer();

        $cas = [
            ['justificatif', ReversementScope::SANS_PIECE, ReversementScope::CLE_JUSTIFICATIF],
            ['periode', ReversementScope::CE_MOIS, ReversementScope::CLE_PERIODE],
            ['virement', ReversementScope::GROUPE, ReversementScope::CLE_VIREMENT],
        ];

        foreach ($cas as [$parametre, $valeur, $cle]) {
            $resultat = $this->outil()->execute([
                'entite' => 'ReversementRetroAgent',
                $parametre => $valeur,
            ], $s['scope']);

            self::assertSame(
                ReversementScope::critereRecherche(ReversementScope::ENTITE, $cle, $valeur),
                $resultat->uiAction['criteres'] ?? [],
                sprintf('Le paramètre « %s » doit poser le critère du chip.', $parametre),
            );
            // ANNONCER CE QUE L'ÉCRAN MONTRE : une liste filtrée sans un mot se lit comme
            // une liste complète, et l'assistant passerait pour avoir tout montré.
            self::assertContains(
                ReversementScope::libelle($cle, $valeur),
                $resultat->data['filtres'] ?? [],
                sprintf('Le filtre « %s » doit être annoncé.', $parametre),
            );
        }
    }

    /** Les filtres se cumulent, comme les chips de la barre. */
    public function testLesFiltresSeCumulent(): void
    {
        $s = $this->semer();

        $resultat = $this->outil()->execute([
            'entite' => 'ReversementRetroAgent',
            'beneficiaire' => 'Alice',
            'justificatif' => ReversementScope::SANS_PIECE,
        ], $s['scope']);

        $criteres = $resultat->uiAction['criteres'] ?? [];
        self::assertArrayHasKey(ReversementScope::CLE_BENEFICIAIRE, $criteres);
        self::assertArrayHasKey(ReversementScope::CLE_JUSTIFICATIF, $criteres);
    }

    // ===================== 2. La résolution du nom =====================

    /** Le nom EXACT l'emporte sur le partiel : « Alice » n'est pas « Alice Dupont ». */
    public function testLeNomExactLEmporteSurLePartiel(): void
    {
        $s = $this->semer();

        $resultat = $this->outil()->execute([
            'entite' => 'ReversementRetroAgent',
            'beneficiaire' => 'Alice',
        ], $s['scope']);

        // La valeur porte la FAMILLE puis l'identifiant : le bénéficiaire vit tantôt dans
        // `agent`, tantôt dans `partenaire`, et l'agent #3 n'est pas le partenaire #3.
        self::assertSame(
            ReversementScope::valeurBeneficiaire(ReversementScope::TYPE_AGENT, (int) $s['agent']->getId()),
            $resultat->uiAction['criteres'][ReversementScope::CLE_BENEFICIAIRE]['value'],
        );
    }

    /**
     * UN AGENT INCONNU N'OUVRE PAS LA LISTE ENTIÈRE.
     *
     * Ouvrir tout en lot de consolation reviendrait à annoncer les versements d'une
     * personne et à montrer ceux de tout le monde — exactement la contradiction que le
     * filtrage à l'ouverture corrige.
     */
    public function testUnBeneficiaireInconnuNOuvrePasLaListeEntiere(): void
    {
        $s = $this->semer();

        $resultat = $this->outil()->execute([
            'entite' => 'ReversementRetroAgent',
            'beneficiaire' => 'Personne Inconnue',
        ], $s['scope']);

        self::assertSame(AiToolResult::STATUS_INTROUVABLE, $resultat->status);
        self::assertNull($resultat->uiAction, 'Aucune rubrique ne doit s’ouvrir.');
    }

    /** Sur une AUTRE rubrique, ces paramètres sont sans effet — ils lui sont étrangers. */
    public function testLesFiltresDeReversementNeDebordentPasSurUneAutreRubrique(): void
    {
        $s = $this->semer();

        $resultat = $this->outil()->execute([
            'entite' => 'Avenant',
            'justificatif' => ReversementScope::SANS_PIECE,
            'beneficiaire' => 'Alice',
        ], $s['scope']);

        $criteres = $resultat->uiAction['criteres'] ?? [];
        self::assertArrayNotHasKey(ReversementScope::CLE_JUSTIFICATIF, $criteres);
        self::assertArrayNotHasKey(ReversementScope::CLE_BENEFICIAIRE, $criteres);
    }

    // ===================== 3. Les deux chips s'alignent =====================

    /**
     * LE TYPE S'ALIGNE SUR LA FAMILLE DU BÉNÉFICIAIRE — comme à l'écran.
     *
     * Choisir un bénéficiaire y pose le chip « Type » du même geste. Sans cet alignement,
     * la MÊME demande produirait deux états de chips selon qu'elle vient de la souris ou de
     * Ket : une parité rompue, et rien pour la signaler.
     */
    public function testLeTypeSAligneSurLaFamilleDuBeneficiaire(): void
    {
        $s = $this->semer();

        $resultat = $this->outil()->execute([
            'entite' => 'ReversementRetroAgent',
            'beneficiaire' => 'SUNU Courtage',
        ], $s['scope']);

        self::assertSame(AiToolResult::STATUS_OK, $resultat->status);
        $criteres = $resultat->uiAction['criteres'] ?? [];

        self::assertSame(
            ReversementScope::valeurBeneficiaire(
                ReversementScope::TYPE_PARTENAIRE,
                (int) $s['partenaire']->getId(),
            ),
            $criteres[ReversementScope::CLE_BENEFICIAIRE]['value'],
        );
        self::assertSame(
            ReversementScope::TYPE_PARTENAIRE,
            $criteres[ReversementScope::CLE_TYPE]['value'] ?? null,
            'Le type doit être POSÉ, exactement comme le fait le chip-sélecteur de l’écran.',
        );
    }

    /**
     * LE TYPE DICTÉ DÉSAMBIGUÏSE LE NOM, il ne se contente pas de filtrer.
     *
     * « Alice » existe dans les deux familles. Chercher l'agent d'abord en toutes
     * circonstances aurait fait échouer « les versements de type partenaire à Alice » sur
     * un homonyme interne — alors que l'utilisateur venait précisément de lever le doute.
     */
    public function testLeTypeDicteDesambiguiseUnNomPorteParLesDeuxFamilles(): void
    {
        $s = $this->semer();

        $agentDAbord = $this->outil()->execute([
            'entite' => 'ReversementRetroAgent',
            'beneficiaire' => 'Alice',
        ], $s['scope']);
        self::assertSame(
            ReversementScope::valeurBeneficiaire(ReversementScope::TYPE_AGENT, (int) $s['agent']->getId()),
            $agentDAbord->uiAction['criteres'][ReversementScope::CLE_BENEFICIAIRE]['value'],
            'Sans type dicté, l’agent l’emporte : c’est la famille la plus fréquente.',
        );

        $partenaireDicte = $this->outil()->execute([
            'entite' => 'ReversementRetroAgent',
            'beneficiaire' => 'Alice',
            'type' => ReversementScope::TYPE_PARTENAIRE,
        ], $s['scope']);
        self::assertSame(AiToolResult::STATUS_OK, $partenaireDicte->status);
        self::assertSame(
            ReversementScope::valeurBeneficiaire(
                ReversementScope::TYPE_PARTENAIRE,
                (int) $s['partenaireHomonyme']->getId(),
            ),
            $partenaireDicte->uiAction['criteres'][ReversementScope::CLE_BENEFICIAIRE]['value'],
            'Le type dicté doit guider la recherche, pas seulement la restreindre après coup.',
        );
    }

    /**
     * LE COUPLE CONTRADICTOIRE EST REFUSÉ, ET LA CONTRADICTION EST NOMMÉE.
     *
     * « type : agent » avec le nom d'un partenaire décrit un ensemble VIDE — agent
     * renseigné ET partenaire = 5 est impossible. Ouvrir la rubrique montrerait zéro ligne
     * sans que rien n'en dise la cause, et l'utilisateur en conclurait qu'il n'a jamais rien
     * versé. À l'écran ce couple est inatteignable ; ici il se refuse.
     */
    public function testLeCoupleContradictoireEstRefuseEtNommee(): void
    {
        $s = $this->semer();

        $resultat = $this->outil()->execute([
            'entite' => 'ReversementRetroAgent',
            'beneficiaire' => 'SUNU Courtage',
            'type' => ReversementScope::TYPE_AGENT,
        ], $s['scope']);

        self::assertSame(AiToolResult::STATUS_INTROUVABLE, $resultat->status);
        self::assertNull($resultat->uiAction, 'Aucune rubrique ne doit s’ouvrir sur un ensemble vide.');

        // Le motif doit NOMMER la contradiction : « aucun résultat » enverrait chercher
        // ailleurs un défaut qui n'existe pas.
        $motif = json_encode($resultat->data, JSON_UNESCAPED_UNICODE);
        self::assertStringContainsString('SUNU Courtage', $motif);
        self::assertStringContainsString('partenaire externe', $motif);
    }

    /**
     * UN NOM VRAIMENT INCONNU garde son propre refus, distinct du précédent.
     *
     * Les deux messages ne disent pas la même chose : l'un invite à corriger le type,
     * l'autre le nom. Les confondre, c'était envoyer l'utilisateur sur la mauvaise piste.
     */
    public function testUnNomInconnuGardeSonRefusPropre(): void
    {
        $s = $this->semer();

        $resultat = $this->outil()->execute([
            'entite' => 'ReversementRetroAgent',
            'beneficiaire' => 'Personne Inconnue',
            'type' => ReversementScope::TYPE_AGENT,
        ], $s['scope']);

        self::assertSame(AiToolResult::STATUS_INTROUVABLE, $resultat->status);
        $motif = json_encode($resultat->data, JSON_UNESCAPED_UNICODE);
        self::assertStringContainsString('Ni agent ni partenaire', $motif);
    }

    /** Un type dicté COMPATIBLE est conservé tel quel : il reste celui de l'utilisateur. */
    public function testUnTypeCompatibleEstConserve(): void
    {
        $s = $this->semer();

        $resultat = $this->outil()->execute([
            'entite' => 'ReversementRetroAgent',
            'beneficiaire' => 'SUNU Courtage',
            'type' => ReversementScope::TYPE_PARTENAIRE,
        ], $s['scope']);

        self::assertSame(AiToolResult::STATUS_OK, $resultat->status);
        self::assertSame(
            ReversementScope::TYPE_PARTENAIRE,
            $resultat->uiAction['criteres'][ReversementScope::CLE_TYPE]['value'],
        );
    }
}
