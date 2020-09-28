<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateImportScheduleTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('import_schedule', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_type_id');
            $table->char('company_id', 36);
            $table->boolean('daily');
            $table->integer('week_day')->nullable();
            $table->integer('month_day')->nullable();
            $table->string('once')->nullable();
            $table->integer('start_hour');
            $table->integer('start_minute');
            $table->timestamp('created_at');
            $table->timestamp('archived_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('import_schedule');
    }
}
