<?php

declare(strict_types=1);

namespace App\Http\Controllers\SocialHub;

use App\Enums\PostVariantStatus;
use App\Http\Controllers\Controller;
use App\Models\PostVariant;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Renders the content calendar. Day, week and month views all come from the
 * same query — the grid shape is the only thing that changes.
 */
final class CalendarController extends Controller
{
    public function index(Request $request): View
    {
        $view = in_array($request->string('view')->toString(), ['day', 'week', 'month'], true)
            ? $request->string('view')->toString()
            : 'month';

        $anchor = $request->date('date') ?? today();

        [$start, $end] = match ($view) {
            'day' => [$anchor->copy()->startOfDay(), $anchor->copy()->endOfDay()],
            'week' => [$anchor->copy()->startOfWeek(), $anchor->copy()->endOfWeek()],
            default => [$anchor->copy()->startOfMonth(), $anchor->copy()->endOfMonth()],
        };

        $entries = PostVariant::query()
            ->with(['post', 'socialAccount'])
            ->whereNotNull('scheduled_at')
            ->whereBetween('scheduled_at', [$start, $end])
            ->orderBy('scheduled_at')
            ->get()
            ->map(fn (PostVariant $variant) => $this->entry($variant));

        // Drafts have no schedule, so the month view merges them into their day
        // instead of leaving them invisible.
        $drafts = $view === 'month'
            ? \App\Models\Post::query()
                ->drafts()
                ->whereBetween('created_at', [$start, $end])
                ->orderBy('created_at')
                ->get()
                ->map(fn (\App\Models\Post $post) => [
                    'id' => 'post-'.$post->id,
                    'variant_id' => null,
                    'post_id' => $post->id,
                    'title' => $post->title ?: 'Untitled draft',
                    'provider' => null,
                    'platform' => null,
                    'status' => 'draft',
                    'status_label' => 'Draft',
                    'badge' => 'bg-gray-100 text-gray-700 dark:bg-white/5 dark:text-white/80',
                    'at' => $post->created_at?->toIso8601String(),
                    'human' => $post->created_at?->diffForHumans(),
                ])
                ->all()
            : [];

        $merged = collect($entries)->concat($drafts)
            ->sortBy('at')
            ->values()
            ->all();

        $byDay = [];

        foreach ($merged as $item) {
            if ($item['at'] === null) {
                continue;
            }

            $byDay[Carbon::parse($item['at'])->toDateString()][] = $item;
        }

        return view('pages.socialhub.calendar', [
            'title' => 'Calendar',
            'view' => $view,
            'anchor' => $anchor,
            'start' => $start,
            'end' => $end,
            'entries' => $merged,
            'byDay' => $byDay,
            'previous' => $this->step($anchor, $view, -1),
            'next' => $this->step($anchor, $view, 1),
            'today' => today(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(PostVariant $variant): array
    {
        $status = $variant->statusEnum();

        return [
            'id' => 'variant-'.$variant->id,
            'variant_id' => $variant->id,
            'post_id' => $variant->post_id,
            'title' => $variant->post?->title ?: 'Untitled post',
            'caption' => $variant->caption,
            'provider' => $variant->provider?->value,
            'platform' => $variant->provider?->label(),
            'status' => $status->value,
            'status_label' => $status->label(),
            'badge' => $status->badgeColor(),
            'at' => $variant->scheduled_at?->toIso8601String() ?? $variant->published_at?->toIso8601String(),
            'human' => ($variant->scheduled_at ?? $variant->published_at)?->diffForHumans(),
            'is_draggable' => in_array($status, [PostVariantStatus::Pending, PostVariantStatus::Scheduled], true),
        ];
    }

    private function step(Carbon $anchor, string $view, int $direction): string
    {
        $date = match ($view) {
            'day' => $anchor->copy()->addDays($direction),
            'week' => $anchor->copy()->addWeeks($direction),
            default => $anchor->copy()->addMonthsNoOverflow($direction),
        };

        return $date->toDateString();
    }
}
