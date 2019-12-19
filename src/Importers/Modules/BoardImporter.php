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

namespace Xpressengine\Plugins\Importer\Importers\Modules;

use Carbon\Carbon;
use XeMedia;
use XeStorage;
use Xpressengine\Category\Models\CategoryItem;
use Xpressengine\Document\Models\Document;
use Xpressengine\Counter\Models\CounterLog;
use Xpressengine\Editor\EditorHandler;
use Xpressengine\Media\Handlers\ImageHandler;
use Xpressengine\Media\Models\Media;
use Xpressengine\Menu\MenuHandler;
use Xpressengine\Permission\Grant;
use Xpressengine\Plugins\Board\Components\Modules\BoardModule;
use Xpressengine\Plugins\Board\Models\Board;
use Xpressengine\Plugins\Board\Models\BoardSlug;
use Xpressengine\Plugins\Importer\Importers\DynamicFieldResolveTrait;
use Xpressengine\Plugins\Importer\ImporterStorage;
use Xpressengine\Plugins\Importer\XMLElement;
use Xpressengine\Storage\FileRepository;
use Xpressengine\User\Models\Guest;
use Xpressengine\User\Models\UnknownUser;
use Xpressengine\Plugins\Comment\Models\Comment;

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
class BoardImporter extends AbstractModuleImporter
{
    use DynamicFieldResolveTrait {
        createField as traitCreateField;
    }

    protected static $moduleType = 'board';

    protected $importerStorage;

