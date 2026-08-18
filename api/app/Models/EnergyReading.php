<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnergyReading extends Model
{
    use HasFactory;

    protected $fillable = [
        'energy_device_id',
        'voltage',
        'current',
        'power',
        'energy',
        'recorded_at',
        'source',
    ];

    protected $casts = [
        'recorded_at' => 'datetime',
        'voltage'     => 'float',
        'current'     => 'float',
        'power'       => 'float',
        'energy'      => 'float',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(EnergyDevice::class, 'energy_device_id');
    }
}
