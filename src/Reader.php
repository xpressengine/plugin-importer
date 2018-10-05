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

use File;

/**
 * 지정된 xml 파일을 읽는다. xml 파일을 한 줄씩 읽을 때마다, 등록된 extractor를 호출한다.
 *
 * @category    Importer
 * @package     Xpressengine\Plugins\Importer
 * @author      XE Team (developers) <developers@xpressengine.com>
 * @copyright   2015 Copyright (C) NAVER <http://www.navercorp.com>
 * @license     http://www.gnu.org/licenses/lgpl-3.0-standalone.html LGPL
 * @link        http://www.xpressengine.com
 */
class Reader
{
    /**
     * Temp file's key. made by md5 with filename
     * @var string
     */
    protected $key = '';

    /**
     * File name
     * @var string
     */
    protected $filename = null;

    /**
     * File resource
     * @var resource
     */
    protected $fd = null;

    /**
     * Temp cache file path
     * @var string
     */
    protected $cachePath = null;


    /**
     * @var Extractor[]
     */
    protected $extractors = [];

    /**
     * initialize
     *
     * @param string $filename filename
     *
     * @return string cache path
     */
    public function init($type, $revision, $filename)
    {
        $this->filename = $filename;

        // xml파일의 키 지정
        $this->key = md5($filename);

        // 캐싱파일들을 저장할 디렉토리 지정
        $this->cachePath = storage_path("app/plugin/importer/cache/{$this->key}");

        File::makeDirectory($this->cachePath, 0777, true, true);

        File::put($this->cachePath.'/type', $type);
        File::put($this->cachePath.'/revision', $revision);

        return $this->cachePath;
    }

    public function register(Extractor $extractor)
    {
        $this->extractors[] = $extractor;
    }

    public function read()
    {
        $this->fd = static::getFilePointer($this->filename);

        // extractors 시작!
        foreach ($this->extractors as $extractor) {
            $extractor->begin($this->cachePath);
        }

        // 파일을 한줄씩 읽으면서 extractor들에게 전달
        while (!feof($this->fd)) {
            $line = fgets($this->fd, 1024);
            foreach ($this->extractors as $extractor) {
                if ($extractor->isFinished() === false) {
                    $extractor->read($line);
                }
            }
        }

        // extractors 끝!
        foreach ($this->extractors as $extractor) {
            $extractor->end();
        }

        fclose($this->fd);
    }

    /**
     * getFilePointer
     *
     * @param $filepath
     *
     * @return bool|null|resource
     * @throws \Exception
     */
    public static function getFilePointer($filepath)
    {
        $fd = null;

        // fd(xml 파일 포인터) 지정
        // If local file
        if (strncasecmp('http://', $filepath, 7) !== 0) {
            if (!file_exists($filepath)) {
                throw new \Exception('XML file not found.');
            }
            $fd = fopen($filepath, "r");
            // If remote file
        } else {
            $url_info = parse_url($filepath);
            if (!array_has($url_info, 'port')) {
                $url_info['port'] = 80;
            }
            if (!array_has($url_info, 'path')) {
                $url_info['path'] = '/';
            }

            $fd = @fsockopen($url_info['host'], $url_info['port']);
            if (!$fd) {
                throw new \Exception('XML file not found.');
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

            $header = sprintf(
                "GET %s?%s HTTP/1.0\r\nHost: %s\r\nReferer: %s://%s\r\nConnection: Close\r\n\r\n",
                $url_info['path'],
                $url_info['query'],
                $url_info['host'],
                $url_info['scheme'],
                $url_info['host']
            );

            @fwrite($fd, $header);
            $buff = '';
            while (!feof($fd)) {
                $buff .= $str = fgets($fd, 1024);
                if (!trim($str)) {
                    break;
                }
            }
            if (preg_match('/404 Not Found/i', $buff)) {
                throw new \Exception('XML file not found.');
            }
        }
        return $fd;
    }
}
