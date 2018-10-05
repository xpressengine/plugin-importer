<?php
namespace Xpressengine\Plugins\Importer\Exceptions;

class DuplicateException extends \Exception
{
    protected $message = 'already imported';
}
