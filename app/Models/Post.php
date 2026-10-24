<?php

namespace App\Models;

use App\Enums\PostStatus;
use App\Exceptions\CampaignMismatchException;
use Database\Factories\PostFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'campaign_id', 'product_id', 'social_account_id', 'content', 'link_reply_content', 'image_url', 'generation',
    'status', 'approved_at', 'approved_by', 'external_post_id', 'permalink', 'link_reply_id', 'published_at', 'last_error',
    'telegram_chat_id', 'telegram_message_id',
])]
class Post extends Model
{
    /** @use HasFactory<PostFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (Post $post) {
            if ($post->isDirty(['campaign_id', 'social_account_id', 'product_id'])) {
                $post->ensureBelongsToCampaign();
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PostStatus::class,
            'published_at' => 'datetime',
            'approved_at' => 'datetime',
            'generation' => 'array',
            'telegram_message_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<SocialAccount, $this>
     */
    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    /**
     * @return HasMany<PostInsight, $this>
     */
    public function insights(): HasMany
    {
        return $this->hasMany(PostInsight::class);
    }

    /**
     * @return HasOne<PostInsight, $this>
     */
    public function latestInsight(): HasOne
    {
        return $this->hasOne(PostInsight::class)->latestOfMany('fetched_at');
    }

    /**
     * @return HasMany<PostReply, $this>
     */
    public function replies(): HasMany
    {
        return $this->hasMany(PostReply::class);
    }

    /**
     * The photo published with the post. A new post copies your last photo of the product when it
     * is created, so what you approve is what goes out.
     */
    public function imageUrl(): ?string
    {
        return $this->image_url ?: null;
    }

    /**
     * Text from you (pasted or typed) rather than from the AI. The AI never rewrites it.
     */
    public function writtenByYou(): bool
    {
        return ($this->generation['source'] ?? null) === 'manual';
    }

    /**
     * A campaign's content may only go to that campaign's social accounts,
     * and only for products from that campaign's affiliate account.
     *
     * @throws CampaignMismatchException
     */
    public function ensureBelongsToCampaign(): void
    {
        if ($this->campaign_id === null) {
            return;
        }

        $campaign = Campaign::findOrFail($this->campaign_id);

        if (! $campaign->socialAccounts()->whereKey($this->social_account_id)->exists()) {
            throw new CampaignMismatchException(
                "Social account {$this->social_account_id} is not linked to campaign {$campaign->id}."
            );
        }

        if ($this->product_id !== null
            && Product::findOrFail($this->product_id)->affiliate_account_id !== $campaign->affiliate_account_id) {
            throw new CampaignMismatchException(
                "Product {$this->product_id} does not belong to campaign {$campaign->id}'s affiliate account."
            );
        }
    }
}
