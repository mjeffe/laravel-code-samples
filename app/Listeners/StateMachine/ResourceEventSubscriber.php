<?php

namespace App\Listeners;

use App\Events\ResourceStateChanged;
use App\Services\ResourceStateService;
use Illuminate\Events\Dispatcher;

/*
 * NOTE!! This subscriber is manually registered in \App\Providers\AppServiceProvider
 * As such, the handler method is named process* rather than handle* to avoid Laravel's
 * automatic event discovery. And the handler is not type hinted since it receives many
 * different event types (although they all contain a Model ResourceInterface).
 *
 * NOTE!! All events contain a Resource (use App\Models\ResourceInterface as Resource);
 */

//
// should be rename to: ChangeResourceState
//
class ResourceEventSubscriber
{
    protected ResourceStateService $service;

    public function __construct(ResourceStateService $service)
    {
        $this->service = $service;
    }

    /**
     * register listeners
     */
    public function subscribe(Dispatcher $events)
    {
        return [
            \App\Events\CreatedResource::class => 'processChangeState',
            \App\Events\UpdatedResource::class => 'processChangeState',
            \App\Events\DeletedResource::class => 'processChangeState',
            \App\Events\ApprovedResource::class => 'processChangeState',
            \App\Events\RejectedResource::class => 'processChangeState',
            \App\Events\SubmittedResource::class => 'processChangeState',
            \App\Events\AcceptedResource::class => 'processChangeState',
            \App\Events\PaidResource::class => 'processChangeState',
        ];
    }

    public function processChangeState($event)
    {
        // All events contain an App\Models\ResourceInterface

        $payload = $event->resource->getEventPayload();

        $event->resource = $this->service->updateState($event->resource, $event->action, $payload);

        // updating state returns a 'freshed' resource, so restore payload for future events
        $event->resource->setEventPayload($payload);

        // now that we have successfully changed state, let everyone know
        ResourceStateChanged::dispatch($event->resource);

        return $event->resource;
    }
}