<?php

namespace App\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;

class EnergyDevice extends Model
{
    use HasFactory;

    protected $fillable = [
        'device_uid',
        'name',
        'location',
        'description',
        'photo',
        'added_by',
    ];

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }

    public function readings(): HasMany
    {
        return $this->hasMany(EnergyReading::class);
    }

    /**
     * Most recent reading for this device — used to power the "live"
     * card on the devices list without a separate query per row.
     */
    public function latestReading(): HasOne
    {
        return $this->hasOne(EnergyReading::class)->latestOfMany('recorded_at');
    }

    /**
     * A device counts as online if it has posted a reading within the
     * last 2 minutes.
     */
    public function getIsOnlineAttribute(): bool
    {
        $reading = $this->relationLoaded('latestReading')
            ? $this->latestReading
            : $this->latestReading()->first();

        if (!$reading) {
            return false;
        }

        return $reading->recorded_at->diffInSeconds(now()) < 120;
    }

    /**
     * Full public URL for the device photo, or null if none set.
     * `photo` column stores the storage path only (e.g.
     * "energy_devices/xyz.jpg"), this turns it into a usable <img src>.
     */
    public function getPhotoUrlAttribute(): ?string
    {
        return $this->photo ? Storage::disk('public')->url($this->photo) : null;
    }

    protected $appends = ['is_online', 'photo_url'];
}