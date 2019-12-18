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

use App\FieldTypes\Address;
use App\FieldTypes\Category;
use App\FieldTypes\CellPhoneNumber;
use App\FieldTypes\Email;
use App\FieldTypes\Text;
use App\FieldTypes\Textarea;
use App\FieldTypes\Url;
use Xpressengine\Category\CategoryHandler;
use Xpressengine\Plugins\Importer\XMLElement;

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
                    if (!$fieldInfo) {
                        continue;
                    }

                    list($group, $id) = explode('.', $fieldInfo->new_id);
                    $fieldType = $fieldHandler->get($group, $id);
                    $fieldInfo = $this->typeInfo($fieldType->getConfig()->get('typeId'));

                    if ($fieldInfo !== null) {
                        $resolver = $fieldInfo['resolve'];
                        $fieldDatas = array_merge($fieldDatas, $resolver($id, $val));
                    }
                }
            }
        }

        return $fieldDatas;
    }

    protected function createField(XMLElement $info, $typeInfo)
    {
        $handler = app('xe.dynamicField');

        $origin_id = $info->id->decode();
        $title = $info->title->decode();

        \DB::beginTransaction();

        try {
            $label = app('xe.translator')->genUserKey();
            app('xe.translator')->save($label, 'ko', $title, false);

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

            $additionalInputs = $this->getAdditionalInputs($typeInfo['type'], $info);

            $inputs = array_merge($inputs, $additionalInputs);

            $configHandler = $handler->getConfigHandler();
            $config = $configHandler->getDefault();
            foreach ($inputs as $name => $value) {
                $config->set($name, $value);
            }

            $handler->setConnection(\XeDB::connection());
            $handler->create($config);

            $id = $config->get('id');

            $this->sync($origin_id, 'user.' . $id, $additionalInputs);
        } catch (\Exception $e) {
            \DB::rollBack();
            throw $e;
        }

        \DB::commit();
    }

    protected function getAdditionalInputs($fieldType, XMLElement $info)
    {
        switch ($fieldType) {
            case Category::getId():
                /** @var CategoryHandler $categoryHandler */
                $categoryHandler = app('xe.category');

                $categoryData = ['name' => $info->name->decode()];
                $category = $categoryHandler->createCate($categoryData);

                $categoryItemIds = [];
                $categoryItemValues = explode('|@|', $info->set->decode());
                foreach ($categoryItemValues as $value) {
                    $word = app('xe.translator')->genUserKey();
                    app('xe.translator')->save($word, 'ko', $value, false);

                    $description = app('xe.translator')->genUserKey();
                    app('xe.translator')->save($description, 'ko', '', false);

                    $categoryItemAttributes = [
                        'word' => $word,
                        'description' => $description,
                        'type' => 'add'
                    ];

                    $categoryItem = $categoryHandler->createItem($category, $categoryItemAttributes);

                    $categoryItemIds[$value] = $categoryItem->id;
                }

                return [
                    'category_id' => $category->id,
                    'categoryItems' => $categoryItemIds
                ];

            default:
                return [];
        }
    }

    protected function typeInfo($id = null)
    {
        switch ($id) {
            case Text::getId():
                return [
                    'type' => 'fieldType/xpressengine@Text',
                    'skin' => 'fieldType/xpressengine@Text/fieldSkin/xpressengine@TextDefault',
                    'resolve' => function ($id, $value) {
                        return [$id . '_text' => $value];
                    }
                ];
                break;

            case Textarea::getId():
                return [
                    'type' => 'fieldType/xpressengine@Textarea',
                    'skin' => 'fieldType/xpressengine@Textarea/fieldSkin/xpressengine@TextareaDefault',
                    'resolve' => function ($id, $value) {
                        return [$id . '_text' => $value];
                    }
                ];
                break;

            case CellPhoneNumber::getId():
                return [
                    'type' => 'fieldType/xpressengine@CellPhoneNumber',
                    'skin' => 'fieldType/xpressengine@CellPhoneNumber/fieldSkin/xpressengine@CellPhoneNumberDefault',
                    'resolve' => function ($id, $value) {
                        return [$id . '_cell_phone_number' => $value];
                    }
                ];
                break;

            case Url::getId():
                return [
                    'type' => 'fieldType/xpressengine@Url',
                    'skin' => 'fieldType/xpressengine@Url/fieldSkin/xpressengine@UrlDefault',
                    'resolve' => function ($id, $value) {
                        return [$id . '_url' => $value];
                    }
                ];
                break;

            case Address::getId():
                return [
                    'type' => 'fieldType/xpressengine@Address',
                    'skin' => 'fieldType/xpressengine@Address/fieldSkin/xpressengine@default',
                    'resolve' => function ($id, $value) {
                        $address = explode('|@|', $value);

                        return [
                            $id . '_postcode' => $address[0],
                            $id . '_address1' => $address[1],
                            $id . '_address2' => $address[2]
                        ];
                    }
                ];
                break;

            case Email::getId():
                return [
                    'type' => 'fieldType/xpressengine@Email',
                    'skin' => 'fieldType/xpressengine@Email/fieldSkin/xpressengine@EmailDefault',
                    'resolve' => function ($id, $value) {
                        return [$id . '_email' => $value];
                    }
                ];
                break;
        }

        if ($id === Category::getId() || $id === 'radio' || $id === 'select') {
            $typeInfo = [
                'type' => 'fieldType/xpressengine@Category',
                'skin' => 'fieldType/xpressengine@Category/fieldSkin/xpressengine@default',
                'resolve' => function ($id, $value) {
                    $synced = $this->sync()->getQuery()->where('new_id', 'user.' . $id)->first();
                    $categoryItems = $synced->data['categoryItems'];

                    if (isset($categoryItems[$value])) {
                        return [$id . '_item_id' => $categoryItems[$value]];
                    } else {
                        return [];
                    }
                }
            ];

            if ($id === 'radio') {
                $typeInfo['skin'] ='fieldType/xpressengine@Category/fieldSkin/xpressengine@radio';
            }

            return $typeInfo;
        }

        return null;
    }
}
