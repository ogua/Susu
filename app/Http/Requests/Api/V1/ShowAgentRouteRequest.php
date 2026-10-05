<?php

namespace App\Http\Requests\Api\V1;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

class ShowAgentRouteRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var User $agent */
        $agent = $this->route('agent');

        return $this->user()->can('trackRoute', $agent);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
        ];
    }

    public function routeDate(): CarbonImmutable
    {
        return $this->filled('date')
            ? CarbonImmutable::createFromFormat('Y-m-d', $this->string('date')->value())->startOfDay()
            : CarbonImmutable::today();
    }
}
