<?php

use App\Services\OguaFamily;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

function oguaFamilyFeed(array $overrides = []): array
{
    return array_replace_recursive(config('ogua_family.fallback'), $overrides);
}

beforeEach(function (): void {
    config(['ogua_family.feed_url' => 'https://ogusesitsolutions.test/products.json']);
});

it('falls back to the bundled snapshot when nothing is cached', function (): void {
    $slugs = array_column(app(OguaFamily::class)->products(), 'slug');

    expect($slugs)->toBe(['oguaschoolz', 'oguapos', 'oguachurch', 'oguacare'])
        ->and(app(OguaFamily::class)->company()['url'])->toBe('https://ogusesitsolutions.com');
});

it('leaves this product out unless asked for', function (): void {
    $family = app(OguaFamily::class);

    expect(array_column($family->products(), 'slug'))->not->toContain('oguafinance')
        ->and(array_column($family->products(includeCurrent: true), 'slug'))->toContain('oguafinance');
});

it('caches a valid feed on refresh', function (): void {
    $feed = oguaFamilyFeed();
    $feed['products'][0]['name'] = 'OguaSchoolz Renamed';
    Http::fake(['ogusesitsolutions.test/*' => Http::response($feed)]);

    expect(app(OguaFamily::class)->refresh())->toBeTrue()
        ->and(app(OguaFamily::class)->products()[0]['name'])->toBe('OguaSchoolz Renamed');

    $this->artisan('ogua-family:refresh')->assertSuccessful();
});

it('keeps the previous cache when the feed fails or has an unknown schema', function (array|int $response): void {
    Cache::forever(OguaFamily::CACHE_KEY, OguaFamily::normalize(oguaFamilyFeed(['company' => ['name' => 'Cached Co']])));
    Http::fake(['ogusesitsolutions.test/*' => is_int($response) ? Http::response('down', $response) : Http::response($response)]);

    expect(app(OguaFamily::class)->refresh())->toBeFalse()
        ->and(app(OguaFamily::class)->company()['name'])->toBe('Cached Co');

    $this->artisan('ogua-family:refresh')->assertFailed();
})->with([
    'server error' => [500],
    'unknown schema' => [['schema' => 2, 'company' => [], 'products' => []]],
    'not a feed' => [['hello' => 'world']],
]);

it('drops non-http links and malformed products from the feed', function (): void {
    $feed = oguaFamilyFeed();
    $feed['products'][0]['website_url'] = 'javascript:alert(1)';
    $feed['products'][0]['company_page_url'] = 'javascript:alert(2)';
    $feed['products'][1]['accent_color'] = 'red;background:url(x)';
    $feed['products'][] = ['slug' => '', 'name' => 'Nameless'];
    $feed['products'][] = 'not-an-array';

    $products = OguaFamily::normalize($feed)['products'];

    expect($products)->toHaveCount(5)
        ->and($products[0]['website_url'])->toBeNull()
        ->and($products[0]['href'])->toBeNull()
        ->and($products[1]['accent_color'])->toBeNull();
});

it('renders the family links, skipping products without a usable link', function (): void {
    $feed = oguaFamilyFeed();
    $feed['products'][0]['website_url'] = 'javascript:alert(1)';
    $feed['products'][0]['company_page_url'] = null;
    Cache::forever(OguaFamily::CACHE_KEY, OguaFamily::normalize($feed));

    $html = (string) $this->blade('<ul><x-ogua-family.links with-company show-category /></ul>');

    expect($html)
        ->not->toContain('OguaSchoolz')
        ->not->toContain('javascript:')
        ->toContain('https://pos.oguaschoolz.com')
        ->toContain('Hospital &amp; clinic management')
        ->toContain('https://ogusesitsolutions.com')
        ->not->toContain('finance.oguaschoolz.com');
});
