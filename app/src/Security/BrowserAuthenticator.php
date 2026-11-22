<?php

namespace App\Security;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DatabaseException;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\Ldap\Exception\InvalidCredentialsException;
use Symfony\Component\Ldap\Ldap;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

final class BrowserAuthenticator extends AbstractAuthenticator
{
    private const TOKEN = 'browser_login';
    private const FIELD = 'CSRFtoken';

    public function __construct(
        private readonly Ldap $ldap,
        private readonly MemberProvider $members,
        private readonly Connection $db,
        private readonly CsrfTokenManagerInterface $csrf,
        private readonly TokenStorageInterface $tokens,
        private readonly string $baseDn,
        private readonly string $serviceDn,
        #[\SensitiveParameter] private readonly string $servicePassword,
        private readonly bool $secureCookie,
        private readonly string $cookiePath,
    ) {
    }

    public function supports(Request $request): bool
    {
        $form = $request->request->all();

        return $request->isMethod('POST') && 'app_home' === $request->attributes->get('_route')
            && !$request->query->has('logoff')
            && !$this->tokens->getToken()?->getUser() instanceof MemberIdentity
            && isset($form['nickname'], $form['password']);
    }

    public function authenticate(Request $request): Passport
    {
        if ($this->tokens->getToken()?->getUser() instanceof MemberIdentity) {
            throw new AccessDeniedHttpException();
        }

        $form = $request->request->all();
        $token = $form[self::FIELD] ?? null;
        $cookie = $request->cookies->get(self::FIELD);
        if (!is_string($token) || !is_string($cookie) || !hash_equals($cookie, $token)
            || !$this->csrf->isTokenValid(new CsrfToken(self::TOKEN, $token))) {
            throw new InvalidCsrfTokenException();
        }

        $nickname = $form['nickname'] ?? null;
        $password = $form['password'] ?? null;
        if (!is_string($nickname) || '' === $nickname || !is_string($password) || '' === $password
            || str_contains($password, "\0")) {
            throw new BadCredentialsException();
        }

        if ('' === $this->serviceDn || '' === $this->servicePassword) {
            throw new ServiceUnavailableHttpException(message: 'LDAP service credentials are not configured.');
        }
        $this->ldap->bind($this->serviceDn, $this->servicePassword);
        $filter = '(&(objectClass=posixAccount)(uid='.$this->ldap->escape($nickname, '', LDAP_ESCAPE_FILTER).'))';
        $matches = $this->ldap->query($this->baseDn, $filter, ['filter' => ['uidNumber'], 'maxItems' => 2])->execute()->toArray();
        $uids = count($matches) === 1 ? $matches[0]->getAttribute('uidNumber', false) : null;
        if (null === $uids || 1 !== count($uids) || !ctype_digit($uids[0])) {
            throw new BadCredentialsException();
        }

        try {
            $this->ldap->bind($matches[0]->getDn(), $password);
        } catch (InvalidCredentialsException) {
            throw new BadCredentialsException();
        }

        $user = $this->members->loadUserByIdentifier($uids[0]);
        if (preg_match('/[\x00-\x1f\x7f]/', $user->nickname)) {
            throw new BadCredentialsException();
        }

        return new SelfValidatingPassport(new UserBadge($user->getUserIdentifier(), static fn () => $user));
    }

    public function loginCookie(): Cookie
    {
        return new Cookie(self::FIELD, $this->csrf->getToken(self::TOKEN)->getValue(), time() + 300, $this->cookiePath, null, $this->secureCookie, true, false, Cookie::SAMESITE_STRICT);
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): Response
    {
        $user = $token->getUser();
        if (!$user instanceof MemberIdentity) {
            throw new \LogicException('Expected a Redeemer member.');
        }

        try {
            $this->db->executeQuery('SELECT member_login(lower(?))', [$user->nickname]);
        } catch (DatabaseException $exception) {
            $this->tokens->setToken(null);
            throw $exception;
        }

        $this->csrf->removeToken(self::TOKEN);
        $base = $request->getBasePath().'/';
        $uri = $request->getRequestUri();
        $path = explode('?', $uri, 2)[0];
        if (str_starts_with($uri, '//') || !in_array($path, [$base, $base.'index.php'], true)) {
            $uri = $base;
        }
        $response = new RedirectResponse($uri, Response::HTTP_MOVED_PERMANENTLY, [
            'X-Login-OK' => sprintf('User=%s; Id=%d', $user->nickname, $user->originalId),
            'Cache-Control' => 'private, no-store',
        ]);
        $response->headers->clearCookie(self::FIELD, $this->cookiePath, null, $this->secureCookie, true, Cookie::SAMESITE_STRICT);

        return $response;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        if ($exception instanceof InvalidCsrfTokenException) {
            $missing = !$request->request->has(self::FIELD) || !$request->cookies->has(self::FIELD);

            return new Response('Login method error - please reload the page and try again.', $missing ? 406 : 401, ['Cache-Control' => 'private, no-store']);
        }

        $request->attributes->set('browser_login_failed', true);

        return null;
    }
}
