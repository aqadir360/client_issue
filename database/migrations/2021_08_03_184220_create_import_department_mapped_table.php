<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateImportDepartmentMappedTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('import_department_mapped', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_result_id');
            $table->text('department');
            $table->text('category');
            $table->text('department_rule');
            $table->text('category_rule');
            $table->char('department_id', 36)->nullable();
            $table->integer('skip');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('import_department_mapped');
    }
}
