<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class InitializeCheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'savings_account_id' => ['required', 'uuid'],
            'amount' => ['required', 'integer', 'min:1'],
            'callback_url' => ['required', 'url'],
            'client_reference' => ['nullable', 'uuid'],
        ];
    }
}
