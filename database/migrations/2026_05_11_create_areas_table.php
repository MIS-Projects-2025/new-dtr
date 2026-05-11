// database/migrations/xxxx_xx_xx_create_areas_table.php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Master list of areas
        Schema::create('areas', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->unsignedInteger('required_hc')->default(0);
            $table->timestamps();
        });

        // Area ↔ Employee assignments (one area → many employees)
        Schema::create('area_employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('area_id')->constrained('areas')->cascadeOnDelete();
            $table->string('employee_id');   // matches EMPLOYID (string) in your masterlist
            $table->timestamps();

            $table->unique(['area_id', 'employee_id']); // no duplicates
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('area_employees');
        Schema::dropIfExists('areas');
    }
};