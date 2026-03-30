<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_active_statuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->enum('status', ['active', 'inactive']);
            $table->date('effective_from');
            $table->date('effective_until')->nullable(); // null = ongoing
            $table->string('remarks', 500)->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'effective_from']);
        });

        // Seed: create an initial "active" record for every existing active employee
        // and "inactive" for inactive ones, effective from their created_at date
        $employees = DB::table('employees')->select('id', 'status', 'created_at')->get();
        foreach ($employees as $emp) {
            DB::table('employee_active_statuses')->insert([
                'employee_id'    => $emp->id,
                'status'         => $emp->status ?? 'active',
                'effective_from' => date('Y-m-d', strtotime($emp->created_at)),
                'effective_until' => null,
                'remarks'        => 'Migrated from legacy status field',
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_active_statuses');
    }
};
