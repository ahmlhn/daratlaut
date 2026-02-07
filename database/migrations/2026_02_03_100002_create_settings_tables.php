<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Settings and configuration tables from native PHP
     */
    public function up(): void
    {
        // WhatsApp configuration
        if (!Schema::hasTable('noci_conf_wa')) {
            Schema::create('noci_conf_wa', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('tenant_id')->default(1);
                $table->string('base_url', 255)->nullable();
                $table->string('group_url', 255)->nullable();
                $table->string('token', 255)->nullable();
                $table->string('sender_number', 50)->nullable();
                $table->string('target_number', 50)->nullable();
                $table->string('group_id', 100)->nullable();
                $table->boolean('is_active')->default(false);
                $table->timestamps();
                
                $table->index('tenant_id');
            });
        }

        // Telegram configuration
        if (!Schema::hasTable('noci_conf_tg')) {
            Schema::create('noci_conf_tg', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('tenant_id')->default(1);
                $table->string('bot_token', 255)->nullable();
                $table->string('chat_id', 100)->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                
                $table->index('tenant_id');
            });
        }

        // Message templates
        if (!Schema::hasTable('noci_msg_templates')) {
            Schema::create('noci_msg_templates', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('tenant_id')->default(1);
                $table->string('code', 50);
                $table->string('description', 150)->nullable();
                $table->text('message');
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                
                $table->unique(['tenant_id', 'code']);
            });
        }

        // WA Gateways (multi-gateway support)
        if (!Schema::hasTable('noci_wa_gateways')) {
            Schema::create('noci_wa_gateways', function (Blueprint $table) {
                $table->id();
                $table->string('name', 100);
                $table->string('type', 50)->comment('balesotomatis, mpwa, wablas, etc');
                $table->string('base_url', 255);
                $table->string('token', 255)->nullable();
                $table->text('extra_config')->nullable()->comment('JSON for type-specific config');
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        // Tenant-gateway mapping
        if (!Schema::hasTable('noci_wa_tenant_gateways')) {
            Schema::create('noci_wa_tenant_gateways', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('tenant_id');
                $table->unsignedBigInteger('gateway_id');
                $table->unsignedTinyInteger('priority')->default(1);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                
                $table->index(['tenant_id', 'priority']);
                $table->foreign('gateway_id')->references('id')->on('noci_wa_gateways')->onDelete('cascade');
            });
        }

        // WA outbox (pending messages)
        if (!Schema::hasTable('noci_wa_outbox')) {
            Schema::create('noci_wa_outbox', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('tenant_id');
                $table->string('target', 50)->comment('Phone number');
                $table->text('message');
                $table->string('type', 20)->default('personal')->comment('personal or group');
                $table->string('status', 20)->default('pending');
                $table->unsignedTinyInteger('attempts')->default(0);
                $table->text('last_error')->nullable();
                $table->timestamp('scheduled_at')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamps();
                
                $table->index(['tenant_id', 'status']);
                $table->index(['status', 'scheduled_at']);
            });
        }

        // Notification logs
        if (!Schema::hasTable('noci_notif_logs')) {
            Schema::create('noci_notif_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('tenant_id')->default(1);
                $table->string('platform', 30)->comment('wa, tg, sms');
                $table->string('target', 100)->nullable();
                $table->text('message')->nullable();
                $table->string('status', 30)->nullable();
                $table->text('response_log')->nullable();
                $table->timestamps();
                
                $table->index(['tenant_id', 'platform', 'created_at']);
            });
        }

        // Recap groups (for teknisi rekap)
        if (!Schema::hasTable('noci_recap_groups')) {
            Schema::create('noci_recap_groups', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('tenant_id');
                $table->string('group_name', 100);
                $table->string('group_id', 100)->comment('WA group ID');
                $table->boolean('is_default')->default(false);
                $table->timestamps();
                
                $table->index('tenant_id');
            });
        }

        // Activity logs
        if (!Schema::hasTable('noci_activity_logs')) {
            Schema::create('noci_activity_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('tenant_id')->default(1);
                $table->string('actor', 100)->nullable();
                $table->string('action_type', 50)->nullable();
                $table->string('target_type', 50)->nullable();
                $table->unsignedBigInteger('target_id')->nullable();
                $table->text('details')->nullable();
                $table->timestamps();
                
                $table->index(['tenant_id', 'created_at']);
                $table->index(['tenant_id', 'target_type', 'target_id']);
            });
        }

        // Quick replies for chat
        if (!Schema::hasTable('noci_quick_replies')) {
            Schema::create('noci_quick_replies', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('tenant_id')->default(1);
                $table->string('label', 100);
                $table->text('message');
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();
                
                $table->index('tenant_id');
            });
        }

        // Chat settings
        if (!Schema::hasTable('noci_chat_settings')) {
            Schema::create('noci_chat_settings', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('tenant_id')->default(1);
                $table->enum('mode', ['manual_on', 'manual_off', 'scheduled'])->default('manual_on');
                $table->string('wa_number', 30)->nullable();
                $table->text('wa_message')->nullable();
                $table->time('start_hour')->default('08:00:00');
                $table->time('end_hour')->default('17:00:00');
                $table->timestamps();
                
                $table->index('tenant_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('noci_chat_settings');
        Schema::dropIfExists('noci_quick_replies');
        Schema::dropIfExists('noci_activity_logs');
        Schema::dropIfExists('noci_recap_groups');
        Schema::dropIfExists('noci_notif_logs');
        Schema::dropIfExists('noci_wa_outbox');
        Schema::dropIfExists('noci_wa_tenant_gateways');
        Schema::dropIfExists('noci_wa_gateways');
        Schema::dropIfExists('noci_msg_templates');
        Schema::dropIfExists('noci_conf_tg');
        Schema::dropIfExists('noci_conf_wa');
    }
};
