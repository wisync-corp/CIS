<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mutuários ao nível do GEMIC (sem alterar a tabela Clientes):
 *  - perfil CIS do mutuário: pessoa singular ou colectiva (empresa), dados da empresa e fotografia;
 *  - lista negra (central de risco interna), com histórico de entradas e saídas;
 *  - avalista/fiador ligado a um mutuário já registado.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('cis_client_profile')) {
            Schema::create('cis_client_profile', function (Blueprint $t) {
                $t->unsignedBigInteger('client_id')->primary();
                $t->string('tipo', 12)->default('singular');
                $t->string('forma_juridica', 40)->nullable();
                $t->string('nuit', 20)->nullable()->index();
                $t->string('actividade', 160)->nullable();
                $t->date('data_constituicao')->nullable();
                $t->string('representante_nome', 160)->nullable();
                $t->string('representante_cargo', 80)->nullable();
                $t->string('representante_documento', 80)->nullable();
                $t->string('representante_contacto', 40)->nullable();
                $t->unsignedBigInteger('foto_document_id')->nullable();
                $t->unsignedBigInteger('updated_by')->nullable();
                $t->timestamps();
            });
        }
        if (!Schema::hasTable('cis_client_blacklist')) {
            Schema::create('cis_client_blacklist', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('client_id')->index();
                $t->string('motivo', 255);
                $t->unsignedBigInteger('added_by')->nullable();
                $t->timestamp('created_at')->nullable();
                $t->timestamp('removed_at')->nullable();
                $t->unsignedBigInteger('removed_by')->nullable();
                $t->string('removed_motivo', 255)->nullable();
            });
        }
        if (Schema::hasTable('cis_guarantors') && !Schema::hasColumn('cis_guarantors', 'guarantor_client_id')) {
            Schema::table('cis_guarantors', function (Blueprint $t) {
                $t->unsignedBigInteger('guarantor_client_id')->nullable()->index()->after('client_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('cis_guarantors', 'guarantor_client_id')) {
            Schema::table('cis_guarantors', fn (Blueprint $t) => $t->dropColumn('guarantor_client_id'));
        }
        Schema::dropIfExists('cis_client_blacklist');
        Schema::dropIfExists('cis_client_profile');
    }
};
