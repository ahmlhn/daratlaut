<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Api\V1\ChatController as ChatApiController;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Legacy-compatible endpoint for the native chat frontend (chat/app.js + chat/game.js).
 *
 * The goal is 1:1 payload compatibility with `chat/admin_api.php` so we can keep
 * the original UI/JS intact while serving it from the Laravel admin panel.
 */
class ChatAdminApiController extends Controller
{
    private static array $ensuredTenants = [];

    private function tenantId(Request $request): int
    {
        return (int) ($request->attributes->get('tenant_id') ?? 0);
    }

    private function normalizePhone(string $raw): string
    {
        $p = preg_replace('/\D/', '', $raw);
        if ($p === '') return '';
        if (str_starts_with($p, '0')) $p = '62' . substr($p, 1);
        if (str_starts_with($p, '8')) $p = '62' . $p;
        return $p;
    }

    private function ensureLegacyTables(int $tenantId): void
    {
        // This endpoint is hit frequently (polling). Avoid INFORMATION_SCHEMA checks on every request.
        if (isset(self::$ensuredTenants[$tenantId])) return;
        self::$ensuredTenants[$tenantId] = true;

        // Best-effort: if DB is unavailable, just skip.
        try {
            if (!Schema::hasTable('noci_quick_replies')) {
                Schema::create('noci_quick_replies', function ($t) {
                    $t->increments('id');
                    $t->integer('tenant_id')->index();
                    $t->string('label', 50)->nullable();
                    $t->text('message')->nullable();
                });
            }

            if (!Schema::hasTable('noci_game_scores')) {
                Schema::create('noci_game_scores', function ($t) {
                    $t->increments('id');
                    $t->integer('tenant_id')->index();
                    $t->string('username', 50);
                    $t->integer('score')->default(0);
                    $t->timestamp('updated_at')->nullable();
                    $t->unique(['tenant_id', 'username'], 'unique_user');
                });
            } else {
                // Existing table from native schema may lack tenant_id — add it.
                if (!Schema::hasColumn('noci_game_scores', 'tenant_id')) {
                    try {
                        Schema::table('noci_game_scores', function ($t) {
                            $t->integer('tenant_id')->default(1)->after('id');
                            $t->index('tenant_id');
                        });
                    } catch (\Throwable) {}
                }
            }

            if (!Schema::hasTable('noci_chat_settings')) {
                Schema::create('noci_chat_settings', function ($t) {
                    $t->integer('tenant_id');
                    $t->integer('id')->default(1);
                    $t->enum('mode', ['manual_on', 'manual_off', 'scheduled'])->default('manual_on');
                    $t->string('wa_number', 20)->default('');
                    $t->text('wa_message')->nullable();
                    $t->time('start_hour')->default('08:00:00');
                    $t->time('end_hour')->default('17:00:00');
                    $t->primary(['tenant_id', 'id']);
                });
            }

            // Seed default settings row if missing (native behavior).
            $exists = DB::table('noci_chat_settings')
                ->where('tenant_id', $tenantId)
                ->where('id', 1)
                ->exists();
            if (!$exists) {
                DB::table('noci_chat_settings')->insert([
                    'tenant_id' => $tenantId,
                    'id' => 1,
                    'mode' => 'manual_on',
                    'wa_number' => '628123456789',
                    'wa_message' => 'Halo Admin...',
                    'start_hour' => '08:00:00',
                    'end_hour' => '17:00:00',
                ]);
            }
        } catch (\Throwable) {
            // ignore
        }
    }

