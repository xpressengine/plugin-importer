<?php

/**
 * Migration.php
 *
 * PHP version 5
 *
 * @category
 * @package
 * @author      XE Developers <developers@xpressengine.com>
 * @copyright   2015 Copyright (C) NAVER Corp. <http://www.navercorp.com>
 * @license     http://www.gnu.org/licenses/old-licenses/lgpl-2.1.html LGPL-2.1
 * @link        https://xpressengine.io
 */

namespace Xpressengine\Plugins\Importer\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Schema;
use XeDB;

class Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if(!Schema::hasColumn('importer_sync', 'revision')) {
            Schema::table('importer_sync', function (Blueprint $table) {
                $table->integer('revision')->default(1);
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if(Schema::hasColumn('importer_sync', 'revision')) {
            Schema::table('importer_sync', function (Blueprint $table) {
                $table->dropColumn('revision');
            });
        }
    }
}
