<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doctors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('specialty');
            $table->string('qualification')->nullable();
            $table->integer('experience_years')->default(0);
            $table->text('bio')->nullable();
            $table->string('image')->nullable();
            $table->decimal('consultation_fee', 10, 2)->default(0);
            $table->json('available_days')->nullable(); // ['monday', 'tuesday', etc.]
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->boolean('is_available')->default(true);
            $table->boolean('is_verified')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doctors');
    }
};