<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('cis_rh_employees',function (Blueprint $t) {
            $t->text('profile_encrypted')->nullable();
            $t->unsignedInteger('profile_revision')->default(1);
        });
    }
    public function down(): void
    {
        Schema::table('cis_rh_employees',fn (Blueprint $t)=>$t->dropColumn(['profile_encrypted','profile_revision']));
    }
};
