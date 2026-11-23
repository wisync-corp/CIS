<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\LegacyUserVerifier;
use App\Support\Permissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
class AuthController extends Controller {
    private static function payload(User $user): array {
        return ['name'=>$user->name,'email'=>$user->email,'role'=>$user->role,
            'role_label'=>Permissions::ROLES[$user->role]['label']??$user->role,
            'permissions'=>Permissions::of($user),
            'must_change_password'=>(bool)($user->must_change_password??false),
            'agencia'=>($b=\App\Support\Branches::userBranch($user))!==null?['id'=>$b,'nome'=>\App\Support\Branches::label($b)]:null];
    }
    public function login(Request $request, LegacyUserVerifier $legacyVerifier) {
        $credentials=$request->validate(['email'=>['required','email'],'password'=>['required','string']]);
        $user=User::query()->where('email',strtolower(trim($credentials['email'])))->first();
        if (!$user || !$user->active ||
            !(Hash::check($credentials['password'],$user->password) ||
              $legacyVerifier->verify($user, $credentials['password']))) {
            throw ValidationException::withMessages(['email'=>'Credenciais inválidas.']);
        }
        // Limita sessões activas na primeira versão do CIS.
        $user->tokens()->delete();
        $token=$user->createToken('cis-web',['read'])->plainTextToken;
        if (Schema::hasColumn('users','last_login_at')) $user->forceFill(['last_login_at'=>now()])->saveQuietly();
        return response()->json(['token'=>$token,'user'=>self::payload($user)]);
    }
    public function me(Request $request) {
        $user=$request->user(); abort_unless($user->active,403);
        return self::payload($user);
    }
    /** O próprio utilizador troca a palavra-passe (obrigatório após palavra-passe temporária). */
    public function changePassword(Request $request) {
        $user=$request->user(); abort_unless($user && $user->active,403);
        $d=$request->validate([
            'actual'=>['required','string'],
            'nova'=>['required','string','min:10','max:120','confirmed','regex:/[A-Za-z]/','regex:/[0-9]/'],
        ],['nova.min'=>'A nova palavra-passe tem de ter pelo menos 10 caracteres.','nova.confirmed'=>'A confirmação não coincide.',
           'nova.regex'=>'Use letras e números na nova palavra-passe.']);
        if (!Hash::check($d['actual'],$user->password)) throw ValidationException::withMessages(['actual'=>'A palavra-passe actual está errada.']);
        if (Hash::check($d['nova'],$user->password)) throw ValidationException::withMessages(['nova'=>'A nova palavra-passe tem de ser diferente da actual.']);
        $keep=$user->currentAccessToken()?->id;
        DB::transaction(function () use ($user,$d,$keep) {
            $user->forceFill(['password'=>$d['nova']]+(Schema::hasColumn('users','must_change_password')?['must_change_password'=>false]:[]))->save();
            $user->tokens()->when($keep,fn($q)=>$q->where('id','!=',$keep))->delete();
            if (Schema::hasTable('cis_user_audit')) DB::table('cis_user_audit')->insert(['actor_id'=>$user->id,'user_id'=>$user->id,'accao'=>'palavra_passe_alterada','detalhe'=>null,'created_at'=>now()]);
        });
        return response()->json(['notice'=>'Palavra-passe alterada.','user'=>self::payload($user->fresh())]);
    }
    public function logout(Request $request) {
        $request->user()->currentAccessToken()?->delete();
        return response()->noContent();
    }
}
