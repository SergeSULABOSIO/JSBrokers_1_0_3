<?php

namespace App\Controller\Api;

use App\Entity\ErreurApplicative;
use App\Entity\Utilisateur;
use App\Security\Csp\PolitiqueCsp;
use App\Supervision\AdresseDuNavigateur;
use App\Supervision\EnregistreurDErreurs;
use App\Supervision\OrigineErreur;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * @file Collecte des violations de la Content-Security-Policy (endpoint PUBLIC).
 *
 * @description
 * Le navigateur signale ici ce que la politique (`PolitiqueCsp`) a bloqué — ou aurait
 * bloqué, tant qu'elle est en Report-Only. Chaque violation entre dans la supervision,
 * comme une erreur JavaScript : type « CSP <directive> : <ressource bloquée> », fichier et
 * ligne de la source. La signature (côté|type|fichier|ligne) regroupe donc une même
 * violation en une seule ligne, avec son compteur.
 *
 * Deux formats, parce que deux mécanismes :
 * - `application/csp-report` — `report-uri`, un rapport par envoi (HTTP et navigateurs
 *   sans Reporting API) ;
 * - `application/reports+json` — `report-to` (Reporting API), en HTTPS seulement, par LOTS
 *   (~1 Ko le rapport) : il ne se voit pas sur le poste de développement, `JournalCspTest`
 *   le tient.
 *
 * ── PUBLIQUE : N'IMPORTE QUI PEUT Y ÉCRIRE N'IMPORTE QUOI ───────────────────
 * - Corps plafonné (8 Ko par rapport isolé, 64 Ko par lot, 20 rapports lus au plus).
 * - Débit limité par adresse (`csp_par_ip`), compté par VIOLATION, pas par envoi.
 * - Seuls les rapports d'une page de NOTRE hôte sont gardés : un site tiers peut nous
 *   désigner comme `report-uri`, ses violations n'ont rien à faire chez nous.
 * - Extensions et antivirus écartés ; adresses anonymisées (`AdresseDuNavigateur` : ni
 *   requête, ni fragment, ni jeton de `/soa/{jeton}`).
 * - JAMAIS d'e-mail d'alerte (`alerter: false`) : un rapport se forge à volonté. La
 *   console de supervision les montre, avec leur compteur.
 * - Réponse 204 quoi qu'il arrive.
 */
#[Route('/api/journal')]
class JournalCspController extends AbstractController
{
    private const CORPS_MAXIMUM = 8192;
    private const LOT_MAXIMUM = 65536;

    /** Un lot `reports+json` groupe plusieurs rapports : on en lit au plus autant. */
    private const RAPPORTS_PAR_ENVOI = 20;

    /** Bornes du type « CSP <directive> : <ressource> » : il tient dans les 180 caractères de la colonne. */
    private const DIRECTIVE_MAXIMUM = 40;
    private const RESSOURCE_MAXIMUM = 120;

    public function __construct(
        private readonly EnregistreurDErreurs $enregistreur,
        private readonly OrigineErreur $origine,
        private readonly RateLimiterFactoryInterface $cspParIpLimiter,
    ) {
    }

    #[Route('/csp', name: 'api.journal.csp', methods: ['POST'])]
    public function recevoir(Request $request): Response
    {
        $silence = new Response('', Response::HTTP_NO_CONTENT);

        try {
            $corps = $request->getContent();
            $plafond = str_contains((string) $request->headers->get('Content-Type'), 'reports+json') ? self::LOT_MAXIMUM : self::CORPS_MAXIMUM;
            if ('' === $corps || \strlen($corps) > $plafond) {
                return $silence;
            }

            $charge = json_decode($corps, true);
            if (!\is_array($charge)) {
                return $silence;
            }

            $violations = $this->retenues(self::violations($charge), $request->getHost());
            if ([] === $violations
                || !$this->cspParIpLimiter->create($request->getClientIp() ?? 'inconnue')->consume(\count($violations))->isAccepted()) {
                return $silence;
            }

            foreach ($violations as $violation) {
                $this->enregistrer($violation, $request->headers->get('User-Agent'));
            }
        } catch (\Throwable) {
            // Rien de ce qui se passe ici ne remonte au navigateur.
        }

        return $silence;
    }

