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
 * xml 파일에서 지정된 영역만 추출하여 cache 파일을 만든다. Reader에서 xml 파일의 한 라인씩 전달 받고, 지정된 영역에 해당하는 부분이면 작동한다.
 *
 * @category    Importer
 * @package     Xpressengine\Plugins\Importer
 * @author      XE Team (developers) <developers@xpressengine.com>
 * @copyright   2015 Copyright (C) NAVER <http://www.navercorp.com>
 * @license     http://www.gnu.org/licenses/lgpl-3.0-standalone.html LGPL
 * @link        http://www.xpressengine.com
 */
class Extractor
{

    protected $dir = '.';

    /**
     * Temp working directory in cache path
     * @var string
     */
    protected $workingDir = '';

    /**
     * Temp index cache file path
     * @var string
     */
    protected $indexFile = '';

    /**
     * Index file resource
     * @var resource
     */
    protected $index_fd = null;

    /**
     * cache file resource
     * @var resource
     */
    protected $fd = null;

    /**
     * Start tag
     * @var string
     */
    protected $startTag = '';

    /**
     * End tag
     * @var string
     */
    protected $endTag = '';

    /**
     * Item start tag
     * @var string
     */
    protected $itemStartTag = '';

    /**
     * Item end tag
     * @var string
     */
    protected $itemEndTag = '';

    /**
     * Start tag open status
     * ready|processing|processing-item|finished
     * @var string
     */
    protected $status = 'ready';

    /**
     * Buffer
     * @var string
     */
    protected $buff = 0;

    /**
     * File count
     * @var int
     */
    protected $index = 0;


    /**
     * Get arguments for constructor, file name, start tag, end tag, tag name for each item
     *
     * @param string $elementPath tag path
     * @param string $dir         directory that cache file is saved in
     *
     * @return string index file path
     */
    public function init($elementPath, $dir = '.')
    {
        $this->dir = $dir;
        $startTag = $endTag = $itemStartTag = $itemEndTag = null;
        $tags = explode('.', $elementPath, 2);
        $groupTag = array_shift($tags);
        $itemTag = array_shift($tags);

        if ($itemTag === null) {
            $itemTag = $groupTag;
            $groupTag = null;
        }

        if ($groupTag) {
            $startTag = "<$groupTag";
            $endTag = "</$groupTag>";
        }

        if ($itemTag) {
            $itemStartTag = "<$itemTag";
            $itemEndTag = "</$itemTag>";
        }

        $this->startTag = $startTag;
        if ($endTag) {
            $this->endTag = $endTag;
        }
        $this->itemStartTag = $itemStartTag;
        $this->itemEndTag = $itemEndTag;
    }


    /**
     * initialize
     *
     * @param string $filename filename
     *
     * @return string cache path
     */
    public function begin($cachePath)
    {

        // working directory 지정
        $this->workingDir = $cachePath."/".$this->dir;

        // 인덱스 파일 경로 지정
        $this->indexFile = $this->workingDir.'/index';

        // 캐싱 디렉토리 초기화
        if (!is_dir($this->workingDir)) {
            File::makeDirectory($this->workingDir, 0777, true, true);
        }

        // index 파일 초기화
        File::delete($this->indexFile);

        // index 파일 및 포인터 준비
        $this->index_fd = fopen($this->indexFile, "a");
    }

    public function read($str)
    {
        // 완료 후
        if ($this->status === 'finished') {
            return;
        }

        // 시작 전
        if ($this->status === 'ready') {
            // start tag가 나오면 시작
            if ($this->startTag) {
                $pos = strpos($str, $this->startTag);
                if ($pos !== false) {
                    // buff에는 start tag 다음부터 저장된다.
                    $str = substr($this->buff, $pos + strlen($this->startTag));
                    $this->status = 'processing';
                }
            } else {
                $this->status = 'processing';
            }
            if ($this->status === 'ready') {
                return;
            }
        }

        // 진행 중

        if ($this->endTag) {
            $endPos = strpos($str, $this->endTag);
            if ($endPos !== false) {
                $this->status = 'finished';
                fclose($this->index_fd);
                app('files')->prepend($this->indexFile, $this->getTotalCount()."\r\n");
                return;
            }
        }

        // item 시작전
        if ($this->status !== 'processing-item') {
            $startPos = strpos($str, $this->itemStartTag);
            if ($startPos !== false) {
                $this->status = 'processing-item';

                $str = substr($str, $startPos);
                $str = preg_replace("/\>/", ">\r\n", $str, 1);

                // item cache file 준비
                $filename = sprintf('%s/%s.xml', $this->workingDir, $this->index++);
                $this->fd = fopen($filename, 'w');

                // index 기록
                fwrite($this->index_fd, $filename."\r\n");

                // item 기록
                fwrite($this->fd, $this->_addTagCRTail($str));
                return;
            }
        // $this->status === 'processing-item'
        } else {
            // item 끝인지 조사
            $endPos = strpos($str, $this->itemEndTag);
            if ($endPos !== false) {
                // item 끝
                $this->status = 'processing';
                $endPos += strlen($this->itemEndTag);
                $buff = substr($str, 0, $endPos);
                fwrite($this->fd, $this->_addTagCRTail($buff));
                fclose($this->fd);
                $str = substr($str, $endPos);
                $this->read($str);
            } else {
                fwrite($this->fd, $this->_addTagCRTail($str));
            }
        }
    }

    public function end()
    {
        if (!in_array($this->status, ['ready', 'finished'])) {
            @fclose($this->index_fd);
            @fclose($this->fd);
            // TODO: exception!!
            throw new \Exception('oh no '.$this->startTag.' '.$this->status);
        }
    }

    public function isFinished()
    {
        return $this->status === 'finished';
    }

    /**
     * getTotalCount
     *
     * @return int
     */
    public function getTotalCount()
    {
        return $this->index;
    }

    /**
     * _addTagCRTail
     *
     * @param string $str str
     *
     * @return mixed
     */
    protected function _addTagCRTail($str) {
        $str = preg_replace('/<\/([^>]*)></i', "</$1>\r\n<", $str);
        return $str;
    }
}
