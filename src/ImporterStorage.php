<?php

namespace Xpressengine\Plugins\Importer;

use Illuminate\Contracts\Routing\ResponseFactory;
use Xpressengine\Storage\Exceptions\WritingFailException;
use Xpressengine\Storage\FileRepository;
use Xpressengine\Storage\FilesystemHandler;
use Xpressengine\Storage\MimeFilter;
use Xpressengine\Storage\RoundRobinDistributor;
use Xpressengine\Storage\Storage;
use Xpressengine\User\UserInterface;

class ImporterStorage extends Storage
{
    public function __construct()
    {
        $app = app();

        $distributor = new RoundRobinDistributor($app['config']['filesystems'], $app['xe.db']->connection());
        $repo = new FileRepository();
        $files = new FilesystemHandler($app['filesystem']);
        $auth = $app['auth']->guard();
        $keygen = $app['xe.keygen'];
        $tempFiles = $app['xe.storage.temp'];
        $response = $app[ResponseFactory::class];
        $mimeFilter = new MimeFilter($app['config']['filesystems']);

        parent::__construct($repo, $files, $auth, $keygen, $distributor, $tempFiles, $response, $mimeFilter);
    }

    public function create(
        $content,
        $path,
        $clientFileName,
        $disk = null,
        $originId = null,
        UserInterface $user = null,
        $option = []
    ) {
        $id = $this->keygen->generate();
        $path = $this->makePath($id, $path);

        $tempFile = $this->tempFiles->create($content);

        $disk = $disk ?: $this->distributor->allot($tempFile);
        $user = $user ?: $this->auth->user();

        $fileHashName = date('YmdHis') . hash('sha1', $clientFileName);
        $extension = pathinfo($clientFileName, PATHINFO_EXTENSION);
        $fileName = sprintf('%s.%s', $fileHashName, $extension);

        if (!$this->files->store($content, $path . '/' . $fileName, $disk)) {
            throw new WritingFailException;
        }

        $file = $this->repo->create([
            'user_id' => $user->getId(),
            'disk' => $disk,
            'path' => $path,
            'filename' => $fileName,
            'clientname' => $clientFileName,
            'mime' => $tempFile->getMimeType(),
            'size' => $tempFile->getSize(),
            'origin_id' => $originId,
        ], $id);

        $tempFile->destroy();

        return $file;
    }

    private function makePath($id, $path)
    {
        $dividePath = implode('/', [substr($id, 0, 2), substr($id, 2, 2)]);

        $path = trim($path, '/');
        if (empty($path) !== true) {
            $path = $path . '/';
        }
        return $path . $dividePath;
    }
}
