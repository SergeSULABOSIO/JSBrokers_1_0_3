<?php

namespace App\Service\Onboarding;

use App\Entity\Entreprise;
use App\Repository\InviteRepository;
use App\Services\Mail\CorporateMailer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * LA SYNTHÈSE DE CONFIGURATION, ENVOYÉE QUAND LE SCORE BOUGE.
 *
 * ── POURQUOI PAS À CHAQUE ENREGISTREMENT ────────────────────────────────────────────
 * « À chaque mise à jour de la fiche » pris au pied de la lettre enverrait un courriel
 * par assureur créé : un courtier qui en saisit dix d'affilée en recevrait dix, et le
 * onzième partirait en indésirable avec tous les suivants. Le déclencheur est donc le
 * SCORE : il ne change que lorsqu'une étape bascule, c'est-à-dire quand il y a vraiment
 * quelque chose de nouveau à annoncer.
 *
 * `Entreprise::onboardingScoreNotifie` mémorise le dernier chiffre annoncé. Ce n'est pas
 * une source de vérité — le score se recalcule à la demande — mais la réponse à une seule
 * question : « a-t-on déjà dit celui-là ? ».
 *
 * ── UN ÉCHEC D'ENVOI N'ANNULE RIEN ──────────────────────────────────────────────────
 * L'écriture qui a fait bouger le score est déjà enregistrée et validée. Une panne de
 * messagerie ne doit pas la remettre en cause : on journalise et on passe (modèle
 * SoaClientNotifier).
 *
 * ── ⚠ ET SURTOUT : ON NE FLUSHE PAS ─────────────────────────────────────────────────
 * Le repère s'écrit par un UPDATE direct, jamais par l'EntityManager. Ce service tourne
 * en FIN DE REQUÊTE, après le travail de quelqu'un d'autre : un `flush()` y rejouerait la
 * totalité de l'unité de travail laissée derrière — avec ses entités supprimées encore
 * référencées en mémoire, ses collections à moitié détachées, ses cascades.
 *
 * Ce n'est pas une précaution théorique. Écrit avec `flush()`, ce service RESSUSCITAIT un
 * portefeuille qui venait d'être supprimé : la suppression répondait 200, l'objet
 * disparaissait de la base, puis reparaissait après la réponse. Un test de suppression
 * l'a vu ; en production, personne ne l'aurait vu.
 *
 * Un repère de notification n'a rien à faire dans la transaction du métier : c'est de la
 * comptabilité d'envoi, et un UPDATE d'une colonne suffit à l'écrire.
 */
class OnboardingNotifier
{
    public function __construct(
        private EntityManagerInterface $manager,
        private OnboardingCompletude $completude,
        private CorporateMailer $corporateMailer,
        private InviteRepository $inviteRepository,
        private UrlGeneratorInterface $urlGenerator,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Envoie la synthèse au propriétaire si — et seulement si — le score a changé.
     *
     * @return bool vrai si un courriel est parti
     */
    public function notifierSiScoreChange(Entreprise $entreprise): bool
    {
        $destinataire = $entreprise->getUtilisateur()?->getEmail();
        if ($destinataire === null || $destinataire === '') {
            return false;
        }

        // ON RELIT APRÈS L'ÉCRITURE, donc on oublie d'abord ce qu'on croyait savoir : un
        // score calculé plus tôt dans la même requête est antérieur à ce qui vient d'être
        // enregistré, et le comparer à lui-même ne signalerait jamais rien.
        $this->completude->oublier($entreprise);
        $bilan = $this->completude->scoreSeul($entreprise);
        $dernierAnnonce = $entreprise->getOnboardingScoreNotifie();

        if ($dernierAnnonce === $bilan['score']) {
            return false;
        }

        // AUCUN COURRIEL SUR LE PREMIER PASSAGE. Sans repère antérieur, on ne sait pas si
        // le score vient de bouger : la moindre écriture sans rapport — un relevé envoyé
        // à un client, par exemple — déclencherait une synthèse annonçant un progrès qui
        // n'a pas eu lieu. On pose donc le repère en silence.
        //
        // Ce cas ne concerne que les cabinets ANTÉRIEURS à cette fonctionnalité : à la
        // création, ServiceProvisionEntreprise pose déjà le repère, et la première vraie
        // avancée est donc annoncée.
        if ($dernierAnnonce === null) {
            $this->inscrireLeRepere($entreprise, $bilan['score']);

            return false;
        }

        // Le score est enregistré AVANT l'envoi, et il l'est même si l'envoi échoue :
        // sinon la moindre panne de messagerie relancerait une tentative à chaque
        // écriture suivante, jusqu'à ce qu'elle passe — en rafale.
        $this->inscrireLeRepere($entreprise, $bilan['score']);

        try {
            $this->corporateMailer->send(
                $destinataire,
                $this->corporateMailer->buildSubject(
                    'Configuration du cabinet',
                    (string) $entreprise->getNom(),
                ),
                'emails/onboarding_synthese.html.twig',
                [
                    'recipientName' => $entreprise->getUtilisateur()?->getNom() ?: $destinataire,
                    'entrepriseNom' => $entreprise->getNom(),
                    'bilan' => $bilan,
                    'etapesCitees' => $this->completude->etapesACiter($entreprise),
                    'guideUrl' => $this->urlDuGuide($entreprise),
                ],
            );

            return true;
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Onboarding : échec d\'envoi de la synthèse à {email} pour {cabinet} : {msg}',
                ['email' => $destinataire, 'cabinet' => $entreprise->getNom(), 'msg' => $e->getMessage()],
            );

            return false;
        }
    }

    /**
     * Inscrit le dernier score annoncé — par un UPDATE, jamais par un flush.
     *
     * L'entité en mémoire est mise à jour elle aussi, pour que le reste de la requête
     * lise la même valeur que la base ; mais c'est bien l'UPDATE qui fait foi.
     */
    private function inscrireLeRepere(Entreprise $entreprise, int $score): void
    {
        $entreprise->setOnboardingScoreNotifie($score);

        $this->manager->getConnection()->executeStatement(
            'UPDATE entreprise SET onboarding_score_notifie = :score WHERE id = :id',
            ['score' => $score, 'id' => $entreprise->getId()],
        );
    }

    /**
     * L'URL qui ramène le courtier DANS son guide, et pas seulement dans son espace.
     *
     * `?onboarding=1` fait ouvrir le guide de lui-même à l'arrivée : sans ce marqueur,
     * le bouton du courriel déposerait le destinataire sur son tableau de bord, à charge
     * pour lui de retrouver l'écran dont on vient de lui parler.
     */
    private function urlDuGuide(Entreprise $entreprise): ?string
    {
        $proprietaire = $this->inviteRepository->findOneBy([
            'entreprise' => $entreprise,
            'proprietaire' => true,
        ]);

        if ($proprietaire === null) {
            return null;
        }

        try {
            return $this->urlGenerator->generate(
                'app_espace_de_travail_component.index',
                [
                    'idInvite' => $proprietaire->getId(),
                    'idEntreprise' => $entreprise->getId(),
                    'onboarding' => 1,
                ],
                UrlGeneratorInterface::ABSOLUTE_URL,
            );
        } catch (\Throwable) {
            // Hors requête HTTP (commande, worker), la génération d'une URL absolue peut
            // manquer de contexte. Le courriel part alors sans bouton plutôt que pas
            // du tout : la synthèse vaut d'être lue même sans lien.
            return null;
        }
    }
}
