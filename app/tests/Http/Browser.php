<?php

namespace App\Tests\Http;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/** A cookie-keeping HTTP client against the stack; never follows redirects. */
final class Browser
{
    private HttpClientInterface $http;
    /** @var array<string, string> */
    private array $cookies = [];

    public function __construct()
    {
        $this->http = HttpClient::create(['max_redirects' => 0]);
    }

    /** @param array<string, mixed> $body */
    public function request(string $method, string $path, array $body = []): ResponseInterface
    {
        $cookie = [];
        foreach ($this->cookies as $name => $value) {
            $cookie[] = $name.'='.$value;
        }
        $response = $this->http->request($method, (string) getenv('HTTP_BASE_URL').$path, [
            'headers' => ['Cookie' => implode('; ', $cookie)],
            'body' => $body,
        ]);
        $response->getStatusCode();
        foreach ($response->getHeaders(false)['set-cookie'] ?? [] as $line) {
            [$name, $value] = explode('=', explode(';', $line, 2)[0], 2);
            if ('deleted' === $value) {
                unset($this->cookies[$name]);
            } else {
                $this->cookies[$name] = $value;
            }
        }

        return $response;
    }

    public function get(string $path): ResponseInterface
    {
        return $this->request('GET', $path);
    }

    /** @param array<string, mixed> $body */
    public function post(string $path, array $body = []): ResponseInterface
    {
        return $this->request('POST', $path, $body);
    }

    public function login(string $nickname, string $password = 'rewrite-test-only'): ResponseInterface
    {
        $page = $this->get('/');
        preg_match('/name="CSRFtoken" value="([^"]+)"/', $page->getContent(), $match);

        return $this->post('/', ['nickname' => $nickname, 'password' => $password, 'CSRFtoken' => $match[1] ?? '']);
    }

    public function cookie(string $name): ?string
    {
        return $this->cookies[$name] ?? null;
    }

    /** Returns the CSRF token of the form marked with data-form="$form". */
    public static function token(string $html, string $form): string
    {
        $pattern = '/<form[^>]*data-form="'.preg_quote($form, '/').'"[^>]*>.*?name="_csrf_token" value="([^"]+)"/s';
        if (1 !== preg_match($pattern, $html, $match)) {
            throw new \RuntimeException('No form '.$form.' with a CSRF token on the page.');
        }

        return $match[1];
    }

    /** Fetches $path and returns the CSRF token of the form marked with data-form="$form". */
    public function tokenFrom(string $path, string $form): string
    {
        return self::token($this->get($path)->getContent(false), $form);
    }
}
