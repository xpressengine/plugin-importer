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
trait DynamicFieldResolveTrait
{
    protected function resolveFields($fields)
    {
        $fieldDatas = [];
        if ($fields) {
            $fieldHandler = app('xe.dynamicField');
            foreach ($fields->field as $field) {
                $val = $field->value->decode();
                if ($val) {
                    $origin_id = $field->id->decode();
                    $fieldInfo = $this->sync()->get($origin_id);
                    if(!$fieldInfo) continue;

                    list($group, $id) = explode('.', $fieldInfo->new_id);
                    $fieldType = $fieldHandler->get($group, $id);
                    $fieldInfo = $this->typeInfo($fieldType->name());

                    if ($fieldInfo !== null) {
                        $resolver = $fieldInfo['resolve'];
                        $fieldDatas = array_merge($fieldDatas, $resolver($id, $val));
                    }
                }
            }
        }
        return $fieldDatas;
    }

    protected function typeInfo($id = null)
    {
        $types = [
            'Text' => [
                'type' => 'fieldType/xpressengine@Text',
                'skin' => 'fieldType/xpressengine@Text/fieldSkin/xpressengine@TextDefault',
                'resolve' => function ($id, $value) {
                    return [$id.'_text' => $value];
                }
            ],
            // TODO: other fields
        ];
        if ($id !== null) {
            return array_get($types, $id);
        } else {
            return $types;
        }
    }


}
