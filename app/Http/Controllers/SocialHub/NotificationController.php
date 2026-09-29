<?php

declare(strict_types=1);

namespace App\Http\Controllers\SocialHub;

use App\Http\Controllers\Controller;
use App\Models\SocialHubNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class NotificationController extends Controller
{
    public function index(Request $request): View
    {
        $notifications = SocialHubNotification::query()
            ->where(function ($query) use ($request): void {
                $query->where('user_id', $request->user()->id)
                    ->orWhereNull('user_id');
            })
            ->active()
            ->latest()
            ->paginate(30);

        return view('pages.socialhub.notifications.index', [
            'title' => 'Notifications',
            'notifications' => $notifications,
        ]);
    }

    public function read(Request $request, SocialHubNotification $notification): RedirectResponse
    {
        $this->authorize('update', $notification);

        $notification->markRead();

        return $notification->action_url
            ? redirect()->to($notification->action_url)
            : back();
    }

    public function readAll(Request $request): RedirectResponse
    {
        SocialHubNotification::query()
            ->where(function ($query) use ($request): void {
                $query->where('user_id', $request->user()->id)->orWhereNull('user_id');
            })
            ->where('is_read', false)
            ->get()
            ->each->markRead();

        return back()->with('status', 'All notifications marked as read.');
    }
}
