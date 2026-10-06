<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The Ogua product family — OguSes IT Solutions and its sibling products —
 * read from the company site's /products.json feed (schema v1).
 *
 * Page requests only ever read the cache (or the bundled config fallback);
 * the network is touched solely by refresh(), run daily by the
 * `ogua-family:refresh` command. A failed or malformed fetch keeps whatever
 * was cached before, so a feed outage never empties the links.
 *
 * Every URL is filtered to http(s) on the way in: the feed is third-party
 * input as far as this app is concerned, and a `javascript:` href would
 * survive Blade's escaping.
 */
class OguaFamily
{
    public const CACHE_KEY = 'ogua-family.feed';

    public const SCHEMA = 1;

    /**
     * @return array{name: string, url: string, tagline: string|null, email: string|null, phones: list<string>, address: string|null}
     */
    public function company(): array
    {
        return $this->feed()['company'];
    }

    /**
     * Sibling products, in feed order. This product is left out unless asked for.
     *
     * @return list<array{slug: string, name: string, category: string|null, tagline: string|null, accent_color: string|null, accent_text_color: string|null, website_url: string|null, login_url: string|null, company_page_url: string|null, status: string, href: string|null}>
     */
    public function products(bool $includeCurrent = false): array
    {
        $current = config('ogua_family.current');

        return array_values(array_filter(
            $this->feed()['products'],
            fn (array $product): bool => $includeCurrent || $product['slug'] !== $current,
        ));
    }

    /** Pulls the feed and caches it; false (cache untouched) on any failure. */
    public function refresh(): bool
    {
        try {
            $response = Http::acceptJson()
                ->timeout(5)
                ->retry(2, 500, throw: false)
                ->get(config('ogua_family.feed_url'));
        } catch (Throwable) {
            return false;
        }

        if (! $response->successful()) {
            return false;
        }

        $feed = self::normalize($response->json());

        if ($feed === null) {
            return false;
        }

        Cache::forever(self::CACHE_KEY, $feed);

        return true;
    }

    /**
     * Validates a raw feed and reduces it to the shape this app uses, or null
     * when it isn't a schema-v1 feed. Malformed products are skipped rather
     * than failing the whole feed.
     *
     * @return array{company: array<string, mixed>, products: list<array<string, mixed>>}|null
     */
    public static function normalize(mixed $feed): ?array
    {
        if (! is_array($feed) || ($feed['schema'] ?? null) !== self::SCHEMA) {
            return null;
        }

        $company = $feed['company'] ?? null;
        $products = $feed['products'] ?? null;

        if (! is_array($company) || ! is_array($products) || ! self::isText($company['name'] ?? null)) {
            return null;
        }

        $companyUrl = self::url($company['url'] ?? null);

        if ($companyUrl === null) {
            return null;
        }

        $normalized = [];

        foreach ($products as $product) {
            if (! is_array($product) || ! self::isText($product['slug'] ?? null) || ! self::isText($product['name'] ?? null)) {
                continue;
            }

            $websiteUrl = self::url($product['website_url'] ?? null);
            $companyPageUrl = self::url($product['company_page_url'] ?? null);

            $normalized[] = [
                'slug' => $product['slug'],
                'name' => $product['name'],
                'category' => self::text($product['category'] ?? null),
                'tagline' => self::text($product['tagline'] ?? null),
                'accent_color' => self::color($product['accent_color'] ?? null),
                'accent_text_color' => self::color($product['accent_text_color'] ?? null),
                'website_url' => $websiteUrl,
                'login_url' => self::url($product['login_url'] ?? null),
                'company_page_url' => $companyPageUrl,
                'status' => ($product['status'] ?? null) === 'live' ? 'live' : 'coming_soon',
                'href' => $websiteUrl ?? $companyPageUrl,
            ];
        }

        return [
            'company' => [
                'name' => $company['name'],
                'url' => $companyUrl,
                'tagline' => self::text($company['tagline'] ?? null),
                'email' => filter_var($company['email'] ?? null, FILTER_VALIDATE_EMAIL) ?: null,
                'phones' => array_values(array_filter($company['phones'] ?? [], self::isText(...))),
                'address' => self::text($company['address'] ?? null),
            ],
            'products' => $normalized,
        ];
    }

    /**
     * @return array{company: array<string, mixed>, products: list<array<string, mixed>>}
     */
    private function feed(): array
    {
        $cached = Cache::get(self::CACHE_KEY);

        if (is_array($cached)) {
            return $cached;
        }

        return self::normalize(config('ogua_family.fallback'))
            ?? ['company' => ['name' => 'Oguses IT Solutions', 'url' => 'https://ogusesitsolutions.com', 'tagline' => null, 'email' => null, 'phones' => [], 'address' => null], 'products' => []];
    }

    private static function isText(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    private static function text(mixed $value): ?string
    {
        return self::isText($value) ? trim($value) : null;
    }

    private static function url(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('#^https?://#i', $value)) {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_URL) ?: null;
    }

    private static function color(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^#[0-9a-f]{6}$/i', $value) ? $value : null;
    }
}
