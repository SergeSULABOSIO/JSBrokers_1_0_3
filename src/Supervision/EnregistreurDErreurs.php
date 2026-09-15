<?php

namespace App\Supervision;

use App\Entity\ErreurApplicative;
use App\Marque;
use App\Repository\ErreurApplicativeRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * @file Enregistre une erreur, la compte, et signale ce que le comptage révèle.
 *
 * @description
 * Le comptage est ce qui transforme une avalanche en liste de tâches : mille
 * occurrences d'un même défaut font UNE ligne, avec un nombre en face. C'est ce
 * nombre qui dit par où commencer.
 *
 * ── CE QUE CET ENREGISTREUR ENVOIE, ET CE QU'IL N'ENVOIE PAS ────────────────
 * Il n'annonce PAS la première apparition d'une erreur : la chaîne Monolog s'en
 * charge déjà, et elle a l'avantage de ne dépendre d'aucune base de données.
 * Envoyer les deux ferait arriver deux e-mails pour le même événement, et la
 * première chose qu'on apprend d'une alerte en double, c'est à ne plus la lire.
 *
 * Il annonce les deux signaux que Monolog ne PEUT pas voir, faute d'historique :
 *   · l'AGGRAVATION — le défaut franchit 10, 100 ou 1000 occurrences ;
 *   · la RÉGRESSION — un défaut marqué résolu reparaît, donc le correctif n'a
 *     pas tenu. C'est le signal le plus précieux des deux : il dit qu'on a cru
 *     avoir terminé.
 *
 * ── DEUX GARDES, SANS LESQUELS CE CODE AGGRAVE CE QU'IL OBSERVE ─────────────
 * 1. Si la base est le problème, on n'écrit pas dans la base. Le gestionnaire
 *    d'entités est vérifié, et TOUT est enveloppé : une erreur Doctrine ne doit
 *    jamais en produire une seconde, ni transformer un 500 lisible en un 500
 *    qui parle d'autre chose.
 * 2. Rien de ce qui se passe ici ne doit interrompre la requête en cours.
 *    L'utilisateur voit déjà une erreur ; il n'a pas à en voir deux.
 */
