<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class MapsController extends Controller
{
    private function tenantId(Request $request): int
    {
        return (int) ($request->attributes->get('tenant_id') ?? 0);
    }

    /**
     * Get technician live locations from noci_user_location_latest
     * with last event from noci_user_location_logs
     */
    public function locations(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        if ($tenantId <= 0) {
            return response()->json(['success' => false, 'message' => 'Tenant context missing'], 403);
        }

        try {
            // Query noci_user_location_latest + LEFT JOIN noci_user_location_logs for last event
            // This matches native api_installations.php get_live_locations
            $technicians = DB::table('noci_user_location_latest as loc')
                ->leftJoin(DB::raw('(SELECT user_id, tenant_id, event_name, recorded_at as event_at FROM noci_user_location_logs WHERE tenant_id = ' . intval($tenantId) . ' AND id IN (SELECT MAX(id) FROM noci_user_location_logs WHERE tenant_id = ' . intval($tenantId) . ' GROUP BY user_id)) as evt'), function ($join) {
                    $join->on('loc.user_id', '=', 'evt.user_id')
                        ->on('loc.tenant_id', '=', 'evt.tenant_id');
                })
                ->where('loc.tenant_id', $tenantId)
                ->select([
                    'loc.user_id as technician_id',
                    'loc.user_name as technician_name',
                    'loc.user_role as technician_role',
                    'loc.latitude',
                    'loc.longitude',
                    'loc.accuracy',
                    'loc.speed',
                    'loc.heading',
                    'loc.recorded_at',
                    'evt.event_name as last_event',
                    'evt.event_at as last_event_at',
                ])
                ->get();

            return response()->json([
                'success' => true,
                'status' => 'success',
                'data' => $technicians,
            ]);
        } catch (QueryException $e) {
            return response()->json([
                'success' => false,
                'status' => 'error',
                'message' => 'Failed to load locations',
                'data' => [],
            ]);
        }
    }

    /**
     * Get technician location history with date range support
     */
    public function history(Request $request, int $techId): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        if ($tenantId <= 0) {
            return response()->json(['success' => false, 'message' => 'Tenant context missing'], 403);
        }

        // Support date range (date_from / date_to) like native, with fallback to single date
        $dateFrom = $request->input('date_from', $request->input('date', now()->toDateString()));
        $dateTo = $request->input('date_to', $dateFrom);

        try {
            $history = DB::table('noci_user_location_logs')
                ->where('tenant_id', $tenantId)
                ->where('user_id', $techId)
                ->whereDate('recorded_at', '>=', $dateFrom)
                ->whereDate('recorded_at', '<=', $dateTo)
                ->orderBy('recorded_at', 'asc')
                ->limit(2000)
                ->get([
                    'event_name',
                    'latitude',
                    'longitude',
                    'accuracy',
                    'speed',
                    'heading',
                    'recorded_at',
                ]);

            return response()->json([
                'success' => true,
                'status' => 'success',
                'data' => $history,
            ]);
        } catch (QueryException $e) {
            return response()->json([
                'success' => false,
                'status' => 'error',
                'data' => [],
                'message' => 'Failed to load history',
            ]);
        }
    }

    /**
     * Update technician location (from mobile)
     */
    public function updateLocation(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'latitude' => 'required|numeric',
            'longitude' => 'required|numeric',
            'accuracy' => 'nullable|numeric',
            'speed' => 'nullable|numeric',
            'heading' => 'nullable|numeric',
            'event_name' => 'nullable|string|max:80',
        ]);

        $tenantId = $this->tenantId($request);
        if ($tenantId <= 0) {
            return response()->json(['success' => false, 'message' => 'Tenant context missing'], 403);
        }

        $user = $request->user();
        $userId = (int) ($user?->id ?? 0);
        if (!$userId) {
            return response()->json(['success' => false, 'message' => 'User not authenticated'], 401);
        }

        $userName = (string) ($user->name ?? '');
        $userRole = (string) ($user->role ?? '');
        $now = now()->format('Y-m-d H:i:s');

        // Upsert into noci_user_location_latest
        try {
            DB::table('noci_user_location_latest')->updateOrInsert(
                ['tenant_id' => $tenantId, 'user_id' => $userId],
                [
                    'user_name' => $userName,
                    'user_role' => $userRole,
                    'latitude' => $validated['latitude'],
                    'longitude' => $validated['longitude'],
                    'accuracy' => $validated['accuracy'] ?? null,
                    'speed' => $validated['speed'] ?? null,
                    'heading' => $validated['heading'] ?? null,
                    'recorded_at' => $now,
                ]
            );
        } catch (QueryException) {
        }

        // Also upsert into noci_user_locations for backward compat
        try {
            DB::table('noci_user_locations')->updateOrInsert(
                ['tenant_id' => $tenantId, 'user_id' => $userId],
                [
                    'latitude' => $validated['latitude'],
                    'longitude' => $validated['longitude'],
                    'accuracy' => $validated['accuracy'] ?? null,
                    'activity' => $validated['event_name'] ?? null,
                    'updated_at' => now(),
                ]
            );
        } catch (QueryException) {
        }

        // Log into noci_user_location_logs
        try {
            DB::table('noci_user_location_logs')->insert([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'user_name' => $userName,
                'user_role' => $userRole,
                'event_name' => $validated['event_name'] ?? null,
                'latitude' => $validated['latitude'],
                'longitude' => $validated['longitude'],
                'accuracy' => $validated['accuracy'] ?? null,
                'speed' => $validated['speed'] ?? null,
                'heading' => $validated['heading'] ?? null,
                'recorded_at' => $now,
            ]);
        } catch (QueryException) {
        }

        return response()->json([
            'success' => true,
            'message' => 'Location updated',
        ]);
    }

    /**
     * Get active installations with coordinates for map
     */
    public function installations(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        if ($tenantId <= 0) {
            return response()->json(['success' => false, 'message' => 'Tenant context missing'], 403);
        }

        $installations = DB::table('noci_installations')
            ->where('tenant_id', $tenantId)
            ->whereNotNull('coordinates')
            ->where('coordinates', '!=', '')
            ->whereIn('status', ['Baru', 'Survey', 'Proses', 'Pending'])
            ->select([
                'id',
                'status',
                'pop',
                DB::raw('customer_name as nama'),
                DB::raw('address as alamat'),
                DB::raw('coordinates as koordinat'),
                DB::raw("CONCAT_WS(', ', NULLIF(technician,''), NULLIF(technician_2,''), NULLIF(technician_3,''), NULLIF(technician_4,'')) as teknisi"),
            ])
            ->get()
            ->map(function ($item) {
                $coords = explode(',', $item->koordinat);
                $item->lat = isset($coords[0]) ? (float) trim($coords[0]) : null;
                $item->lng = isset($coords[1]) ? (float) trim($coords[1]) : null;
                return $item;
            })
            ->filter(fn($item) => $item->lat && $item->lng);

        return response()->json([
            'success' => true,
            'data' => $installations->values(),
        ]);
    }
}
