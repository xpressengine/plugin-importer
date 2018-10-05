<?php
/**
 *  This file is part of the Xpressengine package.
 *
 * PHP version 5
 *
 * @category    Importer
 * @package     Xpressengine\Plugins\Importer
 * @author      XE Team (developers) <developers@xpressengine.com>
 * @copyright   2015 Copyright (C) NAVER <http://www.navercorp.com>
 * @license     http://www.gnu.org/licenses/lgpl-3.0-standalone.html LGPL
 * @link        http://www.xpressengine.com
 */

namespace Xpressengine\Plugins\Importer\Importers;

use Closure;
use Xpressengine\Plugins\Importer\Handler;
use Xpressengine\Plugins\Importer\Synchronizer;
use Xpressengine\Plugins\Importer\XMLElement;

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
abstract class AbstractImporter
{
    /**
     * @var string
     */
    protected static $type = null;
    protected static $revision = null;

    /**
     * @var Handler
     */
    protected static $handler;

    /**
     * set handler
     *
     * @param Handler $handler handler
     *
     * @return void
     */
    public static function setHandler(Handler $handler)
    {
        self::$handler = $handler;
    }

    /**
     * type
     *
     * @return string
     */
    public function type()
    {
        return static::$type;
    }

    /**
     * revision
     *
     * @return string
     */
    public function revision()
    {
        return static::$revision;
    }

    public function setRevision($revision)
    {
        static::$revision = $revision;
    }

    /**
     * prepare
     *
     * @param string $xmlFile data file path
     *
     * @return string cache directory
     */
    abstract public function prepare($xmlFile);

    /**
     * preprocessing
     *
     * @param string $path cache path
     *
     * @return string message
     */
    abstract public function preprocessing($path);

    /**
     * import
     *
     * @param array $extracteds a list of cache file that will be imported.
     *
     * @return int the number of imported items
     */
    abstract public function import(array $extracteds);

    /**
     * load xml file to XMLElement object
     *
     * @param string $path xml file path
     *
     * @return XMLElement
     */
    protected function loadXmlFile($path)
    {
        $obj = simplexml_load_file(trim($path), XMLElement::class);
        return $obj;
    }

    /**
     * read Index
     *
     * @param string  $index    index file path
     * @param Closure $callback callback function
     *
     * @return void
     */
    protected function readIndex($index, Closure $callback)
    {
        $fp = fopen($index, "r");
        $count = fgets($fp, 1024);
        if (!$count) {
            return;
        }

        for ($i = 0; $i < $count; $i++) {
            if (feof($fp)) {
                break;
            }
            $filename = fgets($fp, 1024);
            $callback($filename);
        }
        fclose($fp);
    }

    /**
     * add sync data
     *
     * @param string $origin_id origin id
     * @param string $new_id    new id
     * @param array  $data     extra data
     *
     * @return \Xpressengine\Plugins\Importer\Models\Sync|Synchronizer
     */
    public function sync($origin_id = null, $new_id = null, $data = [])
    {
        if ($origin_id === null) {
            return static::$handler->getSyncManager();
        }
        $revision = $this->revision();
        return static::$handler->getSyncManager()->add(compact('origin_id', 'new_id', 'data', 'revision'));
    }

    /**
     * log
     *
     * @param string $message log message
     *
     * @return void
     */
    protected function log($message)
    {
        static::$handler->getLogger()->log($message);
    }
}
