<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sped_conversions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('direction');
            $table->string('model')->nullable();
            $table->unsignedInteger('input_count')->default(0);
            $table->unsignedInteger('uploaded_count')->default(0);
            $table->unsignedInteger('processed_count')->default(0);
            $table->unsignedInteger('chunk_count')->default(0);
            $table->json('input_names');
            $table->string('output_path')->nullable();
            $table->string('output_name')->nullable();
            $table->unsignedBigInteger('output_size')->nullable();
            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('sheet_count')->default(0);
            $table->string('status')->default('pending');
            $table->text('error_message')->nullable();
            $table->string('workspace_path')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sped_conversions');
    }
};
