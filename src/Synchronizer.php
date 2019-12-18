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

namespace Xpressengine\Plugins\Importer;

use Xpressengine\Plugins\Importer\Models\Sync;

/**
 * extractor read inputted XML file and make cache files before importing.
 *
 * @category    Importer
 * @package     Xpressengine\Plugins\Importer
 * @author      XE Team (developers) <developers@xpressengine.com>
 * @copyright   2015 Copyright (C) NAVER <http://www.navercorp.com>
 * @license     http://www.gnu.org/licenses/lgpl-3.0-standalone.html LGPL
 * @link        http://www.xpressengine.com
 */
class Synchronizer
{

    /**
     * add sync data
     *
     * @param array $info sync info
     *
     * @return Sync
     */
    public function add($info = [])
    {
        /** @var Sync $sync */
        $sync = Sync::findOrNew($info['origin_id']);
        $sync->fill($info);
        $sync->updated_at = $sync->freshTimestamp();
        $sync->save();
        return $sync;
    }

    /**
     * remove sync data
     *
     * @param string|array $origin origin id
     *
     * @return int the number of deleted sync data
     */
    public function remove($origin)
    {
        if (is_string($origin)) {
            $origin = [$origin];
        }
        return Sync::destroy($origin);
    }

    /**
     * get sync data
     *
     * @param string $origin origin id
     *
     * @return Sync|Sync[]
     */
    public function get($origin)
    {
        if ($origin === null) {
            return null;
        }
        if (is_string($origin)) {
            $sync = Sync::find($origin);
        } else {
            $sync = Sync::findMany($origin);
        }

        return $sync;
    }

    /**
     * fetch
     *
     * @param string $prefix namespace of origin id
     *
     * @return mixed
     */
    public function fetch($prefix)
    {
        return Sync::where('origin_id', 'like', $prefix.'%')->get();
    }

    /**
     * get query
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function getQuery()
    {
        return Sync::query();
    }
}
