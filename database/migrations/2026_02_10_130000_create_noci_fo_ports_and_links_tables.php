<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('noci_fo_ports')) {
            Schema::create('noci_fo_ports', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('tenant_id')->index();

                // Port exists at a point (OLT site / ODP / ODC / etc)
                $table->unsignedBigInteger('point_id')->index();

                // Minimal set for tracing: OLT_PON and ODP_OUT
                $table->string('port_type', 30)->index();
                $table->string('port_label', 120);

                // Optional link to existing OLT profile (noci_olts.id) for OLT_PON ports
                $table->unsignedBigInteger('olt_id')->nullable()->index();

                // Where this port connects: cable + core (for end-to-end tracing)
                $table->unsignedBigInteger('cable_id')->nullable()->index();
                $table->unsignedInteger('core_no')->nullable();

                $table->text('notes')->nullable();

                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();

                $table->timestamps();

                $table->unique(['tenant_id', 'point_id', 'port_type', 'port_label'], 'uniq_fo_ports_label');
                $table->index(['tenant_id', 'cable_id', 'core_no'], 'idx_fo_ports_cable_core');
            });
        }

        if (!Schema::hasTable('noci_fo_links')) {
            Schema::create('noci_fo_links', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('tenant_id')->index();

                // Splice/split happens at a point
                $table->unsignedBigInteger('point_id')->index();

                // SPLICE (bidirectional), PATCH (bidirectional), SPLIT (directed 1:N)
                $table->string('link_type', 20)->index();

                $table->unsignedBigInteger('from_cable_id')->index();
                $table->unsignedInteger('from_core_no');
                $table->unsignedBigInteger('to_cable_id')->index();
                $table->unsignedInteger('to_core_no');

                // Group multiple outputs that belong to the same splitter input (for UI grouping).
                $table->string('split_group', 64)->nullable()->index();

                $table->decimal('loss_db', 6, 2)->nullable();
                $table->text('notes')->nullable();

                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();

                $table->timestamps();

                $table->index(['tenant_id', 'point_id', 'from_cable_id', 'from_core_no'], 'idx_fo_links_from');
                $table->index(['tenant_id', 'point_id', 'to_cable_id', 'to_core_no'], 'idx_fo_links_to');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('noci_fo_links');
        Schema::dropIfExists('noci_fo_ports');
    }
};

