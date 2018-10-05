<?php
namespace Xpressengine\Plugins\Importer\Commands;

use Illuminate\Console\Command;
use Xpressengine\Plugins\Importer\Handler;

/**
 * 임시 커맨드. 배포시 삭제해야 함.
 *
 * @category    Importer
 * @package     Xpressengine\Plugins\Importer\Commands
 * @author      XE Team (developers) <developers@xpressengine.com>
 * @copyright   2015 Copyright (C) NAVER <http://www.navercorp.com>
 * @license     http://www.gnu.org/licenses/lgpl-3.0-standalone.html LGPL
 * @link        http://www.xpressengine.com
 */
class RollbackCommand extends Command
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $signature = 'importer:rollback';

    /**
     * The console command description.
     *h```
     * @var string
     */
    protected $description = 'rollback data';

    /**
     * @var Handler
     */
    protected $handler;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(Handler $handler)
    {
        parent::__construct();
        $this->handler = $handler;
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        \Artisan::call('cache:clear-xe');
        \Artisan::call('cache:clear');

        app('files')->delete(storage_path('app/plugin/importer/operation.json'));

        $sync = $this->handler->getSyncManager();

        // user
        $users = $sync->fetch('urn:xe:migrate:user:');
        $userIds = [];
        $oldIds = [];
        foreach ($users as $info) {
            $user = app('xe.user')->find($info->new_id);
            if ($user !== null) {
                $userIds[] = $user->id;
            }
            $oldIds[] = $info->origin_id;
        }
        app('xe.user')->leave($userIds);
        $sync->remove($oldIds);

        // user fields

        $fields = app('xe.dynamicField')->gets('user');
        foreach ($fields as $field) {
            $config = $field->getConfig();
            app('xe.dynamicField')->drop($config);
        }

        //$groups = $sync->fetch('urn:xe:migrate:user-group:');
        //$oldIds = [];
        //foreach ($groups as $info) {
        //    $group = app('xe.user')->groups()->find($info->new_id);
        //    app('xe.user')->groups()->delete($group);
        //    $oldIds[] = $info->origin_id;
        //}
        //$sync->remove($oldIds);

        // document, comment
        \DB::table('board_slug')->truncate();
        \DB::table('board_data')->truncate();
        \DB::table('comment_target')->truncate();
        \DB::table('category_item')->truncate();
        \DB::table('category_closure')->truncate();

        $query = app('xe.menu')->items()->query(); //where('url', 'board')->first();
        $modules = $query->where('url', 'board')->get();

        foreach ($modules as $module) {

            $itemId = $module->id;
            $item = \XeMenu::items()->find($itemId);

            try {
                \XeMenu::deleteItem($item);

                foreach (\XeStorage::fetchByFileable($item->getKey()) as $file) {
                    \XeStorage::unBind($item->getKey(), $file, true);
                }

                \XeMenu::deleteMenuItemTheme($item);
                app('xe.permission')->destroy(\XeMenu::permKeyString($item), 'default');
            } catch (\Exception $e) {
                // throw $e;
            }
        }

        $this->output->success("rollbacked");
    }
}
