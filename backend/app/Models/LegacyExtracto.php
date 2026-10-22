<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LegacyExtracto extends Model
{
    protected $connection = 'mysql_legacy';
    protected $table = 'Extracto';
    protected $primaryKey = 'Nr';
    public $timestamps = false;
    protected $guarded = ['*'];
}
