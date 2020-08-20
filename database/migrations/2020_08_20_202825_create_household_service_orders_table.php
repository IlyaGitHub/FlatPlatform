<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateHouseholdServiceOrdersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('household_service_orders', function (Blueprint $table) {
            $table->id();
            $table->double('price', 15, 2);
            $table->string('status');
            $table->boolean('employee_confirmation');
            $table->boolean('landlord_confirmation');
            $table->date('date_of_completion');
            $table->foreignId('employee_id')->unsigned();
            $table->foreignId('household_service_id')->unsigned();
            $table->timestamps();

            $table->foreign('employee_id')->references('id')->on('users');
            $table->foreign('household_service_id')->references('id')->on('household_services');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('household_service_orders');
    }
}
