<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccessEvent extends Model
{
    public const TYPE_APP_OPEN = 'app_open';
    public const TYPE_PAGE_VIEW = 'page_view';

    protected $fillable = [
        'venue_id',
        'user_id',
        'user_role',
        'visitor_id',
        'session_id',
        'event_type',
        'screen',
        'platform',
        'ip',
        'user_agent',
        'referrer',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
