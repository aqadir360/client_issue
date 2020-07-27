<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateImportDepartmentMappingTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('import_department_mapping', function (Blueprint $table) {
            $table->id();
            $table->char('company_id', 36);
            $table->char('department_id', 36)->nullable();
            $table->string('department');
            $table->string('category');
            $table->integer('skip')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('import_skip_list');
    }
}
