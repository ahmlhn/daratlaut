<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChatUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $tenantId,
        public string $event,
        public string $visitId,
        public array $payload = [],
    ) {
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("tenants.{$this->tenantId}.chat"),
            new PrivateChannel("tenants.{$this->tenantId}.chat.visits." . md5($this->visitId)),
        ];
    }

    public function broadcastAs(): string
    {
        return 'chat.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'event' => $this->event,
            'visit_id' => $this->visitId,
            'payload' => $this->payload,
            'server_time' => now()->format('Y-m-d H:i:s'),
        ];
    }
}
