<?php

use App\Models\NociUser;
use App\Models\Olt;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('olt.auto-register.{tenantId}.{oltId}.{runId}', function (NociUser $user, int $tenantId, int $oltId, int $runId) {
    if ((int) ($user->tenant_id ?? 0) !== $tenantId) {
        return false;
    }

    if (!$user->canManageOlt()) {
        return false;
    }

    return Olt::forTenant($tenantId)->whereKey($oltId)->exists();
});
