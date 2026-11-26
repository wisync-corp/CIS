<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Subscrição do CIS (licença do software) e pagamentos M-Pesa / e-Mola. Separado da Caixa e do Extracto. */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('cis_subscription')) Schema::create('cis_subscription', function (Blueprint $t) {
            $t->id();
            $t->string('plano', 20);
            $t->unsignedSmallInteger('utilizadores_extra')->default(0);
            $t->string('ciclo', 10)->default('mensal');
            $t->string('estado', 12)->default('teste');        // teste | activa
            $t->date('inicio');
            $t->date('valido_ate');
            $t->timestamps();
        });
        if (!Schema::hasTable('cis_subscription_payments')) Schema::create('cis_subscription_payments', function (Blueprint $t) {
            $t->id();
            $t->string('referencia', 24)->unique();
            $t->string('plano', 20);
            $t->unsignedSmallInteger('utilizadores_extra')->default(0);
            $t->string('ciclo', 10);
            $t->unsignedSmallInteger('meses');
            $t->decimal('valor', 12, 2);
            $t->string('metodo', 10);                          // mpesa | emola
            $t->string('modo', 12);                            // api | manual | simulacao
            $t->string('telefone', 15);
            $t->string('estado', 12)->default('pendente');     // pendente | confirmado | falhado | cancelado
            $t->string('comprovativo', 60)->nullable();        // ID da transacção indicado pelo cliente (modo manual)
            $t->string('operadora_transacao', 80)->nullable();
            $t->string('operadora_codigo', 30)->nullable();
            $t->string('operadora_mensagem', 255)->nullable();
            $t->date('valido_de')->nullable();
            $t->date('valido_ate')->nullable();
            $t->unsignedBigInteger('iniciado_por')->nullable();
            $t->string('confirmado_por', 60)->nullable();
            $t->timestamp('confirmado_em')->nullable();
            $t->timestamps();
            $t->index(['estado', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cis_subscription_payments');
        Schema::dropIfExists('cis_subscription');
    }
};
