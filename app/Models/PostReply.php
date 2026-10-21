<?php

namespace App\Models;

use App\Enums\ReplyCategory;
use App\Enums\ReplyStatus;
use Database\Factories\PostReplyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reply someone left on one of our posts. Its text is untrusted public input.
 */
#[Fillable([
    'post_id', 'external_reply_id', 'author_username', 'text', 'category',
    'ai_draft', 'status', 'our_reply_id', 'replied_at',
])]
class PostReply extends Model
{
    /** @use HasFactory<PostReplyFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => ReplyCategory::class,
            'status' => ReplyStatus::class,
            'replied_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Post, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }
}
