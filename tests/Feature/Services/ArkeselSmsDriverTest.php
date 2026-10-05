<?php

use App\Models\Company;
use App\Models\CompanySmsSetting;
use App\Services\Sms\ArkeselSmsDriver;
use App\Services\Sms\SmsService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config(['services.arkesel.base_url' => 'https://sms.oguaschoolz.com']);

    $this->company = Company::factory()->create();
    CompanySmsSetting::create([
        'company_id' => $this->company->id,
        'provider' => 'arkesel',
        'sender_id' => 'MySusu',
        'api_key' => 'arkesel-key',
        'notifications_enabled' => true,
        'quiet_hours_start' => '21:00',
        'quiet_hours_end' => '07:00',
    ]);
});

it('sends through Arkesel with the company key and sender ID', function (): void {
    Http::fake(['https://sms.oguaschoolz.com/api/v2/sms/send' => Http::response([
        'status' => 'success',
        'data' => [['recipient' => '233244000111', 'id' => 'msg-123']],
    ])]);

    $reference = app(SmsService::class)->send($this->company, '024 400 0111', 'Deposit received.');

    expect($reference)->toBe('msg-123');
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('api-key', 'arkesel-key')
        && $request['sender'] === 'MySusu'
        && $request['recipients'] === ['233244000111']
        && $request['message'] === 'Deposit received.');
});

it('throws when Arkesel reports a failure', function (): void {
    Http::fake(['https://sms.oguaschoolz.com/*' => Http::response(['status' => 'error', 'message' => 'Insufficient balance'], 200)]);

    app(SmsService::class)->send($this->company, '0244000111', 'Hello');
})->throws(RuntimeException::class, 'Insufficient balance');

it('normalizes Ghana numbers to the 233 format', function (string $input, ?string $expected): void {
    expect(ArkeselSmsDriver::normalizePhone($input))->toBe($expected);
})->with([
    ['0244000111', '233244000111'],
    ['+233 24 400 0111', '233244000111'],
    ['233244000111', '233244000111'],
    ['12345', null],
]);