    /**
     * Les deux formats ramenés à un seul.
     *
     * @param array<mixed> $charge
     *
     * @return list<array{directive: ?string, ressource: ?string, page: ?string, fichier: ?string, ligne: ?int, extrait: ?string}>
     */
    private static function violations(array $charge): array
    {
        // report-uri : { "csp-report": { "document-uri": …, "blocked-uri": … } }
        if (isset($charge['csp-report']) && \is_array($charge['csp-report'])) {
            $r = $charge['csp-report'];

            return [[
                'directive' => self::texte($r['effective-directive'] ?? null, 60)
                    ?? self::texte(explode(' ', (string) ($r['violated-directive'] ?? ''))[0], 60),
                'ressource' => self::texte($r['blocked-uri'] ?? null, 500),
                'page' => self::texte($r['document-uri'] ?? null, 500),
                'fichier' => self::texte($r['source-file'] ?? null, 500),
                'ligne' => self::entier($r['line-number'] ?? null),
                'extrait' => self::texte($r['script-sample'] ?? null, 200),
            ]];
        }

        // report-to : [ { "type": "csp-violation", "body": { "documentURL": …, "blockedURL": … } } ]
        $violations = [];
        foreach (\array_slice(array_is_list($charge) ? $charge : [], 0, self::RAPPORTS_PAR_ENVOI) as $rapport) {
            if (!\is_array($rapport) || 'csp-violation' !== ($rapport['type'] ?? null) || !\is_array($rapport['body'] ?? null)) {
                continue;
            }
            $b = $rapport['body'];
            $violations[] = [
                'directive' => self::texte($b['effectiveDirective'] ?? null, 60),
                'ressource' => self::texte($b['blockedURL'] ?? null, 500),
                'page' => self::texte($b['documentURL'] ?? $rapport['url'] ?? null, 500),
                'fichier' => self::texte($b['sourceFile'] ?? null, 500),
                'ligne' => self::entier($b['lineNumber'] ?? null),
                'extrait' => self::texte($b['sample'] ?? null, 200),
            ];
        }

        return $violations;
    }

    /**
     * Ne garde que les violations d'une page de NOTRE hôte, hors extensions et antivirus,
     * adresses anonymisées, et une seule fois chacune par envoi.
     *
     * @param list<array{directive: ?string, ressource: ?string, page: ?string, fichier: ?string, ligne: ?int, extrait: ?string}> $violations
     *
     * @return list<array{directive: string, ressource: string, page: ?string, fichier: ?string, ligne: ?int, extrait: ?string}>
     */
    private function retenues(array $violations, string $notreHote): array
    {
        $retenues = [];
        foreach ($violations as $v) {
            if (null === $v['directive'] || null === $v['page']
                || 0 !== strcasecmp((string) parse_url($v['page'], \PHP_URL_HOST), $notreHote)) {
                continue;
            }
            foreach ([$v['ressource'], $v['fichier']] as $adresse) {
                if (null !== $adresse && AdresseDuNavigateur::estEtrangere($adresse)) {
                    continue 2;
                }
            }

            $v['directive'] = self::texte($v['directive'], self::DIRECTIVE_MAXIMUM);
            $v['ressource'] = self::texte(AdresseDuNavigateur::anonymisee($v['ressource']), self::RESSOURCE_MAXIMUM) ?? 'inconnue';
            $v['page'] = AdresseDuNavigateur::anonymisee($v['page']);
            $v['fichier'] = AdresseDuNavigateur::anonymisee($v['fichier']);
            $retenues[implode('|', [$v['directive'], $v['ressource'], $v['fichier'], $v['ligne']])] = $v;
        }

        return array_values($retenues);
    }

    /** @param array{directive: string, ressource: string, page: ?string, fichier: ?string, ligne: ?int, extrait: ?string} $v */
    private function enregistrer(array $v, ?string $navigateur): void
    {
        $utilisateur = $this->getUser();

        $this->enregistreur->enregistrer(
            cote: ErreurApplicative::COTE_NAVIGATEUR,
            branche: $this->origine->depuisUrl($v['page']),
            type: sprintf('CSP %s : %s', $v['directive'], $v['ressource']),
            message: sprintf('La politique de sécurité %s « %s »%s.', PolitiqueCsp::APPLIQUEE ? 'a bloqué' : '(en observation) aurait bloqué', $v['ressource'], null !== $v['extrait'] ? ' — extrait : ' . $v['extrait'] : ''),
            fichier: $v['fichier'],
            ligne: $v['ligne'],
            contexte: [
                'url' => $v['page'],
                'navigateur' => self::texte($navigateur, 300),
                'utilisateur' => $utilisateur?->getUserIdentifier(),
                'cabinet' => $utilisateur instanceof Utilisateur ? $utilisateur->getConnectedTo()?->getNom() : null,
            ],
            alerter: false,
        );
    }

    private static function texte(mixed $valeur, int $max): ?string
    {
        if (!\is_string($valeur) || '' === trim($valeur)) {
            return null;
        }

        return mb_strlen($valeur) > $max ? mb_substr($valeur, 0, $max - 1) . '…' : $valeur;
    }

    /** Un numéro de ligne plausible, ou rien : un nombre hors de la colonne fermerait l'EntityManager. */
    private static function entier(mixed $valeur): ?int
    {
        if (!is_numeric($valeur)) {
            return null;
        }
        $nombre = (float) $valeur;

        return $nombre >= 0 && $nombre <= 2147483647 ? (int) $nombre : null;
    }
}
