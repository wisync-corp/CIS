<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('cis_rh_parameters', function (Blueprint $table) {
            $table->id();
            $table->string('code',64);
            $table->string('scope',16);
            $table->string('company',80)->default('');
            $table->string('category',80)->default('');
            $table->string('employee_code',32)->default('');
            $table->string('value',100);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->string('reason',255);
            $table->timestamp('created_at');
            $table->unique(['code','scope','company','category','employee_code','effective_from'],'rh_param_version');
        });
        Schema::create('cis_rh_tax_tables', function (Blueprint $table) {
            $table->id();
            $table->date('effective_from')->unique();
            $table->json('scales');
            $table->unsignedBigInteger('created_by');
            $table->string('reason',255);
            $table->timestamp('created_at');
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('cis_rh_tax_tables');
        Schema::dropIfExists('cis_rh_parameters');
    }
};
