<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doctor_schedule_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doctor_id')->constrained()->onDelete('cascade');
            $table->enum('request_type', ['leave', 'temporary_timing', 'unavailable', 'break_change']);
            $table->date('request_date')->comment('The date the request is for');
            $table->time('old_start_time')->nullable()->comment('Current schedule start time');
            $table->time('old_end_time')->nullable()->comment('Current schedule end time');
            $table->time('requested_start_time')->nullable()->comment('Requested start time');
            $table->time('requested_end_time')->nullable()->comment('Requested end time');
            $table->text('reason')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->text('admin_notes')->nullable()->comment('Notes added by admin when approving/rejecting');
            $table->foreignId('approved_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index(['doctor_id', 'status']);
            $table->index(['request_date']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doctor_schedule_requests');
    }
};
