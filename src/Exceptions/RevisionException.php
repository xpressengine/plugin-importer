<?php
namespace Xpressengine\Plugins\Importer\Exceptions;

class RevisionException extends \Exception
{
    protected $message = 'already updated';
}
