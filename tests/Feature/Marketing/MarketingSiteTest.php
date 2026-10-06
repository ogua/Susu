<?php

use App\Enums\AppPlatform;
use App\Models\AppVersion;
use App\Models\Company;
use App\Models\Plan;

it('serves the public home page with the product, pricing and family links', function (): void {
    Plan::factory()->create(['name' => 'Collector Plan', 'price_amount' => 150_00, 'max_branches' => 2, 'sort' => 1]);
    Plan::factory()->create(['name' => 'Retired Plan', 'is_active' => false]);

    $this->get('/')
        ->assertOk()
        ->assertSee('OguaFinance')
        ->assertSee('Collector Plan')
        ->assertSee('GHS 150.00')
        ->assertSee('2 branches')
        ->assertSee('Unlimited customers')
        ->assertDontSee('Retired Plan')
        ->assertDontSee('Talk to us about pricing')
        ->assertSee('https://pos.oguaschoolz.com', false)
        ->assertSee('https://oguaschoolz.com', false)
        ->assertSee('https://oguachurch.oguaschoolz.com', false)
        ->assertSee('https://oguacareplus.com', false)
        ->assertSee('https://ogusesitsolutions.com', false);
});

it('falls back to a talk-to-sales card when no plan is on sale', function (): void {
    $this->get('/')->assertOk()->assertSee('Talk to us about pricing');
});

it('only links app downloads once a release is published', function (): void {
    config(['app_updates.store_url_android' => 'https://play.google.com/store/apps/details?id=example']);

    $this->get('/')->assertDontSee('https://play.google.com/store/apps/details?id=example', false);

    AppVersion::factory()->create([
        'platform' => AppPlatform::Android,
        'version' => '1.0.0',
        'version_code' => 1,
        'is_active' => true,
        'store_url' => null,
    ]);

    $this->get('/')->assertSee('https://play.google.com/store/apps/details?id=example', false);
});

it('sends a company custom domain to the staff login instead of the marketing page', function (): void {
    Company::factory()->create(['domain_alias' => 'acme.susuapp.test', 'is_active' => true]);

    $this->get('http://acme.susuapp.test/')
        ->assertRedirect(filament()->getPanel('admin')->getLoginUrl());
});

it('still shows the marketing page on an inactive company domain', function (): void {
    Company::factory()->create(['domain_alias' => 'gone.susuapp.test', 'is_active' => false]);

    $this->get('http://gone.susuapp.test/')->assertOk()->assertSee('Every cedi collected');
});

it('serves the legal pages, sitemap and robots file', function (): void {
    $this->get('/privacy')->assertOk()->assertSee('Data Protection Act');
    $this->get('/terms')->assertOk()->assertSee('Terms of service');

    $this->get('/sitemap.xml')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/xml')
        ->assertSee(route('marketing.demo'), false)
        ->assertSee(route('marketing.privacy'), false);

    $this->get('/robots.txt')
        ->assertOk()
        ->assertSee('Sitemap: '.route('marketing.sitemap'), false)
        ->assertSee('Disallow: /super-admin', false);
});

it('brands the desktop licence pages as OguaFinance', function (): void {
    $this->get('/license/activate')
        ->assertOk()
        ->assertSee('Activate OguaFinance Desktop')
        ->assertDontSee('SusuApp');
});
