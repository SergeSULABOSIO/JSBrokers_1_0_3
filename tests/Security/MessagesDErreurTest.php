<?php

namespace App\Tests\Security;

use PHPUnit\Framework\TestCase;

/**
 * LE TEXTE D'UNE EXCEPTION NE SORT PAS SANS MOTIF — GARDE-FOU DU CHANTIER 10.
 *
 * Quinze `catch` relayaient `$e->getMessage()` à l'écran : SQL, chemins du serveur, noms de
 * classe. La règle (jsbrokers-dev §4) : le message d'une exception va à l'écran par
 * `MessageDErreur::pourLEcran()` — qui ne laisse passer que le métier — et nulle part
 * ailleurs sans motif. Ce test tient l'inventaire des `getMessage()` de src/ : un appel
 * NOUVEAU le fait échouer tant qu'il n'est pas motivé ci-dessous (ou routé par
 * pourLEcran). Un appel retiré aussi : la liste reste exacte.
 */
class MessagesDErreurTest extends TestCase
{
    private const JOURNAL = 'journal / supervision, jamais l\'écran';
    private const GETTER = 'getMessage() d\'une ENTITÉ, pas d\'une exception';
    private const CLI = 'commande CLI : la console de l\'exploitant';

    /** fichier => [nombre d'appels, motif] */
    private const INVENTAIRE = [
        'src/Service/Erreur/MessageDErreur.php' => [2, 'la source unique du tri elle-même'],
        'src/Supervision/HandlerDeSupervision.php' => [1, self::JOURNAL],
        'src/Supervision/EnregistreurDErreurs.php' => [1, self::JOURNAL],

        'src/Controller/Admin/AssistantIaController.php' => [5, 'MutationException, NoteIntangibleException, ImageJointeValidator (métier) ; le reste au journal'],
        'src/Controller/Admin/ControllerUtilsTrait.php' => [4, 'NoteIntangibleException, MutationException, erreurs de formulaire (métier) ; '
            . 'le texte SQL lu SANS être affiché, pour reconnaître une clé étrangère (tableQuiBloqueLaSuppression)'],
        'src/Controller/Admin/NoteController.php' => [2, 'NoteNonValidable (métier)'],
        'src/Controller/Admin/EchangeController.php' => [2, 'ImportImpossibleException, ClasseurIllisibleException (métier)'],
        'src/Controller/Admin/AvenantController.php' => [2, 'MutationException, InvalidArgumentException de MarquageNonRenouvelableService (métier)'],
        'src/Controller/Admin/DocumentController.php' => [1, 'InsufficientTokensException (métier)'],
        'src/Controller/Admin/EntrepriseController.php' => [1, '\DomainException OHADA (métier)'],
        'src/Controller/Console/EntrepriseController.php' => [1, '\DomainException OHADA (métier)'],
        'src/Controller/Console/KetReglagesController.php' => [3, '\DomainException des réglages de Ket (métier)'],
        'src/Controller/Admin/SupportController.php' => [1, self::JOURNAL],
        'src/Controller/Console/EvaluationController.php' => [1, self::JOURNAL],
        'src/Controller/Api/PaymentWebhookController.php' => [1, self::JOURNAL],
        'src/EventListener/CabinetFermeListener.php' => [1, 'AucunCabinetOuvertException (métier)'],
        'src/EventListener/HoteInconnuListener.php' => [2, 'reconnaît le refus « Untrusted Host » et le trace en INFO ; l\'écran reçoit un texte fixe'],
        'src/Service/Workspace/WorkspaceMutationService.php' => [2, 'NoteNonValidable et erreurs de formulaire (métier)'],
        'src/Echange/Service/ImportateurJsbx.php' => [1, 'ClasseurIllisibleException (métier) au rapport'],
        'src/Echange/Etat/ProducteurDeLEtat.php' => [1, self::JOURNAL],
        'src/Ai/Tool/EchangeImporterTool.php' => [1, 'ImportImpossibleException (métier), reformulée par Ket'],
        'src/Ai/Programme/ProgrammeRunner.php' => [1, self::GETTER],
        'src/MessageHandler/TraiterMessagesAssistantHandler.php' => [2, 'AssistantTache.erreur : jamais transmis au navigateur (ChargeUtileDuMessage)'],

        'src/Services/ServiceSuppressionEntreprise.php' => [1, self::JOURNAL],
        'src/Service/Workspace/InvitePerimetreNotifier.php' => [1, self::JOURNAL],
        'src/Service/Soa/SoaClientNotifier.php' => [1, self::JOURNAL],
        'src/Service/Onboarding/OnboardingNotifier.php' => [1, self::JOURNAL],
        'src/Service/Console/AffectationNotifier.php' => [1, self::JOURNAL],
        'src/Service/Conge/CongeMailer.php' => [1, self::JOURNAL],
        'src/EventSubscriber/MailingSubscriber.php' => [1, self::JOURNAL],
        'src/EventSubscriber/ContactTicketSubscriber.php' => [1, self::JOURNAL],

        'src/Entity/SoaEnvoi.php' => [1, self::GETTER],
        'src/Entity/ErreurApplicative.php' => [1, self::GETTER],
        'src/Entity/Crm/CrmNotification.php' => [1, self::GETTER],
        'src/Entity/Crm/CrmCampagne.php' => [1, self::GETTER],
        'src/Entity/AssistantProgrammeEtape.php' => [1, self::GETTER],
        'src/Entity/AssistantDocument.php' => [1, self::GETTER],
        'src/Crm/CrmCampagneService.php' => [1, self::GETTER],
        'src/Ai/Tool/PreparerDocumentTool.php' => [1, self::GETTER],
        'src/Ai/Programme/ProgrammeVerificateur.php' => [1, self::GETTER],

        'src/Command/CongesRappelsCommand.php' => [3, self::CLI],
        'src/Command/KetDiagnosticCommand.php' => [2, self::CLI],
        'src/Command/EchangeSmokeCommand.php' => [2, self::CLI],
        'src/Command/NotesFigerCommand.php' => [1, self::CLI],
        'src/Command/KetJustesseCommand.php' => [1, self::CLI],
        'src/Command/EtatSmokeCommand.php' => [1, self::CLI],
        'src/Command/BordereauEnrichirLignesCommand.php' => [1, self::CLI],
    ];

