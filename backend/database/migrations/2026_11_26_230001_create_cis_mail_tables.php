<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('cis_mail_accounts', function (Blueprint $t) {
            $t->id();
            $t->string('provider',20);
            $t->string('sender',254)->unique();
            $t->string('label',120);
            $t->text('configuration');
            $t->text('authorization')->nullable();
            $t->timestamp('tested_at')->nullable();
            $t->timestamps();
        });
        Schema::create('cis_mail_defaults', function (Blueprint $t) {
            $t->string('purpose',24)->primary();
            $t->foreignId('account_id')->constrained('cis_mail_accounts')->cascadeOnDelete();
        });
        Schema::create('cis_mail_history', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->string('sender',254);
            $t->string('recipient',254);
            $t->string('subject',200);
            $t->string('purpose',24);
            $t->string('status',20);
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cis_mail_history');
        Schema::dropIfExists('cis_mail_defaults');
        Schema::dropIfExists('cis_mail_accounts');
    }
};
