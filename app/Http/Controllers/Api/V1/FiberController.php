<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\FoBreak;
use App\Models\FoCable;
use App\Models\FoPoint;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class FiberController extends Controller
{
    private const BREAK_STATUSES = ['OPEN', 'IN_PROGRESS', 'FIXED', 'CANCELLED'];
    private const BREAK_SEVERITIES = ['MINOR', 'MAJOR', 'CRITICAL'];

    private function tenantId(Request $request): int
    {
        return (int) $request->attributes->get('tenant_id', 1);
    }

    private function normalizeLegacyRole(?string $role): string
    {
        $r = strtolower(trim((string) $role));
        if ($r === 'svp lapangan') return 'svp_lapangan';
        return $r;
    }

    private function userCan(Request $request, string $permission): bool
    {
        $user = $request->user();
        if (!$user) return false;

        $legacyRole = $this->normalizeLegacyRole($user->role ?? null);

        // Always allow admin/owner even if RBAC hasn't been seeded yet.
        if (in_array($legacyRole, ['admin', 'owner'], true)) return true;

        // Legacy wildcard permission for this module.
        if (method_exists($user, 'can') && $user->can('manage fiber')) return true;

        return method_exists($user, 'can') && $user->can($permission);
    }

    private function requirePermission(Request $request, string $permission): ?JsonResponse
    {
        if ($this->userCan($request, $permission)) return null;
        return response()->json(['message' => 'Forbidden'], 403);
    }

    private function tablesReady(): bool
    {
        return Schema::hasTable('noci_fo_cables')
            && Schema::hasTable('noci_fo_points')
            && Schema::hasTable('noci_fo_breaks');
    }

    public function summary(Request $request): JsonResponse
    {
        if ($resp = $this->requirePermission($request, 'view fiber')) return $resp;

        if (!$this->tablesReady()) {
            return response()->json([
                'success' => false,
                'message' => 'Fiber tables not found. Run php artisan migrate.',
                'data' => [
                    'cables' => 0,
                    'points' => 0,
                    'breaks_total' => 0,
                    'breaks_open' => 0,
                ],
            ], 500);
        }

        $tenantId = $this->tenantId($request);

        try {
            $cables = FoCable::forTenant($tenantId)->count();
            $points = FoPoint::forTenant($tenantId)->count();
            $breaksTotal = FoBreak::forTenant($tenantId)->count();
            $breaksOpen = FoBreak::forTenant($tenantId)->whereIn('status', ['OPEN', 'IN_PROGRESS'])->count();

            return response()->json([
                'success' => true,
                'data' => [
                    'cables' => $cables,
                    'points' => $points,
                    'breaks_total' => $breaksTotal,
                    'breaks_open' => $breaksOpen,
                ],
            ]);
        } catch (QueryException) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load summary',
            ], 500);
        }
    }

    public function mapData(Request $request): JsonResponse
    {
        if ($resp = $this->requirePermission($request, 'view fiber')) return $resp;

        if (!$this->tablesReady()) {
            return response()->json([
                'success' => false,
                'message' => 'Fiber tables not found. Run php artisan migrate.',
                'data' => [
                    'cables' => [],
                    'points' => [],
                    'breaks' => [],
                ],
            ], 500);
        }

        $tenantId = $this->tenantId($request);

        try {
            $points = FoPoint::forTenant($tenantId)
                ->orderBy('name')
                ->get([
                    'id',
                    'name',
                    'point_type',
                    'latitude',
                    'longitude',
                    'address',
                    'notes',
                    'created_at',
                    'updated_at',
                ]);

            $cables = FoCable::forTenant($tenantId)
                ->orderBy('name')
                ->get([
                    'id',
                    'name',
                    'code',
                    'cable_type',
                    'core_count',
                    'map_color',
                    'from_point_id',
                    'to_point_id',
                    'path',
                    'notes',
                    'created_at',
                    'updated_at',
                ]);

            $breaks = FoBreak::forTenant($tenantId)
                ->orderByDesc('reported_at')
                ->orderByDesc('id')
                ->limit(2000)
                ->get([
                    'id',
                    'cable_id',
                    'point_id',
                    'status',
                    'severity',
                    'reported_at',
                    'fixed_at',
                    'latitude',
                    'longitude',
                    'description',
                    'created_at',
                    'updated_at',
                ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'cables' => $cables,
                    'points' => $points,
                    'breaks' => $breaks,
                ],
            ]);
        } catch (QueryException) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load fiber map data',
                'data' => [
                    'cables' => [],
                    'points' => [],
                    'breaks' => [],
                ],
            ], 500);
        }
    }

    /* ───────────── Cables ───────────── */

    public function listCables(Request $request): JsonResponse
    {
        if ($resp = $this->requirePermission($request, 'view fiber')) return $resp;

        if (!Schema::hasTable('noci_fo_cables')) {
            return response()->json(['success' => false, 'message' => 'Table noci_fo_cables not found'], 500);
        }

        $tenantId = $this->tenantId($request);
        $q = trim((string) $request->query('q', ''));
        $type = trim((string) $request->query('type', ''));

        $query = FoCable::forTenant($tenantId);
        if ($q !== '') {
            $like = '%' . $q . '%';
            $query->where(function ($qb) use ($like) {
                $qb->where('name', 'like', $like)->orWhere('code', 'like', $like);
            });
        }
        if ($type !== '') {
            $query->where('cable_type', $type);
        }

        $rows = $query
            ->orderBy('name')
            ->limit(5000)
            ->get();

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function storeCable(Request $request): JsonResponse
    {
        if ($resp = $this->requirePermission($request, 'create fiber')) return $resp;

        if (!Schema::hasTable('noci_fo_cables')) {
            return response()->json(['success' => false, 'message' => 'Table noci_fo_cables not found'], 500);
        }

        $tenantId = $this->tenantId($request);

        $validated = $request->validate([
            'name' => 'required|string|max:160',
            'code' => 'nullable|string|max:64',
            'cable_type' => 'nullable|string|max:60',
            'core_count' => 'nullable|integer|min:1|max:9999',
            'map_color' => 'nullable|string|max:20',
            'from_point_id' => 'nullable|integer|min:1',
            'to_point_id' => 'nullable|integer|min:1',
            'path' => 'nullable|array|min:2',
            'path.*.lat' => 'required|numeric|between:-90,90',
            'path.*.lng' => 'required|numeric|between:-180,180',
            'notes' => 'nullable|string',
        ]);

        $fromPointId = (int) ($validated['from_point_id'] ?? 0);
        $toPointId = (int) ($validated['to_point_id'] ?? 0);

        if ($fromPointId > 0 && !FoPoint::forTenant($tenantId)->where('id', $fromPointId)->exists()) {
            return response()->json(['message' => 'From point not found'], 422);
        }
        if ($toPointId > 0 && !FoPoint::forTenant($tenantId)->where('id', $toPointId)->exists()) {
            return response()->json(['message' => 'To point not found'], 422);
        }

        $userId = (int) ($request->user()?->id ?? 0);

        $payload = [
            'tenant_id' => $tenantId,
            'name' => $validated['name'],
            'code' => $validated['code'] ?? null,
            'cable_type' => $validated['cable_type'] ?? null,
            'core_count' => $validated['core_count'] ?? null,
            'map_color' => $validated['map_color'] ?? null,
            'from_point_id' => $fromPointId > 0 ? $fromPointId : null,
            'to_point_id' => $toPointId > 0 ? $toPointId : null,
            'path' => $validated['path'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'created_by' => $userId ?: null,
            'updated_by' => $userId ?: null,
        ];

        try {
            $cable = FoCable::create($payload);
            return response()->json(['success' => true, 'data' => $cable], 201);
        } catch (QueryException) {
            return response()->json(['success' => false, 'message' => 'Failed to create cable'], 500);
        }
    }

    public function updateCable(Request $request, int $id): JsonResponse
    {
        if ($resp = $this->requirePermission($request, 'edit fiber')) return $resp;

        $tenantId = $this->tenantId($request);
        $cable = FoCable::forTenant($tenantId)->findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:160',
            'code' => 'nullable|string|max:64',
            'cable_type' => 'nullable|string|max:60',
            'core_count' => 'nullable|integer|min:1|max:9999',
            'map_color' => 'nullable|string|max:20',
            'from_point_id' => 'nullable|integer|min:1',
            'to_point_id' => 'nullable|integer|min:1',
            'path' => 'nullable|array|min:2',
            'path.*.lat' => 'required|numeric|between:-90,90',
            'path.*.lng' => 'required|numeric|between:-180,180',
            'notes' => 'nullable|string',
        ]);

        $fromPointId = array_key_exists('from_point_id', $validated) ? (int) ($validated['from_point_id'] ?? 0) : null;
        $toPointId = array_key_exists('to_point_id', $validated) ? (int) ($validated['to_point_id'] ?? 0) : null;

        if ($fromPointId !== null && $fromPointId > 0 && !FoPoint::forTenant($tenantId)->where('id', $fromPointId)->exists()) {
            return response()->json(['message' => 'From point not found'], 422);
        }
        if ($toPointId !== null && $toPointId > 0 && !FoPoint::forTenant($tenantId)->where('id', $toPointId)->exists()) {
            return response()->json(['message' => 'To point not found'], 422);
        }

        if ($fromPointId !== null) {
            $validated['from_point_id'] = $fromPointId > 0 ? $fromPointId : null;
        }
        if ($toPointId !== null) {
            $validated['to_point_id'] = $toPointId > 0 ? $toPointId : null;
        }

        $userId = (int) ($request->user()?->id ?? 0);
        $validated['updated_by'] = $userId ?: null;

        try {
            $cable->update($validated);
            return response()->json(['success' => true, 'data' => $cable->fresh()]);
        } catch (QueryException) {
            return response()->json(['success' => false, 'message' => 'Failed to update cable'], 500);
        }
    }

    public function deleteCable(Request $request, int $id): JsonResponse
    {
        if ($resp = $this->requirePermission($request, 'delete fiber')) return $resp;

        $tenantId = $this->tenantId($request);
        $cable = FoCable::forTenant($tenantId)->findOrFail($id);

        // Prevent deletion if used by break records.
        try {
            if (Schema::hasTable('noci_fo_breaks')) {
                $hasBreaks = FoBreak::forTenant($tenantId)->where('cable_id', $cable->id)->exists();
                if ($hasBreaks) {
                    return response()->json(['message' => 'Cable masih dipakai di data putus. Hapus/ubah data putus dulu.'], 409);
                }
            }
        } catch (\Throwable) {
        }

        try {
            $cable->delete();
            return response()->json(['success' => true]);
        } catch (QueryException) {
            return response()->json(['success' => false, 'message' => 'Failed to delete cable'], 500);
        }
    }

    /* ───────────── Points ───────────── */

    public function listPoints(Request $request): JsonResponse
    {
        if ($resp = $this->requirePermission($request, 'view fiber')) return $resp;

        if (!Schema::hasTable('noci_fo_points')) {
            return response()->json(['success' => false, 'message' => 'Table noci_fo_points not found'], 500);
        }

        $tenantId = $this->tenantId($request);
        $q = trim((string) $request->query('q', ''));
        $type = trim((string) $request->query('type', ''));

        $query = FoPoint::forTenant($tenantId);
        if ($q !== '') {
            $like = '%' . $q . '%';
            $query->where('name', 'like', $like);
        }
        if ($type !== '') {
            $query->where('point_type', $type);
        }

        $rows = $query
            ->orderBy('name')
            ->limit(8000)
            ->get();

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function storePoint(Request $request): JsonResponse
    {
        if ($resp = $this->requirePermission($request, 'create fiber')) return $resp;

        if (!Schema::hasTable('noci_fo_points')) {
            return response()->json(['success' => false, 'message' => 'Table noci_fo_points not found'], 500);
        }

        $tenantId = $this->tenantId($request);

        $validated = $request->validate([
            'name' => 'required|string|max:160',
            'point_type' => 'nullable|string|max:60',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'address' => 'nullable|string|max:190',
            'notes' => 'nullable|string',
        ]);

        $userId = (int) ($request->user()?->id ?? 0);

        $payload = [
            'tenant_id' => $tenantId,
            'name' => $validated['name'],
            'point_type' => $validated['point_type'] ?? null,
            'latitude' => $validated['latitude'],
            'longitude' => $validated['longitude'],
            'address' => $validated['address'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'created_by' => $userId ?: null,
            'updated_by' => $userId ?: null,
        ];

        try {
            $point = FoPoint::create($payload);
            return response()->json(['success' => true, 'data' => $point], 201);
        } catch (QueryException) {
            return response()->json(['success' => false, 'message' => 'Failed to create point'], 500);
        }
    }

    public function updatePoint(Request $request, int $id): JsonResponse
    {
        if ($resp = $this->requirePermission($request, 'edit fiber')) return $resp;

        $tenantId = $this->tenantId($request);
        $point = FoPoint::forTenant($tenantId)->findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:160',
            'point_type' => 'nullable|string|max:60',
            'latitude' => 'sometimes|required|numeric|between:-90,90',
            'longitude' => 'sometimes|required|numeric|between:-180,180',
            'address' => 'nullable|string|max:190',
            'notes' => 'nullable|string',
        ]);

        $userId = (int) ($request->user()?->id ?? 0);
        $validated['updated_by'] = $userId ?: null;

        try {
            $point->update($validated);
            return response()->json(['success' => true, 'data' => $point->fresh()]);
        } catch (QueryException) {
            return response()->json(['success' => false, 'message' => 'Failed to update point'], 500);
        }
    }

    public function deletePoint(Request $request, int $id): JsonResponse
    {
        if ($resp = $this->requirePermission($request, 'delete fiber')) return $resp;

        $tenantId = $this->tenantId($request);
        $point = FoPoint::forTenant($tenantId)->findOrFail($id);

        try {
            $usedInCable = Schema::hasTable('noci_fo_cables') && FoCable::forTenant($tenantId)
                ->where(function ($qb) use ($id) {
                    $qb->where('from_point_id', $id)->orWhere('to_point_id', $id);
                })
                ->exists();

            if ($usedInCable) {
                return response()->json(['message' => 'Titik masih dipakai di kabel. Ubah kabel dulu sebelum hapus titik.'], 409);
            }

            $usedInBreak = Schema::hasTable('noci_fo_breaks') && FoBreak::forTenant($tenantId)->where('point_id', $id)->exists();
            if ($usedInBreak) {
                return response()->json(['message' => 'Titik masih dipakai di data putus. Ubah/hapus data putus dulu.'], 409);
            }
        } catch (\Throwable) {
            // Best-effort; continue to delete.
        }

        try {
            $point->delete();
            return response()->json(['success' => true]);
        } catch (QueryException) {
            return response()->json(['success' => false, 'message' => 'Failed to delete point'], 500);
        }
    }

    /* ───────────── Breaks ───────────── */

    public function listBreaks(Request $request): JsonResponse
    {
        if ($resp = $this->requirePermission($request, 'view fiber')) return $resp;

        if (!Schema::hasTable('noci_fo_breaks')) {
            return response()->json(['success' => false, 'message' => 'Table noci_fo_breaks not found'], 500);
        }

        $tenantId = $this->tenantId($request);
        $status = strtoupper(trim((string) $request->query('status', '')));

        $query = FoBreak::forTenant($tenantId);
        if ($status !== '' && in_array($status, self::BREAK_STATUSES, true)) {
            $query->where('status', $status);
        }

        $rows = $query
            ->orderByDesc('reported_at')
            ->orderByDesc('id')
            ->limit(5000)
            ->get();

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function storeBreak(Request $request): JsonResponse
    {
        if ($resp = $this->requirePermission($request, 'create fiber')) return $resp;

        if (!Schema::hasTable('noci_fo_breaks')) {
            return response()->json(['success' => false, 'message' => 'Table noci_fo_breaks not found'], 500);
        }

        $tenantId = $this->tenantId($request);

        $validated = $request->validate([
            'cable_id' => 'nullable|integer|min:1',
            'point_id' => 'nullable|integer|min:1',
            'status' => ['nullable', Rule::in(self::BREAK_STATUSES)],
            'severity' => ['nullable', Rule::in(self::BREAK_SEVERITIES)],
            'reported_at' => 'nullable|date',
            'fixed_at' => 'nullable|date',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'description' => 'nullable|string',
        ]);

        $cableId = (int) ($validated['cable_id'] ?? 0);
        $pointId = (int) ($validated['point_id'] ?? 0);

        if ($cableId > 0 && !FoCable::forTenant($tenantId)->where('id', $cableId)->exists()) {
            return response()->json(['message' => 'Cable not found'], 422);
        }
        if ($pointId > 0 && !FoPoint::forTenant($tenantId)->where('id', $pointId)->exists()) {
            return response()->json(['message' => 'Point not found'], 422);
        }

        $latProvided = array_key_exists('latitude', $validated) && $validated['latitude'] !== null;
        $lngProvided = array_key_exists('longitude', $validated) && $validated['longitude'] !== null;
        if ($latProvided xor $lngProvided) {
            return response()->json(['message' => 'Latitude dan longitude harus diisi berpasangan'], 422);
        }

        // Require a usable location for mapping: either point_id or lat/lng.
        if ($pointId <= 0 && !$latProvided) {
            return response()->json(['message' => 'Lokasi wajib diisi (pilih titik atau pilih lokasi di peta)'], 422);
        }

        // Auto-fill coordinates from point if not provided.
        if (!$latProvided && $pointId > 0) {
            $p = FoPoint::forTenant($tenantId)->where('id', $pointId)->first(['latitude', 'longitude']);
            if ($p) {
                $validated['latitude'] = (float) $p->latitude;
                $validated['longitude'] = (float) $p->longitude;
            }
        }

        $status = strtoupper((string) ($validated['status'] ?? 'OPEN'));
        if (!in_array($status, self::BREAK_STATUSES, true)) {
            $status = 'OPEN';
        }

        $reportedAt = $validated['reported_at'] ?? now();
        $fixedAt = $validated['fixed_at'] ?? null;

        if ($status === 'FIXED' && !$fixedAt) {
            $fixedAt = now();
        }
        if ($status !== 'FIXED') {
            $fixedAt = null;
        }

        $userId = (int) ($request->user()?->id ?? 0);

        $payload = [
            'tenant_id' => $tenantId,
            'cable_id' => $cableId > 0 ? $cableId : null,
            'point_id' => $pointId > 0 ? $pointId : null,
            'status' => $status,
            'severity' => $validated['severity'] ?? null,
            'reported_at' => $reportedAt,
            'fixed_at' => $fixedAt,
            'latitude' => $validated['latitude'] ?? null,
            'longitude' => $validated['longitude'] ?? null,
            'description' => $validated['description'] ?? null,
            'created_by' => $userId ?: null,
            'updated_by' => $userId ?: null,
        ];

        try {
            $br = FoBreak::create($payload);
            return response()->json(['success' => true, 'data' => $br], 201);
        } catch (QueryException) {
            return response()->json(['success' => false, 'message' => 'Failed to create break record'], 500);
        }
    }

    public function updateBreak(Request $request, int $id): JsonResponse
    {
        if ($resp = $this->requirePermission($request, 'edit fiber')) return $resp;

        $tenantId = $this->tenantId($request);
        $br = FoBreak::forTenant($tenantId)->findOrFail($id);

        $validated = $request->validate([
            'cable_id' => 'nullable|integer|min:1',
            'point_id' => 'nullable|integer|min:1',
            'status' => ['nullable', Rule::in(self::BREAK_STATUSES)],
            'severity' => ['nullable', Rule::in(self::BREAK_SEVERITIES)],
            'reported_at' => 'nullable|date',
            'fixed_at' => 'nullable|date',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'description' => 'nullable|string',
        ]);

        if (array_key_exists('cable_id', $validated)) {
            $cableId = (int) ($validated['cable_id'] ?? 0);
            if ($cableId > 0 && !FoCable::forTenant($tenantId)->where('id', $cableId)->exists()) {
                return response()->json(['message' => 'Cable not found'], 422);
            }
            $validated['cable_id'] = $cableId > 0 ? $cableId : null;
        }

        if (array_key_exists('point_id', $validated)) {
            $pointId = (int) ($validated['point_id'] ?? 0);
            if ($pointId > 0 && !FoPoint::forTenant($tenantId)->where('id', $pointId)->exists()) {
                return response()->json(['message' => 'Point not found'], 422);
            }
            $validated['point_id'] = $pointId > 0 ? $pointId : null;
        }

        // Auto-fill coordinates from point if caller sets point_id but doesn't explicitly send lat/lng.
        // This keeps map markers usable when users only reference a point.
        if (array_key_exists('point_id', $validated) && $validated['point_id']) {
            $hasLatKey = array_key_exists('latitude', $validated);
            $hasLngKey = array_key_exists('longitude', $validated);
            if (!$hasLatKey && !$hasLngKey) {
                $p = FoPoint::forTenant($tenantId)
                    ->where('id', (int) $validated['point_id'])
                    ->first(['latitude', 'longitude']);

                if ($p) {
                    $validated['latitude'] = (float) $p->latitude;
                    $validated['longitude'] = (float) $p->longitude;
                }
            }
        }

        $latProvided = array_key_exists('latitude', $validated) && $validated['latitude'] !== null;
        $lngProvided = array_key_exists('longitude', $validated) && $validated['longitude'] !== null;
        if ($latProvided xor $lngProvided) {
            return response()->json(['message' => 'Latitude dan longitude harus diisi berpasangan'], 422);
        }

        if (array_key_exists('status', $validated)) {
            $status = strtoupper((string) ($validated['status'] ?? 'OPEN'));
            if (!in_array($status, self::BREAK_STATUSES, true)) {
                $status = 'OPEN';
            }
            $validated['status'] = $status;

            if ($status === 'FIXED') {
                if (!array_key_exists('fixed_at', $validated) || !$validated['fixed_at']) {
                    $validated['fixed_at'] = now();
                }
            } else {
                $validated['fixed_at'] = null;
            }
        }

        // Ensure there is still a usable location after update.
        $finalPointId = array_key_exists('point_id', $validated) ? (int) ($validated['point_id'] ?? 0) : (int) ($br->point_id ?? 0);
        $finalLat = $latProvided ? $validated['latitude'] : $br->latitude;
        $finalLng = $lngProvided ? $validated['longitude'] : $br->longitude;
        if ($finalPointId <= 0 && ($finalLat === null || $finalLng === null)) {
            return response()->json(['message' => 'Lokasi wajib diisi (pilih titik atau pilih lokasi di peta)'], 422);
        }

        $userId = (int) ($request->user()?->id ?? 0);
        $validated['updated_by'] = $userId ?: null;

        try {
            $br->update($validated);
            return response()->json(['success' => true, 'data' => $br->fresh()]);
        } catch (QueryException) {
            return response()->json(['success' => false, 'message' => 'Failed to update break record'], 500);
        }
    }

    public function deleteBreak(Request $request, int $id): JsonResponse
    {
        if ($resp = $this->requirePermission($request, 'delete fiber')) return $resp;

        $tenantId = $this->tenantId($request);
        $br = FoBreak::forTenant($tenantId)->findOrFail($id);

        try {
            $br->delete();
            return response()->json(['success' => true]);
        } catch (QueryException) {
            return response()->json(['success' => false, 'message' => 'Failed to delete break record'], 500);
        }
    }
}
