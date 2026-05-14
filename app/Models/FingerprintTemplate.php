<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FingerprintTemplate extends Model
{
    protected $connection = 'dtr';
    protected $table      = 'fingerprint_templates';
    protected $primaryKey = 'id';

    public $timestamps = true;

    protected $fillable = [
        'employid',
        'template_data',
        'fmd_data',
        'device_type',
        'finger_index',
        'quality',
        'registered_by',
        'is_active',
    ];

    // Still hide from general serialization to avoid huge JSON payloads
    // but we expose it explicitly when needed for verification
    protected $hidden = [
        'template_data',
    ];

    protected $casts = [
        'finger_index' => 'integer',
        'quality'      => 'integer',
        'is_active'    => 'boolean',
    ];

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', 1);
    }

    // ── Verification helper ───────────────────────────────────────────────────

    /**
     * Returns the template as a standard base64 string,
     * ready to be sent to the browser as data:image/png;base64,...
     */
    public function getTemplateBase64(): string
    {
        return $this->template_data;
    }

    // ── Relationships ─────────────────────────────────────────────────────────

    public function employee()
    {
        return $this->belongsTo(EmployeeMasterlist::class, 'employid', 'EMPLOYID');
    }
}