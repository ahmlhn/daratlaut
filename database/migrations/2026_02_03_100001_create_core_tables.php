<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Core tables migrated from native PHP system
     */
    public function up(): void
    {
        // POPs (Points of Presence)
        if (!Schema::hasTable('noci_pops')) {
            Schema::create('noci_pops', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('tenant_id')->default(1);
                $table->string('pop_name', 100);
                $table->string('wa_number', 50)->nullable();
                $table->string('group_id', 100)->nullable();
                $table->string('address')->nullable();
                $table->string('coordinates', 100)->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                
                $table->index(['tenant_id', 'pop_name']);
            });
        }

        // Team members (teknisi, sales, etc - not users/login)
        if (!Schema::hasTable('noci_team')) {
            Schema::create('noci_team', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('tenant_id')->default(1);
                $table->string('name', 100);
                $table->string('phone', 30)->nullable();
                $table->string('role', 50)->default('teknisi'); // teknisi, sales, admin
                $table->unsignedBigInteger('pop_id')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                
                $table->index(['tenant_id', 'role']);
                $table->index(['tenant_id', 'is_active']);
            });
        }

        // Users (login accounts)
        if (!Schema::hasTable('noci_users')) {
            Schema::create('noci_users', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('tenant_id')->default(1);
                $table->string('username', 50)->unique();
                $table->string('name', 100)->nullable();
                $table->string('email', 100)->nullable();
                $table->string('phone', 30)->nullable();
                $table->string('password', 255);
                $table->enum('role', ['admin', 'teknisi', 'cs', 'owner', 'keuangan', 'svp_lapangan'])->default('teknisi');
                $table->string('default_pop', 100)->nullable();
                $table->enum('status', ['active', 'inactive'])->default('active');
                $table->timestamp('last_login')->nullable();
                $table->rememberToken();
                $table->timestamps();
                
                $table->index(['tenant_id', 'status']);
                $table->index(['tenant_id', 'role']);
            });
        }

        // Installations (pasang baru)
        if (!Schema::hasTable('noci_installations')) {
            Schema::create('noci_installations', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('tenant_id')->default(1);
                $table->string('ticket_id', 10)->nullable();
                $table->string('customer_name', 150);
                $table->string('customer_phone', 50)->nullable();
                $table->text('address')->nullable();
                $table->string('pop', 100)->nullable();
                $table->string('coordinates', 100)->nullable()->comment('Lat,Long');
                $table->string('plan_name', 100)->nullable();
                $table->decimal('price', 12, 0)->default(0);
                $table->date('installation_date')->nullable();
                $table->datetime('finished_at')->nullable();
                $table->enum('status', ['Baru', 'Survey', 'Proses', 'Selesai', 'Batal', 'Pending', 'Req_Batal'])->default('Baru');
                $table->text('notes')->nullable();
                
                // Multiple technicians
                $table->string('technician', 255)->nullable();
                $table->string('technician_2', 100)->nullable();
                $table->string('technician_3', 100)->nullable();
                $table->string('technician_4', 100)->nullable();
                
                // Multiple sales
                $table->string('sales_name', 100)->nullable();
                $table->string('sales_name_2', 100)->nullable();
                $table->string('sales_name_3', 100)->nullable();
                
                $table->boolean('is_priority')->default(false);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                
                $table->index(['tenant_id', 'status']);
                $table->index(['tenant_id', 'installation_date']);
                $table->index(['tenant_id', 'pop']);
            });
        }

        // Installation change history
        if (!Schema::hasTable('noci_installation_changes')) {
            Schema::create('noci_installation_changes', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('tenant_id')->default(1);
                $table->unsignedBigInteger('installation_id');
                $table->string('field_name', 50);
                $table->text('old_value')->nullable();
                $table->text('new_value')->nullable();
                $table->string('changed_by', 100)->nullable();
                $table->string('changed_by_role', 50)->nullable();
                $table->string('source', 30)->nullable();
                $table->timestamps();
                
                $table->index(['tenant_id', 'installation_id']);
                $table->foreign('installation_id')->references('id')->on('noci_installations')->onDelete('cascade');
            });
        }

        // OLTs
        if (!Schema::hasTable('noci_olts')) {
            Schema::create('noci_olts', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('tenant_id')->default(1);
                $table->string('nama_olt', 100);
                $table->string('host', 100)->comment('IP Address');
                $table->unsignedSmallInteger('port')->default(23);
                $table->string('username', 100);
                $table->string('password', 255);
                $table->string('tcont_default', 50)->default('UP-100M');
                $table->unsignedSmallInteger('vlan_default')->default(100);
                $table->text('fsp_cache')->nullable()->comment('JSON cache of FSP list');
                $table->timestamp('fsp_cache_at')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                
                $table->index(['tenant_id', 'is_active']);
            });
        }

        // ONU cache (registered ONUs)
        if (!Schema::hasTable('noci_olt_onu')) {
            Schema::create('noci_olt_onu', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('tenant_id')->default(1);
                $table->unsignedBigInteger('olt_id');
                $table->string('fsp', 20)->comment('Frame/Slot/Port');
                $table->unsignedSmallInteger('onu_id');
                $table->string('serial_number', 50);
                $table->string('onu_name', 100)->nullable();
                $table->string('state', 30)->nullable();
                $table->decimal('rx_power', 8, 2)->nullable();
                $table->timestamps();
                
                $table->index(['tenant_id', 'olt_id', 'fsp']);
                $table->unique(['olt_id', 'fsp', 'onu_id']);
                $table->foreign('olt_id')->references('id')->on('noci_olts')->onDelete('cascade');
            });
        }

        // OLT action logs
        if (!Schema::hasTable('noci_olt_logs')) {
            Schema::create('noci_olt_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('tenant_id')->default(1);
                $table->unsignedBigInteger('olt_id');
                $table->string('action', 50);
                $table->text('command')->nullable();
                $table->text('response')->nullable();
                $table->string('actor', 100)->nullable();
                $table->boolean('success')->default(true);
                $table->timestamps();
                
                $table->index(['tenant_id', 'olt_id', 'created_at']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('noci_olt_logs');
        Schema::dropIfExists('noci_olt_onu');
        Schema::dropIfExists('noci_olts');
        Schema::dropIfExists('noci_installation_changes');
        Schema::dropIfExists('noci_installations');
        Schema::dropIfExists('noci_users');
        Schema::dropIfExists('noci_team');
        Schema::dropIfExists('noci_pops');
    }
};
