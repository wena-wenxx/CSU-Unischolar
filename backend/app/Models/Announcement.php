<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Announcement extends Model
{
    protected $fillable = ['title', 'body', 'image_path', 'posted_by', 'posted_at', 'expires_at'];

    protected $casts = [
        'posted_at' => 'datetime',
        'expires_at' => 'date:Y-m-d',
    ];

    public function author()
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    // Not yet expired (expires_at is the last day it is shown).
    public function scopeCurrent($query)
    {
        $today = Scholarship::today();

        return $query->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>=', $today));
    }

    // An uploaded picture (not a sample shipped with the frontend).
    public function hasUploadedImage(): bool
    {
        return $this->image_path && !str_starts_with($this->image_path, '/');
    }
}
