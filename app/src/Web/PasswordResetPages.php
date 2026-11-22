<?php

namespace App\Web;

use App\Member\MemberAccounts;
use App\Member\Outcome;
use App\Member\Status;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

/** `?section=pwdreset` for anonymous visitors. Same cookie-plus-field CSRF scheme as the login form. */
final readonly class PasswordResetPages
{
    private const TOKEN = 'password_reset';
    private const COOKIE = 'CSRFreset';
    private const FIELD = 'CSRFtoken';

    public function __construct(
        private Environment $twig,
        private CsrfTokenManagerInterface $csrf,
        private MemberAccounts $accounts,
        private bool $secureCookie,
        private string $cookiePath,
    ) {
    }

    public function handle(Request $request): Response
    {
        if (!$request->isMethod('POST')) {
            return $this->form($request, null, Response::HTTP_OK);
        }
        if ('reset' !== $request->query->get('action')) {
            return new Response('Unknown action.', Response::HTTP_NOT_FOUND);
        }

        $post = $request->request->all();
        $field = $post[self::FIELD] ?? null;
        $cookie = $request->cookies->get(self::COOKIE);
        if (!is_string($field) || !is_string($cookie)) {
            return new Response('Reset method error - please reload the page and try again.', Response::HTTP_NOT_ACCEPTABLE, ['Cache-Control' => 'private, no-store']);
        }
        if (!hash_equals($cookie, $field) || !$this->csrf->isTokenValid(new CsrfToken(self::TOKEN, $field))) {
            return new Response('Reset method error - please reload the page and try again.', Response::HTTP_UNAUTHORIZED, ['Cache-Control' => 'private, no-store']);
        }

        $nickname = $post['nickname'] ?? '';
        $email = $post['email'] ?? '';
        $outcome = is_string($nickname) && is_string($email)
            ? $this->accounts->resetPassword($nickname, $email)
            : Outcome::rejected('Ungültige Eingabe.');
        if ($outcome->isDone()) {
            $this->csrf->removeToken(self::TOKEN);
        }

        return $this->form($request, $outcome, match ($outcome->status) {
            Status::Done => Response::HTTP_OK,
            Status::Rejected => Response::HTTP_UNPROCESSABLE_ENTITY,
            default => Response::HTTP_BAD_GATEWAY,
        });
    }

    private function form(Request $request, ?Outcome $outcome, int $status): Response
    {
        $token = $this->csrf->getToken(self::TOKEN)->getValue();
        $nickname = $request->query->get('nickname');
        $response = new Response($this->twig->render('reset.html.twig', [
            'token' => $token,
            'nickname' => is_string($nickname) ? $nickname : null,
            'outcome' => $outcome,
        ]), $status, ['Cache-Control' => 'private, no-store']);
        $response->headers->setCookie(new Cookie(self::COOKIE, $token, time() + 300, $this->cookiePath, null, $this->secureCookie, true, false, Cookie::SAMESITE_STRICT));

        return $response;
    }
}
