<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
class AuthController extends Controller {
    public function login(Request $request) {
        $credentials=$request->validate(['email'=>['required','email'],'password'=>['required','string']]);
        if (!Auth::attempt($credentials)) throw ValidationException::withMessages(['email'=>'Credenciais inválidas.']);
        $user=Auth::user();
        if (!$user->active) { Auth::logout(); throw ValidationException::withMessages(['email'=>'Conta desactivada.']); }
        $user->tokens()->delete();
        $token=$user->createToken('cis-web', ['read'])->plainTextToken;
        Auth::logout();
        return response()->json(['token'=>$token,'user'=>['name'=>$user->name,'email'=>$user->email,'role'=>$user->role]]);
    }
    public function me(Request $request) {
        $user=$request->user();
        abort_unless($user->active,403);
        return ['name'=>$user->name,'email'=>$user->email,'role'=>$user->role];
    }
    public function logout(Request $request) { $request->user()->currentAccessToken()?->delete(); return response()->noContent(); }
}
