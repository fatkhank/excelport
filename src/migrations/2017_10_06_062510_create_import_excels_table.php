<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateImportExcelsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('import_excels', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('uuid');
            $table->string('status')->default('UPLOADING');
            $table->string('original_name')->nullable();
            $table->string('tag')->nullable();
            $table->string('format')->default('xlsx');

            $table->integer('total_rows')->nullable();
            $table->integer('validated_rows')->nullable();
            $table->integer('errors_count')->nullable();
            $table->integer('processed_rows')->nullable();

            $table->timestamps();

            $table->integer('user_id')->unsigned();
            $table->foreign('user_id')->references('id')->on('users');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('import_excels');
    }
}