    public function __construct()
    {
        $this->importerStorage = new ImporterStorage();
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
    public function createModule($menu, $moduleInfo)
    {
        /** @var MenuHandler $menuHandler */
        $menuHandler = app('xe.menu');

        // 기존에 등록된 모듈인지 검사
        $synced = $this->sync()->get($moduleInfo['id']);
        if ($synced !== null) {
            $existed = $menuHandler->items()->find($synced->new_id);
            if ($existed) {
                $this->sync($synced->origin_id, $synced->new_id, ['module'=>'board']);
                return $existed;
            }
        }

        \DB::beginTransaction();
        try {
             // 메뉴 아이템 생성
            $menuTitle = app('xe.translator')->genUserKey();
            app('xe.translator')->save($menuTitle, 'ko', $moduleInfo['title'], false);

            $inputs = [
                'menu_id' => $menu->id,
                'parent_id' => null,
                'title' => $menuTitle,
                'url' => $moduleInfo['url'],
                'description' => 'imported module',
                'target' => '',
                'type' => 'board@board',
                'ordering' => '1',
                'activated' => '1',
            ];
            $menuTypeInput = [
                'page_title' => $moduleInfo['title'],
                'board_name' => 'Board',
                'site_key' => 'default',
                'revision' => 'false',
                'division' => 'false',
            ];

            if ($synced !== null) {
                $inputs['id'] = $synced->new_id;
            }

            $item = $menuHandler->createItem($menu, $inputs, $menuTypeInput);

            $menuHandler->setMenuItemTheme($item, null, null);
            app('xe.permission')->register($menuHandler->permKeyString($item), new Grant);

            $editorPermkey = app('xe.editor')->getPermKey($item->id);
            app('xe.permission')->register($editorPermkey, new Grant);

            // sync
            $this->sync($moduleInfo['id'], $item->id, ['module'=>'board']);

        } catch (\Exception $e) {
            \DB::rollBack();
            throw $e;
        }
        \DB::commit();
        return $item;
    }

    public function createCategory(array $info)
    {
        $categoryHandler = app('xe.category');

        // 이미 추가되었는지 검사
        $synced = $this->sync()->get($info['id']);
        if ($synced !== null) {
            $existed = $categoryHandler->items()->find($synced->new_id);
            if ($existed) {
                // update the revision
                $this->sync($synced->origin_id, $synced->new_id, ['module' => 'board']);
                return $existed;
            }
        }

        // category (group) 존재여부 체크
        $boardId = $info['module_id'];
        $name = $boardId . '-' . BoardModule::getId();

        // category group이 존재하지 않는다면 생성
        $category = $categoryHandler->cates()->where('name', $name)->first();
        if ($category === null) {
            // category group 생성
            $category = $categoryHandler->createCate(compact('name'));
            // board config setting
            $config = app('xe.board.config')->get($boardId);
            $config->set('category', true);
            $config->set('categoryId', $category->id);
            app('xe.board.instance')->updateConfig($config->getPureAll());
        }

        $origin_id = $info['id'];
        if ($synced !== null) {
            $info['id'] = $synced->new_id;
        } else {
            unset($info['id']);
        }
        // category item 생성
        $item = $this->createCategoryItem($category, $info);

        // sync 등록
        $this->sync($origin_id, $item->id, ['module' => 'board']);

        return $item;
    }

    protected function createCategoryItem($category, $info)
    {
        $parent_id = null;
        if ($origin_id = array_get($info, 'parent_id')) {
            $parent = $this->sync()->get($origin_id);
            if ($parent !== null) {
                $parent_id = $parent->new_id;
            }
        }
        $word = app('xe.translator')->genUserKey();
        app('xe.translator')->save($word, 'ko', $info['title'], false);
        $description = '';

        // create item
        \DB::beginTransaction();

        try {
            /** @var CategoryItem $item */
            $item = app('xe.category')->createItem($category, compact('word', 'parent_id', 'description'));
        } catch (\Exception $e) {
            \DB::rollBack();
            throw $e;
        }
        \DB::commit();

        return $item;
    }

    public function createField(XMLElement $info)
    {
        $handler = app('xe.dynamicField');

        $origin_id = $info->id->decode();

        // check sync
        $synced = $this->sync()->get($origin_id);
        if ($synced !== null) {
            list($group, $id) = explode('.', $synced->new_id);
            $field = $handler->get($group, $id);
            if ($field !== null) {
                $this->sync($synced->origin_id, $synced->new_id);
                return $field;
            }
        }

        $config = app('xe.board.config')->get($info->module_id->decode());
        $group = $config->get('documentGroup');

        $typeInfo = $this->typeInfo($info->type->decode());

        $this->traitCreateField($info, $typeInfo, $group);
    }

    /**
     * import document or comment
     *
     * @param XMLElement $info info
     *
     * @return void
     */
    public function import(XMLElement $info)
    {
        $type = $info->type->decode();

        $data = [];
        $data['id'] = $info->id->decode();
        $data['module_id'] = $info->module_id->decode();
        $data['parent_id'] = $info->parent_id->decode();

        if ($info->target_id) {
            $data['target_id'] = $info->target_id->decode();
        }
        if ($info->locale) {
            $data['locale'] = $info->locale->decode();
        }
        if ($info->content) {
            $data['content'] = $info->content->decode();
            $data['format'] = (string) $info->content->attributes()->format;
        }
        if ($info->read_count) {
            $data['read_count'] = $info->read_count->decode();
        }
        if ($info->assent_count) {
            $data['assent_count'] = $info->assent_count->decode();
        }
        if ($info->dissent_count) {
            $data['dissent_count'] = $info->dissent_count->decode();
        }
        if (isset($info->title)) {
            $data['title'] = $info->title->decode();
        }
        if (isset($info->slug)) {
            $data['slug'] = $info->slug->decode();
        }
        if ($info->user_type) {
            $data['user_type'] = $info->user_type->decode();
        }
        if (isset($info->user_id)) {
            $data['user_id'] = $info->user_id->decode();
        }
        if ($info->name) {
            $data['name'] = $info->name->decode();
        }
        if ($info->email) {
            $data['email'] = $info->email->decode();
        }
        if ($info->certify_key) {
            $data['certify_key'] = $info->certify_key->decode();
            $data['hash_algorithm'] = (string) $info->certify_key->attributes()->hash_algorithm;
            $data['hash_function'] = (string) $info->certify_key->attributes()->hash_function;
        }
        if ($info->created_at) {
            $data['created_at'] = Carbon::createFromFormat(\DateTime::ISO8601, $info->created_at->decode());
        }
        if ($info->updated_at) {
            $data['updated_at'] = Carbon::createFromFormat(\DateTime::ISO8601, $info->updated_at->decode());
        }
        if ($info->published_at) {
            $data['published_at'] = Carbon::createFromFormat(\DateTime::ISO8601, $info->published_at->decode());
        }
        if ($info->deleted_at) {
            $data['deleted_at'] = Carbon::createFromFormat(\DateTime::ISO8601, $info->deleted_at->decode());
        }

        if ($info->ipaddress) {
            $data['ipaddress'] = $info->ipaddress->decode();
        }
        if ($info->allow_comment) {
            $data['allow_comment'] = $info->allow_comment->decode();
        }
        if ($info->use_alarm) {
            $data['use_alarm'] = $info->use_alarm->decode();
        }

        if ($info->status) {
            $data['status'] = $info->status->decode();
        }
        if ($info->approved) {
            $data['approved'] = $info->approved->decode();
        }
        if ($info->published) {
            $data['published'] = $info->published->decode();
        }
        if ($info->display) {
            $data['display'] = $info->display->decode();
        }

        // categories
        $data['categories'] = [];
        if ($info->categories) {
            foreach ($info->categories->category as $category) {
                $data['categories'][] = $category->decode();
            }
        }

        // tags
        $data['tags'] = [];
        if ($info->tags) {
            foreach ($info->tags->tag as $tag) {
                $data['tags'][] = $tag->decode();
            }
        }

        // fields
        $data['fields'] = $info->fields;

        // deal with attaches
        if ($info->attaches) {
            $attaches = [];
            foreach ($info->attaches->attach as $attach) {
                $binary = '';
                foreach ($attach->file->buff as $buff) {
                    $binary .= $buff->decode();
                }
                $fileInfo = [
                    'id' => $attach->id->decode(),
                    'filename' => $attach->filename->decode(),
                    'filesize' => $attach->filesize->decode(),
                    'download_count' => $attach->download_count->decode(),
                    'created_at' => $attach->created_at->decode(),
                    'updated_at' => $attach->updated_at->decode(),
                    'buff' => $binary,
                ];
                $attaches[] = $fileInfo;
            }
            $data['attaches'] = $attaches;
        }

        if ($type === 'document') {
            $this->importDocument($data);
        } else {
            $this->importComment($data);
        }
    }

    protected function importDocument(array $info)
    {
        // instance_id, 반드시 미리 등록돼 있다고 가정
        $module_id = $info['module_id'];
        $module = $this->sync()->get($module_id);
        $instance_id = $module->new_id;

        // get board config
        $config = app('xe.board.config')->get($instance_id);

        // get user
        $user = $this->getUser(array_only($info, ['user_id', 'name', 'email']));

        // default infos
        $args = [
            'instance_id' => $instance_id,
            'title' => array_get($info, 'title'),
            'slug' => array_get($info, 'slug', array_get($info, 'title')),

            'dissent_count' => array_get($info, 'dissent_count', 0),
            'assent_count' => array_get($info, 'assent_count', 0),
            'read_count' => array_get($info, 'read_count', 0),

            'email' => array_get($info, 'email'),
            'writer' => array_get($info, 'name'),
            'ipaddress' => array_get($info, 'ipaddress'),

            'allow_comment' => array_get($info, 'allow_comment', false) ? '1' : '0',

//            'use_alarm' => array_get($info, 'use_alarm', false) ? '1' : '0',
            'use_alarm' => false,

            '_hashTags' => [],

            'created_at' => array_get($info, 'created_at'),
            'updated_at' => array_get($info, 'updated_at', array_get($info, 'created_at')),
            'published_at' => array_get($info, 'published_at', array_get($info, 'created_at')),
            'deleted_at' => array_get($info, 'deleted_at', null),
        ];

        $args['certify_key'] = array_get($info, 'certify_key', '');

        // doc id
        $existed = $this->sync()->get($info['id']);
        if ($existed !== null) {
            $args['id'] = $existed->new_id;
        }

        // slug
        $args['slug'] = BoardSlug::convert($args['title'], $args['slug']);

        // format
        $args['format'] = array_get($info, 'format') === 'html' ? Document::FORMAT_HTML : Document::FORMAT_NONE;

        // categories
        $categories = array_get($info, 'categories', []);
        $category = array_shift($categories);
        $synced = $this->sync()->get($category);
        if ($synced !== null) {
            $args['category_item_id'] = $synced->new_id;
        }

        // flags
        $statusType = [
            'trash' => Document::STATUS_TRASH,
            'temp' => Document::STATUS_TEMP,
            'private' => Document::STATUS_PRIVATE,
            'public' => Document::STATUS_PUBLIC,
            'notice' => Document::STATUS_NOTICE
        ];
        $args['status'] = $statusType[array_get($info, 'status')];

        $approvedType = [
            'rejected' => Document::APPROVED_REJECTED,
            'waiting' => Document::APPROVED_WAITING,
            'approved' => Document::APPROVED_APPROVED,
        ];
        $args['approved'] = $approvedType[array_get($info, 'approved')];

        $publishedType = [
            'rejected' => Document::PUBLISHED_REJECTED,
            'waiting' => Document::PUBLISHED_WAITING,
            'reserved' => Document::PUBLISHED_RESERVED,
            'published' => Document::PUBLISHED_PUBLISHED,
        ];
        $args['published'] = $publishedType[array_get($info, 'published')];

        $displayType = [
            'hidden' => Document::DISPLAY_HIDDEN,
            'secret' => Document::DISPLAY_SECRET,
            'visible' => Document::DISPLAY_VISIBLE,
        ];
        $args['display'] = $displayType[array_get($info, 'display')];

        // parent id
        if ($parent_id = array_get($info, 'parent_id')) {
            $parent = $this->sync()->get($parent_id);
            if ($parent) {
                $parentId = $parent->new_id;
            } else {
                $parentId = app('xe.keygen')->generate();
                $this->sync($parent_id, $parentId);
            }
            $args['parent_id'] = $parentId;
        }

        // attaches
        $files = $this->storeAttaches(array_get($info, 'attaches', []), $info['id'], $user);
        $args['_files'] = array_pluck($files, 'file.id');

        // link attaches to content
        $content = $this->linkAttaches($instance_id, array_get($info, 'content'), $files);
        $args['content'] = $content;

        // execute board handler's add()
        $boardHandler = app('xe.board.handler');
        if($existed !== null) {
            $boardDoc = app('xe.board.service')->getItem($existed->new_id, $user, $config);

            // slug 업데이트
            $slug = $boardDoc->boardSlug;

            // slug가 같지 않으면...
            if($boardDoc->boardSlug->slug !== $args['slug']) {
                // 같거나 'slug-#'이면 유지
                if(str_is($args['slug'] . '*', $boardDoc->boardSlug->slug) && preg_match('/(\-[0-9]+?)$/', $boardDoc->boardSlug->slug)) {
                    $args['slug'] = $boardDoc->boardSlug->slug;
                } else {
                    // slug가 변경되었다면 기존 slug 삭제 (board.handler에서 재생성)
                    $boardDoc->boardSlug->delete();
                    $args['slug'] = BoardSlug::make($args['title'], $args['slug']);
                }
            }

            // 문서 업데이트
            $boardHandler->put($boardDoc, $args, $config);
            app('importer::handler')->countUpdated();

            // read log 삭제
            $counterLogs = counterLog::where('target_id', $existed->new_id)
                ->where('counter_name', 'read')
                ->delete();
        } else {
            // fields
            $fieldData = $this->resolveFields($info['fields']);
            if($fieldData) {
                $args = array_merge($args, $fieldData);
            }

            $boardDoc = $boardHandler->add($args, $user, $config);
        }

        app('xe.tag')->set($boardDoc->getKey(), array_get($info, 'tags'), $instance_id);

        // 조회수 로그
        $counterLog = new CounterLog();
        $counterLog->counter_name = 'read';
        $counterLog->counter_option = '';
        $counterLog->target_id = $boardDoc->id;
        $counterLog->user_id = '';
        $counterLog->point = (int)$args['read_count'];
        $counterLog->ipaddress = '127.0.0.1';
        $counterLog->created_at = $counterLog->freshTimestamp();
        $counterLog->save();

        $data = [];
        if ($args['certify_key'] !== '') {
            $data['hash_algorithm'] = array_get($info, 'hash_algorithm');
            $data['hash_function'] = array_get($info, 'hash_function');
        }
        $data['instance_id'] = $instance_id;
        $data['type'] = 'document';

        $this->sync($info['id'], $boardDoc->id, $data);
    }

    protected function importComment(array $info)
    {
        $handler = app('xe.plugin.comment')->getHandler();

        // instance_id, 반드시 미리 등록돼 있다고 가정
        $module_id = $info['module_id'];
        $module = $this->sync()->get($module_id);
        $instance_id = $module->new_id;
        $instance_id = $handler->getInstanceId($instance_id);


        // target document id, 반드시 미리 등록돼 있다고 가정
        $target_id = $info['target_id'];
        $target = $this->sync()->get($target_id);
        $targetId = $target->new_id;

        /** @var Board $targetDocument */
        $targetDocument = Board::where('id', $targetId)->first();

        // get user
        $user = $this->getUser(array_only($info, ['user_id', 'name', 'email']));

        // default infos
        $args = [
            'instance_id' => $instance_id,
            'target_id' => $targetId,
            'target_type' => Board::class,
            'target_author_id' => $user->getId(),

            'slug' => array_get($info, 'slug', array_get($info, 'title')),

            'dissent_count' => array_get($info, 'dissent_count', 0),
            'assent_count' => array_get($info, 'assent_count', 0),
            'read_count' => array_get($info, 'read_count', 0),

            'email' => array_get($info, 'email'),
            'writer' => array_get($info, 'name'),
            'ipaddress' => array_get($info, 'ipaddress'),

            'allow_comment' => array_get($info, 'allow_comment', false) ? '1' : '0',

//            'use_alarm' => array_get($info, 'use_alarm', false) ? '1' : '0',
            'use_alarm' => false,

            'created_at' => array_get($info, 'created_at'),
            'updated_at' => array_get($info, 'updated_at'),
            'published_at' => array_get($info, 'published_at'),
            'deleted_at' =>  array_get($info, 'deleted_at'),
        ];

        $args['certify_key'] = array_get($info, 'certify_key', '');
        $args['target_author_id'] = array_get($info, 'target_author_id', '');

        // comment id
        $existed = $this->sync()->get($info['id']);
        if ($existed !== null) {
            $args['id'] = $existed->new_id;
        }

        // format
        $args['format'] = array_get($info, 'format') === 'html' ? Document::FORMAT_HTML : Document::FORMAT_NONE;

        // flags
        $statusType = [
            'trash' => Document::STATUS_TRASH,
            'temp' => Document::STATUS_TEMP,
            'private' => Document::STATUS_PRIVATE,
            'public' => Document::STATUS_PUBLIC,
            'notice' => Document::STATUS_NOTICE
        ];
        $args['status'] = $statusType[array_get($info, 'status')];

        $approvedType = [
            'rejected' => Document::APPROVED_REJECTED,
            'waiting' => Document::APPROVED_WAITING,
            'approved' => Document::APPROVED_APPROVED,
        ];
        $args['approved'] = $approvedType[array_get($info, 'approved')];

        $publishedType = [
            'rejected' => Document::PUBLISHED_REJECTED,
            'waiting' => Document::PUBLISHED_WAITING,
            'reserved' => Document::PUBLISHED_RESERVED,
            'published' => Document::PUBLISHED_PUBLISHED,
        ];
        $args['published'] = $publishedType[array_get($info, 'published')];

        $displayType = [
            'hidden' => Document::DISPLAY_HIDDEN,
            'secret' => Document::DISPLAY_SECRET,
            'visible' => Document::DISPLAY_VISIBLE,
        ];
        $args['display'] = $displayType[array_get($info, 'display')];

        // parent id
        if ($parent_id = array_get($info, 'parent_id')) {
            $parent = $this->sync()->get($parent_id);
            if ($parent) {
                $parentId = $parent->new_id;
            } else {
                $parentId = app('xe.keygen')->generate();
                $this->sync($parent_id, $parentId);
            }
            $args['parent_id'] = $parentId;
        }

        // attaches
        $files = $this->storeAttaches(array_get($info, 'attaches', []), $info['id'], $user);

        // link attaches to content
        $content = $this->linkAttaches($instance_id, array_get($info, 'content'), $files);
        $args['content'] = $content;

        if($existed !== null) {
            $config = $handler->getConfig($instance_id);


            if($config->get('secret') === false) {
                $information = [];
                $information['secret'] = true;
                $handler->configure($instance_id, $information);
            }
            $config = $handler->getConfig($instance_id);

            $comment = Comment::find($existed->new_id);
            $comment->setStatus($args['status']);
            $comment->setDisplay($args['display']);
            $handler->put($comment, $args, $config);
            app('importer::handler')->countUpdated();
        } else {
            $comment = $handler->create($args, $user);
        }


        // execute comment handler's create()
        XeStorage::sync($comment->getKey(), array_pluck($files, 'file.id'));

        // deal with tag
        app('xe.tag')->set($comment->getKey(), array_get($info, 'tags'), $instance_id);

        $data = [];
        if ($args['certify_key'] !== '') {
            $data['hash_algorithm'] = array_get($info, 'hash_algorithm');
            $data['hash_function'] = array_get($info, 'hash_function');
        }
        $data['instance_id'] = $module->new_id;
        $data['target_id'] = $module->target_id;
        $data['type'] = 'comment';

        $this->sync($info['id'], $comment->id, $data);
    }

    /**
     * storeAttaches
     *
     * @param array $attaches
     * @param       $user
     *
     * @return array
     */
    protected function storeAttaches(array $attaches, $target_origin_id, $user)
    {
        $files = [];
        if ($attaches) {
            $fileRepo = new FileRepository();

            foreach ($attaches as $attach) {
                // check exist
                $synced = $this->sync()->get($attach['id']);
                if ($synced !== null) {
                    $file = $fileRepo->find($synced->new_id);
                    if ($file !== null) {
                        $this->sync($synced->origin_id, $synced->new_id, ['target_origin_id' => $target_origin_id]);
                        $files[$attach['id']] = ['file' => $file];

                        // 썸네일 재생성
                        $media = null;
                        $thumbnails = null;
                        if (XeMedia::is($file) === true) {
                            $media = XeMedia::make($file);
                            $thumbnails = XeMedia::createThumbnails($media, EditorHandler::THUMBNAIL_TYPE);
                            $files[$attach['id']] = ['file' => $file, 'media' => $media, 'thumbnails' => $thumbnails];
                        }

                        continue;
                    }
                }

                $file = $this->importerStorage->create(
                    $attach['buff'],
                    EditorHandler::FILE_UPLOAD_PATH,
                    $attach['filename'],
                    null,
                    null,
                    $user
                );

                // update file info(download_count, created_at, updated_at)
                $file = $fileRepo->update(
                    $file,
                    [
                        'download_count' => $attach['download_count'],
                        'created_at' => $attach['created_at'],
                        'updated_at' => $attach['updated_at']
                    ]
                );

                $media = null;
                $thumbnails = null;
                if (XeMedia::is($file) === true) {
                    $media = XeMedia::make($file);
                    $thumbnails = XeMedia::createThumbnails($media, EditorHandler::THUMBNAIL_TYPE);
                }
                $files[$attach['id']] = ['file' => $file, 'media' => $media, 'thumbnails' => $thumbnails];
                $this->sync($attach['id'], $file->id, ['target_origin_id' => $target_origin_id]);
            }
        }
        return $files;
    }

    /**
     * linkAttaches
     *
     * @param $content
     * @param array $files
     *
     * @return mixed
     */
    protected function linkAttaches($instance_id, $content, $files)
    {
        $content = preg_replace_callback('/{{(urn:xe:migrate:file:[0-9]+)@(file-id|url|download)}}/s', function($matches) use($instance_id, $files) {

            // {{urn:xe:migrate:file:144@file-id}}
            // {{urn:xe:migrate:file:145@url}}
            // {{urn:xe:migrate:file:145@download}}

            $origin_id = $matches[1];
            $type= $matches[2];

            $info = $files[$origin_id];
            $file = $info['file'];

            switch ($type) {
                case 'file-id':
                return $file->id;
                case 'url': // src
                    // thumbnails가 존재하면 thumbnail url을 반환
                    // if ($thumbnails = array_get($info, 'thumbnails')) {
                    //     return $thumbnails[2]->url();
                    // }

                    // media가 존재하면 media url을 반환
                if ($media = array_get($info, 'media')) {
                    return $media->url();
                }

                if (XeMedia::is($file) === true) {
                    $handler = XeMedia::getHandlerByFile($file);
                    $query = $handler->query();
                    /** @var Media $media */
                    $media = $query->where('id', $file->id)->first();
                        // if ($media->getType() === Media::TYPE_IMAGE) {
                        //     /** @var ImageHandler $handler */
                        //     $thumbnails = $handler->getThumbnails($media);

                        //     if(!$thumbnails || !count($thumbnails)) {
                        //         return '';
                        //     }

                        //     return $thumbnails[2]->url();
                        // }
                    return $media->url();
                } else {
                    return '';
                }
                case 'download':
                return route('editor.file.download', ['instanceId' => $instance_id, 'id' => $file->id]);
            }

            return $matches[0];
        }, $content);


        return $content;
    }

    /**
     * getUser
     *
     * @param $user_id
     *
     * @return \Xpressengine\Plugins\Importer\Models\Sync|\Xpressengine\Plugins\Importer\Models\Sync[]|Guest|UnknownUser
     */
    protected function getUser($userInfo)
    {
        if ($user_id = array_get($userInfo, 'user_id')) {
            $user = $this->sync()->get($user_id);
            if ($user === null) {
                $userId = app('xe.keygen')->generate();
                $user = new UnknownUser(['id' => $userId, 'display_name' => $userInfo['name']]);
            } else {
                $userId = $user->new_id;
                $user = app('xe.user')->find($userId);
                if ($user === null) {
                    $user = new UnknownUser(['id' => $userId, 'display_name' => $userInfo['name']]);
                }
            }
            $this->sync($user_id, $userId, ['UnknownUser' => true, 'email' => $userInfo['email']]);
        } else {
            $user = new Guest();
        }
        return $user;
    }

    private function importerStorageCreate($content, $path, $name, $disk = null, $originId = null, $user = null)
    {
        $fileRepo = new FileRepository();


    }
}
