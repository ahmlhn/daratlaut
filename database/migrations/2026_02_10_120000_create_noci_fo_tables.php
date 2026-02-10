<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Splice/joint points (ODP/ODC/closure/pole/etc)
        if (!Schema::hasTable('noci_fo_points')) {
            Schema::create('noci_fo_points', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('tenant_id')->index();

                $table->string('name', 160);
                $table->string('point_type', 60)->nullable(); // e.g. ODP, ODC, JOINT, HANDHOLE

                $table->decimal('latitude', 10, 7);
                $table->decimal('longitude', 10, 7);

                $table->string('address', 190)->nullable();
                $table->text('notes')->nullable();

                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();

                $table->timestamps();
                $table->index(['tenant_id', 'name']);
            });
        }

        // Cable segments / routes between points, with optional drawn path.
        if (!Schema::hasTable('noci_fo_cables')) {
            Schema::create('noci_fo_cables', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('tenant_id')->index();

                $table->string('name', 160);
                $table->string('code', 64)->nullable()->index();
                $table->string('cable_type', 60)->nullable()->index(); // feeder/distribution/drop/etc
                $table->unsignedInteger('core_count')->nullable(); // number of cores/fibers

                $table->string('map_color', 20)->nullable(); // polyline color on map (hex)

                $table->unsignedBigInteger('from_point_id')->nullable()->index();
                $table->unsignedBigInteger('to_point_id')->nullable()->index();

                // JSON array of points: [{lat: -6.2, lng: 106.8}, ...]
                // Stored as TEXT for broad MySQL/MariaDB compatibility (avoid native JSON requirements).
                $table->longText('path')->nullable();

                $table->text('notes')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();

                $table->timestamps();
                $table->index(['tenant_id', 'name']);
            });
        }

        // Break/outage events.
        if (!Schema::hasTable('noci_fo_breaks')) {
            Schema::create('noci_fo_breaks', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('tenant_id')->index();

                $table->unsignedBigInteger('cable_id')->nullable()->index();
                $table->unsignedBigInteger('point_id')->nullable()->index();

                $table->string('status', 20)->default('OPEN')->index(); // OPEN|IN_PROGRESS|FIXED|CANCELLED
                $table->string('severity', 20)->nullable(); // MINOR|MAJOR|CRITICAL

                $table->dateTime('reported_at')->nullable()->index();
                $table->dateTime('fixed_at')->nullable();

                $table->decimal('latitude', 10, 7)->nullable();
                $table->decimal('longitude', 10, 7)->nullable();

                $table->text('description')->nullable();

                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();

                $table->timestamps();
                $table->index(['tenant_id', 'cable_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('noci_fo_breaks');
        Schema::dropIfExists('noci_fo_cables');
        Schema::dropIfExists('noci_fo_points');
    }
};

