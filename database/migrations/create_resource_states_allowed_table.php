<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_states_allowed', function (Blueprint $table) {
            $table->increments('id');
            $table->text('realm_id');
            $table->text('resource_type');
            $table->text('current_state')->nullable();
            $table->text('action_taken');
            $table->text('actor_role_id');
            $table->text('next_state');
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_states_allowed');
    }
};