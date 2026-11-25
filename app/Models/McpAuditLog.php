<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class McpAuditLog extends Model
{
    protected $table = 'mcp_audit_log';

    public $timestamps = false;

    protected $fillable = ['tool', 'arguments', 'before', 'after', 'ip'];

    protected function casts(): array
    {
        return [
            'arguments' => 'array',
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
