<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateImportTypesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('import_types', function (Blueprint $table) {
            $table->id();
            $table->char('company_id', 36)->nullable(true);
            $table->string('type');
            $table->string('name');
            $table->string('description');
            $table->string('ftp_path');
            $table->timestamp('created_at');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('import_types');
    }
}
