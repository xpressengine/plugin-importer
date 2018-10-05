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

namespace Xpressengine\Plugins\Importer\Importers;

use App\Facades\XeUser;
use Carbon\Carbon;
use Xpressengine\Plugins\Importer\Exceptions\DuplicateException;
use Xpressengine\Plugins\Importer\Exceptions\RevisionException;
use Xpressengine\Plugins\Importer\Exceptions\AlreadyUpdatedException;
use Xpressengine\Plugins\Importer\Extractor as Extractor;
use Xpressengine\Plugins\Importer\PasswordManager;
use Xpressengine\Plugins\Importer\Reader;
use Xpressengine\Plugins\Importer\XMLElement;
use Xpressengine\Plugins\Importer\Models\Sync;
use Xpressengine\User\Rating;
use Xpressengine\User\UserHandler;
use Xpressengine\User\UserImageHandler;

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
class UserImporter extends AbstractImporter
{
    use DynamicFieldResolveTrait;

    protected static $type = 'user';
    protected static $revision = null;

    /**
     * @var array
     */
    protected $groupMap = [];

    /**
     * @var UserHandler
     */
    private $userHandler;

    /**
     * @var UserImageHandler
     */
    private $imageHandler;

    protected $cachePath = null;
    protected $total = null;
    /**
     * @var PasswordManager
     */
    private $password;

    /**
     * UserImporter constructor.
     */
    public function __construct(UserHandler $userHandler, UserImageHandler $imageHandler, PasswordManager $passwordManager)
    {
        $this->userHandler = $userHandler;
        $this->imageHandler = $imageHandler;
        $this->password = $passwordManager;
    }

    /**
     * 주어진 xml파일의 데이터를 하나의 아이템씩 자른 캐시파일들을 만든다
     *
     * @param string $xmlFile data file path
     *
     * @return string cache directory
     */
    public function prepare($xmlFile)
    {
        $reader = new Reader();
        $this->cachePath = $path = $reader->init($this->type(), $this->revision(), $xmlFile);

        $extractInfo = [
            ['groups.group', 'groups'],
            ['user_fields.user_field', 'fields'],
            ['users.user', '.'],
        ];
        foreach ($extractInfo as $info) {
            $extractor = new Extractor();
            $extractor->init($info[0], $info[1]);
            $reader->register($extractor);
        }

        try {
            $reader->read();
        } catch (\Exception $e) {
            throw $e;
        }


        return $path;
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
        // import group data
        $this->importGroups($path.'/groups/index');

        $this->importFields($path.'/fields/index');

        return 'Group data is imported.';
    }

    /**
     * import
     *
     * @param array $extracteds a list of cache file that will be imported.
     *
     * @return int the number of imported items
     */
    public function import(array $extracteds)
    {
        $validator = app('validator');
        $validator->extend(
            'display_name',
            function ($attribute, $value, $parameters) {
                return true;
            }
        );

        $validator->extend(
            'password',
            function ($attribute, $value, $parameters) {
                return true;
            }
        );

        $groups = $this->sync()->fetch('urn:xe:migrate:user-group:');

        foreach ($groups as $sync) {
            $this->groupMap[$sync->origin_id] = $sync;
        }

        $count = 0;
        foreach ($extracteds as $path) {
            \DB::beginTransaction();
            try {
                $count ++;
                $xmlObj = $this->loadXmlFile($path);
                $this->importUser($xmlObj);
            } catch (AlreadyUpdatedException $e) {
                \DB::rollBack();
                $this->log("import passed  - $path - '{$e->getMessage()}' (Rev : {$this->revision()})");
                static::$handler->countAlreadyUpdated();
                continue;
            } catch (DuplicateException $e) {
                \DB::rollBack();
                $this->log("import passed - $path - {$e->getMessage()}");
                static::$handler->countFailed();
                continue;
            } catch (\Exception $e) {
                \DB::rollBack();
                $this->log("import failed - $path - {$e->getMessage()} at {$e->getFile()} {$e->getLine()}");
                static::$handler->countFailed();
                continue;
            }
            \DB::commit();
        }
        return $count;
    }

    /**
     * importGroups
     *
     * @param string $index index file path
     *
     * @return void
     */
    protected function importGroups($index)
    {
        $this->readIndex(
            $index,
            function ($filename) {
                $groupInfo = $this->loadXmlFile($filename);
                $this->importGroup($groupInfo);
            }
        );
    }

    /**
     * importGroup
     *
     * @param XMLElement $info xml info
     *
     * @return void
     */
    protected function importGroup(XMLElement $info)
    {
        $id = $info->id->decode();
        $description = 'origin id: '.$id;
        $name = $info->title->decode();

        $existed = $this->userHandler->groups()->query()->where('name', $name)->first();
        if ($existed !== null) {
            try {
                $this->sync($id, $existed->id, ['name' => $name]);
            } catch (\Exception $e) {
                return;
            }
        } else {
            $group = $this->userHandler->createGroup(compact('name', 'description'));
            $this->sync($id, $group->id, ['name' => $name]);
        }
    }

    protected function importFields($index)
    {
        $this->readIndex(
            $index,
            function ($filename) {
                $fieldInfo = $this->loadXmlFile($filename);
                $this->importField($fieldInfo);
            }
        );
    }

