<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('cis_rh_records',function (Blueprint $t) {
            $t->string('review_status',20)->nullable();
            $t->unsignedInteger('revision')->default(1);
            $t->unsignedBigInteger('decided_by')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->string('decision_note',255)->nullable();
        });
        DB::table('cis_rh_records')->whereIn('kind',['contratos','adendas'])->update(['review_status'=>'pendente']);
    }

    public function down(): void
    {
        Schema::table('cis_rh_records',fn (Blueprint $t)=>$t->dropColumn(['review_status','revision','decided_by','decided_at','decision_note']));
    }
};
