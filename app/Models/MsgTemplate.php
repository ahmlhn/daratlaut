<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MsgTemplate extends Model
{
    protected $table = 'noci_msg_templates';

    // Table has no timestamps
    public $timestamps = false;
    
    protected $fillable = [
        'tenant_id',
        'code',
        'description',
        'message',
    ];

    // Channel constants
    const CHANNEL_WA = 'whatsapp';
    const CHANNEL_TG = 'telegram';
    const CHANNEL_ALL = 'all';

    // Scopes
    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForChannel($query, string $channel)
    {
        return $query->where(function ($q) use ($channel) {
            $q->where('channel', $channel)
              ->orWhere('channel', self::CHANNEL_ALL);
        });
    }

    // Template parsing
    public function render(array $data = []): string
    {
        $content = $this->content;
        
        foreach ($data as $key => $value) {
            $content = str_replace("{{$key}}", $value, $content);
            $content = str_replace("{{ $key }}", $value, $content);
        }
        
        return $content;
    }
}
