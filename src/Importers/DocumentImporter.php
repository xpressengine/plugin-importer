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

namespace Xpressengine\Plugins\Importer\Importers;

use Xpressengine\Document\Exceptions\DocumentNotFoundException;
use Xpressengine\Menu\MenuHandler;
use Xpressengine\Plugins\Importer\Exceptions\DuplicateException;
use Xpressengine\Plugins\Importer\Exceptions\RevisionException;
use Xpressengine\Plugins\Importer\Exceptions\AlreadyUpdatedException;
use Xpressengine\Plugins\Importer\Extractor;
use Xpressengine\Plugins\Importer\Importers\Modules\AbstractModuleImporter;
use Xpressengine\Plugins\Importer\Reader;
use Xpressengine\Plugins\Importer\XMLElement;
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
class DocumentImporter extends AbstractImporter
{

    protected static $type = 'document';

    protected $moduleImporters = [];

    protected $cachePath = null;
    protected $total = null;

    /**
     * UserImporter constructor.
     */
    public function __construct($moduleImporters = [])
    {
        $this->moduleImporters = $moduleImporters;
    }

    /**
     * 주어진 xml파일의 데이터를 하나의 아이템씩 자른 캐시파일들을 만든다
     *
     * @param string $xmlFile data file path
     *
     * @return string cache directory
     */
    public function prepare($xmlFile)
    {
        $reader = new Reader();
        $this->cachePath = $path = $reader->init($this->type(), $this->revision(), $xmlFile);

        $extractInfo = [
            ['modules.module', 'modules'],
            ['document_fields.document_field', 'fields'],
            ['document_categories.category', 'categories'],
            ['documents.document', '.'],
        ];
        foreach ($extractInfo as $info) {
            $extractor = new Extractor();
            $extractor->init($info[0], $info[1]);
            $reader->register($extractor);
        }

        try {
            $reader->read();
        } catch (\Exception $e) {
            throw $e;
        }

        return $path;
    }

    /**
     * preprocessing
     *
     * @param string $path cache path
     *
     * @return string message
     */
    public function preprocessing($path)
    {
        // import config data

        // create menu for import
        $menu = $this->createMenu($path);

        // import module data
        $this->importModules($path.'/modules/index', $menu);

        // import category data
        $this->importCategories($path.'/categories/index');

        $this->importFields($path.'/fields/index');

        return 'Modules and Categories data is imported.';
    }

    /**
     * importModules
     *
     * @param string $index index file path
     *
     * @return void
     */
    protected function importModules($index, $menu)
    {
        $this->readIndex(
            $index,
            function ($filename) use ($menu) {
                $moduleInfo = $this->loadXmlFile($filename);
                $this->importModule($moduleInfo, $menu);
            }
        );
    }

    /**
     * importModule
     *
     * @param XMLElement $info xml info
     *
     * @return void
     */
    protected function importModule(XMLElement $info, $menu)
    {
        $module = $info->module_type->decode();
        $id = $info->id->decode();
        $title = $info->title->decode();
        $url = $info->url->decode();
        $created_at = $info->created_at->decode();

        $moduleImporter = $this->getModuleImporter($module);
        $moduleImporter->createModule($menu, compact('module', 'id', 'title', 'url', 'created_at'));
    }

    /**
     * importFields
     *
     * @param $index
     *
     * @return void
     */
    protected function importFields($index)
    {
        $this->readIndex(
            $index,
            function ($filename) {
                $fieldInfo = $this->loadXmlFile($filename);
                $this->importField($fieldInfo);
            }
        );
    }

    /**
     * importField
     *
     * @param XMLElement $info
     *
     * @return void
     * @throws \Exception
     */
    protected function importField(XMLElement $info)
    {
        // $info 정리후, moduleImporter를 찾고, moduleImporter의 createField를 호출
        $id = $info->id->decode();
        $module_id = $info->module_id->decode();
        $name = $info->name->decode();
        $title = $info->title->decode();
        $type = $info->type->decode();
        $required = $info->required->decode();
        $set = $info->set->decode();

        // field를 등록할 module 검색
        $sync = $this->sync()->get($module_id);
        if ($sync) {
            $module = $sync->data['module'];
            $module_id = $sync->new_id;
            $moduleImporter = $this->getModuleImporter($module);
            $moduleImporter->createField(compact('id', 'module_id', 'name', 'type', 'required', 'set', 'title'));
        } else {
            throw new \Exception("module[$module_id] not found");
        }
    }


