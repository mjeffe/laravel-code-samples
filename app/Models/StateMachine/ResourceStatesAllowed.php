<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Resource_states_allowed extends Model
{
    use SoftDeletes;

    protected $table = 'resource_states_allowed';

    protected $primaryKey = 'id';

    public $incrementing = false;

    public $timestamps = false;

    // mutate to/from date
}