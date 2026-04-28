<?php

use App\Models\OltLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Schema;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('tenants.{tenantId}.olts.{oltId}.auto-register.{runId}', function ($user, $tenantId, $oltId, $runId) {
    if ((int) ($user->tenant_id ?? 0) !== (int) $tenantId) {
        return false;
    }

    try {
        $table = (new OltLog())->getTable();
        if (!Schema::hasTable($table)) {
            return false;
        }

        return DB::table($table)
            ->where('tenant_id', (int) $tenantId)
            ->where('olt_id', (int) $oltId)
            ->where('id', (int) $runId)
            ->where('action', 'register_auto')
            ->exists();
    } catch (\Throwable) {
        return false;
    }
});

Broadcast::channel('tenants.{tenantId}.chat', function ($user, $tenantId) {
    if ((int) ($user->tenant_id ?? 0) !== (int) $tenantId) {
        return false;
    }

    try {
        if (method_exists($user, 'can') && $user->can('view chat')) {
            return true;
        }
    } catch (\Throwable) {
    }

    return true;
});