    public function handle(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        if ($tenantId <= 0) {
            return response()->json(['status' => 'error', 'msg' => 'Tenant context missing'], 403);
        }

        $this->ensureLegacyTables($tenantId);

        $action = (string) ($request->input('action') ?? $request->query('action') ?? '');

        /** @var ChatApiController $api */
        $api = app(ChatApiController::class);

        // Passthrough actions we already implemented in the REST controller.
        if ($action === 'get_contacts') return $api->contacts($request);
        if ($action === 'get_messages') {
            $vid = trim((string) $request->query('visit_id', ''));
            if ($vid === '') return response()->json([]);
            return $api->messages($request);
        }
        if ($action === 'send') return $api->send($request);
        if ($action === 'delete_message') {
            $id = (int) $request->input('id', 0);
            return $api->deleteMessage($request, $id);
        }
        if ($action === 'edit_message') {
            $id = (int) $request->input('id', 0);
            return $api->editMessage($request, $id);
        }
        if ($action === 'update_customer') return $api->updateCustomer($request);
        if ($action === 'save_note') return $api->saveNote($request);
        if ($action === 'end_session') return $api->endSession($request);
        if ($action === 'reopen_session') return $api->reopenSession($request);
        if ($action === 'delete_session') return $api->deleteSession($request);

        // Templates (quick replies)
        if ($action === 'get_templates') {
            $rows = DB::table('noci_quick_replies')
                ->where('tenant_id', $tenantId)
                ->orderByDesc('id')
                ->get(['id', 'label', 'message', 'tenant_id']);
            return response()->json($rows);
        }
        if ($action === 'save_template') {
            $label = trim((string) $request->input('label', ''));
            $msg = trim((string) $request->input('message', ''));
            DB::table('noci_quick_replies')->insert([
                'tenant_id' => $tenantId,
                'label' => $label,
                'message' => $msg,
            ]);
            return response()->json(['status' => 'success']);
        }
        if ($action === 'delete_template') {
            $id = (int) $request->input('id', 0);
            DB::table('noci_quick_replies')
                ->where('tenant_id', $tenantId)
                ->where('id', $id)
                ->delete();
            return response()->json(['status' => 'success']);
        }

        // Settings (return plain object, not wrapper)
        if ($action === 'get_settings') {
            $row = DB::table('noci_chat_settings')
                ->where('tenant_id', $tenantId)
                ->where('id', 1)
                ->first();
            return response()->json($row ? (array) $row : null);
        }
        if ($action === 'save_settings') {
            $mode = (string) $request->input('mode', 'manual_on');
            $wa = $this->normalizePhone((string) $request->input('wa_number', ''));
            $msg = (string) $request->input('wa_message', '');
            $start = (string) $request->input('start_hour', '08:00');
            $end = (string) $request->input('end_hour', '17:00');

            DB::table('noci_chat_settings')
                ->where('tenant_id', $tenantId)
                ->where('id', 1)
                ->update([
                    'mode' => $mode,
                    'wa_number' => $wa,
                    'wa_message' => $msg,
                    'start_hour' => $start,
                    'end_hour' => $end,
                ]);

            return response()->json(['status' => 'success']);
        }

        // Admin profile (payload parity with native)
        if ($action === 'get_admin_profile') {
            $username = (string) (session('admin_username') ?: 'admin');

            $u = DB::table('noci_users')
                ->when(Schema::hasColumn('noci_users', 'tenant_id'), fn ($q) => $q->where('tenant_id', $tenantId))
                ->where('username', $username)
                ->first();
            if (!$u) {
                $u = DB::table('noci_team')
                    ->when(Schema::hasColumn('noci_team', 'tenant_id'), fn ($q) => $q->where('tenant_id', $tenantId))
                    ->where('username', $username)
                    ->first();
            }
            if (!$u) return response()->json(['status' => 'error', 'msg' => 'User not found']);

            $arr = (array) $u;
            unset($arr['password']);
            return response()->json(['status' => 'success', 'data' => $arr]);
        }

        if ($action === 'update_admin_profile') {
            $oldUser = (string) (session('admin_username') ?: 'admin');
            $newName = trim((string) $request->input('name', ''));
            $newUser = trim((string) $request->input('username', ''));
            $newPass = (string) $request->input('password', '');

            if ($newName === '' || $newUser === '') {
                return response()->json(['status' => 'error', 'msg' => 'Invalid payload'], 422);
            }

            $update = ['fullname' => $newName, 'username' => $newUser];
            if ($newPass !== '') {
                $update['password'] = password_hash($newPass, PASSWORD_DEFAULT);
            }

            $updated = DB::table('noci_users')
                ->when(Schema::hasColumn('noci_users', 'tenant_id'), fn ($q) => $q->where('tenant_id', $tenantId))
                ->where('username', $oldUser)
                ->update($update);

            if (!$updated) {
                DB::table('noci_team')
                    ->when(Schema::hasColumn('noci_team', 'tenant_id'), fn ($q) => $q->where('tenant_id', $tenantId))
                    ->where('username', $oldUser)
                    ->update($update);
            }

            session(['admin_name' => $newName, 'admin_username' => $newUser]);
            return response()->json(['status' => 'success']);
        }

        // Game: save score + my score + leaderboard (payload parity)
        if ($action === 'get_my_high_score') {
            $username = (string) (session('admin_username') ?: session('username') ?: 'admin');
            $q = DB::table('noci_game_scores')->where('username', $username);
            if (Schema::hasColumn('noci_game_scores', 'tenant_id')) {
                $q->where('tenant_id', $tenantId);
            }
            $row = $q->first(['score']);
            return response()->json(['status' => 'success', 'score' => (int) ($row->score ?? 0)]);
        }

        if ($action === 'save_game_score') {
            $username = (string) (session('admin_username') ?: session('username') ?: 'admin');
            $score = (int) $request->input('score', 0);
            if ($score < 0) $score = 0;

            $hasTenantCol = Schema::hasColumn('noci_game_scores', 'tenant_id');

            // Upsert: keep best score only (native behavior).
            $q = DB::table('noci_game_scores')->where('username', $username);
            if ($hasTenantCol) $q->where('tenant_id', $tenantId);
            $existing = $q->first(['score']);

            if (!$existing) {
                $insert = [
                    'username' => $username,
                    'score' => $score,
                    'updated_at' => now(),
                ];
                if ($hasTenantCol) $insert['tenant_id'] = $tenantId;
                DB::table('noci_game_scores')->insert($insert);
            } else {
                $best = max((int) $existing->score, $score);
                $upd = DB::table('noci_game_scores')->where('username', $username);
                if ($hasTenantCol) $upd->where('tenant_id', $tenantId);
                $upd->update(['score' => $best, 'updated_at' => now()]);
            }

            return response()->json(['status' => 'success']);
        }

        if ($action === 'get_leaderboard') {
            $currentUser = (string) (session('admin_username') ?: session('username') ?: '');
            $q = DB::table('noci_game_scores');
            if (Schema::hasColumn('noci_game_scores', 'tenant_id')) {
                $q->where('tenant_id', $tenantId);
            }
            $rows = $q->orderByDesc('score')
                ->orderBy('updated_at')
                ->limit(5)
                ->get(['username', 'score', 'updated_at']);

            $rank = 0;
            $out = [];
            foreach ($rows as $r) {
                $rank++;
                $out[] = [
                    'rank' => $rank,
                    'name' => (string) ($r->username ?? 'Unknown'),
                    'username' => (string) ($r->username ?? ''),
                    'score' => (int) ($r->score ?? 0),
                    'is_me' => Str::lower((string) ($r->username ?? '')) === Str::lower($currentUser),
                ];
            }

            return response()->json(['status' => 'success', 'data' => $out]);
        }

        return response()->json(['status' => 'error', 'msg' => 'Unknown action'], 400);
    }
}
