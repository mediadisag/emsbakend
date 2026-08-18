<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EnergyDevice;
use App\Models\EnergyReading;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Carbon;

class EnergyDeviceController extends Controller
{
    /**
     * GET /api/energy-devices
     * List every registered device with its latest reading attached,
     * for the "Live Readings" cards.
     */
    public function index()
    {
        $devices = EnergyDevice::with(['latestReading', 'addedBy:id,username,name'])
            ->orderBy('name')
            ->get()
            ->map(fn (EnergyDevice $d) => $this->formatDevice($d));

        return response()->json([
            'status' => true,
            'rows'   => $devices,
        ]);
    }

    /**
     * POST /api/energy-devices
     * Register a device UID (metadata only — name/location/description/
     * photo). Send as multipart/form-data if a photo is included.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'device_uid'  => ['required', 'string', 'max:100', 'unique:energy_devices,device_uid'],
            'name'        => ['required', 'string', 'max:255'],
            'location'    => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'photo'       => ['nullable', 'image', 'max:4096'], // 4MB max
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => false,
                'message' => $validator->errors()->first(),
                'errors'  => $validator->errors(),
            ], 422);
        }

        $photoPath = null;
        if ($request->hasFile('photo')) {
            $photoPath = $request->file('photo')->store('energy_devices', 'public');
        }

        $device = EnergyDevice::create([
            'device_uid'  => $request->string('device_uid'),
            'name'        => $request->string('name'),
            'location'    => $request->input('location'),
            'description' => $request->input('description'),
            'photo'       => $photoPath,
            'added_by'    => $request->user()?->id,
        ]);

        return response()->json([
            'status'  => true,
            'message' => 'Device added.',
            'row'     => $this->formatDevice($device->load('addedBy:id,username,name')),
        ], 201);
    }

    /**
     * PUT /api/energy-devices/{id}
     * Update name/location/description, and optionally replace the
     * photo. device_uid is intentionally not editable — it's the
     * hardware's own identifier.
     *
     * NOTE: since this accepts a file upload, calls MUST be sent as
     * multipart/form-data with a `_method=PUT` field (Laravel's method
     * spoofing) rather than a real HTTP PUT — browsers/PHP don't parse
     * $_FILES on native PUT requests. See the frontend fetch() call.
     */
    public function update(Request $request, EnergyDevice $energyDevice)
    {
        $validator = Validator::make($request->all(), [
            'name'        => ['required', 'string', 'max:255'],
            'location'    => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'photo'       => ['nullable', 'image', 'max:4096'],
            'remove_photo'=> ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $data = $request->only('name', 'location', 'description');

        if ($request->hasFile('photo')) {
            if ($energyDevice->photo) {
                Storage::disk('public')->delete($energyDevice->photo);
            }
            $data['photo'] = $request->file('photo')->store('energy_devices', 'public');
        } elseif ($request->boolean('remove_photo')) {
            if ($energyDevice->photo) {
                Storage::disk('public')->delete($energyDevice->photo);
            }
            $data['photo'] = null;
        }

        $energyDevice->update($data);

        return response()->json([
            'status' => true,
            'row'    => $this->formatDevice($energyDevice->fresh(['latestReading', 'addedBy:id,username,name'])),
        ]);
    }

    /**
     * DELETE /api/energy-devices/{id}
     * Removes the device (and its stored photo + readings via cascade).
     */
    public function destroy(EnergyDevice $energyDevice)
    {
        if ($energyDevice->photo) {
            Storage::disk('public')->delete($energyDevice->photo);
        }
        $energyDevice->delete();

        return response()->json(['status' => true, 'message' => 'Device removed.']);
    }

    /**
     * POST /api/energy-devices/{uid}/readings
     * Ingest a single reading for a device, identified by its
     * device_uid (the hardware's own id, not the DB row id).
     */
    public function storeReading(Request $request, string $uid)
    {
        $device = EnergyDevice::where('device_uid', $uid)->first();

        if (!$device) {
            return response()->json([
                'status'  => false,
                'message' => 'Unknown device_uid. Register the device first via POST /energy-devices.',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'voltage'     => ['nullable', 'numeric'],
            'current'     => ['nullable', 'numeric'],
            'power'       => ['nullable', 'numeric'],
            'energy'      => ['nullable', 'numeric'],
            'recorded_at' => ['nullable', 'date'],
            'source'      => ['nullable', 'in:device,firebase_sync,manual'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $reading = EnergyReading::create([
            'energy_device_id' => $device->id,
            'voltage'           => $request->input('voltage'),
            'current'           => $request->input('current'),
            'power'             => $request->input('power'),
            'energy'            => $request->input('energy'),
            'recorded_at'       => $request->filled('recorded_at')
                ? Carbon::parse($request->input('recorded_at'))
                : now(),
            'source'            => $request->input('source', 'device'),
        ]);

        return response()->json(['status' => true, 'row' => $reading], 201);
    }

    /**
     * Bulk variant of storeReading — accepts { readings: [ {...}, ... ] }
     * so a sync job can push a batch of Firebase log entries in one call.
     */
    public function storeReadingsBulk(Request $request, string $uid)
    {
        $device = EnergyDevice::where('device_uid', $uid)->first();

        if (!$device) {
            return response()->json([
                'status'  => false,
                'message' => 'Unknown device_uid. Register the device first via POST /energy-devices.',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'readings'               => ['required', 'array', 'min:1'],
            'readings.*.voltage'     => ['nullable', 'numeric'],
            'readings.*.current'     => ['nullable', 'numeric'],
            'readings.*.power'       => ['nullable', 'numeric'],
            'readings.*.energy'      => ['nullable', 'numeric'],
            'readings.*.recorded_at' => ['required', 'date'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $rows = collect($request->input('readings'))->map(fn ($r) => [
            'energy_device_id' => $device->id,
            'voltage'           => $r['voltage'] ?? null,
            'current'           => $r['current'] ?? null,
            'power'             => $r['power'] ?? null,
            'energy'            => $r['energy'] ?? null,
            'recorded_at'       => Carbon::parse($r['recorded_at']),
            'source'            => 'firebase_sync',
            'created_at'        => now(),
            'updated_at'        => now(),
        ])->all();

        EnergyReading::insert($rows);

        return response()->json(['status' => true, 'inserted' => count($rows)], 201);
    }

    /**
     * GET /api/energy-devices/{uid}/readings?date=YYYY-MM-DD
     * Date-wise history for the calendar filter, plus a small summary.
     */
    public function readings(Request $request, string $uid)
    {
        $device = EnergyDevice::where('device_uid', $uid)->first();

        if (!$device) {
            return response()->json(['status' => false, 'message' => 'Device not found.'], 404);
        }

        $date = $request->input('date', now()->toDateString());

        try {
            $day = Carbon::parse($date)->startOfDay();
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => 'Invalid date.'], 422);
        }

        $rows = EnergyReading::where('energy_device_id', $device->id)
            ->whereBetween('recorded_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()])
            ->orderBy('recorded_at')
            ->get(['id', 'voltage', 'current', 'power', 'energy', 'recorded_at']);

        $summary = [
            'count'      => $rows->count(),
            'avg_voltage'=> round((float) $rows->avg('voltage'), 1),
            'avg_current'=> round((float) $rows->avg('current'), 2),
            'peak_power' => round((float) $rows->max('power'), 1),
        ];

        return response()->json([
            'status'  => true,
            'device'  => $this->formatDevice($device),
            'date'    => $day->toDateString(),
            'summary' => $summary,
            'rows'    => $rows,
        ]);
    }

    private function formatDevice(EnergyDevice $device): array
    {
        $latest = $device->latestReading;

        return [
            'id'          => $device->id,
            'device_uid'  => $device->device_uid,
            'name'        => $device->name,
            'location'    => $device->location,
            'description' => $device->description,
            'photo_url'   => $device->photo_url,
            'added_by'    => $device->addedBy?->only(['id', 'username', 'name']),
            'is_online'   => $device->is_online,
            'reading'     => $latest ? [
                'voltage'     => $latest->voltage,
                'current'     => $latest->current,
                'power'       => $latest->power,
                'energy'      => $latest->energy,
                'recorded_at' => $latest->recorded_at,
            ] : null,
            'created_at'  => $device->created_at,
        ];
    }
}