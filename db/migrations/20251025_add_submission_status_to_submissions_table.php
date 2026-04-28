<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema; 

class AddSubmissionStatusToSubmissionsTable extends Migration
{
    public function up()
    {
        Schema::table('submissions', function (Blueprint $table) {
            $table->string('submission_status')->default('pending'); // Add submission status column
        });
    }

    public function down()
    {
        Schema::table('submissions', function (Blueprint $table) {
            $table->dropColumn('submission_status');
        });
    }
}