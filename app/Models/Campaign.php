<?php

namespace App\Models;

use Database\Factories\CampaignFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'affiliate_account_id', 'active', 'posts_per_day'])]
class Campaign extends Model
{
    /** @use HasFactory<CampaignFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'posts_per_day' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<AffiliateAccount, $this>
     */
    public function affiliateAccount(): BelongsTo
    {
        return $this->belongsTo(AffiliateAccount::class);
    }

    /**
     * @return BelongsToMany<SocialAccount, $this>
     */
    public function socialAccounts(): BelongsToMany
    {
        return $this->belongsToMany(SocialAccount::class)->withTimestamps();
    }

    /**
     * @return HasMany<Post, $this>
     */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    /**
     * @return HasMany<CampaignRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(CampaignRun::class);
    }

    /**
     * Products from this campaign's affiliate account that have not been posted yet, oldest first.
     *
     * @return Builder<Product>
     */
    public function availableProducts(): Builder
    {
        return Product::query()
            ->where('affiliate_account_id', $this->affiliate_account_id)
            ->available()
            ->oldest('id');
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('active', true);
    }
}
