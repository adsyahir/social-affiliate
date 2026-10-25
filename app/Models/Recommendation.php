<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\RecommendationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reply you post yourself on someone else's Threads post that asked for a recommendation.
 * Status: choosing (pick a product), writing (waiting for your text), ready, replied, skipped.
 */
#[Fillable([
    'product_id', 'threads_url', 'question', 'reply_text', 'status',
    'telegram_chat_id', 'telegram_message_id', 'replied_at', 'link_sent_at',
])]
class Recommendation extends Model
{
    /** @use HasFactory<RecommendationFactory> */
    use HasFactory;

    public const CHOOSING = 'choosing';

    public const WRITING = 'writing';

    public const READY = 'ready';

    public const REPLIED = 'replied';

    public const SKIPPED = 'skipped';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'telegram_message_id' => 'integer',
            'replied_at' => 'datetime',
            'link_sent_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Replies posted since midnight in the campaign timezone.
     */
    public static function repliedToday(): int
    {
        return self::query()->where('replied_at', '>=', self::midnight())->count();
    }

    /**
     * Today's slots for the daily limit: replies posted today, plus ones started today and not yet
     * finished. Holding a slot from the start means you are never sent off to post a reply over the limit.
     */
    public static function takenToday(?int $except = null): int
    {
        $midnight = self::midnight();

        return self::query()
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except))
            ->where(fn ($query) => $query
                ->where(fn ($replied) => $replied->where('status', self::REPLIED)->where('replied_at', '>=', $midnight))
                ->orWhere(fn ($open) => $open->whereIn('status', [self::CHOOSING, self::WRITING, self::READY])->where('created_at', '>=', $midnight)))
            ->count();
    }

    private static function midnight(): CarbonInterface
    {
        return now((string) config('services.campaigns.timezone'))->startOfDay()->utc();
    }
}
