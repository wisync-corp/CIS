<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('cis_rh_payroll', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained('cis_rh_employees');
            $t->date('period');
            $t->string('status',20);
            $t->json('input');
            $t->json('calculation');
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['employee_id','period']);
        });
        Schema::create('cis_rh_payroll_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('payroll_id')->constrained('cis_rh_payroll');
            $t->unsignedBigInteger('user_id');
            $t->string('action',20);
            $t->unsignedInteger('version');
            $t->json('snapshot');
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cis_rh_payroll_events');
        Schema::dropIfExists('cis_rh_payroll');
    }
};
