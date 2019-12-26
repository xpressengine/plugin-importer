<?php
namespace Xpressengine\Plugins\Importer;
ini_set('memory_limit', '384M');

use Illuminate\Console\Application as Artisan;
use Illuminate\Database\Schema\Blueprint;
use Route;
use Schema;
use Xpressengine\Plugin\AbstractPlugin;
use Xpressengine\Plugins\Importer\Commands\ImportCommand;
use Xpressengine\Plugins\Importer\Commands\RollbackCommand;
use Xpressengine\Plugins\Importer\Importers\AbstractImporter;
use Xpressengine\Plugins\Importer\Importers\DocumentImporter;
use Xpressengine\Plugins\Importer\Importers\Modules\AbstractModuleImporter;
use Xpressengine\Plugins\Importer\Importers\Modules\BoardImporter;
use Xpressengine\Plugins\Importer\Importers\UserImporter;
use Xpressengine\Plugins\Importer\Migrations\Migration;

class Plugin extends AbstractPlugin
{
    public function register()
    {
        app()->singleton(
            DocumentImporter::class,
            function ($app) {
                $board = new BoardImporter();
                return new DocumentImporter(['board'=>$board]);
            }
        );
        app()->alias(DocumentImporter::class, 'importer::document');

        app()->singleton(
            PasswordManager::class,
            function ($app) {
                return new PasswordManager();
            }
        );
        app()->alias(PasswordManager::class, 'importer::password');

        app()->singleton(
            UserImporter::class,
            function ($app) {
                return new UserImporter(app('xe.user'), app('xe.user.image'), new PasswordManager());
            }
        );
        app()->alias(UserImporter::class, 'importer::user');

        app()->singleton(
            Handler::class,
            function ($app) {
                $logger = new Logger(storage_path('logs/importer.log'));
                $syncManager = new Synchronizer();
                return new Handler(
                    $syncManager,
                    $logger,
                    [
                        'user' => app('importer::user'),
                        'document' => app('importer::document'),
                    ]
                );
            }
        );
        app()->alias(Handler::class, 'importer::handler');

        app()->singleton(
            'importer::command.import',
            function ($app) {
                return new ImportCommand(app('importer::handler'));
            }
        );
        app()->singleton(
            'importer::command.rollback',
            function ($app) {
                return new RollbackCommand(app('importer::handler'));
            }
        );
        $commands = ['importer::command.import', 'importer::command.rollback'];

        Artisan::starting(function ($artisan) use ($commands) {
            $artisan->resolveCommands($commands);
        });
    }

    /**
     * 이 메소드는 활성화(activate) 된 플러그인이 부트될 때 항상 실행됩니다.
     *
     * @return void
     */
    public function boot()
    {
        AbstractImporter::setHandler(app('importer::handler'));
        AbstractModuleImporter::setDocumentImporter(app('importer::document'));

        $this->registerSettingsMenu();

        $this->registerEvents();

        $this->route();
    }

    protected function route()
    {
        // implement code

        Route::settings(
            $this->getId(),
            function () {
                Route::get(
                    '/',
                    [
                        'as' => 'importer::index',
                        'uses' => '\Xpressengine\Plugins\Importer\Controllers\SettingsController@index',
                        'settings_menu' => 'setting.importer'
                    ]
                );

                Route::post(
                    '/',
                    [
                        'as' => 'importer::import',
                        'uses' => '\Xpressengine\Plugins\Importer\Controllers\SettingsController@doImport'
                    ]
                );

                Route::post(
                    '/operation/delete',
                    [
                        'as' => 'importer::operation.delete',
                        'uses' => '\Xpressengine\Plugins\Importer\Controllers\SettingsController@deleteOperation'
                    ]
                );
            }
        );
    }

    /**
     * 플러그인이 활성화될 때 실행할 코드를 여기에 작성한다.
     *
     * @param string|null $installedVersion 현재 XpressEngine에 설치된 플러그인의 버전정보
     *
     * @return void
     */
    public function activate($installedVersion = null)
    {
        // implement code
    }

