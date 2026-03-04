<?php

namespace App\Services;

use App\Models\Resource_state;
use App\Models\Resource_states_allowed;
use App\Models\ResourceInterface as Resource;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ResourceStateService extends ApiService
{
    public function updateState(Resource $resource, $action, $meta = null)
    {
        // If multiple updateState calls are made on a resource in a single
        // request, the resource object's state does not update. So here,
        // although it's an addition db call, we refresh - because accurate is
        // better than... well, inaccurate.
        $resource = $resource->fresh(['state']);

        try {
            $nextState = $this->getNextState($resource, $action);
            // Log::debug('ResourceStateService->updateState(): Next State', [$nextState]);
            $this->setState($resource, $nextState, $meta);
        } catch (\Exception $e) {
            Log::error('Failed to set next state for resource : '.$e->getMessage());
            throw $e;
        }

        return $resource->fresh(['state']);
    }

    protected function getNextState($resource, $action)
    {
        $user = Auth::user();
        $where = [
            ['realm_id', $user->active_realm],
            ['resource_type', $resource->getResourceType()],
            ['current_state', $resource->getState()],
            ['actor_role_id', $user->active_role],
            ['action_taken', $action],
        ];

        try {
            $nextState = Resource_states_allowed::where($where)->firstOrFail();
        } catch (\Exception $e) {
            $criteria = [];
            foreach ($where as $row) {
                $criteria[$row[0]] = $row[1];
            }
            Log::error('Unable to find next state for resource: '.print_r($criteria, true));
            throw $e;
        }

        return $nextState;
    }

    protected function setState($resource, $nextState, $meta = null)
    {
        // NOTE on event_id:
        // A Laravel model will not actually save unless the model has some
        // change (isDirty). When a resource has back-to-back events that are
        // the same (for example save followed by another save), what we get is
        // the situation where current-state = new-state, so when we set the
        // "changes" in the model with fill(), it appears to the Laravel
        // Resource_state model that we havent changed anything, and therefore
        // it does not run the update.  This means our temporal_tables trigger
        // does not fire, so we don't see the history of events multiple save
        // events. To be clear, the resource's own history table shows the
        // multiple updates, but our resource_state table would not.
        //
        // That is the sole purpose of the 'event_id' field - to arbitrarily
        // make a change so that Laravel will actually save the damn thing.  I
        // did discover the $model->touch() function after implementing
        // event_id, which would also "force" a dirty state. But it would
        // require adding two timestamp columns (create_at and updated_at)
        // rather than the single event_id int column. We decided to stick with
        // the event_id solution.
        $event_id = ($resource->state) ? $resource->state->event_id : 0;
        $data = [
            'event_id' => ++$event_id,
            'current_state' => $nextState->next_state,
            'action_taken' => $nextState->action_taken,
            'actor_user_id' => Auth::user()->user_id,
            'actor_role_id' => Auth::user()->active_role,    // actor_persona_id ???
            'meta' => $meta,
            // 'actor_oa_id',
        ];

        try {
            $state = $this->getCurrentState($resource);
            $state->fill($data)->save();
        } catch (\Exception $e) {
            Log::error('Failed to update resource state: '.$e->getMessage());
            Log::error('New state attempt: '.print_r($data, true));
            throw $e;
        }
    }

    protected function getCurrentState($resource)
    {
        try {
            $state = Resource_state::findOrFail($resource->getKey());
        } catch (\Exception $e) {
            // Log::debug("Creating initial state for {$resource->getResourceType()} resource id {$resource->getKey()}");
            $state = new Resource_state;
            $state->fill([
                'resource_id' => $resource->getKey(),
                'resource_type' => $resource->getResourceType(),
            ]);
        }

        return $state;
    }

    public function getNextActors($resource)
    {
        $user = Auth::user();
        $where = [
            ['realm_id', $user->active_realm],
            ['resource_type', $resource->getResourceType()],
            ['current_state', $resource->getState()],
        ];

        try {
            $nextActors = Resource_states_allowed::where($where)->get()->pluck('actor_role_id')->toArray();
        } catch (\Exception $e) {
            $criteria = [];
            foreach ($where as $row) {
                $criteria[$row[0]] = $row[1];
            }
            Log::error('Unable to find next actors for resource: '.print_r($criteria, true));
            throw $e;
        }

        return array_unique($nextActors);
    }
}