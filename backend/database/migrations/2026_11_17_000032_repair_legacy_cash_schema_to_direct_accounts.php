<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reparação da instalação feita por uma imagem antiga.
 * APENAS se cis_cash_accounts e cis_cash_movements estiverem ambas vazias.
 * Mantém a migração 000030 histórica; nunca a reaplica.
 */
return new class extends Migration {
    public function up(): void
    {
        foreach (['Extracto','Contractos','cis_cash_accounts','cis_cash_movements','migrations'] as $table) {
            if (!Schema::hasTable($table)) {
                throw new RuntimeException('Reparação bloqueada: tabela inesperadamente ausente: '.$table);
            }
        }
        if (Schema::hasTable('cis_cash_links') || Schema::hasColumn('Extracto','account_id')
            || Schema::hasColumn('cis_cash_accounts','opening_extracto_nr')) {
            throw new RuntimeException('Reparação bloqueada: esquema diferente do legado identificado; não alterar automaticamente.');
        }
        if (!DB::table('migrations')->where('migration','2026_11_16_000030_create_cis_cash_tables')->exists()) {
            throw new RuntimeException('Reparação bloqueada: migração anterior não registada.');
        }
        if (DB::table('cis_cash_accounts')->exists() || DB::table('cis_cash_movements')->exists()) {
            throw new RuntimeException('Reparação bloqueada: existem dados em tabelas de Caixa.');
        }
        if (DB::getDriverName()==='mysql') {
            foreach (['Extracto','cis_cash_accounts','cis_cash_movements'] as $tableName) {
                $info=DB::selectOne('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$tableName]);
                if (!$info || strtoupper((string)$info->ENGINE)!=='INNODB') {
                    throw new RuntimeException('Reparação bloqueada: '.$tableName.' não é InnoDB.');
                }
            }
        }
        foreach (['Nr','Tipo de Movimento','Valor','Descrição','Data da Operação'] as $column) {
            if (!Schema::hasColumn('Extracto',$column)) {
                throw new RuntimeException('Reparação bloqueada: Extracto.'.$column.' em falta.');
            }
        }
        Schema::table('cis_cash_accounts',function(Blueprint $t) {
            $t->unsignedBigInteger('opening_extracto_nr')->nullable();
        });
        Schema::table('Extracto',function(Blueprint $t) {
            $t->unsignedBigInteger('account_id')->nullable()->index('cis_extracto_account_id_idx');
        });
        // Depois da migração, continua vazia; verifica novamente antes do DROP.
        if (DB::table('cis_cash_movements')->exists()) {
            throw new RuntimeException('Reparação parcialmente aplicada: movimentos apareceram; não remover tabela.');
        }
        Schema::drop('cis_cash_movements');
    }

    public function down(): void
    {
        // Reverter o esquema implicaria remover um campo financeiro e poderá
        // destruir contas e referências. A recuperação exige plano supervisionado.
        throw new RuntimeException('Reparação financeira não tem rollback automático. Recuperar a partir de backup validado.');
    }
};
