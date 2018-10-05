<?php
/**
 *  This file is part of the Xpressengine package.
 *
 * PHP version 5
 *
 * @category
 * @package     Xpressengine\
 * @author      XE Team (developers) <developers@xpressengine.com>
 * @copyright   2015 Copyright (C) NAVER <http://www.navercorp.com>
 * @license     http://www.gnu.org/licenses/lgpl-3.0-standalone.html LGPL
 * @link        http://www.xpressengine.com
 */

namespace Xpressengine\Plugins\Importer\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @category    Importer
 * @package     Xpressengine\Plugins\Importer\Models
 * @author      XE Team (developers) <developers@xpressengine.com>
 * @copyright   2015 Copyright (C) NAVER <http://www.navercorp.com>
 * @license     http://www.gnu.org/licenses/lgpl-3.0-standalone.html LGPL
 * @link        http://www.xpressengine.com
 */
class Sync extends Model
{
    const CREATED_AT = 'created_at';
    const UPDATED_AT = 'updated_at';

    protected $table = 'importer_sync';
    protected $primaryKey = 'origin_id';
    public $timestamps = true;
    protected $keyType = 'string';
    public $incrementing = false;
    protected $casts = [
        'data' => 'array'
    ];

    protected $fillable = [
        'type', 'origin_id', 'new_id', 'data', 'revision'
    ];
}
