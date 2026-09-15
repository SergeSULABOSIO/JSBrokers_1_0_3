<?php

namespace App\Tests\Console;

use PHPUnit\Framework\TestCase;

/**
 * LA CONSOLE EST RÉSERVÉE AUX AGENTS JOSEARA. PAS AUX CLIENTS, PAS À LEURS ADMINS.
 *
 * ── CE QUE CE TEST PROTÈGE ──────────────────────────────────────────────────
 * Joseara héberge des cabinets de courtage concurrents. La Console contient le
 * chiffre d'affaires de la plateforme, la liste de TOUS les cabinets, leurs
 * consommations, la tarification, les dépenses et la fiscalité de l'éditeur.
 *
 * Un administrateur de cabinet — même titulaire de TOUS les droits dans son
 * propre espace de travail — n'y a rien à faire. Les deux notions de « droits »
 * de ce projet sont délibérément DISJOINTES :
 *
 *   · les droits DANS un cabinet  → Invite / WorkspaceAccessResolver, permissions
 *     par domaine et par portefeuille, portées par des entités métier ;
 *   · la qualité d'AGENT Joseara  → ROLE_ADMIN / ROLE_SUPER_ADMIN, portés par la
 *     colonne `roles` de l'utilisateur.
 *
 * Aucune passerelle n'existe entre les deux, et ce test est là pour qu'il n'en
 * apparaisse jamais — ni par mégarde, ni par commodité.
 *
 * ── POURQUOI UN TEST, ALORS QUE LE CODE EST DÉJÀ CORRECT ────────────────────
 * ⚠ `access_control` est VIDE dans security.yaml : toute la protection de la
 * Console repose sur les attributs #[IsGranted] posés contrôleur par contrôleur.
 * Un contrôleur Console livré sans son attribut serait donc ouvert à tout
 * utilisateur connecté — n'importe quel courtier client — et RIEN ne le
 * signalerait : la page s'afficherait normalement, pour tout le monde.
 *
 * C'est une fuite de données entre concurrents qui ne produirait aucune erreur.
 */
final class AccesReserveAuxAgentsTest extends TestCase
{
    private static function racine(): string
    {
        return \dirname(__DIR__, 2);
    }

    /**
     * @return list<string> chemins des fichiers PHP sous un dossier
     */
    private static function fichiersPhp(string $dossier): array
    {
        $trouves = [];
        $fichiers = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dossier));

        foreach ($fichiers as $fichier) {
            if ($fichier->isFile() && 'php' === $fichier->getExtension()) {
                $trouves[] = str_replace('\\', '/', $fichier->getPathname());
            }
        }

        sort($trouves);

        return $trouves;
    }

    /**
     * CHAQUE contrôleur de la Console exige la qualité d'agent.
     *
     * Le sous-dossier Crm est inclus : il est dans la Console, il en suit la
     * règle. Les classes abstraites sont écartées — elles ne portent aucune
     * route, donc aucune porte.
     */
    public function testChaqueControleurDeLaConsoleExigeLaQualiteDAgent(): void
    {
        $sansGarde = [];

        foreach (self::fichiersPhp(self::racine() . '/src/Controller/Console') as $chemin) {
            $source = (string) file_get_contents($chemin);

            if (str_contains($source, 'abstract class')) {
                continue;
            }

            $protege = str_contains($source, "#[IsGranted('ROLE_ADMIN')]")
                || str_contains($source, "#[IsGranted('ROLE_SUPER_ADMIN')]");

            if (!$protege) {
                $sansGarde[] = basename($chemin);
            }
        }

        self::assertSame(
            [],
            $sansGarde,
            'Ces contrôleurs de la Console n\'exigent pas ROLE_ADMIN. access_control étant vide, '
            . 'ils sont donc ouverts à TOUT utilisateur connecté — y compris l\'administrateur d\'un '
            . 'cabinet client, qui y verrait les données de ses concurrents : ' . implode(', ', $sansGarde)
        );
    }

    /**
     * La qualité d'agent ne s'attribue QUE depuis la Console.
     *
     * `setRoles()` n'a le droit d'être appelé qu'à trois endroits : la
     * définition de l'entité, l'écran de gestion des collaborateurs (lui-même
     * réservé aux agents), et les fixtures de développement — qui ne tournent
     * jamais en production.
     *
     * Le jour où l'inscription, l'accueil d'un nouveau cabinet ou l'acceptation
     * d'une invitation se mettrait à poser un rôle, ce test tombe. C'est le
     * chemin par lequel un client deviendrait agent sans que personne ne l'ait
     * décidé.
     */
    public function testLaQualiteDAgentNeSAttribueQueDepuisLaConsole(): void
    {
        $autorises = [
            'src/Entity/Utilisateur.php',                          // la définition elle-même
            'src/Controller/Console/CollaborateurController.php',  // l'écran des collaborateurs
            'src/DataFixtures/UtilisateurFixtures.php',            // développement uniquement
        ];

        $inattendus = [];

        foreach (self::fichiersPhp(self::racine() . '/src') as $chemin) {
            if (!str_contains((string) file_get_contents($chemin), 'setRoles(')) {
                continue;
            }

            $relatif = ltrim(str_replace(str_replace('\\', '/', self::racine()), '', $chemin), '/');

            if (!in_array($relatif, $autorises, true)) {
                $inattendus[] = $relatif;
            }
        }

        self::assertSame(
            [],
            $inattendus,
            'Un rôle est attribué hors de la Console. Vérifiez qu\'aucun client ne peut ainsi devenir '
            . 'agent Joseara : ' . implode(', ', $inattendus)
        );
    }

    /**
     * Aucun rôle de cabinet ne conduit à la qualité d'agent.
     *
     * La hiérarchie des rôles est l'autre porte dérobée possible : il suffirait
     * d'une ligne — « ROLE_COURTIER: [ROLE_ADMIN] » — pour ouvrir la Console à
     * toute une catégorie d'utilisateurs, sans toucher à un seul contrôleur.
     * Seul le super-administrateur peut mener à ROLE_ADMIN.
     */
    public function testAucunRoleDeCabinetNeMeneALaQualiteDAgent(): void
    {
        $sécurité = (string) file_get_contents(self::racine() . '/config/packages/security.yaml');

        self::assertSame(
            1,
            preg_match('/role_hierarchy:\s*\n((?:\s+ROLE_[A-Z_]+:.*\n)+)/', $sécurité, $bloc),
            'security.yaml doit déclarer une hiérarchie de rôles explicite.'
        );

        preg_match_all('/^\s*(ROLE_[A-Z_]+):\s*\[([^\]]*)\]/m', $bloc[1], $lignes, \PREG_SET_ORDER);

        foreach ($lignes as [, $porteur, $accorde]) {
            if (!str_contains($accorde, 'ROLE_ADMIN')) {
                continue;
            }

            self::assertSame(
                'ROLE_SUPER_ADMIN',
                $porteur,
                sprintf(
                    'Le rôle « %s » accorde ROLE_ADMIN, donc l\'accès à la Console Joseara. '
                    . 'Seul ROLE_SUPER_ADMIN a le droit de le faire.',
                    $porteur
                )
            );
        }
    }
}
