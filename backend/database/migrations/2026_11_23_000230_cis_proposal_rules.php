<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Regras das propostas (modelo Loandisk):
 *  - mínimos e máximos de montante e de taxa por agência;
 *  - aviso ao mutuário quando a proposta é aprovada, recusada ou não levantada (último aviso enviado);
 *  - modelos de mensagem para esses avisos.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('cis_branches') && !Schema::hasColumn('cis_branches', 'credito_minimo')) {
            Schema::table('cis_branches', function (Blueprint $t) {
                $t->unsignedInteger('credito_minimo')->nullable();
                $t->unsignedInteger('credito_maximo')->nullable();
                $t->decimal('taxa_minima', 6, 2)->nullable();
                $t->decimal('taxa_maxima', 6, 2)->nullable();
            });
        }
        if (Schema::hasTable('cis_credit_proposals') && !Schema::hasColumn('cis_credit_proposals', 'avisado_estado')) {
            Schema::table('cis_credit_proposals', function (Blueprint $t) {
                $t->string('avisado_estado', 20)->nullable();
                $t->timestamp('avisado_em')->nullable();
            });
        }
        if (Schema::hasTable('cis_message_templates')) {
            $now = now();
            foreach ([
                'Proposta aprovada' => "Caro(a) {Primeiro Nome}, o seu pedido de crédito {Número da Proposta} foi aprovado: {Montante Aprovado} MT.\nDirija-se à nossa agência até {Válida Até} para levantar o crédito. Obrigado.",
                'Proposta recusada' => "Caro(a) {Primeiro Nome}, lamentamos informar que o seu pedido de crédito {Número da Proposta} não foi aprovado.\nPara mais informações contacte-nos. Obrigado.",
                'Proposta não levantada' => "Caro(a) {Primeiro Nome}, registámos que não levantou o crédito aprovado {Número da Proposta}.\nSe ainda precisar, contacte-nos para um novo pedido. Obrigado.",
            ] as $name => $body) {
                if (!DB::table('cis_message_templates')->where('name', $name)->exists()) {
                    DB::table('cis_message_templates')->insert(['name' => $name, 'body' => $body, 'created_at' => $now, 'updated_at' => $now]);
                }
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('cis_credit_proposals', 'avisado_estado')) {
            Schema::table('cis_credit_proposals', fn (Blueprint $t) => $t->dropColumn(['avisado_estado', 'avisado_em']));
        }
        if (Schema::hasColumn('cis_branches', 'credito_minimo')) {
            Schema::table('cis_branches', fn (Blueprint $t) => $t->dropColumn(['credito_minimo', 'credito_maximo', 'taxa_minima', 'taxa_maxima']));
        }
    }
};
