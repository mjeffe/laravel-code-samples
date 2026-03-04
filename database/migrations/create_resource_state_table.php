<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_state', function (Blueprint $table) {
            $table->integer('resource_id')->primary();
            $table->text('resource_type');
            $table->text('current_state');
            $table->text('action_taken');
            $table->integer('actor_user_id');
            $table->text('actor_role_id');         // should this be persona_id???
            $table->integer('event_id')->nullable();
            $table->jsonb('meta')->nullable();
            $table->SoftDeletes();
        });

        DB::unprepared('alter table resource_state add column sys_period tstzrange not null');
        DB::unprepared('create table resource_state_history (like resource_state)');
        DB::unprepared("create TRIGGER versioning_trigger
            BEFORE INSERT OR UPDATE OR DELETE ON resource_state
            FOR EACH ROW EXECUTE PROCEDURE versioning(
                   'sys_period', 'resource_state_history', true
            )
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_state');
        Schema::dropIfExists('resource_state_history');
    }
};