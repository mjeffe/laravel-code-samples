<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ref_resource_states', function (Blueprint $table) {
            $table->increments('id');
            $table->text('realm_id');
            $table->text('resource_type');
            $table->text('current_state')->nullable();
            $table->text('action_taken');
            $table->text('actor_role_id');
            $table->text('next_state');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ref_resource_states');
    }
};