<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

abstract class ResourceBaseModel extends Model implements ResourceInterface
{
    protected $resourceEventPayload = null;

    // eager load these relationships
    protected $with = ['state'];

    /*
     * relationships
     */
    public function state(): HasOne
    {
        return $this->hasOne(\App\Models\Resource_state::class, 'resource_id', $this->primaryKey);
    }

    /*
     * For resource models, we manage state using Laravel events. For the most
     * part, it's all handled automatically behind the scenes, with a minor
     * exception for custom events. Built in model events (create, update,
     * delete, etc) get fired automatically by Laravel. Custom model events
     * (approve, score, reject, etc) need to be invoked manually.
     *
     * Event names are the past tense of the 'action' in the ref_resource_states
     * table. The logic being, we want to invoke the event to change the state
     * of a resource, once the action has happened.
     *
     * To setup and use a particular event:
     *
     * 1) Make sure the appropriate states exist in the ref_resource_states table
     * 2) Define events we want our Resource models to fire in $dispatchesEvents (below)
     * 3) If it is a custom event
     *       - you will also need to add it to $observables (below)
     *       - define an action function to invoke fireModelEvent() (below)
     * 4) Define an Event class in App\Events\
     *       - The $action member must = the action string in the ref_resource_states table
     * 5) Register the event listener class in App\Listeners\ResourceEventSubscriber
     * 6) For custom model events ONLY, services need to manually invoke the
     *    custom action functions defined below. Note, if you are performing
     *    other operations, then it should all be wrapped in a transaction to
     *    make sure that if either operation fails, the state does not get
     *    changed. For example, to score a module:
     *
     *       // ... fetching, filling, etc of $module and $score left out...
     *
     *       return DB::transaction(function () use($module, $score) {
     *           $this->saveModel($score);
     *           $module->scoreResource();
     *       });
     * 7) Add an appropriate action function to the appropriate Policy class.
     *
     */

    // define the events we want to dispatch
    protected $dispatchesEvents = [
        // built in model events
        'created' => \App\Events\CreatedResource::class,
        'deleted' => \App\Events\DeletedResource::class,
        // custom model events defined below in the $observables array
        'submitted' => \App\Events\SubmittedResource::class,
        'approved' => \App\Events\ApprovedResource::class,
        'rejected' => \App\Events\RejectedResource::class,
        'accepted' => \App\Events\AcceptedResource::class,
        'updated' => \App\Events\UpdatedResource::class,
        'paid' => \App\Events\PaidResource::class,
    ];

    /*
     * Custom model events we want our "Resource" models to fire.
     *
     * For each custom event, you also need to write an accompanying public
     * function to invoke fireModelEvent(), as in the examples below.
     */
    protected $observables = [
        'submitted',
        'approved',
        'rejected',
        'accepted',
        'assigned',
        'updated',
        'paid',
    ];

    public function submitResource()
    {
        $this->fireModelEvent('submitted', false);
    }

    public function approveResource($payload = null)
    {
        $this->setEventPayload($payload);
        $this->fireModelEvent('approved', false);
    }

    public function rejectResource($payload = null)
    {
        $this->setEventPayload($payload);
        $this->fireModelEvent('rejected', false);
    }

    public function acceptResource($payload = null)
    {
        $this->setEventPayload($payload);
        $this->fireModelEvent('accepted', false);
    }

    public function assignResource($payload = null)
    {
        $this->setEventPayload($payload);
        $this->fireModelEvent('assigned', false);
    }

    public function payResource($payload = null)
    {
        $this->setEventPayload($payload);
        $this->fireModelEvent('paid', false);
    }

    /*
     * special getters/setters
     */
    public function getEventPayload()
    {
        return $this->resourceEventPayload;
    }

    public function setEventPayload($payload)
    {
        $this->resourceEventPayload = $payload;
    }

    /*
     * helpers
     */

    // one of the interface requirements
    public function getState()
    {
        return (empty($this->state)) ? null : $this->state->current_state;
    }

    // save a model without fireing any events
    public function saveQuietly(array $options = [])
    {
        $dispatcher = $this->getEventDispatcher();
        $this->unsetEventDispatcher();

        $ret = $this->save();

        $this->setEventDispatcher($dispatcher);

        return $ret;
    }

    // save a model without fireing any events OR the temporal tables trigger
    public function saveVeryQuietly(array $options = [])
    {
        return DB::transaction(function () use ($options) {
            DB::statement("ALTER TABLE {$this->table} DISABLE TRIGGER versioning_trigger");

            $ret = $this->saveQuietly($options);

            DB::statement("ALTER TABLE {$this->table} ENABLE TRIGGER versioning_trigger");

            return $ret;
        });
    }
}