<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OltAutoRegisterProgressUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public int $tenantId,
        public int $oltId,
        public array $payload
    ) {
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel(
            "olt.auto-register.{$this->tenantId}.{$this->oltId}.{$this->payload['run_id']}"
        );
    }

    public function broadcastAs(): string
    {
        return 'olt.auto-register.progress';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
