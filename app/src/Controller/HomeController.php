<?php

namespace App\Controller;

use App\Infrastructure\MemberDashboard;
use App\Security\BrowserAuthenticator;
use App\Security\MemberIdentity;
use App\Web\MemberPages;
use App\Web\PasswordResetPages;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home', methods: ['GET', 'POST'])]
    public function home(Request $request, BrowserAuthenticator $login, Security $security, Connection $db, UrlGeneratorInterface $urls, MemberDashboard $dashboard, MemberPages $memberPages, PasswordResetPages $passwordReset): Response
    {
        $urls->getContext()->setBaseUrl($request->getBasePath());
        $query = $request->query->all();
        if ('true' === ($query['logoff'] ?? null)) {
            if (!$request->isMethod('POST')) {
                throw new MethodNotAllowedHttpException(['POST']);
            }
            $this->denyAccessUnlessGranted('ROLE_MEMBER');
            $token = $request->request->all()['_csrf_token'] ?? null;
            if (!is_string($token) || !$this->isCsrfTokenValid('browser_logout', $token)) {
                throw $this->createAccessDeniedException('Invalid logout token.');
            }
            $security->logout(false);
            $request->getSession()->invalidate();

            return $this->redirectToRoute('app_home', status: Response::HTTP_SEE_OTHER);
        }

        $member = $this->getUser();
        if (!$member instanceof MemberIdentity) {
            if ('pwdreset' === ($query['section'] ?? null)) {
                return $passwordReset->handle($request);
            }
            $cookie = $login->loginCookie();
            $failed = $request->attributes->getBoolean('browser_login_failed');
            $response = $this->render('login.html.twig', ['token' => $cookie->getValue(), 'failed' => $failed]);
            $response->headers->setCookie($cookie);
            $response->headers->set('Cache-Control', 'private, no-store');
            if ($failed) {
                $response->headers->set('X-Login-Failed', 'User/Password error');
            }

            return $response;
        }

        $this->denyAccessUnlessGranted('ROLE_MEMBER');
        $section = $query['section'] ?? '';
        if ('member' === $section) {
            return $memberPages->handle($request, $member);
        }
        if ($request->isMethod('POST')) {
            throw new MethodNotAllowedHttpException(['GET', 'HEAD']);
        }
        if ('' !== $section) {
            throw $this->createNotFoundException();
        }

        $data = $db->fetchAssociative(
            'SELECT nickname, firstname, lastname, street, housenumber, zip, town, email, telephone, mobilephone, fax, homepage, instant_messenger_nick FROM members WHERE id = ?',
            [$member->effectiveId],
        );
        if (false === $data) {
            throw $this->createNotFoundException();
        }

        $response = $this->render('home.html.twig', ['member' => $data] + $dashboard->read($member->effectiveId));
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    #[Route('/ueber', name: 'app_about', methods: ['GET'])]
    public function about(): Response
    {
        $response = $this->render('about.html.twig');
        if ($this->isGranted('ROLE_MEMBER')) {
            $response->headers->set('Cache-Control', 'private, no-store');
        }

        return $response;
    }
}
