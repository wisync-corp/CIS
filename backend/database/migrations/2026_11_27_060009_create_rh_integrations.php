<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('cis_rh_integrations', function (Blueprint $t) {
            $t->id();
            $t->string('name',120);
            $t->string('company',80);
            $t->string('scope',40);
            $t->char('token_hash',64)->unique();
            $t->unsignedBigInteger('created_by');
            $t->timestamp('created_at');
            $t->timestamp('expires_at');
            $t->unsignedBigInteger('revoked_by')->nullable();
            $t->timestamp('revoked_at')->nullable();
        });
        Schema::create('cis_rh_integration_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('integration_id')->constrained('cis_rh_integrations');
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('operation',40);
            $t->unsignedInteger('row_count')->nullable();
            $t->timestamp('created_at');
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('cis_rh_integration_events');
        Schema::dropIfExists('cis_rh_integrations');
    }
};
