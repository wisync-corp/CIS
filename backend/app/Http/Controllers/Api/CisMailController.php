<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CisMail;
use App\Support\Permissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class CisMailController extends Controller
{
    private const PURPOSES='geral,cobrancas,documentos,subscricoes';

    private function ready(): void
    {
        abort_unless(Schema::hasTable('cis_mail_accounts'),503,'Execute a instalação do módulo de e-mail na VPS.');
    }

    private function admin(Request $r): void
    {
        Permissions::require($r->user(),'definicoes');
        $this->ready();
    }

    private function account(int $id): object
    {
        $a=DB::table('cis_mail_accounts')->where('id',$id)->first();
        abort_unless($a,404,'Conta de envio desconhecida.');
        return $a;
    }

    public function accounts(Request $r)
    {
        $this->admin($r);
        $list=DB::table('cis_mail_accounts')->orderBy('label')->select('id','provider','sender','label','tested_at')
            ->selectRaw("CASE WHEN provider = 'smtp' OR authorization IS NOT NULL THEN 1 ELSE 0 END AS authorized")->get();
        return response()->json(['accounts'=>$list,'defaults'=>DB::table('cis_mail_defaults')->pluck('account_id','purpose'),
            'callback'=>$this->redirect()]);
    }

    public function save(Request $r)
    {
        $this->admin($r);
        $d=$r->validate([
            'label'=>['required','string','max:120'],
            'sender'=>['required','email:rfc','max:254'],
            'provider'=>['required','in:smtp,microsoft,google'],
            'tenant'=>['required_if:provider,microsoft','nullable','string','regex:/^[a-zA-Z0-9][a-zA-Z0-9.-]{0,119}$/D'],
            'client_id'=>['required_unless:provider,smtp','nullable','string','max:250'],
            'secret'=>['required','string','max:1024'],
            'host'=>['required_if:provider,smtp','nullable','string','regex:/^[a-zA-Z0-9][a-zA-Z0-9.-]{0,252}$/D'],
            'port'=>['required_if:provider,smtp','nullable','integer','between:1,65535'],
            'security'=>['required_if:provider,smtp','nullable','in:tls,starttls'],
            'username'=>['required_if:provider,smtp','nullable','string','max:254'],
        ]);
        $sender=strtolower($d['sender']);
        DB::table('cis_mail_accounts')->upsert([[
            'sender'=>$sender,'label'=>$d['label'],'provider'=>$d['provider'],
            'configuration'=>CisMail::seal(array_diff_key($d,array_flip(['sender','label','provider']))),
            'authorization'=>null,'tested_at'=>null,'created_at'=>now(),'updated_at'=>now(),
        ]],['sender'],['label','provider','configuration','authorization','tested_at','updated_at']);
        return $this->accounts($r);
    }

    private function redirect(): string
    {
        return trim((string)env('CIS_MAIL_CALLBACK_URL','')) ?: rtrim((string)config('app.url'),'/').'/api/cis/email/oauth/callback';
    }

    public function oauth(Request $r,int $id)
    {
        $this->admin($r);
        $a=$this->account($id);
        abort_if($a->provider==='smtp',422,'A conta SMTP só precisa de um envio de teste.');
        [$cfg,$url,,$scope]=CisMail::oauth($a);
        $state=bin2hex(random_bytes(32));
        $verifier=bin2hex(random_bytes(32));
        $redirect=$this->redirect();
        Cache::put('cis-mail-oauth:'.$state,[
            'id'=>$id,'config_hash'=>hash('sha256',$a->configuration),'verifier'=>$verifier,'redirect'=>$redirect,
        ],now()->addMinutes(10));
        $q=['client_id'=>$cfg['client_id'],'response_type'=>'code','redirect_uri'=>$redirect,
            'scope'=>$scope,'state'=>$state,'login_hint'=>$a->sender,'prompt'=>'consent',
            'code_challenge_method'=>'S256',
            'code_challenge'=>rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'=')];
        if ($a->provider==='google') $q['access_type']='offline';
        return response()->json(['url'=>$url.'?'.http_build_query($q,'','&',PHP_QUERY_RFC3986)]);
    }

    public function callback(Request $r)
    {
        $this->ready();
        $state=$r->query('state');
        $code=$r->query('code');
        abort_unless(is_string($state)&&preg_match('/^[a-f0-9]{64}$/D',$state),422,'Ligação expirada.');
        $key='cis-mail-oauth:'.$state;
        $data=Cache::lock($key.':lock',10)->block(3,fn()=>Cache::pull($key));
        abort_unless($data&&is_string($code)&&$code!==''&&strlen($code)<=4096,422,'Ligação expirada ou autorização recusada.');
        $a=$this->account($data['id']);
        abort_unless(hash_equals($data['config_hash'],hash('sha256',$a->configuration)),409,'A configuração foi alterada.');
        try {
            CisMail::authorize($a,$code,$data['verifier'],$data['redirect']);
        } catch (\Throwable) {
            abort(422,'O fornecedor recusou a ligação. Verifique o remetente autorizado, as permissões e o registo da aplicação.');
        }
        return response('Conta autorizada. Volte ao CIS, actualize a lista e envie um e-mail de teste.',200,
            ['Content-Type'=>'text/plain; charset=UTF-8','Cache-Control'=>'no-store']);
    }

    public function setDefault(Request $r)
    {
        $this->admin($r);
        $d=$r->validate(['purpose'=>['required','in:'.self::PURPOSES],'account_id'=>['required','integer']]);
        $a=$this->account((int)$d['account_id']);
        abort_unless($a->tested_at,422,'Teste primeiro a conta de envio.');
        DB::table('cis_mail_defaults')->updateOrInsert(['purpose'=>$d['purpose']],['account_id'=>$a->id]);
        return $this->accounts($r);
    }

    public function options(Request $r)
    {
        Permissions::require($r->user(),'mensagens');
        $this->ready();
        return response()->json(['accounts'=>DB::table('cis_mail_accounts')->whereNotNull('tested_at')->orderBy('label')
            ->get(['id','label','sender','provider']),'defaults'=>DB::table('cis_mail_defaults')->pluck('account_id','purpose'),
            'history'=>DB::table('cis_mail_history')->where('user_id',$r->user()->id)->orderByDesc('id')->limit(30)
                ->get(['id','sender','recipient','subject','purpose','status','created_at'])]);
    }

    public function test(Request $r,int $id)
    {
        $this->admin($r);
        $d=$r->validate(['recipient'=>['required','email:rfc','max:254']]);
        $a=$this->account($id);
        $this->deliver($r,$a,$d['recipient'],'Teste de envio do CIS','A conta de e-mail foi testada no CIS.','teste');
        DB::table('cis_mail_accounts')->where('id',$id)->where('configuration',$a->configuration)
            ->update(['tested_at'=>now(),'updated_at'=>now()]);
        return $this->accounts($r);
    }

    public function send(Request $r)
    {
        Permissions::require($r->user(),'mensagens');
        $this->ready();
        $d=$r->validate(['purpose'=>['required','in:'.self::PURPOSES],'account_id'=>['nullable','integer'],
            'recipient'=>['required','email:rfc','max:254'],'subject'=>['required','string','max:200','not_regex:/[\r\n]/'],
            'body'=>['required','string','max:15000']]);
        $id=$d['account_id']??DB::table('cis_mail_defaults')->where('purpose',$d['purpose'])->value('account_id')
            ??DB::table('cis_mail_defaults')->where('purpose','geral')->value('account_id');
        abort_unless($id,422,'Escolha uma conta de envio.');
        $a=$this->account((int)$id);
        abort_unless($a->tested_at,422,'Esta conta precisa de um teste antes de enviar.');
        $this->deliver($r,$a,$d['recipient'],$d['subject'],$d['body'],$d['purpose']);
        return response()->json(['status'=>'aceite'],201);
    }

    private function deliver(Request $r,object $a,string $to,string $subject,string $body,string $purpose): void
    {
        $id=DB::table('cis_mail_history')->insertGetId([
            'user_id'=>$r->user()->id,'sender'=>$a->sender,'recipient'=>$to,'subject'=>$subject,
            'purpose'=>$purpose,'status'=>'em_envio','created_at'=>now(),
        ]);
        try {
            CisMail::send($a,$to,$subject,$body);
        } catch (\Throwable) {
            DB::table('cis_mail_history')->where('id',$id)->update(['status'=>'por_confirmar']);
            abort(502,'Envio por confirmar. Consulte a caixa de enviados ou o fornecedor antes de repetir.');
        }
        DB::table('cis_mail_history')->where('id',$id)->update(['status'=>'aceite']);
    }
}
