<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'title',
    'author',
    'original_filename',
    'stored_path',
    'format',
    'size_bytes',
    'uploaded_at',
])]
class Book extends Model
{
    use HasUuids, SoftDeletes;

    public $timestamps = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'uploaded_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }
}
