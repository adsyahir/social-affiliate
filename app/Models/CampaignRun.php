<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['campaign_id', 'product_id', 'status', 'trigger', 'accounts', 'created_at'])]
class CampaignRun extends Model
{
    public const UPDATED_AT = null;

    public const DISPATCHED = 'dispatched';

    public const NO_PRODUCTS = 'no_products';

    /**
     * A draft could not be written; the product becomes available to a later run again.
     */
    public const FAILED = 'failed';

    public const SCHEDULE = 'schedule';

    public const MANUAL = 'manual';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'accounts' => 'integer',
            'created_at' => 'datetime',
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
}
