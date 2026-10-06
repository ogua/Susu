<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\AnnouncementResource;
use App\Models\Announcement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Platform announcements running now for the signed-in user's role (empty for customers). */
class AnnouncementController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return AnnouncementResource::collection(
            Announcement::query()->visibleTo($request->user())->limit(10)->get(),
        );
    }
}
