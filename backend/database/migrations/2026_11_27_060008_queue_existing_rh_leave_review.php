<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('cis_rh_records')->where('kind','ferias')->whereNull('review_status')->update(['review_status'=>'pendente']);
    }

    public function down(): void
    {
        // Mantém o histórico e as decisões aprovadas após instalar.
    }
};
