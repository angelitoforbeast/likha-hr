<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 013: a daily rate saved by anyone but the CEO waits for the CEO's approval.
     *
     * The request is a row of employee_rates itself, with a status. Every existing row is
     * approved. The model reads approved rows only unless a query asks for the others, so
     * payroll, the current rate and the overlap checks never see a pending or rejected row.
     *
     * approved_marker is 1 on an approved row and NULL on any other. A unique index treats
     * NULLs as distinct, so the index below still allows one approved rate per employee and
     * start date, while pending and rejected rows may share a start date with it (an edit
     * that keeps the start date, a request asked again after a rejection). It is the same
     * device as pending_marker on attendance_change_requests.
     */
    public function up(): void
    {
        Schema::table('employee_rates', function (Blueprint $table) {
            $table->string('status', 20)->default('approved')->after('remarks');
            $table->unsignedTinyInteger('approved_marker')->nullable()->default(1)->after('status');
            // On a request to change an approved rate: the rate it changes. NULL on a new rate.
            $table->unsignedBigInteger('replaces_rate_id')->nullable()->after('approved_marker');
            $table->foreignId('requested_by')->nullable()->after('replaces_rate_id')->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->after('requested_by')->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable()->after('decided_by');
            $table->string('decision_note', 500)->nullable()->after('decided_at');
            $table->index('status', 'emp_rate_status_index');
        });

        // The new unique index first, so employee_id stays indexed for its foreign key
        // while the old one is dropped.
        Schema::table('employee_rates', function (Blueprint $table) {
            $table->unique(['employee_id', 'effective_date', 'approved_marker'], 'emp_rate_eff_approved_unique');
        });
        Schema::table('employee_rates', function (Blueprint $table) {
            $table->dropUnique('emp_rate_eff_unique');
        });
    }

    public function down(): void
    {
        // Pending and rejected rows never counted: they go, so the old unique index fits again.
        \Illuminate\Support\Facades\DB::table('employee_rates')->where('status', '!=', 'approved')->delete();

        Schema::table('employee_rates', function (Blueprint $table) {
            $table->unique(['employee_id', 'effective_date'], 'emp_rate_eff_unique');
        });
        Schema::table('employee_rates', function (Blueprint $table) {
            $table->dropUnique('emp_rate_eff_approved_unique');
            $table->dropIndex('emp_rate_status_index');
            // SQLite (tests, local) cannot drop a foreign key by name; dropping the columns below removes it.
            if (Schema::getConnection()->getDriverName() !== 'sqlite') {
                $table->dropForeign(['requested_by']);
                $table->dropForeign(['decided_by']);
            }
        });
        Schema::table('employee_rates', function (Blueprint $table) {
            $table->dropColumn(['status', 'approved_marker', 'replaces_rate_id', 'requested_by', 'decided_by', 'decided_at', 'decision_note']);
        });
    }
};
