<?php

namespace App\Filament\Widgets;

use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\PostInsight;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Str;

/**
 * The week at a glance: what waits for approval, what went out, and how it did.
 */
class PerformanceOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $heading = 'Last 7 days';

    // Numbers change once a day; refreshing every few seconds would only add load.
    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $since = now()->subDays(7);
        $published = Post::query()->where('status', PostStatus::Published)->where('published_at', '>=', $since);

        // Each post's newest snapshot only; earlier snapshots of the same post would double count.
        $latest = PostInsight::query()
            ->whereIn('id', PostInsight::query()->selectRaw('max(id)')->groupBy('post_id'))
            ->whereIn('post_id', (clone $published)->select('id'));

        $totals = (clone $latest)->selectRaw('coalesce(sum(views), 0) as views, coalesce(sum(likes), 0) as likes')->first();
        $top = (clone $latest)->with('post')->orderByDesc('views')->first();

        return [
            Stat::make('Waiting for you', number_format(Post::query()->whereIn('status', [PostStatus::Pending, PostStatus::Draft])->count()))
                ->description('Drafts to approve and posts to write'),
            Stat::make('Published', number_format((clone $published)->count())),
            Stat::make('Views', number_format((int) $totals->views))
                ->description($top ? 'Top: '.Str::limit($top->post->content, 40).' ('.number_format($top->views).')' : 'No insights yet'),
            Stat::make('Likes', number_format((int) $totals->likes)),
        ];
    }
}
