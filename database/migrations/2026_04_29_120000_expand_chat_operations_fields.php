<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('noci_chat')) {
            Schema::table('noci_chat', function (Blueprint $table) {
                if (!Schema::hasColumn('noci_chat', 'delivery_status')) {
                    $table->string('delivery_status', 20)->default('sent')->after('is_read')->index();
                }
                if (!Schema::hasColumn('noci_chat', 'read_at')) {
                    $table->timestamp('read_at')->nullable()->after('delivery_status');
                }
                if (!Schema::hasColumn('noci_chat', 'file_name')) {
                    $table->string('file_name')->nullable()->after('message');
                }
                if (!Schema::hasColumn('noci_chat', 'file_mime')) {
                    $table->string('file_mime', 120)->nullable()->after('file_name');
                }
                if (!Schema::hasColumn('noci_chat', 'file_size')) {
                    $table->unsignedInteger('file_size')->nullable()->after('file_mime');
                }
            });
        }

        if (Schema::hasTable('noci_customers')) {
            Schema::table('noci_customers', function (Blueprint $table) {
                if (!Schema::hasColumn('noci_customers', 'assigned_user_id')) {
                    $table->unsignedInteger('assigned_user_id')->nullable()->after('status')->index();
                }
                if (!Schema::hasColumn('noci_customers', 'chat_tag')) {
                    $table->string('chat_tag', 40)->nullable()->after('assigned_user_id')->index();
                }
                if (!Schema::hasColumn('noci_customers', 'chat_priority')) {
                    $table->string('chat_priority', 20)->nullable()->after('chat_tag')->index();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('noci_chat')) {
            Schema::table('noci_chat', function (Blueprint $table) {
                foreach (['delivery_status', 'read_at', 'file_name', 'file_mime', 'file_size'] as $column) {
                    if (Schema::hasColumn('noci_chat', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('noci_customers')) {
            Schema::table('noci_customers', function (Blueprint $table) {
                foreach (['assigned_user_id', 'chat_tag', 'chat_priority'] as $column) {
                    if (Schema::hasColumn('noci_customers', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