    public function testChaqueGetMessageEstMotive(): void
    {
        $racine = \dirname(__DIR__, 2);
        $trouves = [];
        $iterateur = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($racine . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($iterateur as $fichier) {
            if ($fichier->getExtension() !== 'php') {
                continue;
            }
            $n = substr_count((string) file_get_contents($fichier->getPathname()), 'getMessage()');
            if ($n > 0) {
                $trouves[str_replace('\\', '/', substr($fichier->getPathname(), \strlen($racine) + 1))] = $n;
            }
        }
        self::assertGreaterThan(30, array_sum($trouves), 'L\'inventaire ne trouve plus rien : la lecture de src/ est cassée.');

        $ecarts = [];
        foreach ($trouves as $chemin => $n) {
            $attendu = self::INVENTAIRE[$chemin][0] ?? 0;
            if ($n !== $attendu) {
                $ecarts[] = "$chemin : $n appel(s), $attendu motivé(s)";
            }
        }
        foreach (array_diff_key(self::INVENTAIRE, $trouves) as $chemin => [$attendu]) {
            $ecarts[] = "$chemin : 0 appel, $attendu motivé(s) — retirez-le de l'inventaire";
        }

        self::assertSame([], $ecarts,
            "getMessage() sans motif. Un message d'exception va à l'écran par MessageDErreur::pourLEcran() ; "
            . 'sinon, motivez l\'appel dans MessagesDErreurTest::INVENTAIRE.',
        );
    }
}
