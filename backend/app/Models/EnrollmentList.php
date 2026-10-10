<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EnrollmentList extends Model
{
    protected $fillable = ['period', 'file_name', 'uploaded_by', 'rows_count'];

    public function entries() { return $this->hasMany(EnrollmentListEntry::class); }
    public function uploader() { return $this->belongsTo(User::class, 'uploaded_by'); }

    /** "221-00462", " 221 00462 ", "221-00462 " all compare the same. */
    public static function normalizeId(?string $id): string
    {
        return strtoupper(preg_replace('/[\s\-_.]/', '', (string) $id));
    }

    /** Lower case, no accents, letters only: "Dela Cruz" == "DELA CRUZ" == "dela-cruz". */
    public static function normalizeName(?string $name): string
    {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT', (string) $name);

        return preg_replace('/[^a-z]/', '', strtolower($ascii !== false ? $ascii : (string) $name));
    }
}
