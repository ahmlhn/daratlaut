<?php

namespace App\Services;

use App\Events\ChatUpdated;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class ChatRealtimeBroadcaster
{
    private array $columnCache = [];

    public function messageCreated(int $tenantId, string $visitId, int $messageId): void
    {
        $this->broadcast($tenantId, 'message.created', $visitId, [
            'message' => $this->messagePayload($tenantId, $messageId),
        ]);
    }

    public function messageUpdated(int $tenantId, string $visitId, int $messageId): void
    {
        $this->broadcast($tenantId, 'message.updated', $visitId, [
            'message' => $this->messagePayload($tenantId, $messageId),
        ]);
    }

    public function messageDeleted(int $tenantId, string $visitId, int $messageId): void
    {
        $this->broadcast($tenantId, 'message.deleted', $visitId, [
            'message' => [
                'id' => $messageId,
                'status' => 'deleted',
            ],
        ]);
    }

    public function contactUpdated(int $tenantId, string $visitId, string $event = 'contact.updated'): void
    {
        $this->broadcast($tenantId, $event, $visitId);
    }

    public function presenceUpdated(int $tenantId, string $visitId, bool $isOnline, ?string $lastSeen = null): void
    {
        $this->broadcast($tenantId, 'presence.updated', $visitId, [
            'presence' => [
                'is_online' => $isOnline,
                'last_seen' => $lastSeen ?: now()->format('Y-m-d H:i:s'),
            ],
        ]);
    }

    public function sessionDeleted(int $tenantId, string $visitId): void
    {
        $this->broadcast($tenantId, 'session.deleted', $visitId);
    }

    public function broadcast(int $tenantId, string $event, string $visitId, array $payload = []): void
    {
        if ($tenantId <= 0 || $visitId === '') {
            return;
        }

        try {
            event(new ChatUpdated($tenantId, $event, $visitId, $payload));
        } catch (\Throwable $e) {
            Log::warning('Failed broadcasting chat update', [
                'tenant_id' => $tenantId,
                'event' => $event,
                'visit_id' => $visitId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function messagePayload(int $tenantId, int $messageId): ?array
    {
        if ($messageId <= 0) {
            return null;
        }

        try {
            $hasTenant = $this->hasColumn('noci_chat', 'tenant_id');
            $hasUpdatedAt = $this->hasColumn('noci_chat', 'updated_at');
            $hasMsgStatus = $this->hasColumn('noci_chat', 'msg_status');

            $select = ['id', 'visit_id', 'sender', 'message', 'type', 'is_edited', 'created_at'];
            if ($hasUpdatedAt) {
                $select[] = 'updated_at';
            }
            if ($hasMsgStatus) {
                $select[] = 'msg_status';
            }
            foreach (['delivery_status', 'read_at', 'file_name', 'file_mime', 'file_size'] as $optionalColumn) {
                if ($this->hasColumn('noci_chat', $optionalColumn)) {
                    $select[] = $optionalColumn;
                }
            }

            $row = DB::table('noci_chat')
                ->when($hasTenant, fn ($q) => $q->where('tenant_id', $tenantId))
                ->where('id', $messageId)
                ->first($select);

            if (!$row) {
                return null;
            }

            $type = (string) ($row->type ?? 'text');

            return [
                'id' => (int) $row->id,
                'visit_id' => (string) ($row->visit_id ?? ''),
                'sender' => (string) ($row->sender ?? ''),
                'message' => (string) ($row->message ?? ''),
                'type' => $type,
                'is_edited' => (int) ($row->is_edited ?? 0),
                'status' => $hasMsgStatus ? ((string) ($row->msg_status ?? 'active')) : 'active',
                'delivery_status' => (string) ($row->delivery_status ?? 'sent'),
                'read_at' => (string) ($row->read_at ?? ''),
                'file_name' => (string) ($row->file_name ?? ''),
                'file_mime' => (string) ($row->file_mime ?? ''),
                'file_size' => isset($row->file_size) ? (int) $row->file_size : null,
                'created_at' => (string) ($row->created_at ?? ''),
                'time' => !empty($row->created_at) ? date('H:i', strtotime((string) $row->created_at)) : '',
                'media_url' => in_array($type, ['image', 'file'], true) ? url('/api/v1/chat/media/' . ((int) $row->id)) : null,
            ];
        } catch (\Throwable $e) {
            Log::warning('Failed building chat realtime message payload', [
                'tenant_id' => $tenantId,
                'message_id' => $messageId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function hasColumn(string $table, string $column): bool
    {
        $key = $table . ':' . $column;
        if (array_key_exists($key, $this->columnCache)) {
            return (bool) $this->columnCache[$key];
        }

        try {
            return $this->columnCache[$key] = Schema::hasColumn($table, $column);
        } catch (\Throwable) {
            return $this->columnCache[$key] = false;
        }
    }
}
