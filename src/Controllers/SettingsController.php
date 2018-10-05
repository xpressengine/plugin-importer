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
namespace Xpressengine\Plugins\Importer\Controllers;

use App\Http\Controllers\Controller;
use Xpressengine\Http\Request;
use Xpressengine\Plugins\Importer\Handler;

/**
 * @category    Importer
 * @package     Xpressengine\Plugins\Importer\Controllers
 * @author      XE Team (developers) <developers@xpressengine.com>
 * @copyright   2015 Copyright (C) NAVER <http://www.navercorp.com>
 * @license     http://www.gnu.org/licenses/lgpl-3.0-standalone.html LGPL
 * @link        http://www.xpressengine.com
 */
class SettingsController extends Controller
{
    public function index(Request $request)
    {
        $operation = [];
        $statusBoard = storage_path("app/plugin/importer/operation.json");
        if (file_exists($statusBoard)) {
            $operation = json_dec(app('files')->get($statusBoard), true);
        }

        if (session('opreation', false) || array_get($operation, 'status', 'ready') === 'ready') {
            return app('xe.presenter')->make('importer::views.index');
        } else {
            $options = [];
            if ($operation['direct']) {
                $options[] = 'direct';
            }
            if ($operation['batch']) {
                $options[] = 'batch';
            }
            return app('xe.presenter')->make('importer::views.show', compact('operation', 'options'));
        }
    }

    public function doImport(Request $request, Handler $handler)
    {
        $path = $request->get('path');
        $options = $request->get('option', []);

        $batch = in_array('batch', $options);
        $direct = in_array('direct', $options);

        $statusBoard = storage_path("app/plugin/importer/operation.json");

        // ready, running, successed, failed
        $content = ['status' => 'ready', 'batch' => $batch, 'path' => $path, 'direct' => $direct];
        app('files')->put($statusBoard, json_format(json_enc($content)));

        app()->terminating(
            function () use ($statusBoard, $request, $handler, $path, $direct, $batch) {
                $artisan = app('Illuminate\Contracts\Console\Kernel');

                $content = json_dec(app('files')->get($statusBoard), true);
                $content['status'] = 'running';
                app('files')->put($statusBoard, json_format(json_enc($content)));

                try {
                    $code = $artisan->call(
                        'importer:import',
                        ['path' => $path, '--direct' => $direct, '--batch' => $batch]
                    );
                } catch (\Exception $e) {
                    throw $e;
                }

                $output = $artisan->output();

                $content['status'] = $code === 0 ? 'successed' : 'failed';
                $content['output'] = $output;
                app('files')->put($statusBoard, json_format(json_enc($content), true, false));

                //if ($batch === false) {
                //    $type = $handler->check($path, $direct);
                //    $cachePath = $handler->prepare($type, $path);
                //    $message = $handler->preprocessing($cachePath);
                //    $handler->import($cachePath);
                //} else {
                //    $fileList = $handler->batch($path);
                //    foreach ($fileList as $file) {
                //        $type = $handler->check($file, $direct);
                //        $cachePath = $handler->prepare($type, $file);
                //        $message = $handler->preprocessing($cachePath);
                //        $handler->import($cachePath);
                //    }
                //}
            }
        );
        return redirect()->back()->with('alert', ['type' => 'success', 'message' => '곧 마이그레이션이 시작됩니다.'])->with('operation', true);
    }

    public function deleteOperation()
    {
        $statusBoard = storage_path("app/plugin/importer/operation.json");
        if (file_exists($statusBoard)) {
            unlink($statusBoard);
        }
        return app('xe.presenter')->makeApi(['']);
    }

}
