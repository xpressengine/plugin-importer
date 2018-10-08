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

use Illuminate\Foundation\Bus\DispatchesJobs;
use Xpressengine\Plugins\Importer\Importers\AbstractImporter;

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
class Handler
{
    use DispatchesJobs;

    /**
     * @var AbstractImporter[]
     */
    public $importers = [];

    public $revision = null;

    /**
     * @var Synchronizer
     */
    protected $sync;

    protected $logger;

    protected $failedCount = 0;
    protected $updatedCount = 0;
    protected $alreadyUpdatedCount = 0;

    /**
     * Handler constructor.
     *
     * @param Synchronizer $sync      sync manager
     * @param Logger       $logger    import logger
     * @param array        $importers importer list
     */
    public function __construct(Synchronizer $sync, Logger $logger, array $importers = [])
    {
        $this->sync = $sync;
        $this->logger = $logger;
        $this->importers = $importers;
    }

    /**
     * add Importer
     *
     * @param AbstractImporter $importer importer
     *
     * @return void
     */
    public function addImporter(AbstractImporter $importer)
    {
        $this->importers[$importer->type()] = $importer;
    }

    /**
     * init
     *
     * @return void
     */
    public function init()
    {
        return $this->logger->init();
    }

    public function batch($filename) {

        $fd = Reader::getFilePointer($filename);

        $list = [];
        while (!feof($fd)) {
            $line = trim(fgets($fd, 1024));
            if (strpos(trim($line), 'url=') === 0) {
                $list[] = trim(substr($line, 4), '"');
            }
        }
        fclose($fd);
        return $list;
    }

    /**
     * check xml file
     *
     * @param string $filename xml file path
     *
     * @return bool|null|string
     * @throws \Exception
     */
    public function check($filename, $direct = false)
    {
        $type = null;
        $revision = null;
        if (strncasecmp('http://', $filename, 7) === 0) {
            if (ini_get('allow_url_fopen')) {
                $url_info = parse_url($filename);
                if (!array_has($url_info, 'port')) {
                    $url_info['port'] = 80;
                }
                if (!array_has($url_info, 'path')) {
                    $url_info['path'] = '/';
                }

                $fp = @fsockopen($url_info['host'], $url_info['port']);
                if (!$fp) {
                    throw new \Exception('msg_no_xml_file');
                }
                // If the file name contains Korean, do urlencode(iconv required)
                $path = $url_info['path'];
                if (preg_match('/[\xEA-\xED][\x80-\xFF]{2}/', $path) && function_exists('iconv')) {
                    $path_list = explode('/', $path);
                    $cnt = count($path_list);
                    $filename = $path_list[$cnt - 1];
                    $filename = urlencode(iconv("UTF-8", "EUC-KR", $filename));
                    $path_list[$cnt - 1] = $filename;
                    $path = implode('/', $path_list);
                    $url_info['path'] = $path;
                }

                $method = $direct ? 'HEAD' : 'GET';
                $header = sprintf(
                    "%s %s?%s HTTP/1.0\r\nHost: %s\r\nReferer: %s://%s\r\nConnection: Close\r\n\r\n",
                    $method,
                    $url_info['path'],
                    $url_info['query'],
                    $url_info['host'],
                    $url_info['scheme'],
                    $url_info['host']
                );

                @fwrite($fp, $header);
                $buff = '';

                while (!feof($fp)) {
                    $buff .= $str = fgets($fp, 1024);
                    $pos = strpos($str, 'XE-Migration-Type');
                    if ($pos !== false) {
                        $type = trim(substr($str, 18));
                    }

                    if (strpos($str, 'XE-Migration-Revision') !== false) {
                        $revision = trim(substr($str, 22));
                        break;
                    }
                }

                fclose($fp);

                if (!$type) {
                    throw new \Exception('migration type not found');
                }

                if (!$revision) {
                    throw new \Exception('migration revision not found');
                }
            } else {
                throw new \Exception('allow_url_fopen must be allowed');
            }
        } else {
            $realPath = realpath($filename);
            if (file_exists($realPath) && is_file($realPath)) {
                $fp = fopen($realPath, "r");
                $str = fgets($fp, 100);
                if (strlen($str) > 0) {
                    while (!feof($fp)) {
                        $str = trim(fgets($fp, 1024));
                        $pos = strpos($str, '<type>');
                        if ($pos !== false) {
                            $type = substr($str, 6, /*lenth of </type>*/ -7);
                        }

                        if (strpos($str, '<revision>') !== false) {
                            $revision = substr($str, 10, /*lenth of </revision>*/ -11);
                            break;
                        }
                    }
                    if (!$type) {
                        throw new \Exception('migration type not found');
                    }
                    if (!$revision) {
                        throw new \Exception('migration revision not found');
                    }
                }
                fclose($fp);
            } else {
                throw new \Exception('file not found');
            }
        }
        return compact('type', 'revision');
    }

