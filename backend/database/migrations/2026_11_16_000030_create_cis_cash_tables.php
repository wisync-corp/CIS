<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Caixa Geral padrão: uma tabela nova, cis_cash_accounts,
 * e uma coluna NULLABLE account_id na tabela Extracto legada.
 * Não classifica movimentos históricos, não muda valores do Extracto.
 * NÃO executar sem preflight, backup/restauro testado e ensaio em cópia.
 */
return new class extends Migration {
    public function up(): void {
        if (Schema::hasTable('cis_cash_links') || Schema::hasTable('cis_cash_movements')) {
            throw new RuntimeException('Encontrada versão antiga de Caixa: migração automática bloqueada. Requer transição manual sem perda de dados.');
        }
        if (Schema::hasTable('cis_cash_accounts') || Schema::hasColumn('Extracto', 'account_id')) {
            throw new RuntimeException('Esquema Caixa já existe ou está parcialmente instalado. Não substituir nem eliminar tabelas automaticamente.');
        }
        if (!Schema::hasTable('Extracto')) {
            throw new RuntimeException('A tabela Extracto deve existir antes da migração.');
        }
        foreach (['Nr','Tipo de Movimento','Valor','Descrição','Data da Operação'] as $column) {
            if (!Schema::hasColumn('Extracto', $column)) {
                throw new RuntimeException('Extracto incompatível: falta a coluna '.$column);
            }
        }
        if (DB::getDriverName()==='mysql') {
            $table=DB::selectOne("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Extracto'");
            if (!$table || strtoupper((string)$table->ENGINE)!=='INNODB') {
                throw new RuntimeException('Extracto deve ser InnoDB; migração bloqueada.');
            }
            $nr=DB::selectOne("SELECT DATA_TYPE,COLUMN_TYPE,EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Extracto' AND COLUMN_NAME='Nr'");
            if (!$nr || !in_array(strtolower((string)$nr->DATA_TYPE),['int','bigint'],true)) {
                throw new RuntimeException('Tipo de Extracto.Nr não homologado para o marcador de abertura.');
            }
        }

        Schema::create('cis_cash_accounts', function (Blueprint $t) {
            $t->id();
            $t->string('code',40)->unique();
            $t->string('name',150);
            $t->enum('type',['banco','numerario','carteira_movel']);
            $t->string('identifier',120)->nullable();
            $t->char('currency',3)->default('MZN');
            $t->enum('status',['em_preparacao','pendente_validacao','activa','suspensa'])
                ->default('em_preparacao');
            $t->decimal('opening_balance',18,2)->nullable();
            $t->dateTime('cut_at')->nullable();
            // Maior Extracto.Nr no momento da validação do saldo.
            // Evita contar movimentos anteriores ao saldo de abertura.
            $t->unsignedBigInteger('opening_extracto_nr')->nullable();
            $t->string('evidence_reference',255)->nullable();
            $t->unsignedBigInteger('created_by');
            $t->unsignedBigInteger('validated_by')->nullable();
            $t->dateTime('validated_at')->nullable();
            $t->timestamps();
            $t->index(['status','currency']);
        });

        // Não-NUL para movimentos NOVOS será exigido na API financeira;
        // manter NULL para todos os Extracto históricos sem origem conhecida.
        Schema::table('Extracto', function (Blueprint $t) {
            $t->unsignedBigInteger('account_id')->nullable()->index('cis_extracto_account_id_idx');
        });
        // FK física adiada até homologar privilégios, tipos e escritores legados
        // no ambiente real. A API deve verificar conta, bloquear a linha,
        // e inserir Extracto na mesma transacção MySQL.
    }

    public function down(): void {
        // Não remover a coluna se já houver um Extracto a ela associado.
        if (Schema::hasTable('Extracto') && Schema::hasColumn('Extracto','account_id')
            && DB::table('Extracto')->whereNotNull('account_id')->exists()) {
            throw new RuntimeException('Extracto contém movimentos com conta: rollback destrutivo proibido.');
        }
        if (Schema::hasTable('cis_cash_accounts') && DB::table('cis_cash_accounts')->exists()) {
            throw new RuntimeException('Existem contas financeiras: rollback destrutivo proibido.');
        }
        if (Schema::hasColumn('Extracto','account_id')) {
            Schema::table('Extracto',function (Blueprint $t) {
                $t->dropIndex('cis_extracto_account_id_idx');
                $t->dropColumn('account_id');
            });
        }
        Schema::dropIfExists('cis_cash_accounts');
    }
};
