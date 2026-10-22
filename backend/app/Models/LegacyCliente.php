<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class LegacyCliente extends Model {
    protected $connection = 'mysql_legacy';
    protected $table = 'Clientes';
    protected $primaryKey = 'ID';
    public $timestamps = false;
    protected $guarded = ['*'];
    protected $hidden = ['Nº de BI', 'Contacto', 'Email', 'Nº da Casa', 'Rua'];
}
