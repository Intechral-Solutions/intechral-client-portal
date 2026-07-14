<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_company', function (Blueprint $table) {
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('crm_company_id')->constrained('crm_companies')->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['project_id', 'crm_company_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_company');
    }
};
