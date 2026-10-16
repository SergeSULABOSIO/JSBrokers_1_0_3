<?php

namespace App\Service\Contact;

use App\DTO\DemandeContactDTO;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * LE GARDE DU FORMULAIRE DE CONTACT ANONYME.
 *
 * Un visiteur sans compte y choisit l'adresse où Joseara envoie un accusé de réception :
 * sans garde, c'est un relais d'e-mails ouvert. Le garde dit, AVANT l'envoi :
 *  - ROBOT  : le champ piège est rempli, ou le formulaire est revenu trop vite (ou sans
 *             horodatage signé). On répond comme à un succès, mais rien ne part : le robot
 *             n'apprend rien.
 *  - LIMITE : trop d'envois depuis cette IP, ou vers cette adresse. On le dit.
 *  - PASSE  : on envoie.
 * Les deux portes (la vitrine et /admin/demande_contact) passent par lui.
 *
 * Un refus du limiteur journalise l'IP RETENUE (canal « contact »), sans rien du contenu
 * du formulaire : c'est ce qui permet de vérifier, en production, que l'IP est bien celle
 * du visiteur et non celle d'un proxy (TRUSTED_PROXIES).
 */
final class GardeDuFormulaireDeContact
{
    public const PASSE = 'passe';
    public const ROBOT = 'robot';
    public const LIMITE = 'limite';

    /** Champ piège : invisible pour un humain, rempli par un robot qui remplit tout. */
    public const CHAMP_PIEGE = 'site_web';
    /** Horodatage signé de l'affichage du formulaire. */
    public const CHAMP_HORODATAGE = 'affiche_le';
    /** En dessous, ce n'est pas un humain qui a lu et rempli le formulaire. */
    public const DELAI_MINIMAL_SECONDES = 3;

    public function __construct(
        private readonly RateLimiterFactory $contactParIpLimiter,
        private readonly RateLimiterFactory $contactParDestinataireLimiter,
        private readonly LoggerInterface $contactLogger,
        private readonly ClockInterface $horloge,
        #[Autowire('%kernel.secret%')]
        private readonly string $secret,
    ) {
    }

    /** L'horodatage signé à poser dans le formulaire au moment de l'afficher. */
    public function jeton(?\DateTimeImmutable $instant = null): string
    {
        $secondes = ($instant ?? $this->horloge->now())->getTimestamp();

        return $secondes . '.' . $this->signature($secondes);
    }

    public function verifier(Request $request, FormInterface $form, DemandeContactDTO $demande): string
    {
        $ip = $request->getClientIp() ?? 'inconnue';

        $piege = $form->has(self::CHAMP_PIEGE) ? trim((string) $form->get(self::CHAMP_PIEGE)->getData()) : '';
        if ($piege !== '') {
            return $this->robot($ip, 'champ piège rempli');
        }

        $affiche = $form->has(self::CHAMP_HORODATAGE) ? $this->instantSigne((string) $form->get(self::CHAMP_HORODATAGE)->getData()) : null;
        if ($affiche === null) {
            return $this->robot($ip, 'horodatage absent ou falsifié');
        }
        if ($this->horloge->now()->getTimestamp() - $affiche < self::DELAI_MINIMAL_SECONDES) {
            return $this->robot($ip, 'formulaire renvoyé trop vite');
        }

        if (!$this->contactParIpLimiter->create($ip)->consume()->isAccepted()) {
            return $this->limite($ip, 'ip');
        }
        $destinataire = mb_strtolower(trim($demande->email));
        if (!$this->contactParDestinataireLimiter->create($destinataire)->consume()->isAccepted()) {
            return $this->limite($ip, 'destinataire');
        }

        return self::PASSE;
    }

    private function robot(string $ip, string $motif): string
    {
        $this->contactLogger->info('Formulaire de contact : envoi écarté (robot présumé).', ['motif' => $motif, 'ip' => $ip]);

        return self::ROBOT;
    }

    private function limite(string $ip, string $limiteur): string
    {
        $this->contactLogger->warning('Formulaire de contact : envoi refusé par le limiteur.', ['limiteur' => $limiteur, 'ip' => $ip]);

        return self::LIMITE;
    }

    private function signature(int $secondes): string
    {
        return hash_hmac('sha256', 'contact|' . $secondes, $this->secret);
    }

    /** L'instant signé, ou null si le jeton est absent, mal formé ou falsifié. */
    private function instantSigne(string $jeton): ?int
    {
        if (!preg_match('/^(\d{1,12})\.([0-9a-f]{64})$/', $jeton, $m)) {
            return null;
        }

        return hash_equals($this->signature((int) $m[1]), $m[2]) ? (int) $m[1] : null;
    }
}
