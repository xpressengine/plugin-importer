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

use Illuminate\Hashing\BcryptHasher;
use Xpressengine\Plugins\Importer\Models\UserPassword;

/**
 * @category    Importer
 * @package     Xpressengine\Plugins\Importer
 * @author      XE Team (developers) <developers@xpressengine.com>
 * @copyright   2015 Copyright (C) NAVER <http://www.navercorp.com>
 * @license     http://www.gnu.org/licenses/lgpl-3.0-standalone.html LGPL
 * @link        http://www.xpressengine.com
 */
class PasswordManager
{
    public function add($user, $origin_id, $password, $hash_function, $meta)
    {
        if (is_string($user)) {
            $user_id = $user;
        } else {
            $user_id = $user->id;
        }

        $userPassword = UserPassword::findOrNew($origin_id);
        $userPassword->fill(compact('origin_id', 'user_id', 'password', 'hash_function', 'meta'));
        $userPassword->save();
    }

    public function get($origin_id)
    {
        $imported = UserPassword::find($origin_id);
        return $imported;
    }

    public function getByUserId($user_id)
    {
        $imported = UserPassword::where('user_id', $user_id)->first();
        return $imported;
    }

    public function attempt($imported, $password)
    {
        $functionList = [
            'md5' => function ($password, $imported) {
                return md5($password) === base64_decode($imported->password) || md5(sha1(md5($password))) === base64_decode($imported->password);
            },
            'bcrypt' => function ($password, $imported) {
                /** @var BcryptHasher $hasher */
                $hasher = app('hash');
                return $hasher->check($password, base64_decode($imported->password));
            },
            'pbkdf2' => function ($password, $imported) {
                $meta = (object) $imported->meta;
                return base64_decode($imported->password) === hash_pbkdf2(
                    $meta->algorithm,
                    $password,
                    $meta->salt,
                    $meta->iterations,
                    $meta->length,
                    true
                );
            },
            'mysql_password' => function ($password, $imported) {
                return base64_decode($imported->password) === '*'.strtoupper(hash('sha1', pack('H*', hash('sha1', $password))));
            },
            'mysql_old_password' => function ($password, $imported) {
                $len = strlen($password);
                $add = 7;
                $nr = 1345345333;
                $nr2 = 0x12345671;
                $tmp = 0;
                foreach (str_split($password) as $chr) {
                    if ($chr == " " or $chr == "\t") {
                        continue;
                    }
                    $tmp = ord($chr);
                    $nr ^= ((($nr & 0x3f)+$add)*$tmp) + ($nr << 8);
                    $nr2 += ($nr2 << 8) ^ $nr;
                    $nr2 &= 0xffffffff; // We need to limit this to 32-bit
                    $add += $tmp;
                }
                // Strip sign bit
                $nr &= 0x7fffffff;
                $nr2 &= 0x7fffffff;

                return base64_decode($imported->password) === sprintf("%08lx%08lx",$nr,$nr2);
            },
        ];

        $hash = array_get($functionList, $imported->hash_function);
        if ($hash !== null && $hash($password, $imported)) {
            return true;
        } else {
            return false;
        }
    }

    public function remove($imported)
    {
        $imported->delete();
    }
}
