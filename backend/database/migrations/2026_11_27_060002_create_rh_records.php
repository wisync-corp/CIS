<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('cis_rh_employees', function (Blueprint $t) {
            $t->id();
            $t->string('company',80);
            $t->string('code',32);
            $t->string('name',160);
            $t->string('category',80)->default('');
            $t->date('admission');
            $t->string('status',20);
            $t->timestamps();
            $t->unique(['company','code']);
        });
        Schema::create('cis_rh_records', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained('cis_rh_employees');
            $t->foreignId('parent_id')->nullable()->constrained('cis_rh_records');
            $t->string('kind',20);
            $t->date('reference_date');
            $t->json('details');
            $t->timestamps();
            $t->index(['kind','reference_date']);
        });
        Schema::create('cis_rh_record_audit', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained('cis_rh_employees');
            $t->foreignId('record_id')->nullable()->constrained('cis_rh_records');
            $t->unsignedBigInteger('user_id');
            $t->string('source',30);
            $t->string('action',30);
            $t->json('snapshot');
            $t->timestamp('created_at');
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('cis_rh_record_audit');
        Schema::dropIfExists('cis_rh_records');
        Schema::dropIfExists('cis_rh_employees');
    }
};
