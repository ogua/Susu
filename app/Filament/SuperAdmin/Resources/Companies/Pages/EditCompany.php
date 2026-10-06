<?php

namespace App\Filament\SuperAdmin\Resources\Companies\Pages;

use App\Actions\Company\SetCompanyActiveStatusAction;
use App\Filament\SuperAdmin\Resources\Companies\CompanyResource;
use App\Models\Company;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class EditCompany extends EditRecord
{
    protected static string $resource = CompanyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }

    /**
     * Flipping "Active" here goes through SetCompanyActiveStatusAction, so a
     * suspension from this form also revokes the company's API tokens.
     *
     * @param  Company  $record
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $record->update(Arr::except($data, ['is_active']));

        if (array_key_exists('is_active', $data) && (bool) $data['is_active'] !== $record->is_active) {
            app(SetCompanyActiveStatusAction::class)->execute($record, (bool) $data['is_active']);
        }

        return $record;
    }
}
