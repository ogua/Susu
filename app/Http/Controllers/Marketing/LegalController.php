<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use Illuminate\View\View;

class LegalController extends Controller
{
    public function privacy(): View
    {
        return view('marketing.legal.privacy');
    }

    public function terms(): View
    {
        return view('marketing.legal.terms');
    }

    /** Dynamic so the Sitemap line always carries this deployment's own domain. */
    public function robots(): Response
    {
        $lines = [
            'User-agent: *',
            'Disallow: /admin',
            'Disallow: /super-admin',
            '',
            'Sitemap: '.route('marketing.sitemap'),
        ];

        return response(implode("\n", $lines)."\n")->header('Content-Type', 'text/plain');
    }

    public function sitemap(): Response
    {
        $urls = [
            ['loc' => route('marketing.home'), 'priority' => '1.0'],
            ['loc' => route('marketing.demo'), 'priority' => '0.8'],
            ['loc' => route('marketing.privacy'), 'priority' => '0.3'],
            ['loc' => route('marketing.terms'), 'priority' => '0.3'],
        ];

        return response()
            ->view('marketing.sitemap', ['urls' => $urls])
            ->header('Content-Type', 'application/xml');
    }
}
