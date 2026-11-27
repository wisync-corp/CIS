<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Permissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class RhSettingsController extends Controller
{
    private const PARAMETERS = [
        'DIAS_TRABALHADOS_MODO'=>'Preenchimento dos dias trabalhados dos mensalistas',
        'BASE_DIAS_MES'=>'Dias base do mês',
        'BASE_HORAS_DIA'=>'Horas base do dia',
        'VALOR_HORA'=>'Valor por hora',
        'INSS_CONDICAO_CANALIZAR'=>'Condição de canalização do INSS',
        'INSS_TAXA_COLAB'=>'Taxa de INSS do colaborador (decimal)',
        'INSS_TAXA_EMP'=>'Taxa de INSS da empresa (decimal)',
        'INSS_COLAB_ARRED_CASAS'=>'Casas decimais de INSS do colaborador',
        'INSS_EMP_ARRED_CASAS'=>'Casas decimais de INSS da empresa',
        'IRPS_ARRED_CASAS'=>'Casas decimais de IRPS',
        'ARRED_CASAS_DEFAULT'=>'Casas decimais predefinidas',
        'IRPS_ISENCAO_DEPARTAMENTO'=>'Departamento com isenção de IRPS',
        'IRPS_DEPENDENTES_MAX'=>'Limite de dependentes para IRPS',
    ];

    private function authorizeSettings(Request $request): void
    {
        Permissions::require($request->user(),'definicoes');
        abort_unless(Schema::hasTable('cis_rh_parameters') && Schema::hasTable('cis_rh_tax_tables'),
            503,'As Definições de RH precisam de instalação na VPS.');
    }

    public function index(Request $request)
    {
        $this->authorizeSettings($request);
        $parameters=DB::table('cis_rh_parameters')->orderByDesc('effective_from')->orderByDesc('id')->paginate(30);
        $tables=DB::table('cis_rh_tax_tables')->orderByDesc('effective_from')->orderByDesc('id')->limit(20)->get()
            ->map(function ($row) {
                $row->scales=json_decode($row->scales,true,512,JSON_THROW_ON_ERROR);
                return $row;
            });
        $ids=$parameters->getCollection()->pluck('created_by')->merge($tables->pluck('created_by'))->unique();
        $names=DB::table('users')->whereIn('id',$ids)->pluck('name','id');
        $parameters->through(function ($row) use ($names) {
            $row->author=$names[$row->created_by]??('Utilizador #'.$row->created_by);
            return $row;
        });
        $tables->each(function ($row) use ($names) {
            $row->author=$names[$row->created_by]??('Utilizador #'.$row->created_by);
        });
        return response()->json(['catalogue'=>self::PARAMETERS,'parameters'=>$parameters,'tax_tables'=>$tables])
            ->header('Cache-Control','no-store, private');
    }

    public function parameter(Request $request)
    {
        $this->authorizeSettings($request);
        $data=$request->validate([
            'code'=>['required','in:'.implode(',',array_keys(self::PARAMETERS))],
            'scope'=>['required','in:global,empresa,categoria,colaborador'],
            'company'=>['required_unless:scope,global','nullable','string','max:80'],
            'category'=>['required_if:scope,categoria','nullable','string','max:80'],
            'employee_code'=>['required_if:scope,colaborador','nullable','string','max:32'],
            'value'=>['required','string','max:100'],
            'effective_from'=>['required','date_format:Y-m-d'],
            'effective_to'=>['nullable','date_format:Y-m-d','after_or_equal:effective_from'],
            'reason'=>['required','string','max:255'],
        ]);
        $value=trim($data['value']);
        $code=$data['code'];
        if ($code==='DIAS_TRABALHADOS_MODO' && !in_array($value,['confirmar','base_mensal'],true)) {
            throw ValidationException::withMessages(['value'=>'Escolha confirmação manual ou dias base do mês.']);
        }
        $range=match ($code) {
            'BASE_DIAS_MES'=>[1,31], 'BASE_HORAS_DIA'=>[0.01,24],
            'INSS_TAXA_COLAB','INSS_TAXA_EMP'=>[0,1],
            'IRPS_DEPENDENTES_MAX'=>[0,100],
            'ARRED_CASAS_DEFAULT','INSS_COLAB_ARRED_CASAS','INSS_EMP_ARRED_CASAS','IRPS_ARRED_CASAS'=>[0,8],
            'VALOR_HORA'=>[0.01,9999999],
            default=>null,
        };
        if ($range!==null) {
            $value=str_replace(',','.',$value);
            $integer=str_ends_with($code,'_CASAS')||in_array($code,['BASE_DIAS_MES','IRPS_DEPENDENTES_MAX'],true);
            $format=$integer?'/^\d+$/D':'/^\d+(?:\.\d{1,8})?$/D';
            if (!preg_match($format,$value)||(float)$value<$range[0]||(float)$value>$range[1]) {
                throw ValidationException::withMessages(['value'=>'Indique um valor válido entre '.$range[0].' e '.$range[1].'.']);
            }
        }
        $scope=$data['scope'];
        $data['company']=$scope==='global'?'':trim($data['company']);
        $data['category']=$scope==='categoria'?trim($data['category']):'';
        $data['employee_code']=$scope==='colaborador'?trim($data['employee_code']):'';
        $data['value']=$value;
        $this->insertVersion('cis_rh_parameters',$data,$request);
        return response()->json(['saved'=>true],201);
    }

    public function taxTable(Request $request)
    {
        $this->authorizeSettings($request);
        $money=['required','numeric','min:0','max:9999999999','decimal:0,2'];
        $data=$request->validate([
            'effective_from'=>['required','date_format:Y-m-d'],
            'reason'=>['required','string','max:255'],
            'scales'=>['required','array','min:1','max:100'],
            'scales.*'=>['array:lower,upper,coefficient,d0,d1,d2,d3,d4'],
            'scales.*.lower'=>$money,
            'scales.*.upper'=>['present','nullable','numeric','min:0','max:9999999999','decimal:0,2'],
            'scales.*.coefficient'=>['required','numeric','min:0','max:1','decimal:0,8'],
            'scales.*.d0'=>$money,'scales.*.d1'=>$money,'scales.*.d2'=>$money,'scales.*.d3'=>$money,'scales.*.d4'=>$money,
        ]);
        $rows=array_values($data['scales']);
        usort($rows,fn ($a,$b)=>(float)$a['lower']<=>(float)$b['lower']);
        $next=0;
        foreach ($rows as $i=>$row) {
            $low=(int)round((float)$row['lower']*100);
            $high=$row['upper']===null?null:(int)round((float)$row['upper']*100);
            $last=$i===count($rows)-1;
            if ($low!==$next||($last!==($high===null))||($high!==null&&$high<$low)) {
                throw ValidationException::withMessages(['scales'=>'Indique escalões contínuos desde zero, sem sobreposição, e deixe o último limite superior em branco.']);
            }
            if ($high!==null) $next=$high+1;
        }
        $data['scales']=json_encode($rows,JSON_THROW_ON_ERROR);
        $this->insertVersion('cis_rh_tax_tables',$data,$request);
        return response()->json(['saved'=>true],201);
    }

    public function effective(Request $request)
    {
        $this->authorizeSettings($request);
        $data=$request->validate([
            'date'=>['required','date_format:Y-m-d'],
            'company'=>['nullable','string','max:80'],
            'category'=>['nullable','string','max:80'],
            'employee_code'=>['nullable','string','max:32'],
        ]);
        return response()->json(self::rulesFor($data['date'],$data['company']??'',$data['category']??'',$data['employee_code']??''))
            ->header('Cache-Control','no-store, private');
    }

    public static function rulesFor(string $date,string $company,string $category,string $employee): array
    {
        $parameters=DB::table('cis_rh_parameters')
            ->where('effective_from','<=',$date)
            ->where(fn ($q)=>$q->whereNull('effective_to')->orWhere('effective_to','>=',$date))
            ->where(function ($q) use ($company,$category,$employee) {
                $q->where('scope','global');
                if ($company==='') return;
                $q->orWhere(fn ($s)=>$s->where('scope','empresa')->where('company',$company));
                if ($category!=='') $q->orWhere(fn ($s)=>$s->where('scope','categoria')->where('company',$company)->where('category',$category));
                if ($employee!=='') $q->orWhere(fn ($s)=>$s->where('scope','colaborador')->where('company',$company)->where('employee_code',$employee));
            })->orderByDesc('effective_from')->orderByDesc('id')->get()
            ->unique(fn ($p)=>$p->code.'|'.$p->scope)->values()
            ->map(fn ($p)=>[
                'Codigo_Parametro'=>$p->code,'Valor'=>$p->value,
                'Tipo_Valor'=>in_array($p->code,['INSS_CONDICAO_CANALIZAR','IRPS_ISENCAO_DEPARTAMENTO','DIAS_TRABALHADOS_MODO'],true)?'Texto':'Decimal',
                'Nivel_Aplicacao'=>$p->scope,'Empresa'=>$p->company,'Categoria'=>$p->category,
                'Codigo_Colaborador'=>$p->employee_code,'Vigencia_Inicio'=>$p->effective_from,
                'Vigencia_Fim'=>$p->effective_to,'Versao'=>$p->id,'Estado'=>'Activo',
            ]);
        $table=DB::table('cis_rh_tax_tables')->where('effective_from','<=',$date)->orderByDesc('effective_from')->first();
        $tableId=$table?'IRPS-'.$table->id:null;
        $scales=$table?array_map(fn ($scale)=>[
            'ID_Tabela_Escaloes'=>$tableId,'Limite_Inferior'=>$scale['lower'],'Limite_Superior'=>$scale['upper'],
            'Coeficiente_Adicional'=>$scale['coefficient'],'Dependentes_0'=>$scale['d0'],'Dependentes_1'=>$scale['d1'],
            'Dependentes_2'=>$scale['d2'],'Dependentes_3'=>$scale['d3'],'Dependentes_4_Mais'=>$scale['d4'],
            'Nivel_Aplicacao'=>'Global','Estado'=>'Activo','Vigencia_Inicio'=>$table->effective_from,'Versao'=>$table->id,
        ],json_decode($table->scales,true,512,JSON_THROW_ON_ERROR)):[];
        return ['dataReferencia'=>$date,'contexto'=>['empresa'=>$company,'categoria'=>$category,'codigo'=>$employee],
            'parametros'=>$parameters->all(),'tabelaIRPS'=>$tableId,'escaloes'=>$scales];
    }

    private function insertVersion(string $table,array $data,Request $request): void
    {
        try {
            DB::table($table)->insert($data+['created_by'=>$request->user()->id,'created_at'=>now('Africa/Maputo')]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            abort(409,'Já existe uma versão para este âmbito e data de início.');
        }
    }
}
