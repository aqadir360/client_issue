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
            $table->char('company_id', 36);
            $table->string('filename');

            $table->integer('success')->default(0);
            $table->integer('skipped')->default(0);
            $table->integer('skip_list')->default(0);
            $table->integer('errors')->default(0);
            $table->integer('barcode_errors')->default(0);
            $table->integer('total')->default(0);

            $table->longText('invalid_depts')->nullable();
            $table->longText('invalid_stores')->nullable();
            $table->longText('invalid_barcodes')->nullable();
            $table->timestamps();
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
