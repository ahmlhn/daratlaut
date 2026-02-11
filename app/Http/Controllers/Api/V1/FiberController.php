<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\FoBreak;
use App\Models\FoCable;
use App\Models\FoLink;
use App\Models\FoPoint;
use App\Models\FoPort;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class FiberController extends Controller
{
    private const BREAK_STATUSES = ['OPEN', 'IN_PROGRESS', 'FIXED', 'CANCELLED'];
    private const BREAK_SEVERITIES = ['MINOR', 'MAJOR', 'CRITICAL'];

    private const PORT_TYPES = ['OLT_PON', 'ODP_OUT'];
    private const LINK_TYPES = ['SPLICE', 'PATCH', 'SPLIT'];

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

    private function extraTablesReady(): bool
    {
        return Schema::hasTable('noci_fo_ports')
            && Schema::hasTable('noci_fo_links');
    }

    private function loadCable(int $tenantId, int $cableId): ?FoCable
    {
        if ($cableId <= 0) return null;
        return FoCable::forTenant($tenantId)->where('id', $cableId)->first();
    }

    private function cableAttachedToPoint(?FoCable $cable, int $pointId): bool
    {
        if (!$cable) return false;
        $from = (int) ($cable->from_point_id ?? 0);
        $to = (int) ($cable->to_point_id ?? 0);
        return $from === $pointId || $to === $pointId;
    }

    private function coreValidForCable(?FoCable $cable, int $coreNo): bool
    {
        if (!$cable || $coreNo <= 0) return false;
        $cc = (int) ($cable->core_count ?? 0);
        if ($cc > 0) return $coreNo <= $cc;
        return true;
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
                    'ports' => 0,
                    'links' => 0,
                    'breaks_total' => 0,
                    'breaks_open' => 0,
                ],
            ], 500);
        }

        $tenantId = $this->tenantId($request);

        try {
            $cables = FoCable::forTenant($tenantId)->count();
            $points = FoPoint::forTenant($tenantId)->count();
            $ports = $this->extraTablesReady() ? FoPort::forTenant($tenantId)->count() : 0;
            $links = $this->extraTablesReady() ? FoLink::forTenant($tenantId)->count() : 0;
            $breaksTotal = FoBreak::forTenant($tenantId)->count();
            $breaksOpen = FoBreak::forTenant($tenantId)->whereIn('status', ['OPEN', 'IN_PROGRESS'])->count();

            return response()->json([
                'success' => true,
                'data' => [
                    'cables' => $cables,
                    'points' => $points,
                    'ports' => $ports,
                    'links' => $links,
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
                    'ports' => [],
                    'links' => [],
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

            $cableCols = [
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
            ];
            if (Schema::hasColumn('noci_fo_cables', 'length_m')) {
                $cableCols[] = 'length_m';
            }

            $cables = FoCable::forTenant($tenantId)
                ->orderBy('name')
                ->get($cableCols);

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

            $ports = [];
            $links = [];
            if ($this->extraTablesReady()) {
                $ports = FoPort::forTenant($tenantId)
                    ->orderBy('point_id')
                    ->orderBy('port_type')
                    ->orderBy('port_label')
                    ->get([
                        'id',
                        'point_id',
                        'port_type',
                        'port_label',
                        'olt_id',
                        'cable_id',
                        'core_no',
                        'notes',
                        'created_at',
                        'updated_at',
                    ]);

                $links = FoLink::forTenant($tenantId)
                    ->orderBy('point_id')
                    ->orderBy('link_type')
                    ->orderBy('id')
                    ->limit(20000)
                    ->get([
                        'id',
                        'point_id',
                        'link_type',
                        'from_cable_id',
                        'from_core_no',
                        'to_cable_id',
                        'to_core_no',
                        'split_group',
                        'loss_db',
                        'notes',
                        'created_at',
                        'updated_at',
                    ]);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'cables' => $cables,
                    'points' => $points,
                    'ports' => $ports,
                    'links' => $links,
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
                    'ports' => [],
                    'links' => [],
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
            'length_m' => 'nullable|integer|min:0',
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
        if (Schema::hasColumn('noci_fo_cables', 'length_m')) {
            $payload['length_m'] = array_key_exists('length_m', $validated) ? ($validated['length_m'] ?? null) : null;
        }

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
            'length_m' => 'nullable|integer|min:0',
            'notes' => 'nullable|string',
        ]);
        if (!Schema::hasColumn('noci_fo_cables', 'length_m')) {
            unset($validated['length_m']);
        }

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

        // Prevent deletion if used by ports/links (for tracing integrity).
        try {
            if (Schema::hasTable('noci_fo_ports')) {
                $hasPorts = FoPort::forTenant($tenantId)->where('cable_id', $cable->id)->exists();
                if ($hasPorts) {
                    return response()->json(['message' => 'Cable masih dipakai di port (OLT/ODP). Hapus/ubah port dulu.'], 409);
                }
            }
        } catch (\Throwable) {
        }

        try {
            if (Schema::hasTable('noci_fo_links')) {
                $hasLinks = FoLink::forTenant($tenantId)
                    ->where(function ($qb) use ($cable) {
                        $qb->where('from_cable_id', $cable->id)->orWhere('to_cable_id', $cable->id);
                    })
                    ->exists();

                if ($hasLinks) {
                    return response()->json(['message' => 'Cable masih dipakai di sambungan (splice/split). Hapus/ubah sambungan dulu.'], 409);
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

            $usedInPort = Schema::hasTable('noci_fo_ports') && FoPort::forTenant($tenantId)->where('point_id', $id)->exists();
            if ($usedInPort) {
                return response()->json(['message' => 'Titik masih dipakai di port (OLT/ODP). Hapus/ubah port dulu.'], 409);
            }

            $usedInLink = Schema::hasTable('noci_fo_links') && FoLink::forTenant($tenantId)->where('point_id', $id)->exists();
            if ($usedInLink) {
                return response()->json(['message' => 'Titik masih dipakai di sambungan (splice/split). Hapus/ubah sambungan dulu.'], 409);
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

    public function fixBreak(Request $request, int $id): JsonResponse
    {
        if ($resp = $this->requirePermission($request, 'edit fiber')) return $resp;

        if (!$this->tablesReady()) {
            return response()->json([
                'success' => false,
                'message' => 'Fiber tables not found. Run php artisan migrate.',
            ], 500);
        }

        $tenantId = $this->tenantId($request);
        $br = FoBreak::forTenant($tenantId)->findOrFail($id);

        $currentStatus = strtoupper((string) ($br->status ?? 'OPEN'));
        if ($currentStatus === 'CANCELLED') {
            return response()->json(['message' => 'Data putus sudah CANCELLED.'], 409);
        }

        // Idempotent: if already FIXED and has a point, just return it (avoid duplicating joint points).
        if ($currentStatus === 'FIXED' && (int) ($br->point_id ?? 0) > 0) {
            $jp = null;
            try {
                $jp = FoPoint::forTenant($tenantId)->where('id', (int) $br->point_id)->first();
            } catch (\Throwable) {
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'break' => $br,
                    'joint_point' => $jp,
                ],
            ]);
        }

        $validated = $request->validate([
            'joint_point_id' => 'nullable|integer|min:1',
            'joint_name' => 'nullable|string|max:160',
            'joint_type' => 'nullable|string|max:60',
            'joint_address' => 'nullable|string|max:255',
            'joint_notes' => 'nullable|string',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'fixed_at' => 'nullable|date',
        ]);

        $latProvided = array_key_exists('latitude', $validated) && $validated['latitude'] !== null;
        $lngProvided = array_key_exists('longitude', $validated) && $validated['longitude'] !== null;
        if ($latProvided xor $lngProvided) {
            return response()->json(['message' => 'Latitude dan longitude harus diisi berpasangan'], 422);
        }

        // Determine joint coordinates.
        $lat = null;
        $lng = null;
        if ($latProvided) {
            $lat = (float) $validated['latitude'];
            $lng = (float) $validated['longitude'];
        } elseif ($br->latitude !== null && $br->longitude !== null) {
            $lat = (float) $br->latitude;
            $lng = (float) $br->longitude;
        } elseif ((int) ($br->point_id ?? 0) > 0) {
            try {
                $p = FoPoint::forTenant($tenantId)->where('id', (int) $br->point_id)->first(['latitude', 'longitude']);
                if ($p && $p->latitude !== null && $p->longitude !== null) {
                    $lat = (float) $p->latitude;
                    $lng = (float) $p->longitude;
                }
            } catch (\Throwable) {
            }
        }

        if ($lat === null || $lng === null) {
            return response()->json(['message' => 'Lokasi putus belum lengkap. Isi lat/lng atau pilih titik lokasi dulu.'], 422);
        }

        $jointPointId = (int) ($validated['joint_point_id'] ?? 0);

        // Creating a joint point requires create permission.
        if ($jointPointId <= 0 && !$this->userCan($request, 'create fiber')) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $userId = (int) ($request->user()?->id ?? 0);

        try {
            return DB::transaction(function () use ($tenantId, $br, $validated, $jointPointId, $lat, $lng, $userId) {
                $joint = null;

                if ($jointPointId > 0) {
                    $joint = FoPoint::forTenant($tenantId)->where('id', $jointPointId)->first();
                    if (!$joint) {
                        return response()->json(['message' => 'Joint point not found'], 422);
                    }
                } else {
                    $jointName = trim((string) ($validated['joint_name'] ?? ''));
                    if ($jointName === '') {
                        $cName = null;
                        try {
                            if ((int) ($br->cable_id ?? 0) > 0) {
                                $c = FoCable::forTenant($tenantId)->where('id', (int) $br->cable_id)->first(['name']);
                                $cName = $c ? (string) ($c->name ?? '') : null;
                            }
                        } catch (\Throwable) {
                        }

                        $jointName = $cName ? ('JC - ' . $cName) : ('Joint Closure - Break #' . (int) $br->id);
                    }

                    $jointType = trim((string) ($validated['joint_type'] ?? 'JOINT_CLOSURE'));
                    if ($jointType === '') $jointType = 'JOINT_CLOSURE';

                    $joint = FoPoint::create([
                        'tenant_id' => $tenantId,
                        'name' => $jointName,
                        'point_type' => $jointType,
                        'latitude' => $lat,
                        'longitude' => $lng,
                        'address' => $validated['joint_address'] ?? null,
                        'notes' => $validated['joint_notes'] ?? null,
                        'created_by' => $userId ?: null,
                        'updated_by' => $userId ?: null,
                    ]);
                }

                $fixedAt = $validated['fixed_at'] ?? now();

                $br->update([
                    'status' => 'FIXED',
                    'fixed_at' => $fixedAt,
                    'point_id' => $joint?->id,
                    'latitude' => $lat,
                    'longitude' => $lng,
                    'updated_by' => $userId ?: null,
                ]);

                return response()->json([
                    'success' => true,
                    'data' => [
                        'break' => $br->fresh(),
                        'joint_point' => $joint,
                    ],
                ]);
            });
        } catch (QueryException) {
            return response()->json(['success' => false, 'message' => 'Failed to fix break'], 500);
        } catch (\Throwable) {
            return response()->json(['success' => false, 'message' => 'Failed to fix break'], 500);
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

    /* â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ Ports (OLT PON / ODP) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */

    public function listPorts(Request $request): JsonResponse
    {
        if ($resp = $this->requirePermission($request, 'view fiber')) return $resp;

        if (!Schema::hasTable('noci_fo_ports')) {
            return response()->json(['success' => false, 'message' => 'Table noci_fo_ports not found'], 500);
        }

        $tenantId = $this->tenantId($request);
        $q = trim((string) $request->query('q', ''));
        $type = strtoupper(trim((string) $request->query('type', '')));
        $pointId = (int) $request->query('point_id', 0);

        $query = FoPort::forTenant($tenantId);
        if ($pointId > 0) $query->where('point_id', $pointId);
        if ($q !== '') {
            $like = '%' . $q . '%';
            $query->where('port_label', 'like', $like);
        }
        if ($type !== '' && in_array($type, self::PORT_TYPES, true)) {
            $query->where('port_type', $type);
        }

        $rows = $query
            ->orderBy('point_id')
            ->orderBy('port_type')
            ->orderBy('port_label')
            ->limit(10000)
            ->get();

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function storePort(Request $request): JsonResponse
    {
        if ($resp = $this->requirePermission($request, 'create fiber')) return $resp;

        if (!Schema::hasTable('noci_fo_ports')) {
            return response()->json(['success' => false, 'message' => 'Table noci_fo_ports not found'], 500);
        }

        $tenantId = $this->tenantId($request);

        $validated = $request->validate([
            'point_id' => 'required|integer|min:1',
            'port_type' => ['required', Rule::in(self::PORT_TYPES)],
            'port_label' => 'required|string|max:120',
            'olt_id' => 'nullable|integer|min:1',
            'cable_id' => 'nullable|integer|min:1',
            'core_no' => 'nullable|integer|min:1|max:9999',
            'notes' => 'nullable|string',
        ]);

        $pointId = (int) $validated['point_id'];
        if (!FoPoint::forTenant($tenantId)->where('id', $pointId)->exists()) {
            return response()->json(['message' => 'Point not found'], 422);
        }

        $portType = strtoupper((string) $validated['port_type']);
        $cableId = (int) ($validated['cable_id'] ?? 0);
        $coreNo = (int) ($validated['core_no'] ?? 0);

        if (in_array($portType, ['OLT_PON', 'ODP_OUT'], true)) {
            if ($cableId <= 0 || $coreNo <= 0) {
                return response()->json(['message' => 'Cable/core wajib diisi untuk port ini'], 422);
            }
        }

        $cable = null;
        if ($cableId > 0) {
            $cable = $this->loadCable($tenantId, $cableId);
            if (!$cable) return response()->json(['message' => 'Cable not found'], 422);
            if (!$this->cableAttachedToPoint($cable, $pointId)) {
                return response()->json(['message' => 'Kabel tidak terhubung ke titik ini (set from/to titik di kabel)'], 422);
            }
            if ($coreNo > 0 && !$this->coreValidForCable($cable, $coreNo)) {
                return response()->json(['message' => 'Core melebihi jumlah core kabel'], 422);
            }
        }

        $userId = (int) ($request->user()?->id ?? 0);

        $payload = [
            'tenant_id' => $tenantId,
            'point_id' => $pointId,
            'port_type' => $portType,
            'port_label' => $validated['port_label'],
            'olt_id' => array_key_exists('olt_id', $validated) ? ((int) ($validated['olt_id'] ?? 0) ?: null) : null,
            'cable_id' => $cableId > 0 ? $cableId : null,
            'core_no' => $coreNo > 0 ? $coreNo : null,
            'notes' => $validated['notes'] ?? null,
            'created_by' => $userId ?: null,
            'updated_by' => $userId ?: null,
        ];

        try {
            $port = FoPort::create($payload);
            return response()->json(['success' => true, 'data' => $port], 201);
        } catch (QueryException) {
            return response()->json(['success' => false, 'message' => 'Failed to create port (cek duplikasi label)'], 500);
        }
    }

    public function updatePort(Request $request, int $id): JsonResponse
    {
        if ($resp = $this->requirePermission($request, 'edit fiber')) return $resp;

        if (!Schema::hasTable('noci_fo_ports')) {
            return response()->json(['success' => false, 'message' => 'Table noci_fo_ports not found'], 500);
        }

        $tenantId = $this->tenantId($request);
        $port = FoPort::forTenant($tenantId)->where('id', $id)->first();
        if (!$port) return response()->json(['message' => 'Port not found'], 404);

        $validated = $request->validate([
            'point_id' => 'sometimes|required|integer|min:1',
            'port_type' => ['sometimes', 'required', Rule::in(self::PORT_TYPES)],
            'port_label' => 'sometimes|required|string|max:120',
            'olt_id' => 'nullable|integer|min:1',
            'cable_id' => 'nullable|integer|min:1',
            'core_no' => 'nullable|integer|min:1|max:9999',
            'notes' => 'nullable|string',
        ]);

        $finalPointId = array_key_exists('point_id', $validated) ? (int) $validated['point_id'] : (int) $port->point_id;
        $finalPortType = array_key_exists('port_type', $validated) ? strtoupper((string) $validated['port_type']) : strtoupper((string) $port->port_type);

        $finalCableId = array_key_exists('cable_id', $validated) ? (int) ($validated['cable_id'] ?? 0) : (int) ($port->cable_id ?? 0);
        $finalCoreNo = array_key_exists('core_no', $validated) ? (int) ($validated['core_no'] ?? 0) : (int) ($port->core_no ?? 0);

        if (!FoPoint::forTenant($tenantId)->where('id', $finalPointId)->exists()) {
            return response()->json(['message' => 'Point not found'], 422);
        }

        if (in_array($finalPortType, ['OLT_PON', 'ODP_OUT'], true)) {
            if ($finalCableId <= 0 || $finalCoreNo <= 0) {
                return response()->json(['message' => 'Cable/core wajib diisi untuk port ini'], 422);
            }
        }

        if ($finalCableId > 0) {
            $cable = $this->loadCable($tenantId, $finalCableId);
            if (!$cable) return response()->json(['message' => 'Cable not found'], 422);
            if (!$this->cableAttachedToPoint($cable, $finalPointId)) {
                return response()->json(['message' => 'Kabel tidak terhubung ke titik ini (set from/to titik di kabel)'], 422);
            }
            if ($finalCoreNo > 0 && !$this->coreValidForCable($cable, $finalCoreNo)) {
                return response()->json(['message' => 'Core melebihi jumlah core kabel'], 422);
            }
        }

        if (array_key_exists('point_id', $validated)) {
            $validated['point_id'] = $finalPointId;
        }
        if (array_key_exists('port_type', $validated)) {
            $validated['port_type'] = $finalPortType;
        }
        if (array_key_exists('cable_id', $validated)) {
            $validated['cable_id'] = $finalCableId > 0 ? $finalCableId : null;
        }
        if (array_key_exists('core_no', $validated)) {
            $validated['core_no'] = $finalCoreNo > 0 ? $finalCoreNo : null;
        }
        if (array_key_exists('olt_id', $validated)) {
            $validated['olt_id'] = ((int) ($validated['olt_id'] ?? 0)) ?: null;
        }

        $userId = (int) ($request->user()?->id ?? 0);
        $validated['updated_by'] = $userId ?: null;

        try {
            $port->update($validated);
            return response()->json(['success' => true, 'data' => $port->fresh()]);
        } catch (QueryException) {
            return response()->json(['success' => false, 'message' => 'Failed to update port'], 500);
        }
    }

    public function deletePort(Request $request, int $id): JsonResponse
    {
        if ($resp = $this->requirePermission($request, 'delete fiber')) return $resp;

        if (!Schema::hasTable('noci_fo_ports')) {
            return response()->json(['success' => false, 'message' => 'Table noci_fo_ports not found'], 500);
        }

        $tenantId = $this->tenantId($request);
        $port = FoPort::forTenant($tenantId)->where('id', $id)->first();
        if (!$port) return response()->json(['message' => 'Port not found'], 404);

        try {
            $port->delete();
            return response()->json(['success' => true]);
        } catch (QueryException) {
            return response()->json(['success' => false, 'message' => 'Failed to delete port'], 500);
        }
    }

    /* â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ Links (splice/patch/split) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */

    public function listLinks(Request $request): JsonResponse
    {
        if ($resp = $this->requirePermission($request, 'view fiber')) return $resp;

        if (!Schema::hasTable('noci_fo_links')) {
            return response()->json(['success' => false, 'message' => 'Table noci_fo_links not found'], 500);
        }

        $tenantId = $this->tenantId($request);
        $type = strtoupper(trim((string) $request->query('type', '')));
        $pointId = (int) $request->query('point_id', 0);

        $query = FoLink::forTenant($tenantId);
        if ($pointId > 0) $query->where('point_id', $pointId);
        if ($type !== '' && in_array($type, self::LINK_TYPES, true)) {
            $query->where('link_type', $type);
        }

        $rows = $query
            ->orderBy('point_id')
            ->orderBy('link_type')
            ->orderBy('id')
            ->limit(20000)
            ->get();

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function storeLink(Request $request): JsonResponse
    {
        if ($resp = $this->requirePermission($request, 'create fiber')) return $resp;

        if (!Schema::hasTable('noci_fo_links')) {
            return response()->json(['success' => false, 'message' => 'Table noci_fo_links not found'], 500);
        }

        $tenantId = $this->tenantId($request);

        $validated = $request->validate([
            'point_id' => 'required|integer|min:1',
            'link_type' => ['required', Rule::in(self::LINK_TYPES)],
            'from_cable_id' => 'required|integer|min:1',
            'from_core_no' => 'required|integer|min:1|max:9999',
            'to_cable_id' => 'nullable|integer|min:1',
            'to_core_no' => 'nullable|integer|min:1|max:9999',
            'outputs' => 'nullable|array|min:1',
            'outputs.*.to_cable_id' => 'required|integer|min:1',
            'outputs.*.to_core_no' => 'required|integer|min:1|max:9999',
            'split_group' => 'nullable|string|max:64',
            'loss_db' => 'nullable|numeric|min:0|max:99',
            'notes' => 'nullable|string',
        ]);

        $pointId = (int) $validated['point_id'];
        if (!FoPoint::forTenant($tenantId)->where('id', $pointId)->exists()) {
            return response()->json(['message' => 'Point not found'], 422);
        }

        $linkType = strtoupper((string) $validated['link_type']);
        $fromCableId = (int) $validated['from_cable_id'];
        $fromCoreNo = (int) $validated['from_core_no'];

        $fromCable = $this->loadCable($tenantId, $fromCableId);
        if (!$fromCable) return response()->json(['message' => 'From cable not found'], 422);
        if (!$this->cableAttachedToPoint($fromCable, $pointId)) {
            return response()->json(['message' => 'From cable tidak terhubung ke titik ini (set from/to titik di kabel)'], 422);
        }
        if (!$this->coreValidForCable($fromCable, $fromCoreNo)) {
            return response()->json(['message' => 'From core melebihi jumlah core kabel'], 422);
        }

        $userId = (int) ($request->user()?->id ?? 0);
        $lossDb = array_key_exists('loss_db', $validated) ? ($validated['loss_db'] !== null ? (float) $validated['loss_db'] : null) : null;
        $notes = $validated['notes'] ?? null;

        if ($linkType === 'SPLIT') {
            $outputs = $validated['outputs'] ?? null;
            if (!is_array($outputs) || count($outputs) < 1) {
                return response()->json(['message' => 'outputs wajib diisi untuk SPLIT'], 422);
            }

            $group = (string) ($validated['split_group'] ?? '');
            if ($group === '') {
                $group = 'split_' . Str::uuid()->toString();
            }

            try {
                $created = DB::transaction(function () use ($tenantId, $pointId, $linkType, $fromCableId, $fromCoreNo, $outputs, $group, $lossDb, $notes, $userId) {
                    $rows = [];
                    foreach ($outputs as $out) {
                        $toCableId = (int) ($out['to_cable_id'] ?? 0);
                        $toCoreNo = (int) ($out['to_core_no'] ?? 0);
                        if ($toCableId <= 0 || $toCoreNo <= 0) continue;

                        $toCable = $this->loadCable($tenantId, $toCableId);
                        if (!$toCable) continue;
                        if (!$this->cableAttachedToPoint($toCable, $pointId)) continue;
                        if (!$this->coreValidForCable($toCable, $toCoreNo)) continue;

                        $rows[] = FoLink::create([
                            'tenant_id' => $tenantId,
                            'point_id' => $pointId,
                            'link_type' => $linkType,
                            'from_cable_id' => $fromCableId,
                            'from_core_no' => $fromCoreNo,
                            'to_cable_id' => $toCableId,
                            'to_core_no' => $toCoreNo,
                            'split_group' => $group,
                            'loss_db' => $lossDb,
                            'notes' => $notes,
                            'created_by' => $userId ?: null,
                            'updated_by' => $userId ?: null,
                        ]);
                    }

                    if (count($rows) < 1) {
                        throw new \RuntimeException('Output splitter tidak valid (cek kabel/core & titik)');
                    }

                    return $rows;
                });

                return response()->json([
                    'success' => true,
                    'data' => [
                        'split_group' => $group,
                        'links' => $created,
                    ],
                ], 201);
            } catch (\Throwable $e) {
                return response()->json(['success' => false, 'message' => $e->getMessage() ?: 'Failed to create SPLIT link'], 422);
            }
        }

        $toCableId = (int) ($validated['to_cable_id'] ?? 0);
        $toCoreNo = (int) ($validated['to_core_no'] ?? 0);
        if ($toCableId <= 0 || $toCoreNo <= 0) {
            return response()->json(['message' => 'to_cable_id/to_core_no wajib diisi'], 422);
        }

        $toCable = $this->loadCable($tenantId, $toCableId);
        if (!$toCable) return response()->json(['message' => 'To cable not found'], 422);
        if (!$this->cableAttachedToPoint($toCable, $pointId)) {
            return response()->json(['message' => 'To cable tidak terhubung ke titik ini (set from/to titik di kabel)'], 422);
        }
        if (!$this->coreValidForCable($toCable, $toCoreNo)) {
            return response()->json(['message' => 'To core melebihi jumlah core kabel'], 422);
        }

        $payload = [
            'tenant_id' => $tenantId,
            'point_id' => $pointId,
            'link_type' => $linkType,
            'from_cable_id' => $fromCableId,
            'from_core_no' => $fromCoreNo,
            'to_cable_id' => $toCableId,
            'to_core_no' => $toCoreNo,
            'split_group' => null,
            'loss_db' => $lossDb,
            'notes' => $notes,
            'created_by' => $userId ?: null,
            'updated_by' => $userId ?: null,
        ];

        try {
            $link = FoLink::create($payload);
            return response()->json(['success' => true, 'data' => $link], 201);
        } catch (QueryException) {
            return response()->json(['success' => false, 'message' => 'Failed to create link'], 500);
        }
    }

    public function updateLink(Request $request, int $id): JsonResponse
    {
        if ($resp = $this->requirePermission($request, 'edit fiber')) return $resp;

        if (!Schema::hasTable('noci_fo_links')) {
            return response()->json(['success' => false, 'message' => 'Table noci_fo_links not found'], 500);
        }

        $tenantId = $this->tenantId($request);
        $link = FoLink::forTenant($tenantId)->where('id', $id)->first();
        if (!$link) return response()->json(['message' => 'Link not found'], 404);

        $validated = $request->validate([
            'point_id' => 'sometimes|required|integer|min:1',
            'link_type' => ['sometimes', 'required', Rule::in(self::LINK_TYPES)],
            'from_cable_id' => 'sometimes|required|integer|min:1',
            'from_core_no' => 'sometimes|required|integer|min:1|max:9999',
            'to_cable_id' => 'nullable|integer|min:1',
            'to_core_no' => 'nullable|integer|min:1|max:9999',
            'outputs' => 'nullable|array|min:1',
            'outputs.*.to_cable_id' => 'required|integer|min:1',
            'outputs.*.to_core_no' => 'required|integer|min:1|max:9999',
            'split_group' => 'nullable|string|max:64',
            'loss_db' => 'nullable|numeric|min:0|max:99',
            'notes' => 'nullable|string',
        ]);

        $finalPointId = array_key_exists('point_id', $validated) ? (int) $validated['point_id'] : (int) $link->point_id;
        $finalLinkType = array_key_exists('link_type', $validated) ? strtoupper((string) $validated['link_type']) : strtoupper((string) $link->link_type);
        $finalFromCableId = array_key_exists('from_cable_id', $validated) ? (int) $validated['from_cable_id'] : (int) $link->from_cable_id;
        $finalFromCoreNo = array_key_exists('from_core_no', $validated) ? (int) $validated['from_core_no'] : (int) $link->from_core_no;

        if (!FoPoint::forTenant($tenantId)->where('id', $finalPointId)->exists()) {
            return response()->json(['message' => 'Point not found'], 422);
        }

        $fromCable = $this->loadCable($tenantId, $finalFromCableId);
        if (!$fromCable) return response()->json(['message' => 'From cable not found'], 422);
        if (!$this->cableAttachedToPoint($fromCable, $finalPointId)) {
            return response()->json(['message' => 'From cable tidak terhubung ke titik ini (set from/to titik di kabel)'], 422);
        }
        if (!$this->coreValidForCable($fromCable, $finalFromCoreNo)) {
            return response()->json(['message' => 'From core melebihi jumlah core kabel'], 422);
        }

        $userId = (int) ($request->user()?->id ?? 0);

        if ($finalLinkType === 'SPLIT' && array_key_exists('outputs', $validated)) {
            $outputs = $validated['outputs'] ?? null;
            if (!is_array($outputs) || count($outputs) < 1) {
                return response()->json(['message' => 'outputs wajib diisi untuk SPLIT'], 422);
            }

            $group = (string) ($validated['split_group'] ?? ($link->split_group ?? ''));
            if ($group === '') {
                $group = 'split_' . Str::uuid()->toString();
            }

            $oldPointId = (int) $link->point_id;
            $oldGroup = (string) ($link->split_group ?? '');

            try {
                $created = DB::transaction(function () use ($tenantId, $finalPointId, $finalLinkType, $finalFromCableId, $finalFromCoreNo, $outputs, $group, $validated, $userId, $oldPointId, $oldGroup, $id) {
                    if ($oldGroup !== '') {
                        FoLink::forTenant($tenantId)
                            ->where('point_id', $oldPointId)
                            ->where('link_type', 'SPLIT')
                            ->where('split_group', $oldGroup)
                            ->delete();
                    } else {
                        FoLink::forTenant($tenantId)->where('id', $id)->delete();
                    }

                    $rows = [];
                    $lossDb = array_key_exists('loss_db', $validated) ? ($validated['loss_db'] !== null ? (float) $validated['loss_db'] : null) : null;
                    $notes = $validated['notes'] ?? null;

                    foreach ($outputs as $out) {
                        $toCableId = (int) ($out['to_cable_id'] ?? 0);
                        $toCoreNo = (int) ($out['to_core_no'] ?? 0);
                        if ($toCableId <= 0 || $toCoreNo <= 0) continue;

                        $toCable = $this->loadCable($tenantId, $toCableId);
                        if (!$toCable) continue;
                        if (!$this->cableAttachedToPoint($toCable, $finalPointId)) continue;
                        if (!$this->coreValidForCable($toCable, $toCoreNo)) continue;

                        $rows[] = FoLink::create([
                            'tenant_id' => $tenantId,
                            'point_id' => $finalPointId,
                            'link_type' => $finalLinkType,
                            'from_cable_id' => $finalFromCableId,
                            'from_core_no' => $finalFromCoreNo,
                            'to_cable_id' => $toCableId,
                            'to_core_no' => $toCoreNo,
                            'split_group' => $group,
                            'loss_db' => $lossDb,
                            'notes' => $notes,
                            'created_by' => $userId ?: null,
                            'updated_by' => $userId ?: null,
                        ]);
                    }

                    if (count($rows) < 1) {
                        throw new \RuntimeException('Output splitter tidak valid (cek kabel/core & titik)');
                    }

                    return $rows;
                });

                return response()->json([
                    'success' => true,
                    'data' => [
                        'split_group' => $group,
                        'links' => $created,
                    ],
                ]);
            } catch (\Throwable $e) {
                return response()->json(['success' => false, 'message' => $e->getMessage() ?: 'Failed to update SPLIT link'], 422);
            }
        }

        if ($finalLinkType !== 'SPLIT' && array_key_exists('outputs', $validated)) {
            return response()->json(['message' => 'outputs hanya untuk SPLIT'], 422);
        }

        $finalToCableId = array_key_exists('to_cable_id', $validated) ? (int) ($validated['to_cable_id'] ?? 0) : (int) $link->to_cable_id;
        $finalToCoreNo = array_key_exists('to_core_no', $validated) ? (int) ($validated['to_core_no'] ?? 0) : (int) $link->to_core_no;

        if ($finalLinkType !== 'SPLIT') {
            if ($finalToCableId <= 0 || $finalToCoreNo <= 0) {
                return response()->json(['message' => 'to_cable_id/to_core_no wajib diisi'], 422);
            }
        }

        if ($finalToCableId > 0) {
            $toCable = $this->loadCable($tenantId, $finalToCableId);
            if (!$toCable) return response()->json(['message' => 'To cable not found'], 422);
            if (!$this->cableAttachedToPoint($toCable, $finalPointId)) {
                return response()->json(['message' => 'To cable tidak terhubung ke titik ini (set from/to titik di kabel)'], 422);
            }
            if ($finalToCoreNo > 0 && !$this->coreValidForCable($toCable, $finalToCoreNo)) {
                return response()->json(['message' => 'To core melebihi jumlah core kabel'], 422);
            }
        }

        if (array_key_exists('point_id', $validated)) $validated['point_id'] = $finalPointId;
        if (array_key_exists('link_type', $validated)) $validated['link_type'] = $finalLinkType;
        if (array_key_exists('from_cable_id', $validated)) $validated['from_cable_id'] = $finalFromCableId;
        if (array_key_exists('from_core_no', $validated)) $validated['from_core_no'] = $finalFromCoreNo;

        if (array_key_exists('to_cable_id', $validated)) $validated['to_cable_id'] = $finalToCableId > 0 ? $finalToCableId : null;
        if (array_key_exists('to_core_no', $validated)) $validated['to_core_no'] = $finalToCoreNo > 0 ? $finalToCoreNo : null;

        if ($finalLinkType !== 'SPLIT') {
            $validated['split_group'] = null;
        } elseif (array_key_exists('split_group', $validated)) {
            $sg = (string) ($validated['split_group'] ?? '');
            $validated['split_group'] = $sg !== '' ? $sg : null;
        }

        $validated['updated_by'] = $userId ?: null;

        try {
            $link->update($validated);
            return response()->json(['success' => true, 'data' => $link->fresh()]);
        } catch (QueryException) {
            return response()->json(['success' => false, 'message' => 'Failed to update link'], 500);
        }
    }

    public function deleteLink(Request $request, int $id): JsonResponse
    {
        if ($resp = $this->requirePermission($request, 'delete fiber')) return $resp;

        if (!Schema::hasTable('noci_fo_links')) {
            return response()->json(['success' => false, 'message' => 'Table noci_fo_links not found'], 500);
        }

        $tenantId = $this->tenantId($request);
        $link = FoLink::forTenant($tenantId)->where('id', $id)->first();
        if (!$link) return response()->json(['message' => 'Link not found'], 404);

        try {
            $deleted = 0;
            if (strtoupper((string) $link->link_type) === 'SPLIT' && $link->split_group) {
                $deleted = FoLink::forTenant($tenantId)
                    ->where('point_id', (int) $link->point_id)
                    ->where('link_type', 'SPLIT')
                    ->where('split_group', (string) $link->split_group)
                    ->delete();
            } else {
                $deleted = FoLink::forTenant($tenantId)->where('id', $id)->delete();
            }

            return response()->json(['success' => true, 'data' => ['deleted' => (int) $deleted]]);
        } catch (QueryException) {
            return response()->json(['success' => false, 'message' => 'Failed to delete link'], 500);
        }
    }

    /* â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ Trace (OLT PON -> ODP OUT) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */

    public function trace(Request $request, int $portId): JsonResponse
    {
        if ($resp = $this->requirePermission($request, 'view fiber')) return $resp;

        if (!$this->tablesReady() || !Schema::hasTable('noci_fo_ports') || !Schema::hasTable('noci_fo_links')) {
            return response()->json([
                'success' => false,
                'message' => 'Fiber tables not found. Run php artisan migrate.',
            ], 500);
        }

        $tenantId = $this->tenantId($request);

        $startPort = FoPort::forTenant($tenantId)->where('id', $portId)->first();
        if (!$startPort) return response()->json(['message' => 'Start port not found'], 404);

        $portType = strtoupper((string) $startPort->port_type);
        if ($portType !== 'OLT_PON') {
            return response()->json(['message' => 'Start port harus tipe OLT_PON'], 422);
        }

        $startPointId = (int) ($startPort->point_id ?? 0);
        $startCableId = (int) ($startPort->cable_id ?? 0);
        $startCoreNo = (int) ($startPort->core_no ?? 0);

        if ($startPointId <= 0 || $startCableId <= 0 || $startCoreNo <= 0) {
            return response()->json(['message' => 'Start port belum lengkap (point/cable/core)'], 422);
        }

        $startPoint = FoPoint::forTenant($tenantId)
            ->where('id', $startPointId)
            ->first(['id', 'name', 'point_type', 'latitude', 'longitude']);
        if (!$startPoint) return response()->json(['message' => 'Start point not found'], 422);

        $startCable = $this->loadCable($tenantId, $startCableId);
        if (!$startCable) return response()->json(['message' => 'Start cable not found'], 422);
        if (!$this->cableAttachedToPoint($startCable, $startPointId)) {
            return response()->json(['message' => 'Start cable tidak terhubung ke titik OLT'], 422);
        }
        if (!$this->coreValidForCable($startCable, $startCoreNo)) {
            return response()->json(['message' => 'Start core melebihi jumlah core kabel'], 422);
        }

        $stopAtOdpOut = true;
        $qStop = $request->query('stop_at_odp_out', null);
        if ($qStop !== null) {
            $v = strtolower(trim((string) $qStop));
            $stopAtOdpOut = !in_array($v, ['0', 'false', 'no', 'off'], true);
        }

        $maxNodes = (int) $request->query('max_nodes', 20000);
        if ($maxNodes < 1000) $maxNodes = 1000;
        if ($maxNodes > 200000) $maxNodes = 200000;

        $nodeKey = static function (int $pointId, int $cableId, int $coreNo): string {
            return $pointId . ':' . $cableId . ':' . $coreNo;
        };
        $parseKey = static function (string $key): array {
            $p = explode(':', $key);
            return [
                'point_id' => (int) ($p[0] ?? 0),
                'cable_id' => (int) ($p[1] ?? 0),
                'core_no' => (int) ($p[2] ?? 0),
            ];
        };

        $points = FoPoint::forTenant($tenantId)
            ->get(['id', 'name', 'point_type', 'latitude', 'longitude'])
            ->keyBy('id');

        $cables = FoCable::forTenant($tenantId)
            ->get(['id', 'name', 'code', 'core_count', 'from_point_id', 'to_point_id'])
            ->keyBy('id');

        $links = FoLink::forTenant($tenantId)
            ->get([
                'id',
                'point_id',
                'link_type',
                'from_cable_id',
                'from_core_no',
                'to_cable_id',
                'to_core_no',
                'split_group',
                'loss_db',
            ]);

        // Index link transitions per (point,cable,core) state.
        $adj = [];
        foreach ($links as $ln) {
            $lt = strtoupper((string) ($ln->link_type ?? ''));
            $pId = (int) ($ln->point_id ?? 0);
            $fromCable = (int) ($ln->from_cable_id ?? 0);
            $fromCore = (int) ($ln->from_core_no ?? 0);
            $toCable = (int) ($ln->to_cable_id ?? 0);
            $toCore = (int) ($ln->to_core_no ?? 0);
            if ($pId <= 0 || $fromCable <= 0 || $fromCore <= 0 || $toCable <= 0 || $toCore <= 0) continue;

            $kFrom = $nodeKey($pId, $fromCable, $fromCore);
            $kTo = $nodeKey($pId, $toCable, $toCore);

            $adj[$kFrom][] = [
                'to' => $kTo,
                'edge' => ['type' => 'LINK', 'link_id' => (int) $ln->id, 'link_type' => $lt, 'dir' => 'FWD'],
            ];

            if (in_array($lt, ['SPLICE', 'PATCH'], true)) {
                $adj[$kTo][] = [
                    'to' => $kFrom,
                    'edge' => ['type' => 'LINK', 'link_id' => (int) $ln->id, 'link_type' => $lt, 'dir' => 'REV'],
                ];
            }
        }

        // Endpoint ports (ODP_OUT) are where we stop tracing (customer tracing is next update).
        $odpPorts = FoPort::forTenant($tenantId)
            ->where('port_type', 'ODP_OUT')
            ->whereNotNull('cable_id')
            ->whereNotNull('core_no')
            ->get(['id', 'point_id', 'port_type', 'port_label', 'cable_id', 'core_no']);

        $odpByNode = [];
        foreach ($odpPorts as $p) {
            $pId = (int) ($p->point_id ?? 0);
            $cId = (int) ($p->cable_id ?? 0);
            $core = (int) ($p->core_no ?? 0);
            if ($pId <= 0 || $cId <= 0 || $core <= 0) continue;
            $k = $nodeKey($pId, $cId, $core);
            if (!array_key_exists($k, $odpByNode)) $odpByNode[$k] = [];
            $odpByNode[$k][] = $p;
        }

        $startKey = $nodeKey($startPointId, $startCableId, $startCoreNo);
        $queue = [$startKey];
        $qIdx = 0;
        $visited = [$startKey => true];
        $parent = []; // childKey => ['prev' => parentKey, 'edge' => edgeInfo]

        $endpointNodeKeys = [];

        while ($qIdx < count($queue) && count($visited) < $maxNodes) {
            $key = $queue[$qIdx++];

            if (array_key_exists($key, $odpByNode)) {
                $endpointNodeKeys[$key] = true;
                if ($stopAtOdpOut) {
                    continue;
                }
            }

            $n = $parseKey($key);
            $pId = (int) ($n['point_id'] ?? 0);
            $cId = (int) ($n['cable_id'] ?? 0);
            $core = (int) ($n['core_no'] ?? 0);

            // 1) Travel along cable to the opposite end point (same cable + core).
            $cable = $cables->get($cId);
            if ($cable) {
                $fromId = (int) ($cable->from_point_id ?? 0);
                $toId = (int) ($cable->to_point_id ?? 0);
                $other = 0;
                if ($fromId === $pId && $toId > 0) $other = $toId;
                elseif ($toId === $pId && $fromId > 0) $other = $fromId;

                if ($other > 0) {
                    $nk = $nodeKey($other, $cId, $core);
                    if (!isset($visited[$nk])) {
                        $visited[$nk] = true;
                        $parent[$nk] = ['prev' => $key, 'edge' => ['type' => 'CABLE', 'cable_id' => $cId]];
                        $queue[] = $nk;
                    }
                }
            }

            // 2) Traverse splice/patch/split links at the same point (switch cable/core).
            $edges = $adj[$key] ?? [];
            foreach ($edges as $e) {
                $nk = (string) ($e['to'] ?? '');
                if ($nk === '' || isset($visited[$nk])) continue;
                $visited[$nk] = true;
                $parent[$nk] = ['prev' => $key, 'edge' => $e['edge'] ?? ['type' => 'LINK']];
                $queue[] = $nk;
            }
        }

        $truncated = count($visited) >= $maxNodes;

        $usedCableIds = [];
        $usedLinkIds = [];

        $buildPath = function (string $endKey) use ($startKey, $parent, $parseKey, $points, $cables, &$usedCableIds, &$usedLinkIds): array {
            $keys = [$endKey];
            $guard = 0;
            while ($keys[count($keys) - 1] !== $startKey && isset($parent[$keys[count($keys) - 1]]) && $guard < 200000) {
                $guard++;
                $keys[] = (string) ($parent[$keys[count($keys) - 1]]['prev'] ?? '');
            }

            $keys = array_values(array_filter($keys));
            $keys = array_reverse($keys);

            $steps = [];
            for ($i = 0; $i < count($keys); $i++) {
                $k = $keys[$i];
                $n = $parseKey($k);
                $pId = (int) ($n['point_id'] ?? 0);
                $cId = (int) ($n['cable_id'] ?? 0);

                $edge = null;
                if ($i > 0) {
                    $edge = $parent[$k]['edge'] ?? null;
                    if (is_array($edge) && ($edge['type'] ?? '') === 'CABLE') {
                        $usedCableIds[(int) ($edge['cable_id'] ?? 0)] = true;
                    }
                    if (is_array($edge) && ($edge['type'] ?? '') === 'LINK') {
                        $usedLinkIds[(int) ($edge['link_id'] ?? 0)] = true;
                    }
                }

                $steps[] = [
                    'node' => $n,
                    'edge' => $edge,
                    'point' => $points->get($pId),
                    'cable' => $cables->get($cId),
                ];
            }

            return $steps;
        };

        $endpoints = [];
        foreach (array_keys($endpointNodeKeys) as $ek) {
            $portsAtNode = $odpByNode[$ek] ?? [];
            if (count($portsAtNode) < 1) continue;

            $steps = $buildPath($ek);
            $node = $parseKey($ek);
            $pId = (int) ($node['point_id'] ?? 0);

            foreach ($portsAtNode as $p) {
                $endpoints[] = [
                    'port' => $p,
                    'point' => $points->get($pId),
                    'path' => $steps,
                ];
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'start_port' => [
                    'id' => (int) $startPort->id,
                    'point_id' => $startPointId,
                    'port_type' => $portType,
                    'port_label' => (string) ($startPort->port_label ?? ''),
                    'cable_id' => $startCableId,
                    'core_no' => $startCoreNo,
                ],
                'start_point' => $startPoint,
                'endpoints' => $endpoints,
                'used_cable_ids' => array_map('intval', array_keys($usedCableIds)),
                'used_link_ids' => array_map('intval', array_keys($usedLinkIds)),
                'visited_nodes' => count($visited),
                'truncated' => $truncated,
            ],
        ]);
    }
}
