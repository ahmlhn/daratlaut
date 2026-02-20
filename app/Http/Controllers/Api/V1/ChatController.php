<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ChatController extends Controller
{
    private const CHAT_HISTORY_CACHE_TTL_SECONDS = 20;
    private const CONTACTS_CACHE_TTL_SECONDS = 5;
    private static array $colCache = [];
    private static array $autoEndLastRun = [];
    private ?string $dbNowCache = null;

    private function tenantId(Request $request): int
    {
        return (int) ($request->attributes->get('tenant_id') ?? 0);
    }

    private function nowDb(): string
    {
        if (is_string($this->dbNowCache) && $this->dbNowCache !== '') return $this->dbNowCache;

        try {
            $row = DB::selectOne('SELECT NOW() as now_time');
            if ($row && isset($row->now_time)) {
                $this->dbNowCache = (string) $row->now_time;
                return $this->dbNowCache;
            }
        } catch (\Throwable) {
        }

        // Fallback (should be rare)
        $this->dbNowCache = now()->format('Y-m-d H:i:s');
        return $this->dbNowCache;
    }

    private function conversationCacheVersionKey(int $tenantId, string $visitId): string
    {
        return 'chat:v1:convver:' . $tenantId . ':' . md5($visitId);
    }

    private function conversationChunkCacheKey(
        int $tenantId,
        string $visitId,
        int $beforeId,
        int $limit,
        bool $hasUpdatedAt,
        bool $hasMsgStatus,
        int $version
    ): string {
        $fingerprint = implode(':', [
            $tenantId,
            md5($visitId),
            $beforeId,
            $limit,
            $hasUpdatedAt ? 'u1' : 'u0',
            $hasMsgStatus ? 's1' : 's0',
            $version,
        ]);
        return 'chat:v1:chunk:' . md5($fingerprint);
    }

    private function conversationCacheVersion(int $tenantId, string $visitId): int
    {
        $key = $this->conversationCacheVersionKey($tenantId, $visitId);
        try {
            $v = Cache::get($key);
            if (is_numeric($v)) return max(1, (int) $v);
            Cache::forever($key, 1);
        } catch (\Throwable) {
        }
        return 1;
    }

    private function bumpConversationCacheVersion(int $tenantId, string $visitId): void
    {
        $key = $this->conversationCacheVersionKey($tenantId, $visitId);
        try {
            if (Cache::has($key)) {
                Cache::increment($key);
            } else {
                Cache::forever($key, 2);
            }
        } catch (\Throwable) {
        }
    }

    private function forgetContactsCache(int $tenantId): void
    {
        if ($tenantId <= 0) return;

        try {
            Cache::forget('chat:v1:contacts_full:' . $tenantId);
        } catch (\Throwable) {
        }
    }

    private function hasColumnCached(string $table, string $col): bool
    {
        $key = $table . ':' . $col;
        if (array_key_exists($key, self::$colCache)) {
            return (bool) self::$colCache[$key];
        }

        $exists = false;
        try {
            $exists = Schema::hasColumn($table, $col);
        } catch (\Throwable) {
            $exists = false;
        }

        self::$colCache[$key] = $exists;
        return $exists;
    }

    private function hasChatColumn(string $col): bool
    {
        return $this->hasColumnCached('noci_chat', $col);
    }

    private function hasCustomerColumn(string $col): bool
    {
        return $this->hasColumnCached('noci_customers', $col);
    }

    private function hasSettingsColumn(string $col): bool
    {
        return $this->hasColumnCached('noci_chat_settings', $col);
    }

    private function maybeAutoEndSessions(int $tenantId): void
    {
        if ($tenantId <= 0) return;

        // Throttle to once per minute per tenant.
        $now = time();
        $last = (int) (self::$autoEndLastRun[$tenantId] ?? 0);
        if ($now - $last < 60) return;
        self::$autoEndLastRun[$tenantId] = $now;

        if (!$this->hasCustomerColumn('tenant_id')) return;

        // Hybrid auto-close: session closes when BOTH conditions are met:
        //   1. No chat from anyone (admin or customer) in the last 30 minutes
        //   2. last_seen is also > 30 minutes ago (covers admin reopen without sending a message)
        // This way: customer chat keeps it open, admin chat keeps it open,
        // admin reopen (updates last_seen) keeps it open for 30 min even without chat.
        try {
            $hasChatTenant = $this->hasChatColumn('tenant_id');
            $hasLastSeen = $this->hasCustomerColumn('last_seen');
            $tenantFilter = $hasChatTenant ? "AND ch.tenant_id = ?" : "";
            $bindings = $hasChatTenant ? [$tenantId, $tenantId] : [$tenantId];

            $lastSeenCond = $hasLastSeen
                ? "AND (c.last_seen IS NULL OR c.last_seen < (NOW() - INTERVAL 30 MINUTE))"
                : "";

            DB::statement(
                "UPDATE noci_customers c
                 SET c.status = 'Selesai'
                 WHERE c.tenant_id = ? AND c.status != 'Selesai'
                   {$lastSeenCond}
                   AND NOT EXISTS (
                       SELECT 1 FROM noci_chat ch
                       WHERE ch.visit_id = c.visit_id {$tenantFilter}
                         AND ch.created_at > (NOW() - INTERVAL 30 MINUTE)
                   )",
                $bindings
            );
        } catch (\Throwable) {
        }
    }

    private function chatUploadDir(): string
    {
        // Store under public/ so both API and legacy UI can serve files easily.
        return public_path('uploads/chat');
    }

    private function normalizePhone(string $raw): string
    {
        $p = preg_replace('/\D/', '', $raw);
        if ($p === '') return '';
        if (str_starts_with($p, '0')) $p = '62' . substr($p, 1);
        if (str_starts_with($p, '8')) $p = '62' . $p;
        return $p;
    }

    /**
     * GET /api/v1/chat/contacts
     * Mirrors native chat/admin_api.php get_contacts (including last_sync + search).
     */
    public function contacts(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        if ($tenantId <= 0) {
            return response()->json(['status' => 'error', 'msg' => 'Tenant context missing'], 403);
        }

        // Auto-end session after 30 minutes of inactivity (native behavior).
        $this->maybeAutoEndSessions($tenantId);

        $search = trim((string) $request->query('search', ''));
        $lastSync = trim((string) $request->query('last_sync', ''));
        $limit = 100;

        // Cache the "full contacts list" response briefly. This reduces load on shared hosting where
        // contacts are polled frequently and the list query does a join + aggregates.
        $contactsCacheKey = '';
        if ($search === '' && $lastSync === '') {
            $contactsCacheKey = 'chat:v1:contacts_full:' . $tenantId;
            try {
                $cached = Cache::get($contactsCacheKey);
                if (is_array($cached)) {
                    return response()->json($cached);
                }
            } catch (\Throwable) {
                // Ignore cache backend failures and fall back to DB path.
            }
        }

        $hasTenant = $this->hasCustomerColumn('tenant_id');
        $base = DB::table('noci_customers as c');
        if ($hasTenant) {
            $base->where('c.tenant_id', $tenantId);
        }

        $chatHasTenant = $this->hasChatColumn('tenant_id');
        $hasMsgStatus = $this->hasChatColumn('msg_status');
        $chatActiveWhere = $hasMsgStatus ? "(msg_status='active' OR msg_status IS NULL)" : "1=1";
        $chatActiveExistsWhere = $hasMsgStatus ? "(ch.msg_status='active' OR ch.msg_status IS NULL)" : "1=1";

        // Fast path for polling: if client sends last_sync and there are no changes,
        // avoid running the heavier join + aggregation queries.
        if ($search === '' && $lastSync !== '') {
            $syncCol = $this->hasChatColumn('updated_at') ? 'updated_at' : 'created_at';

            try {
                $bindings = [];

                $custExistsSql = 'SELECT 1 FROM noci_customers';
                $custWhere = [];
                if ($hasTenant) {
                    $custWhere[] = 'tenant_id = ?';
                    $bindings[] = $tenantId;
                }
                if ($this->hasCustomerColumn('last_seen')) {
                    $custWhere[] = 'last_seen > ?';
                    $bindings[] = $lastSync;
                } else {
                    $custWhere[] = '1=0';
                }
                $custExistsSql .= ' WHERE ' . implode(' AND ', $custWhere) . ' LIMIT 1';

                $chatExistsSql = 'SELECT 1 FROM noci_chat';
                $chatWhere = [];
                if ($chatHasTenant) {
                    $chatWhere[] = 'tenant_id = ?';
                    $bindings[] = $tenantId;
                }
                $chatWhere[] = $syncCol . ' > ?';
                $bindings[] = $lastSync;
                $chatExistsSql .= ' WHERE ' . implode(' AND ', $chatWhere) . ' LIMIT 1';

                $row = DB::selectOne(
                    'SELECT EXISTS(' . $custExistsSql . ') as cust_changed, EXISTS(' . $chatExistsSql . ') as chat_changed',
                    $bindings
                );

                $hasCustomerUpdate = (bool) ($row->cust_changed ?? false);
                $hasChatUpdate = (bool) ($row->chat_changed ?? false);

                if (!$hasCustomerUpdate && !$hasChatUpdate) {
                    return response()->json([
                        'status' => 'success',
                        // Keep the same sync cursor to avoid extra NOW() queries on idle polling.
                        'server_time' => $lastSync,
                        'data' => [],
                        'is_search' => false,
                    ]);
                }
            } catch (\Throwable) {
                // If the quick check fails, fall back to full query path.
            }
        }

        $lastChat = DB::table('noci_chat')
            ->selectRaw('visit_id, MAX(created_at) AS last_chat_at')
            ->when($chatHasTenant, fn ($q) => $q->where('tenant_id', $tenantId))
            ->whereRaw($chatActiveWhere)
            ->groupBy('visit_id');

        $base->leftJoinSub($lastChat, 'lc', function ($join) {
            $join->on('lc.visit_id', '=', 'c.visit_id');
        });
        // Do not show empty sessions in admin list.
        // A customer may hit start_session without sending any message yet.
        $base->whereExists(function ($q) use ($tenantId, $chatHasTenant, $chatActiveExistsWhere) {
            $q->selectRaw('1')
                ->from('noci_chat as ch')
                ->whereColumn('ch.visit_id', 'c.visit_id')
                ->when($chatHasTenant, fn ($qq) => $qq->where('ch.tenant_id', $tenantId))
                ->whereRaw($chatActiveExistsWhere);
        });

        if ($search !== '') {
            $base->where(function ($q) use ($search) {
                $q->where('c.customer_name', 'like', "%{$search}%")
                    ->orWhere('c.customer_phone', 'like', "%{$search}%")
                    ->orWhere('c.visit_id', 'like', "%{$search}%");
            });
            $base->limit(20);
        } elseif ($lastSync !== '') {
            // Delta sync: customers with last_seen changes OR any chat updated after lastSync.
            $syncCol = $this->hasChatColumn('updated_at') ? 'updated_at' : 'created_at';
            $base->where(function ($q) use ($tenantId, $lastSync, $chatHasTenant, $syncCol) {
                $q->where('c.last_seen', '>', $lastSync);
                $q->orWhereIn('c.visit_id', function ($sub) use ($tenantId, $lastSync, $chatHasTenant, $syncCol) {
                    $sub->selectRaw('DISTINCT visit_id')
                        ->from('noci_chat')
                        ->when($chatHasTenant, fn ($qq) => $qq->where('tenant_id', $tenantId))
                        ->where($syncCol, '>', $lastSync);
                });
            });
        } else {
            $base->limit($limit);
        }

        // Order like native: COALESCE(last_chat_at, last_seen) desc, then last_seen desc, then visit_id desc
        $base->orderByRaw('COALESCE(lc.last_chat_at, c.last_seen) DESC')
            ->orderByDesc('c.last_seen')
            ->orderByDesc('c.visit_id');

        $selectCols = [
            'c.visit_id',
            'c.customer_name',
            'c.customer_phone',
            'c.customer_address',
            'c.status',
            'c.notes',
            'c.ip_address',
            'c.location_info',
            'c.last_seen',
            DB::raw('NOW() as _server_time'),
        ];

        $rows = $base->get($selectCols);
        $serverTime = (string) (($rows->first()->_server_time ?? '') ?: '');

        $visitIds = $rows->pluck('visit_id')->filter()->values()->all();
        if (empty($visitIds)) {
            $fallbackTime = $lastSync !== '' ? $lastSync : ($serverTime !== '' ? $serverTime : $this->nowDb());
            return response()->json(['status' => 'success', 'server_time' => $fallbackTime, 'data' => [], 'is_search' => $search !== '']);
        }

        // Unread counts (user -> admin, is_read=0).
        $unread = DB::table('noci_chat')
            ->selectRaw('visit_id, COUNT(*) as unread')
            ->when($chatHasTenant, fn ($q) => $q->where('tenant_id', $tenantId))
            ->whereIn('visit_id', $visitIds)
            ->where('sender', 'user')
            ->where('is_read', 0)
            ->when($hasMsgStatus, fn ($q) => $q->where(function ($qq) {
                $qq->where('msg_status', 'active')->orWhereNull('msg_status');
            }))
            ->groupBy('visit_id')
            ->pluck('unread', 'visit_id')
            ->toArray();

        // Last message per visit_id (active only).
        $lastMsgSub = DB::table('noci_chat')
            ->selectRaw('visit_id, MAX(id) as max_id')
            ->when($chatHasTenant, fn ($q) => $q->where('tenant_id', $tenantId))
            ->whereIn('visit_id', $visitIds)
            ->when($hasMsgStatus, fn ($q) => $q->where(function ($qq) {
                $qq->where('msg_status', 'active')->orWhereNull('msg_status');
            }))
            ->groupBy('visit_id');

        $lastMsgs = DB::table('noci_chat as ch')
            ->joinSub($lastMsgSub, 'm', function ($join) {
                $join->on('m.max_id', '=', 'ch.id');
            })
            ->get(['ch.id', 'ch.visit_id', 'ch.message', 'ch.type', 'ch.created_at'])
            ->keyBy('visit_id');

        $data = [];
        foreach ($rows as $r) {
            $vid = (string) ($r->visit_id ?? '');
            $lm = $lastMsgs->get($vid);
            $data[] = [
                'visit_id' => $vid,
                'name' => (string) ($r->customer_name ?? 'Tanpa Nama'),
                'phone' => (string) ($r->customer_phone ?? ''),
                'address' => (string) ($r->customer_address ?? ''),
                'status' => (string) ($r->status ?? ''),
                'notes' => (string) ($r->notes ?? ''),
                'ip_address' => (string) ($r->ip_address ?? ''),
                'location_info' => (string) ($r->location_info ?? ''),
                'last_seen' => (string) ($r->last_seen ?? ''),
                'unread' => (int) ($unread[$vid] ?? 0),
                'last_msg' => $lm ? (string) ($lm->message ?? '') : '',
                'msg_type' => $lm ? (string) ($lm->type ?? 'text') : 'text',
                'last_msg_at' => $lm ? (string) ($lm->created_at ?? '') : '',
                'display_time' => $lm && !empty($lm->created_at) ? date('H:i', strtotime((string) $lm->created_at)) : '',
            ];
        }

        $payload = [
            'status' => 'success',
            'server_time' => $serverTime !== '' ? $serverTime : ($lastSync !== '' ? $lastSync : $this->nowDb()),
            'data' => $data,
            'is_search' => $search !== '',
        ];

        if ($contactsCacheKey !== '') {
            try {
                Cache::put($contactsCacheKey, $payload, now()->addSeconds(self::CONTACTS_CACHE_TTL_SECONDS));
            } catch (\Throwable) {
                // ignore
            }
        }

        return response()->json($payload);
    }

    /**
     * GET /api/v1/chat/messages?visit_id=...&last_sync=...
     * Mirrors native smart sync (delta updates via updated_at).
     */
    public function messages(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        if ($tenantId <= 0) {
            return response()->json(['status' => 'error', 'msg' => 'Tenant context missing'], 403);
        }

        $visitId = trim((string) $request->query('visit_id', ''));
        if ($visitId === '') {
            return response()->json(['status' => 'success', 'server_time' => $this->nowDb(), 'messages' => [], 'has_more' => false, 'oldest_id' => null]);
        }

        $lastSync = trim((string) $request->query('last_sync', ''));
        $beforeId = max(0, (int) $request->query('before_id', 0));
        $limit = max(20, min(200, (int) $request->query('limit', 80)));
        $hasUpdatedAt = $this->hasChatColumn('updated_at');
        $hasMsgStatus = $this->hasChatColumn('msg_status');

        $select = ['id', 'sender', 'message', 'type', 'is_edited', 'created_at'];
        if ($hasMsgStatus) $select[] = 'msg_status';
        if ($hasUpdatedAt) $select[] = 'updated_at';
        $rows = collect();
        $hasMore = false;
        $oldestId = null;

        $canUseChunkCache = ($lastSync === '');
        $chunkCacheKey = '';
        $cachedChunk = null;
        if ($canUseChunkCache) {
            $version = $this->conversationCacheVersion($tenantId, $visitId);
            $chunkCacheKey = $this->conversationChunkCacheKey(
                $tenantId,
                $visitId,
                $beforeId,
                $limit,
                $hasUpdatedAt,
                $hasMsgStatus,
                $version
            );
            try {
                $cachedChunk = Cache::get($chunkCacheKey);
            } catch (\Throwable) {
                $cachedChunk = null;
            }
        }

        if (is_array($cachedChunk) && isset($cachedChunk['rows']) && is_array($cachedChunk['rows'])) {
            $rows = collect($cachedChunk['rows'])->map(function ($r) {
                return (object) $r;
            });
            $hasMore = (bool) ($cachedChunk['has_more'] ?? false);
            $tmpOldest = $cachedChunk['oldest_id'] ?? null;
            $oldestId = is_numeric($tmpOldest) ? (int) $tmpOldest : null;
        } else {
            $q = DB::table('noci_chat')
                ->when($this->hasChatColumn('tenant_id'), fn ($qb) => $qb->where('tenant_id', $tenantId))
                ->where('visit_id', $visitId);

            if ($lastSync !== '') {
                // Delta sync: prefer updated_at (native behavior). If the legacy table
                // doesn't have updated_at, fall back to created_at to avoid returning
                // full history every poll (which would replay notif sound).
                if ($hasUpdatedAt) {
                    $q->where('updated_at', '>', $lastSync);
                } else {
                    $q->where('created_at', '>', $lastSync);
                }
                $q->orderBy('id');
            } else {
                if ($hasMsgStatus) {
                    $q->where(function ($qb) {
                        $qb->where('msg_status', 'active')->orWhereNull('msg_status');
                    });
                }
                if ($beforeId > 0) {
                    $q->where('id', '<', $beforeId);
                }
                // Initial/history chunk: fetch newest rows first then reverse in-memory.
                $q->orderByDesc('id')->limit($limit);
            }

            $rows = $q->get($select);
            if ($lastSync === '') {
                $rows = $rows->reverse()->values();
            }

            if ($lastSync === '' && !$rows->isEmpty()) {
                $oldestId = (int) $rows->min('id');
                if ($oldestId > 0) {
                    $olderQ = DB::table('noci_chat')
                        ->when($this->hasChatColumn('tenant_id'), fn ($qb) => $qb->where('tenant_id', $tenantId))
                        ->where('visit_id', $visitId)
                        ->where('id', '<', $oldestId);
                    if ($hasMsgStatus) {
                        $olderQ->where(function ($qb) {
                            $qb->where('msg_status', 'active')->orWhereNull('msg_status');
                        });
                    }
                    $hasMore = $olderQ->exists();
                }
            }

            if ($canUseChunkCache && $chunkCacheKey !== '') {
                $rowsForCache = [];
                foreach ($rows as $r) {
                    $rowsForCache[] = (array) $r;
                }
                try {
                    Cache::put($chunkCacheKey, [
                        'rows' => $rowsForCache,
                        'has_more' => $hasMore,
                        'oldest_id' => $oldestId,
                    ], now()->addSeconds(self::CHAT_HISTORY_CACHE_TTL_SECONDS));
                } catch (\Throwable) {
                }
            }
        }

        // Mark read (admin viewing).
        // IMPORTANT: do NOT force-touch updated_at on every poll, otherwise the legacy
        // frontend will treat every response as "new" and keep playing notif sound.
        // Only update rows that are still unread, and only when we actually received user messages
        // (or on initial load).
        $isHistoryChunk = ($lastSync === '' && $beforeId > 0);
        $shouldMarkRead = ($lastSync === '' && !$isHistoryChunk);
        if (!$shouldMarkRead) {
            foreach ($rows as $r) {
                if ((string) ($r->sender ?? '') === 'user') {
                    $shouldMarkRead = true;
                    break;
                }
            }
        }

        // Decide sync cursor with minimal DB round-trips.
        // - If we mark read, advance cursor to DB NOW() (and pin updated_at to it) so read-updates
        //   don't get re-sent on the next poll and unread badges can clear via contacts delta-sync.
        // - Otherwise, for change payloads, use max(updated_at)/max(created_at) from returned rows.
        // - If no changes, keep last_sync (prevents extra NOW() queries while polling).
        $serverTime = '';
        if ($shouldMarkRead) {
            $serverTime = $this->nowDb();
        } elseif (!$rows->isEmpty()) {
            $serverTime = $hasUpdatedAt ? ((string) $rows->max('updated_at')) : ((string) $rows->max('created_at'));
        } elseif ($lastSync !== '') {
            $serverTime = $lastSync;
        } else {
            $serverTime = $this->nowDb();
        }

        if ($shouldMarkRead) {
            try {
                $update = ['is_read' => 1];
                if ($hasUpdatedAt) {
                    // Pin updated_at to the same cursor we return to avoid re-sending read-updates next poll.
                    $update['updated_at'] = $serverTime;
                }

                DB::table('noci_chat')
                    ->when($this->hasChatColumn('tenant_id'), fn ($qq) => $qq->where('tenant_id', $tenantId))
                    ->where('visit_id', $visitId)
                    ->where('sender', 'user')
                    ->where('is_read', 0)
                    ->update($update);
            } catch (\Throwable) {
            }
        }

        $messages = [];
        foreach ($rows as $r) {
            $messages[] = [
                'id' => (int) $r->id,
                'sender' => (string) ($r->sender ?? ''),
                'message' => (string) ($r->message ?? ''),
                'type' => (string) ($r->type ?? 'text'),
                'is_edited' => (int) ($r->is_edited ?? 0),
                'status' => $hasMsgStatus ? ((string) ($r->msg_status ?? 'active')) : 'active',
                'created_at' => (string) ($r->created_at ?? ''),
                'time' => !empty($r->created_at) ? date('H:i', strtotime((string) $r->created_at)) : '',
                'media_url' => ((string) ($r->type ?? 'text') === 'image') ? url("/api/v1/chat/media/" . ((int) $r->id)) : null,
            ];
        }

        return response()->json([
            'status' => 'success',
            'server_time' => $serverTime,
            'messages' => $messages,
            'has_more' => $hasMore,
            'oldest_id' => $oldestId,
        ]);
    }

    /**
     * GET /api/v1/chat/media/{id}
     * Serve image message securely (tenant-scoped).
     */
    public function media(Request $request, int $id): BinaryFileResponse|JsonResponse
    {
        $tenantId = $this->tenantId($request);
        if ($tenantId <= 0) {
            return response()->json(['status' => 'error', 'msg' => 'Tenant context missing'], 403);
        }

        $row = DB::table('noci_chat')
            ->when($this->hasChatColumn('tenant_id'), fn ($q) => $q->where('tenant_id', $tenantId))
            ->where('id', $id)
            ->first(['id', 'type', 'message']);

        if (!$row || (string) ($row->type ?? '') !== 'image') {
            return response()->json(['status' => 'error', 'msg' => 'Not found'], 404);
        }

        $filename = basename((string) ($row->message ?? ''));
        if ($filename === '' || Str::contains($filename, ['..', '/', '\\'])) {
            return response()->json(['status' => 'error', 'msg' => 'Invalid file'], 400);
        }

        $candidates = [
            $this->chatUploadDir() . DIRECTORY_SEPARATOR . $filename,
            // Legacy native path (best-effort, for migrated data)
            base_path('chat' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $filename),
        ];

        foreach ($candidates as $path) {
            if (is_file($path)) {
                return response()->file($path);
            }
        }

        return response()->json(['status' => 'error', 'msg' => 'File not found'], 404);
    }

    /**
     * POST /api/v1/chat/send  (multipart: message + optional image)
     */
    public function send(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        if ($tenantId <= 0) {
            return response()->json(['status' => 'error', 'msg' => 'Tenant context missing'], 403);
        }

        $request->validate([
            'visit_id' => 'required|string|max:50',
            'message' => 'nullable|string',
            'image' => 'nullable|file|max:5120', // 5MB
        ]);

        $visitId = trim((string) $request->input('visit_id'));
        $sender = 'admin';

        $type = 'text';
        $msg = trim((string) $request->input('message', ''));

        if ($request->hasFile('image')) {
            $file = $request->file('image');
            if (!$file || !$file->isValid()) {
                return response()->json(['status' => 'error', 'msg' => 'Upload gagal'], 422);
            }
            $ext = strtolower((string) $file->getClientOriginalExtension());
            $allowedExt = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
            if (!in_array($ext, $allowedExt, true)) {
                return response()->json(['status' => 'error', 'msg' => 'Format gambar tidak valid'], 422);
            }

            $dir = $this->chatUploadDir();
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }

            $newName = time() . '_' . random_int(1000, 9999) . '.' . $ext;
            $file->move($dir, $newName);

            $type = 'image';
            $msg = $newName;
        } else {
            if ($msg === '') {
                return response()->json(['status' => 'error', 'msg' => 'Pesan kosong'], 422);
            }
        }

        $hasTenant = $this->hasChatColumn('tenant_id');
        $hasUpdatedAt = $this->hasChatColumn('updated_at');
        $hasMsgStatus = $this->hasChatColumn('msg_status');

        $now = $this->nowDb();
        $insert = [
            'visit_id' => $visitId,
            'sender' => $sender,
            'message' => $msg,
            'type' => $type,
            'is_read' => 1,
            'is_edited' => 0,
            'created_at' => $now,
        ];
        if ($hasTenant) $insert['tenant_id'] = $tenantId;
        if ($hasUpdatedAt) $insert['updated_at'] = $now;
        if ($hasMsgStatus) $insert['msg_status'] = 'active';

        $id = DB::table('noci_chat')->insertGetId($insert);
        $this->bumpConversationCacheVersion($tenantId, $visitId);

        // When admin sends, ensure customer status is Proses unless already Selesai (native behavior).
        if ($this->hasCustomerColumn('tenant_id')) {
            try {
                DB::table('noci_customers')
                    ->where('tenant_id', $tenantId)
                    ->where('visit_id', $visitId)
                    ->where('status', '!=', 'Selesai')
                    ->update(['status' => 'Proses', 'last_seen' => DB::raw('NOW()')]);
            } catch (\Throwable) {
            }
        }

        return response()->json(['status' => 'success', 'id' => $id]);
    }

    /**
     * POST /api/v1/chat/message/{id}/delete
     */
    public function deleteMessage(Request $request, int $id): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        if ($tenantId <= 0) {
            return response()->json(['status' => 'error', 'msg' => 'Tenant context missing'], 403);
        }

        $row = DB::table('noci_chat')
            ->when($this->hasChatColumn('tenant_id'), fn ($q) => $q->where('tenant_id', $tenantId))
            ->where('id', $id)
            ->first(['id', 'visit_id', 'sender', 'type', 'message']);

        if (!$row) {
            return response()->json(['status' => 'error', 'msg' => 'Not found'], 404);
        }
        if ((string) ($row->sender ?? '') !== 'admin') {
            return response()->json(['status' => 'error', 'msg' => 'Forbidden'], 403);
        }

        $hasMsgStatus = $this->hasChatColumn('msg_status');
        if ($hasMsgStatus) {
            DB::table('noci_chat')
                ->when($this->hasChatColumn('tenant_id'), fn ($q) => $q->where('tenant_id', $tenantId))
                ->where('id', $id)
                ->update(['msg_status' => 'deleted'] + ($this->hasChatColumn('updated_at') ? ['updated_at' => $this->nowDb()] : []));
        } else {
            DB::table('noci_chat')
                ->when($this->hasChatColumn('tenant_id'), fn ($q) => $q->where('tenant_id', $tenantId))
                ->where('id', $id)
                ->delete();
        }

        // If image, remove file if exists (parity native).
        if ((string) ($row->type ?? '') === 'image') {
            $filename = basename((string) ($row->message ?? ''));
            $path = $this->chatUploadDir() . DIRECTORY_SEPARATOR . $filename;
            if (is_file($path)) {
                @unlink($path);
            }
        }

        $this->bumpConversationCacheVersion($tenantId, (string) ($row->visit_id ?? ''));

        return response()->json(['status' => 'success']);
    }

    /**
     * POST /api/v1/chat/message/{id}/edit
     */
    public function editMessage(Request $request, int $id): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        if ($tenantId <= 0) {
            return response()->json(['status' => 'error', 'msg' => 'Tenant context missing'], 403);
        }

        $validated = $request->validate([
            'message' => 'required|string',
        ]);

        $row = DB::table('noci_chat')
            ->when($this->hasChatColumn('tenant_id'), fn ($q) => $q->where('tenant_id', $tenantId))
            ->where('id', $id)
            ->first(['id', 'visit_id', 'sender', 'type']);
        if (!$row) return response()->json(['status' => 'error', 'msg' => 'Not found'], 404);
        if ((string) ($row->sender ?? '') !== 'admin') return response()->json(['status' => 'error', 'msg' => 'Forbidden'], 403);
        if ((string) ($row->type ?? '') === 'image') return response()->json(['status' => 'error', 'msg' => 'Tidak bisa edit gambar'], 422);

        $upd = [
            'message' => (string) $validated['message'],
            'is_edited' => 1,
        ];
        if ($this->hasChatColumn('updated_at')) $upd['updated_at'] = $this->nowDb();

        DB::table('noci_chat')
            ->when($this->hasChatColumn('tenant_id'), fn ($q) => $q->where('tenant_id', $tenantId))
            ->where('id', $id)
            ->update($upd);

        $this->bumpConversationCacheVersion($tenantId, (string) ($row->visit_id ?? ''));

        return response()->json(['status' => 'success']);
    }

    public function updateCustomer(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        if ($tenantId <= 0) return response()->json(['status' => 'error', 'msg' => 'Tenant context missing'], 403);

        $validated = $request->validate([
            'visit_id' => 'required|string|max:50',
            'name' => 'required|string|max:100',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:255',
        ]);

        $upd = [
            'customer_name' => (string) $validated['name'],
            'customer_phone' => $this->normalizePhone((string) ($validated['phone'] ?? '')),
            'customer_address' => (string) ($validated['address'] ?? ''),
        ];

        DB::table('noci_customers')
            ->when($this->hasCustomerColumn('tenant_id'), fn ($q) => $q->where('tenant_id', $tenantId))
            ->where('visit_id', (string) $validated['visit_id'])
            ->update($upd);

        return response()->json(['status' => 'success']);
    }

    public function saveNote(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        if ($tenantId <= 0) return response()->json(['status' => 'error', 'msg' => 'Tenant context missing'], 403);

        $validated = $request->validate([
            'visit_id' => 'required|string|max:50',
            'note' => 'nullable|string',
        ]);

        DB::table('noci_customers')
            ->when($this->hasCustomerColumn('tenant_id'), fn ($q) => $q->where('tenant_id', $tenantId))
            ->where('visit_id', (string) $validated['visit_id'])
            ->update(['notes' => (string) ($validated['note'] ?? '')]);

        return response()->json(['status' => 'success']);
    }

    public function endSession(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        if ($tenantId <= 0) return response()->json(['status' => 'error', 'msg' => 'Tenant context missing'], 403);

        $validated = $request->validate([
            'visit_id' => 'required|string|max:50',
        ]);

        DB::table('noci_customers')
            ->when($this->hasCustomerColumn('tenant_id'), fn ($q) => $q->where('tenant_id', $tenantId))
            ->where('visit_id', (string) $validated['visit_id'])
            ->update(['status' => 'Selesai']);

        return response()->json(['status' => 'success']);
    }

    public function reopenSession(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        if ($tenantId <= 0) return response()->json(['status' => 'error', 'msg' => 'Tenant context missing'], 403);

        $validated = $request->validate([
            'visit_id' => 'required|string|max:50',
        ]);

        $upd = ['status' => 'Proses'];
        if ($this->hasCustomerColumn('last_seen')) {
            $upd['last_seen'] = DB::raw('NOW()');
        }

        DB::table('noci_customers')
            ->when($this->hasCustomerColumn('tenant_id'), fn ($q) => $q->where('tenant_id', $tenantId))
            ->where('visit_id', (string) $validated['visit_id'])
            ->update($upd);

        return response()->json(['status' => 'success']);
    }

    public function deleteSession(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        if ($tenantId <= 0) return response()->json(['status' => 'error', 'msg' => 'Tenant context missing'], 403);

        $validated = $request->validate([
            'visit_id' => 'required|string|max:50',
        ]);

        $visitId = (string) $validated['visit_id'];
        $hasTenant = $this->hasChatColumn('tenant_id');

        // Clean images
        $imgs = DB::table('noci_chat')
            ->when($hasTenant, fn ($q) => $q->where('tenant_id', $tenantId))
            ->where('visit_id', $visitId)
            ->where('type', 'image')
            ->pluck('message')
            ->all();
        foreach ($imgs as $fn) {
            $filename = basename((string) $fn);
            $path = $this->chatUploadDir() . DIRECTORY_SEPARATOR . $filename;
            if (is_file($path)) @unlink($path);
        }

        DB::table('noci_chat')->when($hasTenant, fn ($q) => $q->where('tenant_id', $tenantId))->where('visit_id', $visitId)->delete();
        DB::table('noci_customers')->when($this->hasCustomerColumn('tenant_id'), fn ($q) => $q->where('tenant_id', $tenantId))->where('visit_id', $visitId)->delete();
        $this->bumpConversationCacheVersion($tenantId, $visitId);
        $this->forgetContactsCache($tenantId);

        return response()->json(['status' => 'success']);
    }

    public function templates(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        if ($tenantId <= 0) return response()->json(['status' => 'error', 'msg' => 'Tenant context missing'], 403);

        try {
            $rows = DB::table('noci_quick_replies')
                ->when(Schema::hasColumn('noci_quick_replies', 'tenant_id'), fn ($q) => $q->where('tenant_id', $tenantId))
                ->orderByDesc('id')
                ->get(['id', 'label', 'message']);
        } catch (QueryException) {
            $rows = collect();
        }

        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    public function saveTemplate(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        if ($tenantId <= 0) return response()->json(['status' => 'error', 'msg' => 'Tenant context missing'], 403);

        $validated = $request->validate([
            'label' => 'required|string|max:50',
            'message' => 'required|string',
        ]);

        $insert = [
            'label' => (string) $validated['label'],
            'message' => (string) $validated['message'],
        ];
        if (Schema::hasColumn('noci_quick_replies', 'tenant_id')) $insert['tenant_id'] = $tenantId;

        $id = DB::table('noci_quick_replies')->insertGetId($insert);
        return response()->json(['status' => 'success', 'id' => $id]);
    }

    public function deleteTemplate(Request $request, int $id): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        if ($tenantId <= 0) return response()->json(['status' => 'error', 'msg' => 'Tenant context missing'], 403);

        DB::table('noci_quick_replies')
            ->when(Schema::hasColumn('noci_quick_replies', 'tenant_id'), fn ($q) => $q->where('tenant_id', $tenantId))
            ->where('id', $id)
            ->delete();

        return response()->json(['status' => 'success']);
    }

    public function getSettings(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        if ($tenantId <= 0) return response()->json(['status' => 'error', 'msg' => 'Tenant context missing'], 403);

        try {
            $q = DB::table('noci_chat_settings');
            if ($this->hasSettingsColumn('tenant_id')) $q->where('tenant_id', $tenantId);
            $row = $q->where('id', 1)->first();
        } catch (QueryException) {
            $row = null;
        }

        return response()->json(['status' => 'success', 'data' => $row]);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        if ($tenantId <= 0) return response()->json(['status' => 'error', 'msg' => 'Tenant context missing'], 403);

        $validated = $request->validate([
            'mode' => 'required|string|in:manual_on,manual_off,scheduled',
            'wa_number' => 'nullable|string|max:20',
            'wa_message' => 'nullable|string',
            'start_hour' => 'nullable|string',
            'end_hour' => 'nullable|string',
        ]);

        $wa = $this->normalizePhone((string) ($validated['wa_number'] ?? ''));
        $data = [
            'mode' => (string) $validated['mode'],
            'wa_number' => $wa,
            'wa_message' => (string) ($validated['wa_message'] ?? ''),
            'start_hour' => (string) ($validated['start_hour'] ?? '08:00:00'),
            'end_hour' => (string) ($validated['end_hour'] ?? '17:00:00'),
        ];

        try {
            $q = DB::table('noci_chat_settings')->where('id', 1);
            if ($this->hasSettingsColumn('tenant_id')) {
                $q->where('tenant_id', $tenantId);
            }
            $exists = $q->exists();

            if ($exists) {
                DB::table('noci_chat_settings')
                    ->where('id', 1)
                    ->when($this->hasSettingsColumn('tenant_id'), fn ($qq) => $qq->where('tenant_id', $tenantId))
                    ->update($data);
            } else {
                $ins = ['id' => 1] + $data;
                if ($this->hasSettingsColumn('tenant_id')) $ins['tenant_id'] = $tenantId;
                DB::table('noci_chat_settings')->insert($ins);
            }
        } catch (QueryException $e) {
            return response()->json(['status' => 'error', 'msg' => $e->getMessage()], 500);
        }

        return response()->json(['status' => 'success']);
    }

    /**
     * GET /api/v1/chat/admin-profile
     * Native parity: get_admin_profile
     */
    public function adminProfile(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        if ($tenantId <= 0) return response()->json(['status' => 'error', 'msg' => 'Tenant context missing'], 403);

        $username = (string) ($request->user()?->username ?? '');
        if ($username === '') {
            // Fallback: some setups only have name
            $username = (string) ($request->user()?->email ?? '');
        }

        $q = DB::table('noci_users')->where('tenant_id', $tenantId);
        if ($username !== '' && Schema::hasColumn('noci_users', 'username')) {
            $q->where('username', $username);
        } else {
            $q->where('id', (int) ($request->user()?->id ?? 0));
        }

        $row = $q->first();
        if (!$row) {
            return response()->json(['status' => 'error', 'msg' => 'User not found'], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'name' => (string) (($row->fullname ?? null) ?: ($row->name ?? '')),
                'username' => (string) ($row->username ?? ''),
            ],
        ]);
    }

    /**
     * POST /api/v1/chat/admin-profile
     * Native parity: update_admin_profile
     */
    public function updateAdminProfile(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        if ($tenantId <= 0) return response()->json(['status' => 'error', 'msg' => 'Tenant context missing'], 403);

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'username' => 'required|string|max:50',
            'password' => 'nullable|string|min:4',
        ]);

        $userId = (int) ($request->user()?->id ?? 0);
        if ($userId <= 0) return response()->json(['status' => 'error', 'msg' => 'User not authenticated'], 401);

        $upd = [];
        if (Schema::hasColumn('noci_users', 'fullname')) {
            $upd['fullname'] = (string) $validated['name'];
        }
        if (Schema::hasColumn('noci_users', 'name')) {
            $upd['name'] = (string) $validated['name'];
        }
        if (Schema::hasColumn('noci_users', 'username')) {
            $upd['username'] = (string) $validated['username'];
        }
        if (!empty($validated['password']) && Schema::hasColumn('noci_users', 'password')) {
            $upd['password'] = password_hash((string) $validated['password'], PASSWORD_DEFAULT);
        }

        if (empty($upd)) {
            return response()->json(['status' => 'error', 'msg' => 'No columns to update'], 500);
        }

        DB::table('noci_users')
            ->where('tenant_id', $tenantId)
            ->where('id', $userId)
            ->update($upd);

        return response()->json(['status' => 'success']);
    }

    /**
     * GET /api/v1/chat/game/my-high-score
     * Native parity: get_my_high_score
     */
    public function myHighScore(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        if ($tenantId <= 0) return response()->json(['status' => 'error', 'msg' => 'Tenant context missing'], 403);

        $me = (string) ($request->user()?->name ?? 'Admin');
        $q = DB::table('noci_game_scores')->where('username', $me);
        if (Schema::hasColumn('noci_game_scores', 'tenant_id')) {
            $q->where('tenant_id', $tenantId);
        }
        $score = (int) ($q->value('score') ?? 0);

        return response()->json(['status' => 'success', 'score' => $score]);
    }

    /**
     * POST /api/v1/chat/game/score
     * Native parity: save_game_score (only update if higher)
     */
    public function saveGameScore(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        if ($tenantId <= 0) return response()->json(['status' => 'error', 'msg' => 'Tenant context missing'], 403);

        $validated = $request->validate([
            'score' => 'required|integer|min:0',
        ]);

        $me = (string) ($request->user()?->name ?? 'Admin');
        $score = (int) $validated['score'];

        $q = DB::table('noci_game_scores')->where('username', $me);
        if (Schema::hasColumn('noci_game_scores', 'tenant_id')) {
            $q->where('tenant_id', $tenantId);
        }
        $existing = $q->value('score');

        if ($existing === null) {
            $ins = ['username' => $me, 'score' => $score];
            if (Schema::hasColumn('noci_game_scores', 'tenant_id')) {
                $ins['tenant_id'] = $tenantId;
            }
            DB::table('noci_game_scores')->insert($ins);
            return response()->json(['status' => 'success', 'msg' => 'Skor pertama!']);
        }

        $existingScore = (int) $existing;
        if ($score > $existingScore) {
            $upd = ['score' => $score];
            if (Schema::hasColumn('noci_game_scores', 'updated_at')) $upd['updated_at'] = $this->nowDb();
            DB::table('noci_game_scores')
                ->where('username', $me)
                ->when(Schema::hasColumn('noci_game_scores', 'tenant_id'), fn ($qq) => $qq->where('tenant_id', $tenantId))
                ->update($upd);
            return response()->json(['status' => 'success', 'msg' => 'Rekor baru!']);
        }

        return response()->json(['status' => 'info', 'msg' => 'Belum rekor.']);
    }

    /**
     * GET /api/v1/chat/game/leaderboard
     * Native parity: get_leaderboard
     */
    public function leaderboard(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        if ($tenantId <= 0) return response()->json(['status' => 'error', 'msg' => 'Tenant context missing'], 403);

        $me = (string) ($request->user()?->name ?? '');

        $q = DB::table('noci_game_scores');
        if (Schema::hasColumn('noci_game_scores', 'tenant_id')) {
            $q->where('tenant_id', $tenantId);
        }

        $rows = $q->orderByDesc('score')->limit(5)->get(['username', 'score']);

        $rank = 1;
        $data = [];
        foreach ($rows as $r) {
            $data[] = [
                'rank' => $rank++,
                'name' => (string) ($r->username ?? ''),
                'score' => (int) ($r->score ?? 0),
                'is_me' => ((string) ($r->username ?? '') === $me),
            ];
        }

        return response()->json(['status' => 'success', 'data' => $data]);
    }
}