    protected function importField(XMLElement $info)
    {
        $handler = app('xe.dynamicField');

        $origin_id = $info->id->decode();

        // check sync
        $synced = $this->sync()->get($origin_id);
        if ($synced !== null) {
            list($group, $id) = explode('.', $synced->new_id);
            $field = $handler->get($group, $id);
            if ($field !== null) {
                return $field;
            }
        }

        $title = $info->title->decode();

        \DB::beginTransaction();
        try {
            $label = app('xe.translator')->genUserKey();
            app('xe.translator')->save($label, 'ko', $title, false);

            $typeInfo = $this->typeInfo($info->type->decode());

            $inputs = [
                'group' => 'user',
                'id' => $info->name->decode(),
                'typeId' => $typeInfo['type'],
                'skinId' => $typeInfo['skin'],
                'label' => $label,
                'required' => $info->required->decode(),
                'use' => 'true',
                'sortable' => 'true',
                'searchable' => 'true',
            ];

            $register = $handler->getRegisterHandler();
            $configHandler = $handler->getConfigHandler();

            $config = $configHandler->getDefault();
            foreach ($inputs as $name => $value) {
                $config->set($name, $value);
            }

            $handler->setConnection(\XeDB::connection());
            $handler->create($config);

            $id = $config->get('id');
            $this->sync($origin_id, 'user.'.$id);
        } catch (\Exception $e) {
            \DB::rollBack();
            throw $e;
        }
        \DB::commit();
    }

    /**
     * importUser
     *
     * @param XMLElement $info user info
     *
     * @return void
     */
    protected function importUser($info)
    {
        list($email, $emails) = $this->resolveEmails($info);

        $status = $info->status->activated->decode() === 'true' ? XeUser::STATUS_ACTIVATED : XeUser::STATUS_DENIED;
        $userData = [
            'display_name' => $info->display_name->decode(),
            'email' => $email,
            'rating' => Rating::MEMBER,
            'status' => $status,
            'introduction' => $info->introduction->decode(),
            'created_at' => Carbon::createFromFormat(\DateTime::ISO8601, $info->created_at->decode()),
            'updated_at' => Carbon::now(),
            'login_at' => Carbon::createFromFormat(\DateTime::ISO8601, $info->login_at->decode()),
            'password_updated_at' => Carbon::createFromFormat(\DateTime::ISO8601, $info->password_updated_at->decode())
        ];

        $origin_id = $info->id->decode();
        $synced = $this->sync()->get($origin_id);

        $updatable = false;
        $user = null;

        // 이미 등록된 회원이 있는지 검사
        if ($synced !== null) {
            // sync된 id 적용
            $userData['id'] = $synced->new_id;

            // 업데이트를 실행할 수 없고 실제 등록된 user가 있다면 중복 처리
            $user = $this->userHandler->find($synced->new_id);
            $updatable = $this->updatable($user, $userData, $synced);
            if ($user !== null && !$updatable) {
                $this->sync($origin_id, $synced->new_id);
                throw new DuplicateException();
            }
        }

        // resolve dynamic field data
        $fieldData = $this->resolveFields($info->fields);
        $userData = array_merge($userData, $fieldData);

        if($updatable) {
            $user = $this->userHandler->find($synced->new_id);
            static::$handler->countUpdated();
        } else {
            // 회원 생성
            if(app('xe.users')->where(['display_name' => $userData['display_name']])->first() !== null) {
                $userData['display_name'] = implode([$userData['display_name'], str_random(5)], '-');
            }
            $user = $this->userHandler->create($userData);
        }

        $password = (string) $info->password;

        // password 등록
        $meta = [];
        foreach ($info->password->attributes() as $key => $attr) {
            $meta[$key] = (string) $attr;
        }
        unset($meta['hash_function']);
        $this->password->add($user, $origin_id, $password, $info->password->attributes()->hash_function, $meta);

        // primary가 아닌 email 처리
        foreach ($emails as $address => $verified) {
            $created_at = $updated_at = $info->created_at->decode();
            $this->userHandler->createEmail($user, compact('address', 'created_at', 'updated_at'), $verified);
        }

        $updateData = [];

        if($updatable) {
            $updateData = array_merge($updateData, $userData);
        }

        // profile image
        $updateData['profile_img_file'] = $this->resolveProfileImage($info);

        // attach group
        $updateData['group_id'] = $this->resolveGroups($info);

        $this->userHandler->update($user, $updateData);
        $this->sync($origin_id, $user->id);
    }

    function updatable($user, $data, Sync $synced)
    {
        if($user === null) {
            return false;
        }

        if (Rating::SUPER === $user->rating) {
            throw new RevisionException('Super User do not updatable');
        }

        if($this->revision() <= $synced->revision) {
            throw new AlreadyUpdatedException();
            return false;
        }

        return true;
    }

    protected function resolveEmails($info)
    {
        $emailInfos = $info->emails;

        // add emails, primary email만 처리
        $email = null;
        $emails = [];
        foreach ($emailInfos->email as $mail) {
            $address = $mail->decode();
            $primary = ((string) $mail->attributes()->primary) === 'true';
            $verified = ((string) $mail->attributes()->verified) === 'true';
            if ($primary) {
                $email = $address;
            } else {
                $emails[$address] = $verified;
            }
        }
        return array($email, $emails);
    }

    protected function resolveProfileImage($info)
    {
        if ($info->profile_image) {
            $image = '';
            foreach ($info->profile_image->file->buff as $buff) {
                $image .= $buff->decode();
            }
            return $image;
        }
        return null;
    }

    protected function resolveGroups($info)
    {
        $groupIds = [];
        if ($info->groups) {
            foreach ($info->groups->group as $group) {
                $gid = $group->decode();
                if (array_has($this->groupMap, $gid)) {
                    $map = $this->groupMap[$gid];
                    $groupIds[] = $map->new_id;
                }
            }
        }
        return $groupIds;
    }

}
