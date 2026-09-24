<?php

namespace App\Http\Controllers;

use App\Enums\Platform;
use App\Models\ContentPost;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ContentController extends Controller
{
    public function index(Request $request): View
    {
        $posts = ContentPost::query()
            ->with(['campaign', 'influencer', 'deliverable'])
            ->when($request->filled('platform'), fn ($query) => $query->where('platform', $request->string('platform')))
            ->when($request->boolean('flagged'), fn ($query) => $query->flagged())
            ->latest('posted_at')
            ->paginate(20)
            ->withQueryString();

        return view('pages.scrutium.content.index', compact('posts') + [
            'title' => 'Content',
            'platforms' => Platform::options(),
            'totalReach' => ContentPost::sum('reach'),
            'flaggedCount' => ContentPost::flagged()->count(),
        ]);
    }
}