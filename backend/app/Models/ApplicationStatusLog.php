<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApplicationStatusLog extends Model
{
    protected $fillable = [
        'application_id', 'from_status', 'to_status', 'remarks', 'changed_by', 'read_at',
    ];

    protected $casts = [
        'read_at' => 'datetime',
    ];

    public function application()
    {
        return $this->belongsTo(Application::class);
    }

    public function changer()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    public static function record(Application $application, string $to, ?string $remarks = null, ?int $userId = null, ?string $from = null): self
    {
        return self::create([
            'application_id' => $application->id,
            'from_status' => $from,
            'to_status' => $to,
            'remarks' => $remarks,
            'changed_by' => $userId,
        ]);
    }
}
