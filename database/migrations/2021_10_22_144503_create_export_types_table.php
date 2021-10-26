<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateExportTypesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('export_types', function (Blueprint $table) {
            $table->id();
            $table->char('company_id', 36);
            $table->text('type');
            $table->text('description');
            $table->text('ftp_disk');
            $table->text('ftp_path');
            $table->timestamp('last_run')->nullable()->default(null);
            $table->timestamp('created_at')->nullable()->default(null);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('export_types');
    }
}
