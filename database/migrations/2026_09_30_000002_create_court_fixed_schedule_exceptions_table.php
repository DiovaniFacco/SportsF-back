<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('court_fixed_schedule_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('court_fixed_schedule_id')->constrained('court_fixed_schedules')->cascadeOnDelete();
            // Data da ocorrência desmarcada (YYYY-MM-DD)
            $table->date('date');
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->unique(['court_fixed_schedule_id', 'date'], 'fixed_sched_date_unique');
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('court_fixed_schedule_exceptions');
    }
};
