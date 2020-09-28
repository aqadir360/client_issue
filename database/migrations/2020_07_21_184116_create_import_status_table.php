<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateImportStatusTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('import_status', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_schedule_id');
            $table->text('error_message')->nullable();
            $table->integer('files_processed')->nullable();
            $table->integer('compare_date')->default(0);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('completed_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('import_status');
    }
}
