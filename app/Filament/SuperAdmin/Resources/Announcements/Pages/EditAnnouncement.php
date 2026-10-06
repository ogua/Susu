<?php

namespace App\Filament\SuperAdmin\Resources\Announcements\Pages;

use App\Filament\SuperAdmin\Resources\Announcements\AnnouncementResource;
use Filament\Resources\Pages\EditRecord;

class EditAnnouncement extends EditRecord
{
    protected static string $resource = AnnouncementResource::class;
}
