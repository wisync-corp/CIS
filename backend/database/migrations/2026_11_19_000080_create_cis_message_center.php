<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Centro de mensagens (equivalente ao frmMessageCenter do VBA).
 * - cis_message_templates: modelos com marcadores {campo} (substitui a folha MessageTemplates).
 * - cis_message_log: registo de cada mensagem preparada/aberta pelo operador (evidência de contacto).
 * Tabelas próprias do CIS; nenhuma tabela do legado é alterada.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('cis_message_templates')) {
            Schema::create('cis_message_templates', function (Blueprint $t) {
                $t->id();
                $t->string('name',80)->unique();
                $t->text('body');
                $t->unsignedBigInteger('created_by')->nullable();
                $t->unsignedBigInteger('updated_by')->nullable();
                $t->timestamps();
            });
            $now=now();
            DB::table('cis_message_templates')->insert([
                ['name'=>'Lembrete de vencimento','body'=>"Caro(a) {Primeiro Nome}, lembramos que a prestação do contrato {Nr do Contracto} vence a {Data de Cobrança}.\nValor a pagar: {Valor a Pagar} MT.\nObrigado.",'created_at'=>$now,'updated_at'=>$now],
                ['name'=>'Pagamento atrasado','body'=>"Caro(a) {Primeiro Nome}, o contrato {Nr do Contracto} tem {Prestações Vencidas} prestação(ões) vencida(s) desde {Data de Cobrança} ({Dias de Atraso} dia(s) de atraso).\nValor em dívida: {Valor em Dívida} MT.\nPedimos que regularize o pagamento com a maior brevidade. Obrigado.",'created_at'=>$now,'updated_at'=>$now],
                ['name'=>'Confirmação de pagamento','body'=>"Caro(a) {Primeiro Nome}, confirmamos a recepção do seu pagamento referente ao contrato {Nr do Contracto}. Obrigado pela pontualidade.",'created_at'=>$now,'updated_at'=>$now],
                ['name'=>'Contrato liquidado','body'=>"Caro(a) {Primeiro Nome}, o contrato {Nr do Contracto} está totalmente liquidado. Obrigado pela confiança.",'created_at'=>$now,'updated_at'=>$now],
            ]);
        }
        if (!Schema::hasTable('cis_message_log')) {
            Schema::create('cis_message_log', function (Blueprint $t) {
                $t->id();
                $t->string('contract_number',155)->nullable()->index();
                $t->unsignedBigInteger('client_id')->nullable()->index();
                $t->string('phone',40)->nullable();
                $t->string('channel',16);
                $t->unsignedBigInteger('template_id')->nullable();
                $t->string('group',24)->nullable();
                $t->text('body');
                $t->unsignedBigInteger('sent_by');
                $t->timestamp('created_at')->useCurrent();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cis_message_log');
        Schema::dropIfExists('cis_message_templates');
    }
};