    /**
     * prepare
     *
     * @param string $type    import type
     * @param string $xmlFile xml file path
     *
     * @return string cache path
     */
    public function prepare($type, $revision, $xmlFile)
    {
        $importer = $this->getImporter($type);
        $importer->setRevision($revision);
        return $importer->prepare($xmlFile);
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
        $type = $this->getImportType($path);
        return $this->getImporter($type)->preprocessing($path);
    }

    /**
     * extract import type from cache directory
     *
     * @param string $path cache directory path
     *
     * @return string
     */
    public function getImportType($path)
    {
        return \File::get($path.'/type');
    }

    /**
     * extract import revision from cache directory
     *
     * @param string $path cache directory path
     *
     * @return string
     */
    public function getImportRevision($path)
    {
        return \File::get($path.'/revision');
    }
    public function setImportRevision($revision)
    {
        $this->revision = $revision;
    }

    /**
     * getImportSize
     *
     * @param string $path get size of data
     *
     * @return int
     */
    public function getImportSize($path)
    {
        $fp = fopen($path.'/index', 'r');
        return (int) trim(fgets($fp, 1024));
    }

    /**
     * import
     *
     * @param string $cachePath cache path
     * @param int    $cur       offset
     * @param int    $limit     limit
     *
     * @return int the number of rest items
     */
    public function import($cachePath, $cur = 0, $limit = null)
    {
        $type = $this->getImportType($cachePath);

        $index_file = $cachePath.'/index';

        // todo get extracteds
        // Open an index file
        $f = fopen($index_file, "r");

        $total = trim(fgets($f, 1024));

        // Pass if already read
        for ($i = 0; $i < $cur; $i++) {
            fgets($f, 1024);
        }

        $importer = $this->getImporter($type);
        // indexfile에서 start부터 limit만큼의 캐시파일 목록을 가져온다.
        $extracteds = [];
        // Read by each line until the condition meets
        $idx = $cur;
        while (1) {
            $file = fgets($f, 1024);
            if (feof($f) || ($limit !== null && $idx >= ($cur + $limit))) {
                break;
            }
            $extracteds[] = trim($file);
            $idx++;
        }

        $count = $importer->import($extracteds);

        fclose($f);

        return $total - ($cur + $count);
    }

    /**
     * getImporter
     *
     * @param string $type import type
     *
     * @return AbstractImporter
     * @throws \Exception
     */
    public function getImporter($type)
    {
        $importer = array_get($this->importers, $type);

        if ($importer === null) {
            throw new \Exception("'{$type}' Importer not found.");
        }

        return $importer;
    }

    /**
     * @return Synchronizer
     */
    public function getSyncManager()
    {
        return $this->sync;
    }

    public function getLogger()
    {
        return $this->logger;
    }

    public function countFailed()
    {
        $this->failedCount++;
    }

    public function getFailedCount()
    {
        return $this->failedCount;
    }

    public function countAlreadyUpdated()
    {
        $this->alreadyUpdatedCount++;
    }

    public function getAlreadyUpdatedCount()
    {
        return $this->alreadyUpdatedCount;
    }

    public function countUpdated()
    {
        $this->updatedCount++;
    }

    public function getUpdatedCount()
    {
        return $this->updatedCount;
    }

    public function resetCount()
    {
        $this->failedCount = 0;
        $this->updatedCount = 0;
        $this->alreadyUpdatedCount = 0;
    }
}