    /**
     * importCategories
     *
     * @param string $index index file path
     *
     * @return void
     */
    protected function importCategories($index)
    {
        $this->readIndex(
            $index,
            function ($filename) {
                $categoryInfo = $this->loadXmlFile($filename);
                $this->importCategory($categoryInfo);
            }
        );
    }

    /**
     * importCategory
     *
     * @param XMLElement $info xml info
     *
     * @return void
     */
    protected function importCategory(XMLElement $info)
    {
        // info 정리
        $id = $info->id->decode();
        $module_id = $info->module_id->decode();
        $title = $info->title->decode();
        $created_at = $info->created_at->decode();

        // 해당 모듈임포터 찾기
        $moduleSynced = $this->sync()->get($module_id);
        $module = array_get($moduleSynced->data, 'module');
        $module_id = $moduleSynced->new_id;

        // 모듈임포터에게 요청
        $moduleImporter = $this->getModuleImporter($module);
        $categoryItem = $moduleImporter->createCategory(compact('id', 'module_id', 'title', 'created_at'));
    }



    /**
     * import
     *
     * @param array $extracteds a list of cache file that will be imported.
     *
     * @return int the number of imported items
     */
    public function import(array $extracteds)
    {
        $count = 0;
        foreach ($extracteds as $path) {
            \DB::beginTransaction();
            try {
                $count ++;
                $xmlObj = $this->loadXmlFile($path);
                $this->importDocument($xmlObj);
            } catch (AlreadyUpdatedException $e) {
                \DB::rollBack();
                $this->log("import passed  - $path - '{$e->getMessage()}' (Rev : {$this->revision()})");
                static::$handler->countAlreadyUpdated();
                continue;
            } catch (DuplicateException $e) {
                \DB::rollBack();
                $this->log("import passed - $path - '{$e->getMessage()}'");
                static::$handler->countFailed();
                continue;
            } catch (\Exception $e) {
                \DB::rollBack();
                $this->log("import failed - $path - {$e->getMessage()} at {$e->getFile()} {$e->getLine()}");
                static::$handler->countFailed();
                continue;
            }
            \DB::commit();
        }
        return $count;
    }

    /**
     * importDocument
     *
     * @param XMLElement $info user info
     *
     * @return void
     */
    protected function importDocument($info)
    {
        // arrange info
        $id = $info->id->decode();
        $module_id = $info->module_id->decode();
        // check exist
        $existed = $this->sync()->get($id);

        $document = null;
        $updatable = null;

        if ($existed !== null) {
            try {
                $document = app('xe.document')->get($existed->new_id);
                $updatable = $this->updatable($document, [], $existed);
            } catch (DocumentNotFoundException $e) {
                $document = null;
            }

            if ($document !== null && !$updatable) {
                throw new DuplicateException('document already exists!!');
            }
        }

        // get module importer & execute module importer
        $synced = $this->sync()->get($module_id);
        $module = array_get($synced->data, 'module');
        $importer = $this->getModuleImporter($module);

        $importer->import($info);
    }

    function updatable($document, $data, Sync $synced)
    {
        if($document === null) {
            return false;
        }

        if($this->revision() <= $synced->revision) {
            throw new AlreadyUpdatedException();
        }

        return true;
    }

    protected function createMenu($path)
    {
        // 앞으로 import될 모듈(메뉴아이템)들이 등록될 메뉴를 생성
        $handler = static::$handler;
        $syncId = 'menu:import-root';
        $sync = $handler->getSyncManager()->get($syncId);

        /** @var MenuHandler $menuHandler */
        $menuHandler = app('xe.menu');

        // 이미 임포트 된 메뉴가 없으면 생성
        if ($sync === null) {
            // 기본 메뉴 config 설정(theme)
            $defaultMenuTheme = 'theme/xpressengine@blankTheme';

            $menu = $menuHandler->createMenu([
                                                 'title' => 'Imported',
                                                 'description' => 'Imported',
                                                 'site_key' => 'default'
                                             ]);
            $menuHandler->setMenuTheme($menu, $defaultMenuTheme, $defaultMenuTheme);
            app('xe.permission')->register($menu->getKey(), $menuHandler->getDefaultGrant());
            $this->sync($syncId, $menu->id);
            return $menu;
        } else {
            $menuId = $sync->new_id;
            $menu = $menuHandler->menus()->findWith($menuId);
            $this->sync($syncId, $menu->id);
        }
        return $menu;
    }

    /**
     * getModuleImporter
     *
     * @param string $module
     *
     * @return AbstractModuleImporter
     */
    protected function getModuleImporter($module)
    {
        return array_get($this->moduleImporters, $module);
    }

}
