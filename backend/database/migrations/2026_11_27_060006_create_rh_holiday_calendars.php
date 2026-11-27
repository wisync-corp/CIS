<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('cis_rh_holiday_calendars', function (Blueprint $t) {
            $t->id();
            $t->unsignedSmallInteger('year');
            $t->string('company',80)->default('');
            $t->json('holidays');
            $t->unsignedBigInteger('created_by');
            $t->string('reason',255);
            $t->timestamp('created_at');
            $t->index(['year','company','id'],'rh_holiday_calendar_lookup');
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('cis_rh_holiday_calendars');
    }
};
