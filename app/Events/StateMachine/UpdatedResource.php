<?php

namespace App\Events;

use App\Models\ResourceInterface as Resource;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UpdatedResource
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Resource $resource;

    public $action;

    public function __construct(Resource $resource)
    {
        $this->resource = $resource;
        $this->action = 'update';
    }
}