<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('cis_rh_payroll_periods', function (Blueprint $t) {
            $t->date('period')->primary();
            $t->unsignedBigInteger('opened_by');
            $t->timestamp('opened_at');
            $t->unsignedBigInteger('closed_by')->nullable();
            $t->timestamp('closed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cis_rh_payroll_periods');
    }
};
