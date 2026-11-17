<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Campo de idempotência para lançamentos futuros da Caixa Geral.
 *
 * Exclusivamente uma coluna nullable UNIQUE no Extracto existente.
 * NULL preserva todas as operações históricas sem inventar identidades.
 * Uma transferência futura usará duas chaves distintas, correlacionadas,
 * para as duas pernas, inseridas na mesma transacção InnoDB.
 *
 * Nunca executar sem backup, ensaio restaurado e janela sem escritores.
 */
return new class extends Migration {
    private const INDEX='cis_extracto_operation_key_uq';

    public function up(): void
    {
        if (!Schema::hasTable('Extracto') || !Schema::hasTable('cis_cash_accounts')
            || !Schema::hasColumn('Extracto','account_id')) {
            throw new RuntimeException('Caixa directa não instalada; migração de idempotência bloqueada.');
        }
        if (Schema::hasColumn('Extracto','operation_key')) {
            throw new RuntimeException('Extracto.operation_key já existe. Inspeccionar estrutura; não reutilizar esta migração.');
        }
        if (Schema::hasTable('cis_cash_movements') || Schema::hasTable('cis_cash_links')) {
            throw new RuntimeException('Encontrado esquema de Caixa paralelo; migração bloqueada.');
        }
        if (DB::getDriverName()==='mysql') {
            $info=DB::selectOne("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Extracto'");
            if (!$info || strtoupper((string)$info->ENGINE)!=='INNODB') {
                throw new RuntimeException('Extracto não é InnoDB; abortado.');
            }
            $index=DB::selectOne('SELECT COUNT(*) AS n FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?',
                ['Extracto',self::INDEX]);
            if ($index && (int)$index->n!==0) {
                throw new RuntimeException('Índice de operação já existe com estrutura potencialmente diferente.');
            }
        }
        Schema::table('Extracto', function (Blueprint $t) {
            $t->string('operation_key',120)->nullable()->unique(self::INDEX);
        });
    }

    public function down(): void
    {
        // Mesmo sem utilização visível, remover uma chave financeira UNIQUE pode
        // permitir repetições de movimentos. Requer processo de recuperação manual.
        throw new RuntimeException('Rollback da idempotência financeira proibido; exige revisão e backup.');
    }
};
