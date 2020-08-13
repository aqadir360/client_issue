<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateImportResultsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('import_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_status_id');
            $table->string('filename');

            $table->integer('adds')->default(0);
            $table->integer('moves')->default(0);
            $table->integer('discos')->default(0);
            $table->integer('static')->default(0);
            $table->integer('skipped')->default(0);
            $table->integer('metrics')->default(0);
            $table->integer('skip_list')->default(0);
            $table->integer('skip_invalid_depts')->default(0);
            $table->integer('skip_invalid_stores')->default(0);
            $table->integer('skip_invalid_barcodes')->default(0);
            $table->integer('errors')->default(0);
            $table->integer('barcode_errors')->default(0);
            $table->integer('total')->default(0);

            $table->text('output')->nullable();
            $table->longText('invalid_depts')->nullable();
            $table->longText('invalid_stores')->nullable();
            $table->longText('invalid_barcodes')->nullable();

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
        Schema::dropIfExists('import_results');
    }
}
