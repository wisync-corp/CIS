<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\LegacyCliente;
use App\Models\LegacyPrestacao;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
class CisConsultaController extends Controller {
    private function autorizar(): void {
        // Até serem implementados filtros por agência/carteira, negar consulta a agente/cobrador.
        Gate::authorize('cis.consultar.todos');
    }
    public function clientes(Request $request) {
        $this->autorizar();
        return LegacyCliente::query()->select(['ID','Numeração','Nome Completo','Bairro','Cidade','Provincia'])
            ->orderBy('ID')->paginate(20);
    }
    public function contractos(Request $request) {
        $this->autorizar();
        return LegacyPrestacao::query()->select([
            'Ordem','Nr do Contracto','Nº da Parcela','Nº de Prestações','Nº de Cliente',
            'Quantia Creditada','Capital','Amortização','Valor de Juros',
            'Valor de Multa','Valor a Pagar','Valor Pago','Data de Cobrança',
            'Estado','Estado do Contrato'
        ])->orderBy('Ordem')->paginate(20);
    }
    public function prestacoes(Request $request,string $numero) {
        $this->autorizar();
        abort_if(strlen($numero)>155,422);
        return LegacyPrestacao::query()->where('Nr do Contracto',$numero)
            ->select(['Ordem','Nr do Contracto','Nº da Parcela','Nº de Prestações',
                'Capital','Amortização','Valor de Juros','Valor de Multa',
                'Valor a Pagar','Valor Pago','Data de Cobrança','Estado'])
            ->orderBy('Nº da Parcela')->get();
    }
}