<?php

namespace App\Http\Requests\Api\V1;

use App\Actions\Company\UpdateCompanyIntegrationSettingsAction;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCompanyIntegrationSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->user()->company);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return UpdateCompanyIntegrationSettingsAction::rules();
    }
}
