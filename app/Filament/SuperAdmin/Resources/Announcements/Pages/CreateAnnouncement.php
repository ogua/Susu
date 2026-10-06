<?php

namespace App\Filament\SuperAdmin\Resources\Announcements\Pages;

use App\Actions\Platform\PublishAnnouncementAction;
use App\Filament\SuperAdmin\Resources\Announcements\AnnouncementResource;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateAnnouncement extends CreateRecord
{
    protected static string $resource = AnnouncementResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        /** @var User|null $author */
        $author = Filament::auth()->user();

        return app(PublishAnnouncementAction::class)->execute(
            $data,
            $author,
            (bool) ($this->data['notify_now'] ?? false),
        );
    }
}