final class EnregistreurDErreurs
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ErreurApplicativeRepository $depot,
        private readonly MailerInterface $mailer,
        #[Autowire('%app.mail_from%')]
        private readonly string $mailFrom,
        #[Autowire('%env(ALERTE_EMAIL)%')]
        private readonly string $destinataire,
    ) {
    }

    /**
     * Enregistre une occurrence et renvoie le défaut, ou null si l'écriture n'a
     * pas été possible — cas dans lequel il n'y a rien à faire de plus : la
     * chaîne Monolog, elle, aura quand même prévenu.
     *
     * @param array<string, mixed> $contexte
     */
    public function enregistrer(
        string $cote,
        string $branche,
        string $type,
        string $message,
        ?string $fichier = null,
        ?int $ligne = null,
        ?string $trace = null,
        array $contexte = [],
    ): ?ErreurApplicative {
        // GARDE 1 : une base fermée ou absente rend toute écriture impossible.
        // On sort en silence plutôt que de lever une exception par-dessus celle
        // qu'on est en train de traiter.
        if (!$this->em->isOpen()) {
            return null;
        }

        try {
            $signature = ErreurApplicative::signatureDe($cote, $type, $fichier, $ligne);
            $erreur = $this->depot->findOneBy(['signature' => $signature]);

            $premiere = null === $erreur;
            if ($premiere) {
                $erreur = (new ErreurApplicative())
                    ->setSignature($signature)
                    ->setCote($cote)
                    ->setBranche($branche)
                    ->setType(self::tronquer($type, 180))
                    ->setFichier(null === $fichier ? null : self::tronquer($fichier, 500))
                    ->setLigne($ligne);

                $this->em->persist($erreur);
            }

            $regression = $erreur->enregistrerOccurrence(new \DateTimeImmutable());

            // Le contexte décrit toujours la DERNIÈRE occurrence : c'est elle
            // qu'on cherchera à reproduire, pas celle d'il y a trois semaines.
            $erreur
                ->setMessage(self::tronquer($message, 500))
                ->setTrace(null === $trace ? $erreur->getTrace() : self::tronquer($trace, 20000))
                ->setUrl(self::tronquerOuNull($contexte['url'] ?? null, 500))
                ->setNavigateur(self::tronquerOuNull($contexte['navigateur'] ?? null, 300))
                ->setDernierUtilisateurEmail(self::tronquerOuNull($contexte['utilisateur'] ?? null, 180))
                ->setDernierCabinet(self::tronquerOuNull($contexte['cabinet'] ?? null, 180));

            $palier = $erreur->palierFranchi();

            $this->em->flush();

            // L'envoi a lieu APRÈS l'écriture : si le courrier échoue, le
            // comptage est déjà acquis. L'inverse perdrait la donnée pour
            // sauver un message.
            if ($regression) {
                $this->prevenir('RÉGRESSION', $erreur, "Ce défaut avait été marqué résolu. Il vient de reparaître : le correctif n'a pas tenu.");
            } elseif (null !== $palier && !$premiere) {
                $this->prevenir('AGGRAVATION', $erreur, sprintf('Ce défaut vient de franchir %d occurrences.', $palier));
            }

            return $erreur;
        } catch (UniqueConstraintViolationException) {
            // Deux requêtes simultanées ont créé la même signature. Sans
            // conséquence : l'une des deux a gagné, le compteur de l'autre est
            // perdu. Mieux vaut une occurrence non comptée qu'une exception.
            return null;
        } catch (\Throwable) {
            // GARDE 1 (suite) : quoi qu'il arrive ici, la requête en cours ne
            // doit pas en souffrir. On a déjà la chaîne Monolog pour prévenir.
            return null;
        }
    }

    /**
     * L'e-mail des signaux. Enveloppé lui aussi : une messagerie indisponible
     * ne doit pas défaire l'enregistrement qui vient d'aboutir.
     */
    private function prevenir(string $signal, ErreurApplicative $erreur, string $explication): void
    {
        if ('' === trim($this->destinataire)) {
            return;
        }

        try {
            $corps = sprintf(
                "%s\n\n%s : %s\n\nCôté      : %s\nBranche   : %s\nFichier   : %s:%s\nVu        : %d fois depuis le %s\nDernière  : %s\nPage      : %s\nCabinet   : %s\nUtilisateur : %s\n",
                $explication,
                $erreur->getType(),
                $erreur->getMessage(),
                $erreur->getCote(),
                $erreur->getBranche(),
                $erreur->getFichier() ?? '—',
                $erreur->getLigne() ?? '—',
                $erreur->getNombreOccurrences(),
                $erreur->getPremiereOccurrenceAt()?->format('d/m/Y H:i') ?? '—',
                $erreur->getDerniereOccurrenceAt()?->format('d/m/Y H:i') ?? '—',
                $erreur->getUrl() ?? '—',
                $erreur->getDernierCabinet() ?? '—',
                $erreur->getDernierUtilisateurEmail() ?? '—',
            );

            $this->mailer->send(
                (new Email())
                    ->from(new Address($this->mailFrom, Marque::NOM))
                    ->to($this->destinataire)
                    ->subject(sprintf('[%s] %s — %s', Marque::NOM, $signal, self::tronquer((string) $erreur->getType(), 80)))
                    ->text($corps)
            );
        } catch (\Throwable) {
            // Rien à faire : prévenir d'un échec de prévenance nous ramènerait
            // exactement ici.
        }
    }

    private static function tronquer(string $valeur, int $max): string
    {
        return mb_strlen($valeur) > $max ? mb_substr($valeur, 0, $max - 1) . '…' : $valeur;
    }

    private static function tronquerOuNull(mixed $valeur, int $max): ?string
    {
        if (!is_string($valeur) || '' === trim($valeur)) {
            return null;
        }

        return self::tronquer($valeur, $max);
    }
}
