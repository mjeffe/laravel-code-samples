<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Resource_state extends Model
{
    protected $table = 'resource_state';

    protected $primaryKey = 'resource_id';

    public $timestamps = false;

    public $incrementing = false;

    use SoftDeletes;

    protected $fillable = [
        'resource_id',
        'resource_type',
        'current_state',
        'action_taken',
        'actor_user_id',
        'actor_role_id',    // actor_persona_id ???
        // 'actor_oa_id',
        'meta',
        'event_id',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }

    /*
     * relationships
     */
    public function actor(): HasOne
    {
        return $this->hasOne(\App\Models\User::class, 'user_id', 'actor_user_id');
    }
}