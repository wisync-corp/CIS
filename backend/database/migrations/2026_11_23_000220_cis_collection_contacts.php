<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contactos de cobrança e promessas de pagamento (modelo Loandisk: comentários do empréstimo,
 * lembretes e folha de cobrança). Cada contacto pode trazer uma promessa (valor + data) e uma
 * data para o próximo contacto. Acrescenta o modelo de mensagem «Lembrete de promessa».
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('cis_collection_contacts')) {
            Schema::create('cis_collection_contacts', function (Blueprint $t) {
                $t->id();
                $t->string('contract_number', 155)->index();
                $t->unsignedBigInteger('client_id')->nullable()->index();
                $t->string('canal', 20);      // chamada | whatsapp | sms | visita | presencial | outro
                $t->string('resultado', 30);  // prometeu_pagar | vai_pagar | sem_resposta | numero_errado | recusou | ja_pagou | outro
                $t->text('notas')->nullable();
                $t->date('proximo_contacto')->nullable()->index();
                $t->decimal('promessa_valor', 14, 2)->nullable();
                $t->date('promessa_data')->nullable()->index();
                $t->string('promessa_estado', 12)->nullable()->index(); // pendente | cumprida | falhada | cancelada
                $t->decimal('promessa_pago', 14, 2)->nullable();
                $t->timestamp('promessa_resolvida_em')->nullable();
                $t->unsignedBigInteger('user_id')->index();
                $t->timestamps();
            });
        }
        if (Schema::hasTable('cis_message_templates') && !DB::table('cis_message_templates')->where('name', 'Lembrete de promessa')->exists()) {
            DB::table('cis_message_templates')->insert(['name' => 'Lembrete de promessa',
                'body' => "Caro(a) {Primeiro Nome}, lembramos o seu compromisso de pagar {Valor Prometido} MT hoje ({Data da Promessa}), referente ao contrato {Nr do Contracto}.\nObrigado.",
                'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cis_collection_contacts');
    }
};
