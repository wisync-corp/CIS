<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dossier do crédito (nível GEMIC):
 *  - fiadores e garantias de cada proposta;
 *  - documentos digitalizados do mutuário e da proposta (guardados na própria base, com a cópia de segurança);
 *  - agência de cada mutuário (para a lista de mutuários por agência).
 * Só tabelas do CIS; não altera Clientes, Contractos nem Extracto.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('cis_guarantors')) {
            Schema::create('cis_guarantors', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('proposal_id')->index();
                $t->unsignedBigInteger('client_id')->index();
                $t->string('nome', 160);
                $t->string('bi', 40)->nullable();
                $t->string('contacto', 40)->nullable();
                $t->string('relacao', 60)->nullable();
                $t->string('morada', 255)->nullable();
                $t->string('actividade', 160)->nullable();
                $t->decimal('rendimento_mensal', 14, 2)->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
            });
        }
        if (!Schema::hasTable('cis_collaterals')) {
            Schema::create('cis_collaterals', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('proposal_id')->index();
                $t->unsignedBigInteger('client_id')->index();
                $t->string('tipo', 40);
                $t->string('descricao', 255);
                $t->decimal('valor_estimado', 14, 2);
                $t->string('localizacao', 160)->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
            });
        }
        if (!Schema::hasTable('cis_documents')) {
            Schema::create('cis_documents', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('client_id')->index();
                $t->unsignedBigInteger('proposal_id')->nullable()->index();
                $t->string('tipo', 40);
                $t->string('descricao', 160)->nullable();
                $t->string('nome', 190);
                $t->string('mime', 100);
                $t->unsignedInteger('tamanho');
                $t->char('sha256', 64);
                $t->unsignedBigInteger('uploaded_by')->nullable();
                $t->timestamp('created_at')->nullable();
                $t->timestamp('deleted_at')->nullable();
                $t->unsignedBigInteger('deleted_by')->nullable();
            });
        }
        if (!Schema::hasTable('cis_document_files')) {
            Schema::create('cis_document_files', function (Blueprint $t) {
                $t->unsignedBigInteger('document_id')->primary();
                $t->binary('conteudo');
            });
            if (DB::getDriverName() === 'mysql') DB::statement('ALTER TABLE cis_document_files MODIFY conteudo MEDIUMBLOB NOT NULL');
        }
        if (!Schema::hasTable('cis_client_branch')) {
            Schema::create('cis_client_branch', function (Blueprint $t) {
                $t->unsignedBigInteger('client_id')->primary();
                $t->unsignedBigInteger('branch_id')->index();
                $t->unsignedBigInteger('assigned_by')->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        foreach (['cis_client_branch', 'cis_document_files', 'cis_documents', 'cis_collaterals', 'cis_guarantors'] as $t) Schema::dropIfExists($t);
    }
};
