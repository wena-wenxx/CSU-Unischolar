<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollRecord extends Model
{
    protected $fillable = ['scholar_record_id', 'amount', 'period', 'bank_atm_status', 'signature_status'];

    public function scholarRecord()
    {
        return $this->belongsTo(ScholarRecord::class);
    }
}