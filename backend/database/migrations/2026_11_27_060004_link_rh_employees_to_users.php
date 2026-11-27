<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('cis_rh_user_links', function (Blueprint $t) {
            $t->foreignId('employee_id')->primary()->constrained('cis_rh_employees');
            $t->foreignId('user_id')->unique()->constrained('users');
            $t->timestamps();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('cis_rh_user_links');
    }
};
