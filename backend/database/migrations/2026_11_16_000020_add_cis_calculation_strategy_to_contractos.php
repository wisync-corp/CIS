<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Alteração autorizada: apenas uma coluna textual e opcional.
        // Preserva contratos históricos sem inventar estratégia para linhas antigas.
        abort_unless(Schema::hasTable('Contractos'), 503,
            'A tabela original Contractos deve existir antes desta migração.');
        if (!Schema::hasColumn('Contractos', 'Estratégia de Cálculo')) {
            Schema::table('Contractos', function (Blueprint $table) {
                $table->string('Estratégia de Cálculo', 90)->nullable()
                    ->comment('Estratégia seleccionada na abertura CIS; contratos antigos permanecem NULL');
            });
        }
    }

    public function down(): void
    {
        // Não eliminar automaticamente um campo com opções financeiras já gravadas.
        // A reversão deve ser aprovada e precedida de backup.
    }
};
