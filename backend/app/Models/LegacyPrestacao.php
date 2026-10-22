<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class LegacyPrestacao extends Model {
    protected $connection = 'mysql_legacy';
    protected $table = 'Contractos';
    protected $primaryKey = 'Ordem';
    public $timestamps = false;
    protected $guarded = ['*'];
}
