<?php

namespace App\Tests\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use PHPUnit\Framework\TestCase;

/** Real HTTP through nginx and PHP-FPM, with the app mounted at /wien/. */
final class WienTest extends TestCase
{
    private HttpClientInterface $http;

    protected function setUp(): void
    {
        $this->http = HttpClient::create(['max_redirects' => 0]);
    }

    private function get(string $path): ResponseInterface
    {
        return $this->http->request('GET', (string) getenv('HTTP_BASE_URL').$path);
    }

    public function testFirstPageLinksAndAssetsStayBelowWien(): void
    {
        $response = $this->get('/');
        $html = $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<h1>Redeemer</h1>', $html);
        self::assertStringContainsString('href="/wien/assets/redeemer.css"', $html);
        self::assertStringContainsString('href="/wien/ueber"', $html);
        self::assertStringContainsString('href="/wien/"', $html);
        self::assertDoesNotMatchRegularExpression('#(?:href|src)="/(?!wien/)#', $html);
    }

    public function testLinkedPageAndAssetAreServed(): void
    {
        $about = $this->get('/ueber');
        self::assertSame(200, $about->getStatusCode());
        self::assertStringContainsString('Über diesen Stand', $about->getContent());

        $css = $this->get('/assets/redeemer.css');
        self::assertSame(200, $css->getStatusCode());
        self::assertStringContainsString('text/css', $css->getHeaders()['content-type'][0]);
    }

    #[DataProvider('hiddenPaths')]
    public function testFilesOutsideWebrootAreNotServed(string $path): void
    {
        self::assertSame(404, $this->get($path)->getStatusCode(), $path);
    }

    /** @return iterable<string, array{string}> */
    public static function hiddenPaths(): iterable
    {
        foreach (['/composer.json', '/composer.lock', '/vendor/autoload.php', '/config/services.yaml', '/src/Kernel.php', '/templates/base.html.twig', '/bin/console', '/index.php', '/assets/../composer.json', '/assets/%2e%2e/composer.json'] as $path) {
            yield $path => [$path];
        }
    }

    public function testNothingIsServedOutsideWien(): void
    {
        $base = preg_replace('#/wien$#', '', (string) getenv('HTTP_BASE_URL'));

        foreach (['/composer.json', '/vendor/autoload.php', '/index.php', '/assets/redeemer.css'] as $path) {
            self::assertSame(404, $this->http->request('GET', $base.$path)->getStatusCode(), $path);
        }
    }
}