    /**
     * 플러그인을 설치한다. 플러그인이 설치될 때 실행할 코드를 여기에 작성한다
     *
     * @return void
     */
    public function install()
    {
        if (!Schema::hasTable('importer_sync')) {
            Schema::create(
                'importer_sync',
                function (Blueprint $table) {
                    $table->engine = "InnoDB";

                    $table->string('origin_id', 100);
                    $table->string('new_id', 36);
                    $table->text('data');
                    $table->timestamp('created_at')->index();
                    $table->timestamp('updated_at')->index();
                    $table->integer('revision')->default(1);

                    $table->primary('origin_id');
                }
            );
        }
        if (!Schema::hasTable('importer_user_password')) {
            Schema::create(
                'importer_user_password',
                function (/*Blueprint*/ $table) {
                    $table->engine = "InnoDB";

                    $table->string('origin_id', 100);
                    $table->string('user_id', 36);
                    $table->string('hash_function', 50);
                    $table->text('meta');
                    $table->string('password', 500);
                    $table->timestamp('created_at')->index();
                    $table->timestamp('updated_at')->index();

                    $table->primary('origin_id');
                    $table->unique('user_id');
                }
            );
        }
    }


    /**
     * 해당 플러그인이 설치된 상태라면 true, 설치되어있지 않다면 false를 반환한다.
     * 이 메소드를 구현하지 않았다면 기본적으로 설치된 상태(true)를 반환한다.
     *
     * @return boolean 플러그인의 설치 유무
     */
    public function checkInstalled()
    {
        return Schema::hasTable('importer_sync');
    }

    /**
     * 플러그인을 업데이트한다.
     *
     * @return void
     */
    public function update()
    {
        (new Migration())->up();
    }

    /**
     * 해당 플러그인이 최신 상태로 업데이트가 된 상태라면 true, 업데이트가 필요한 상태라면 false를 반환함.
     * 이 메소드를 구현하지 않았다면 기본적으로 최신업데이트 상태임(true)을 반환함.
     *
     * @return boolean 플러그인의 설치 유무,
     */
    public function checkUpdated()
    {
        // implement code

        if (!Schema::hasColumn('importer_sync', 'revision')) {
            return false;
        }

        return parent::checkUpdated();
    }

    protected function registerSettingsMenu()
    {
        app('xe.register')->push(
            'settings/menu',
            'setting.importer',
            [
                'title' => '마이그레이션',
                'description' => '타 CMS의 데이터를 이 사이트로 들여옵니다',
                'display' => true,
                'ordering' => 6000
            ]
        );
    }

    protected function registerEvents()
    {
        // 회원로그인
        intercept('Auth@attempt', 'importer::attempt', function($target, array $credentials = [], $remember = false, $login = true){

            // 로그인 시도
            $result = $target($credentials, $remember, $login);
            $password = array_get($credentials, 'password');

            if ($result === true || $password === null) {
                return $result;
            }

            // 로그인에 실패했을 경우
            $attempted = app('auth')->getLastAttempted();

            if ($attempted === null) {
                return $result;
            }

            // 비밀번호가 틀렸고, 비밀번호가 지정되어 있지 않은 회원일 경우
            if ($attempted !== null && $attempted->password === null) {
                $imported = app('importer::password')->getByUserId($attempted->id);
                $result = app('importer::password')->attempt($imported, $password);

                // 임포트 된 회원 비밀번호와 일치할 경우
                if ($result === true) {
                    // disable password validator
                    $validator = app('validator');
                    $validator->extend(
                        'password',
                        function ($attribute, $value, $parameters) {
                            return true;
                        }
                    );

                    // 비밀번호 갱신
                    app('xe.user')->update($attempted, compact('password'));

                    // sync data 제거
                    /** @var Handler $handler */
                    $handler = app('importer::handler');
                    $handler->getSyncManager()->remove($imported->origin_id);

                    // imported password 제거
                    app('importer::password')->remove($imported);

                    app('auth')->login($attempted, $remember);
                    return true;
                }
            }
            return false;
        });
    }
}
