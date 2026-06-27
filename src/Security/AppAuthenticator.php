<?php

namespace App\Security;

use App\Entity\Utilisateur;
use App\Repository\EntrepriseRepository;
use App\Repository\InviteRepository;
use App\Services\InvitationLinker;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authenticator\AbstractLoginFormAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\SecurityRequestAttributes;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

class AppAuthenticator extends AbstractLoginFormAuthenticator
{
    use TargetPathTrait;

    public const LOGIN_ROUTE = 'app_login';

    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private InvitationLinker $invitationLinker,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
    ) {}

    public function authenticate(Request $request): Passport
    {
        $email = $request->getPayload()->getString('email');

        $request->getSession()->set(SecurityRequestAttributes::LAST_USERNAME, $email);

        return new Passport(
            new UserBadge($email),
            new PasswordCredentials($request->getPayload()->getString('password')),
            [
                new CsrfTokenBadge('authenticate', $request->getPayload()->getString('_csrf_token')),
                new RememberMeBadge(),
            ]
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        /** @var Utilisateur $user */
        $user = $token->getUser();

        // Filet de sécurité : rattache d'éventuelles invitations en attente portant
        // l'email de l'utilisateur (cas où il s'est inscrit avant d'être invité, ou
        // invité via une autre voie). Idempotent et sans effet si rien n'est en attente.
        if ($user instanceof Utilisateur) {
            $this->invitationLinker->linkPendingInvitations($user);
        }

        // Point de décision UNIQUE après authentification.
        // Un utilisateur dont l'adresse e-mail n'est pas encore vérifiée est confiné
        // au parcours de re-vérification : on ignore volontairement le target path pour
        // ne pas le laisser entrer sur une page protégée tant qu'il n'est pas vérifié.
        if ($user instanceof Utilisateur && !$user->isVerified()) {
            return new RedirectResponse($this->urlGenerator->generate('app_reverify_email'));
        }

        $estAgent = in_array('ROLE_ADMIN', $user->getRoles(), true) || in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true);

        // Onboarding self-service : un courtier vérifié qui n'a encore NI entreprise
        // possédée NI invitation n'a aucun espace exploitable. On le conduit directement
        // à l'assistant de création de sa première entreprise — avant même de respecter
        // un éventuel target path, qui pointerait de toute façon vers une page vide.
        // Un invité (qui dispose d'une invitation) garde son accès direct à son espace.
        // Comptage en base (fiable, indépendant de l'hydratation des collections inverses).
        if (!$estAgent
            && $this->entrepriseRepository->count(['utilisateur' => $user]) === 0
            && $this->inviteRepository->count(['utilisateur' => $user]) === 0) {
            return new RedirectResponse($this->urlGenerator->generate('app_onboarding.index'));
        }

        // Utilisateur vérifié : on respecte la page initialement demandée, sinon l'accueil applicatif.
        if ($targetPath = $this->getTargetPath($request->getSession(), $firewallName)) {
            return new RedirectResponse($targetPath);
        }

        // Les agents JS Brokers (ROLE_ADMIN/ROLE_SUPER_ADMIN) atterrissent sur la
        // Console d'administration ; les utilisateurs/clients sur leur espace.
        if ($estAgent) {
            return new RedirectResponse($this->urlGenerator->generate('console.dashboard'));
        }

        return new RedirectResponse($this->urlGenerator->generate('admin.entreprise.index'));
    }

    protected function getLoginUrl(Request $request): string
    {
        return $this->urlGenerator->generate(self::LOGIN_ROUTE);
    }
}
