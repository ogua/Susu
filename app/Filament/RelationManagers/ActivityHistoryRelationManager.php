<?php

namespace App\Filament\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Spatie\Activitylog\Models\Activity;

/**
 * Read-only audit trail for any record that uses Spatie's LogsActivity trait
 * (its `activities` morph relation). Shared by the customer and loan pages.
 */
class ActivityHistoryRelationManager extends RelationManager
{
    protected static string $relationship = 'activities';

    protected static ?string $title = 'Activity History';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime()->sortable(),
                TextColumn::make('description')->label('Activity')->wrap(),
                TextColumn::make('event')->badge()->color('gray'),
                TextColumn::make('causer.name')->label('By')->placeholder('System'),
                TextColumn::make('changes')
                    ->label('Changes')
                    ->state(fn (Activity $record): string => self::summarizeChanges($record))
                    ->wrap()
                    ->limit(160),
            ])
            ->modifyQueryUsing(fn ($query) => $query->with('causer'))
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No activity recorded yet');
    }

    private static function summarizeChanges(Activity $activity): string
    {
        $properties = $activity->properties?->toArray() ?? [];
        $new = $properties['attributes'] ?? [];
        $old = $properties['old'] ?? [];

        if ($new === []) {
            return collect($properties)
                ->reject(fn ($value): bool => $value === null || is_array($value))
                ->map(fn ($value, string $key): string => str_replace('_', ' ', $key).': '.$value)
                ->implode(', ');
        }

        return collect($new)
            ->map(fn ($value, string $key): string => str_replace('_', ' ', $key).': '
                .(array_key_exists($key, $old) ? self::display($old[$key]).' → ' : '')
                .self::display($value))
            ->implode(', ');
    }

    private static function display(mixed $value): string
    {
        return match (true) {
            $value === null => '—',
            is_bool($value) => $value ? 'yes' : 'no',
            is_array($value) => json_encode($value),
            default => (string) $value,
        };
    }
}
