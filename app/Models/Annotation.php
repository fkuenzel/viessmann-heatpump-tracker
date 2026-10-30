<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Annotation extends Model
{
    use HasFactory;

    protected $fillable = [
        'timestamp',
        'note',
        'category',
    ];

    protected function casts(): array
    {
        return [
            'timestamp' => 'datetime',
        ];
    }
}
