<?php

namespace App\Tests\Frontend;

use App\Marque;
use App\Services\Mail\CorporateMailer;
use PHPUnit\Framework\TestCase;

/**
 * UNE SEULE MARQUE EST AFFICHÉE À L'UTILISATEUR.
 *
 * La plateforme s'appelle « Joseara ». « JS Brokers » reste le nom de code du projet —
 * dépôt git, dossiers, classes CSS `jsb-*`, classes `*Jsbx`, commande de démarrage — mais
 * plus rien de ce que lit un utilisateur ne doit le nommer ainsi.
 *
 * ── CE QUI ARRIVERAIT SANS CE TEST ───────────────────────────────────────────────────
 * Le nom commercial est écrit EN TOUTES LETTRES dans les gabarits et les traductions,
 * et non derrière une variable : c'est délibéré (une indirection dans 77 templates rendrait
 * le texte illisible pour un gain nul), mais cela retire le filet. Une nouvelle page, un
 * nouvel e-mail, une nouvelle traduction recopiés depuis un fichier existant ramèneraient
 * l'ancienne marque sans que rien ne proteste — et elle repartirait chez un client, dans
 * un e-mail ou sur une facture. Ce test EST le filet : il remplace l'indirection évitée.
 *
 * Les points où la marque est COMPOSÉE par du code (expéditeur, facture, métadonnées de
 * fichier) lisent App\Marque : on vérifie aussi que ce chaînage tient.
 */
class MarqueUnifieeTest extends TestCase
{
    private const RACINE = __DIR__ . '/../..';

    /** Les répertoires dont le contenu est lu, directement ou indirectement, par un utilisateur. */
    private const DOSSIERS = ['templates', 'translations', 'src', 'config'];

    /** Extensions balayées (on ignore images, archives et fichiers compilés). */
    private const EXTENSIONS = ['twig', 'yaml', 'yml', 'php', 'md'];

    /**
     * Toutes les graphies de l'ancienne marque, y compris celles qu'un grep naïf rate :
     * le singulier « JS Broker », l'espace insécable des CGU, la forme collée et le
     * domaine historique.
     */
    private const GRAPHIES_INTERDITES = [
        'JS Brokers',
        'JS Broker',
        'JS&nbsp;Brokers',
        'JSBrokers',
        'js-brokers',
        'jsbrokers',
    ];

    /**
     * LA CATÉGORIE B, nommément. Chaque entrée autorise UNE graphie dans UN fichier, et
     * dit pourquoi : une liste blanche qui s'élargit sans justification ne protège plus.
     *
     * @var array<string, array<int, string>> chemin relatif => graphies tolérées
     */
    private const TOLERANCES = [
        // La classe de marque explique elle-même ce qu'est devenu le nom de code.
        'src/Marque.php' => ['JS Brokers'],
        // Commande de développement : son nom est une adresse tapée à la main, pas un libellé.
        'src/Command/JsbrokersDemarrerCommand.php' => ['jsbrokers'],
        // La CLÉ de traduction est un identifiant ; c'est sa VALEUR qui porte la marque.
        'translations/messages.fr.yaml' => ['jsbrokers'],
        'translations/messages.en.yaml' => ['jsbrokers'],
    ];

    /**
     * AUCUN FICHIER LU PAR UN UTILISATEUR NE NOMME L'ANCIENNE MARQUE.
     */
    public function testAucuneAncienneMarqueDansLesSourcesVisibles(): void
    {
        $fautes = [];

        foreach ($this->fichiersBalayes() as $relatif => $chemin) {
            $contenu = (string) file_get_contents($chemin);
            $tolerees = self::TOLERANCES[$relatif] ?? [];

            foreach (self::GRAPHIES_INTERDITES as $graphie) {
                if (\in_array($graphie, $tolerees, true) || !str_contains($contenu, $graphie)) {
                    continue;
                }

                // Une graphie tolérée en contient parfois une autre (« jsbrokers » vit dans
                // « JsbrokersDemarrerCommand ») : on ne signale que ce qui reste une fois
                // les passages tolérés retirés.
                $reste = $contenu;
                foreach ($tolerees as $toleree) {
                    $reste = str_replace($toleree, '', $reste);
                }

                if (str_contains($reste, $graphie)) {
                    $fautes[] = sprintf('%s → « %s »', $relatif, $graphie);
                }
            }
        }

        self::assertSame(
            [],
            $fautes,
            "L'ancienne marque réapparaît dans des fichiers lus par l'utilisateur. "
                . "La plateforme s'appelle « " . Marque::NOM . " » : corrigez le libellé, "
                . "ou déclarez la tolérance dans self::TOLERANCES en disant pourquoi.\n"
                . implode("\n", $fautes),
        );
    }

    /**
     * L'EXPÉDITEUR DE TOUS LES E-MAILS EST LA MARQUE.
     *
     * `CorporateMailer::SENDER_NAME` nourrit l'expéditeur ET l'objet normalisé
     * « Joseara - [objet] - [concerné] » de chaque envoi. S'il se détachait de la
     * constante, la marque changerait partout sauf dans la boîte de réception.
     */
    public function testLExpediteurCorporateSuitLaMarque(): void
    {
        self::assertSame(Marque::NOM, CorporateMailer::SENDER_NAME);
        self::assertStringStartsWith(
            Marque::NOM . ' - ',
            (new CorporateMailer(
                $this->createMock(\Symfony\Component\Mailer\MailerInterface::class),
                Marque::CONTACT,
                '@images/entreprises/logofav.png',
            ))->buildSubject('Objet', 'Concerné'),
        );
    }

    /**
     * L'ADRESSE D'ENVOI VIT SUR LE DOMAINE DE LA MARQUE.
     *
     * `MAILER_FROM` est la seule valeur de marque qui ne soit pas dans le code : elle est
     * dans l'environnement. Un renommage qui l'oublierait laisserait tous les e-mails
     * partir de l'ancien domaine, sans que rien d'autre ne le montre.
     */
    public function testLAdresseExpeditriceEstSurLeDomaineDeLaMarque(): void
    {
        $env = (string) file_get_contents(self::RACINE . '/.env');

        self::assertSame(
            1,
            preg_match('/^MAILER_FROM=(.+)$/m', $env, $trouve),
            'MAILER_FROM doit rester déclaré dans .env.',
        );

        self::assertStringEndsWith(
            '@' . Marque::DOMAINE,
            trim($trouve[1]),
            'L\'adresse expéditrice doit vivre sur le domaine de la marque.',
        );
    }

    /**
     * Les fichiers à balayer, indexés par chemin relatif au projet.
     *
     * @return array<string, string>
     */
    private function fichiersBalayes(): array
    {
        $fichiers = [];

        foreach (self::DOSSIERS as $dossier) {
            $iterateur = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(
                    self::RACINE . '/' . $dossier,
                    \FilesystemIterator::SKIP_DOTS,
                ),
            );

            /** @var \SplFileInfo $fichier */
            foreach ($iterateur as $fichier) {
                if (!$fichier->isFile() || !\in_array($fichier->getExtension(), self::EXTENSIONS, true)) {
                    continue;
                }

                $relatif = str_replace('\\', '/', $fichier->getPathname());
                $relatif = substr($relatif, strpos($relatif, '/' . $dossier . '/') + 1);
                $fichiers[$relatif] = $fichier->getPathname();
            }
        }

        return $fichiers;
    }
}
