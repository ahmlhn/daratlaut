<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('noci_public_redirect_links')) {
            Schema::create('noci_public_redirect_links', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('tenant_id')->default(1);
                $table->string('code', 120);
                $table->string('type', 20)->default('whatsapp')->comment('whatsapp/custom');
                $table->string('wa_number', 50)->nullable();
                $table->text('wa_message')->nullable();
                $table->text('target_url')->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedBigInteger('click_count')->default(0);
                $table->unsignedBigInteger('redirect_success_count')->default(0);
                $table->timestamp('last_clicked_at')->nullable();
                $table->timestamp('last_redirect_success_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();

                $table->unique(['tenant_id', 'code'], 'noci_pub_redirect_links_tenant_code_uq');
                $table->index(['tenant_id', 'is_active'], 'noci_pub_redirect_links_tenant_active_idx');
                $table->index(['tenant_id', 'type'], 'noci_pub_redirect_links_tenant_type_idx');
            });
        }

        if (!Schema::hasTable('noci_public_redirect_events')) {
            Schema::create('noci_public_redirect_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('tenant_id')->default(1);
                $table->unsignedBigInteger('redirect_link_id')->nullable();
                $table->string('code', 120);
                $table->string('event_type', 30)->comment('click/redirect_success/redirect_failed');
                $table->text('target_url')->nullable();
                $table->unsignedSmallInteger('http_status')->nullable();
                $table->string('error_message', 255)->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->string('user_agent', 500)->nullable();
                $table->string('referer', 500)->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['tenant_id', 'created_at'], 'noci_pub_redirect_events_tenant_created_idx');
                $table->index(['tenant_id', 'event_type', 'created_at'], 'noci_pub_redirect_events_type_created_idx');
                $table->index(['redirect_link_id', 'created_at'], 'noci_pub_redirect_events_link_created_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('noci_public_redirect_events');
        Schema::dropIfExists('noci_public_redirect_links');
    }
};
