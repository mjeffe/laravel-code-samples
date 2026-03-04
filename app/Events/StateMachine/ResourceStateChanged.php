<?php

namespace App\Events;

use App\Models\ResourceInterface as Resource;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ResourceStateChanged
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Resource $resource;

    public function __construct(Resource $resource)
    {
        $this->resource = $resource;
    }
}