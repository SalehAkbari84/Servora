<?php
// Author: Saleh Akbari <saleh.akbari.programmer@gmail.com>

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AuditLog is an append-only table.
 * It has only created_at, no updated_at.
 */
class AuditLog extends Model
{
    protected $table = 'audit_log';

    // Append-only: only created_at exists
    const UPDATED_AT = null;

    protected $fillable = [
        'entity_type',
        'entity_id',
        'action',
        'description',
        'performed_by',
        'ip_address',
    ];

    protected $casts = [
        'entity_id'    => 'integer',
        'performed_by' => 'integer',
        'created_at'   => 'datetime',
    ];

    // -------------------------------------------------------
    // Relationships
    // -------------------------------------------------------

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
