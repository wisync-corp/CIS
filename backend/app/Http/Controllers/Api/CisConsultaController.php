<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LegacyCliente;
use App\Models\LegacyPrestacao;
use App\Models\LegacyExtracto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CisConsultaController extends Controller
{
    private function autorizar(): void
    {
        // Enquanto não existir controlo por carteira, agentes/cobradores não podem consultar listagens globais.
        Gate::authorize('cis.consultar.todos');
    }

    public function clientes(Request $request)
    {
        $this->autorizar();
        $validated = $request->validate(['q' => ['sometimes', 'string', 'max:155']]);
        $term = trim($validated['q'] ?? '');
        $query = LegacyCliente::query()->select([
            'ID', 'Numeração', 'Nome Completo', 'Bairro', 'Cidade', 'Provincia'
        ]);
        if ($term !== '') {
            $query->where(function ($q) use ($term) {
                $q->where('Nome Completo', 'like', '%'.addcslashes($term, '%_\\').'%');
                if (ctype_digit($term) && strlen($term) < 11) {
                    $q->orWhere('ID', (int) $term);
                }
            });
        }
        return $query->orderBy('ID')->paginate(20)->withQueryString();
    }


    public function cliente(Request $request, int $id)
    {
        $this->autorizar();
        // Apenas campos cadastrais essenciais, sem BI, contacto ou email.
        return LegacyCliente::query()
            ->select(['ID', 'Numeração', 'Nome Completo', 'Bairro', 'Cidade', 'Provincia'])
            ->where('ID', $id)
            ->firstOrFail();
    }

    public function contractos(Request $request)
    {
        $this->autorizar();
        $validated = $request->validate(['q' => ['sometimes', 'string', 'max:155']]);
        $term = trim($validated['q'] ?? '');
        $query = LegacyPrestacao::query()->select([
            'Ordem', 'Nr do Contracto', 'Nº da Parcela', 'Nº de Prestações', 'Nº de Cliente',
            'Quantia Creditada', 'Capital', 'Amortização', 'Valor de Juros',
            'Valor de Multa', 'Valor a Pagar', 'Valor Pago', 'Data de Cobrança',
            'Estado', 'Estado do Contrato'
        ]);
        if ($term !== '') {
            $query->where('Nr do Contracto', 'like', '%'.addcslashes($term, '%_\\').'%');
        }
        return $query->orderBy('Ordem')->paginate(20)->withQueryString();
    }

    public function prestacoes(Request $request, string $numero)
    {
        $this->autorizar();
        abort_if(mb_strlen($numero) > 155, 422);
        return LegacyPrestacao::query()
            ->where('Nr do Contracto', $numero)
            ->select([
                'Ordem', 'Nr do Contracto', 'Nº da Parcela', 'Nº de Prestações',
                'Capital', 'Amortização', 'Valor de Juros', 'Valor de Multa',
                'Valor a Pagar', 'Valor Pago', 'Data de Cobrança', 'Estado'
            ])->orderBy('Nº da Parcela')->get();
    }
    public function extracto(Request $request)
    {
        $this->autorizar();
        $filters = $request->validate([
            'contracto' => ['sometimes', 'string', 'max:155'],
            'inicio' => ['sometimes', 'date_format:Y-m-d'],
            'fim' => ['sometimes', 'date_format:Y-m-d'],
        ]);
        $query = LegacyExtracto::query()->select([
            'Nr', 'Contracto', 'Parcela', 'Tipo de Movimento',
            'Descrição', 'Data da Operação', 'Valor'
        ]);
        if (isset($filters['contracto']) && trim($filters['contracto']) !== '') {
            $query->where('Contracto', trim($filters['contracto']));
        }
        if (isset($filters['inicio'])) {
            $query->where('Data da Operação', '>=', $filters['inicio']);
        }
        if (isset($filters['fim'])) {
            $query->where('Data da Operação', '<=', $filters['fim']);
        }
        return $query->orderByDesc('Data da Operação')->orderByDesc('Nr')->paginate(20);
    }

}
