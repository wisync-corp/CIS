<?php
namespace App\Models;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Notifications\Notifiable;
class User extends Authenticatable {
    use HasApiTokens, Notifiable;
    protected $fillable=['name','email','password','role','active','legacy_username'];
    protected $hidden=['password','remember_token'];
    protected function casts(): array { return ['password'=>'hashed','active'=>'boolean']; }
}
