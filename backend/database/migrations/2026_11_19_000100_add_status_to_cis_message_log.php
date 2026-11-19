<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Estado do envio pelo WhatsApp Web (extensão CIS WhatsApp): enviada / falhada / aberta, com motivo. */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('cis_message_log')) return;
        Schema::table('cis_message_log', function (Blueprint $t) {
            if (!Schema::hasColumn('cis_message_log','status')) $t->string('status',16)->nullable()->after('channel');
            if (!Schema::hasColumn('cis_message_log','detail')) $t->string('detail',255)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('cis_message_log')) return;
        Schema::table('cis_message_log', function (Blueprint $t) {
            foreach (['detail','status'] as $c) if (Schema::hasColumn('cis_message_log',$c)) $t->dropColumn($c);
        });
    }
};
