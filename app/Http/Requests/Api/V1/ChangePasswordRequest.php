<?php

namespace App\Http\Requests\Api\V1;

use App\Actions\Staff\ChangeOwnPasswordAction;
use Illuminate\Foundation\Http\FormRequest;

class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return ChangeOwnPasswordAction::rules();
    }
}
