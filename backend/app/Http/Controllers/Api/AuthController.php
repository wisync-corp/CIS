<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
class AuthController extends Controller {
    public function login(Request $request) {
        $credentials=$request->validate(['email'=>['required','email'],'password'=>['required','string']]);
        $user=User::query()->where('email',$credentials['email'])->first();
        if (!$user || !$user->active || !Hash::check($credentials['password'],$user->password)) {
            throw ValidationException::withMessages(['email'=>'Credenciais inválidas.']);
        }
        // Limita sessões activas na primeira versão do CIS.
        $user->tokens()->delete();
        $token=$user->createToken('cis-web',['read'])->plainTextToken;
        return response()->json([
            'token'=>$token,
            'user'=>['name'=>$user->name,'email'=>$user->email,'role'=>$user->role]
        ]);
    }
    public function me(Request $request) {
        $user=$request->user(); abort_unless($user->active,403);
        return ['name'=>$user->name,'email'=>$user->email,'role'=>$user->role];
    }
    public function logout(Request $request) {
        $request->user()->currentAccessToken()?->delete();
        return response()->noContent();
    }
}
