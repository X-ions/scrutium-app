<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PublishingAttempt;
use Illuminate\Console\Command;

/**
 * Trims the publishing attempt history.
 *
 * Attempts are an audit trail, not an infinite log: once a variant reaches a
 * terminal state, only the last few attempts are worth keeping, and the
 * retention window keeps the table proportional to the live backlog.
 */
class PrunePublishingAttemptsCommand extends Command
{
    protected $signature = 'publishing:prune-attempts
        {--days=30 : Delete attempts older than this many days}
        {--keep=5 : Keep at least this many recent attempts per variant}
        {--dry-run : Report what would be deleted without deleting it}';

    protected $description = 'Delete old publishing attempts while keeping the most recent history per variant';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $keep = max(0, (int) $this->option('keep'));
        $dryRun = (bool) $this->option('dry-run');

        $cutoff = now()->subDays($days);
        $prunableIds = $this->prunableIds($cutoff, $keep);

        if ($prunableIds === []) {
            $this->components->info('No publishing attempts are old enough to prune.');

            return self::SUCCESS;
        }

        $count = count($prunableIds);

        if ($dryRun) {
            $this->components->info(sprintf('%d publishing attempt(s) would be deleted.', $count));

            return self::SUCCESS;
        }

        $deleted = PublishingAttempt::query()
            ->withoutGlobalScopes()
            ->whereIn('id', $prunableIds)
            ->delete();

        $this->components->info(sprintf('Pruned %d publishing attempt(s) older than %d day(s).', $deleted, $days));

        return self::SUCCESS;
    }

    /**
     * @return list<int>
     */
    private function prunableIds(\DateTimeInterface $cutoff, int $keep): array
    {
        $old = PublishingAttempt::query()
            ->withoutGlobalScopes()
            ->where('started_at', '<', $cutoff)
            ->pluck('post_variant_id')
            ->unique()
            ->all();

        $ids = [];

        foreach ($old as $variantId) {
            $protected = PublishingAttempt::query()
                ->withoutGlobalScopes()
                ->where('post_variant_id', $variantId)
                ->orderByDesc('attempt_number')
                ->limit($keep)
                ->pluck('id')
                ->all();

            $ids = array_merge($ids, PublishingAttempt::query()
                ->withoutGlobalScopes()
                ->where('post_variant_id', $variantId)
                ->where('started_at', '<', $cutoff)
                ->when($protected !== [], fn ($query) => $query->whereNotIn('id', $protected))
                ->pluck('id')
                ->all());
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }
}
