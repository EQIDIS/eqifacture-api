<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cfdi_files', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('download_job_id');
            $table->string('cfdi_uuid', 36);
            $table->enum('resource_type', ['xml', 'pdf', 'cancel_request', 'cancel_voucher']);
            $table->string('storage_path');
            $table->bigInteger('file_size')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();
            
            $table->foreign('download_job_id')->references('id')->on('download_jobs')->onDelete('cascade');
            $table->unique(['download_job_id', 'cfdi_uuid', 'resource_type']);
            $table->index('cfdi_uuid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cfdi_files');
    }
};
