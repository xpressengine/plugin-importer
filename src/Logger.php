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

namespace Xpressengine\Plugins\Importer;

use Carbon\Carbon;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Xpressengine\Plugins\Importer\Importers\AbstractImporter;

/**
 * importer 실행 도중 언제든 사용된다. 정상적으로 import되지 않을 경우 실행되어 로깅한다.
 *
 * @category    Importer
 * @package     Xpressengine\Plugins\Importer
 * @author      XE Team (developers) <developers@xpressengine.com>
 * @copyright   2015 Copyright (C) NAVER <http://www.navercorp.com>
 * @license     http://www.gnu.org/licenses/lgpl-3.0-standalone.html LGPL
 * @link        http://www.xpressengine.com
 */
class Logger
{
    /**
     * @var string
     */
    private $path;

    /**
     * Logger constructor.
     *
     * @param string $path path of log file
     */
    public function __construct($path)
    {
        $this->path = $path;
    }

    public function getPath()
    {
        return $this->path;
    }

    public function init()
    {
        // @unlink($this->path);
    }

    public function log($message)
    {
        $now = Carbon::now();
        file_put_contents($this->path, "{$now->format('Y.m.d H:i:s')}: $message".PHP_EOL, FILE_APPEND);
    }

}
