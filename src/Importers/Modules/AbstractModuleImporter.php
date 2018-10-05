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

namespace Xpressengine\Plugins\Importer\Importers\Modules;

use Xpressengine\Plugins\Importer\Importers\DocumentImporter;
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
abstract class AbstractModuleImporter
{
    /**
     * @var string
     */
    protected static $moduleType = null;

    /**
     * @var DocumentImporter
     */
    protected static $documentImporter = null;

    /**
     * @param DocumentImporter $documentImporter
     */
    public static function setDocumentImporter(DocumentImporter $documentImporter)
    {
        self::$documentImporter = $documentImporter;
    }

    /**
     * createModule
     *
     * @param $menu
     * @param $moduleInfo
     *
     * @return \Xpressengine\Menu\Models\MenuItem
     * @throws \Exception
     */
    abstract public function createModule($menu, $moduleInfo);

    /**
     * createCategory
     *
     * @param array $info
     *
     * @return mixed
     */
    abstract public function createCategory(array $info);

    /**
     * createField
     *
     * @param array $info
     *
     * @return mixed
     */
    abstract public function createField(array $info);

    /**
     * import
     *
     * @param XMLElement $info
     *
     * @return mixed
     */
    abstract public function import(XMLElement $info);

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
        return static::$documentImporter->sync($origin_id, $new_id, $data);
    }


}
