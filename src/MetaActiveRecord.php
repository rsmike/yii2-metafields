<?php

namespace rsmike\metafields;

use yii\db\ActiveRecord;
use yii\db\Exception;

/**
 * Class MetaActiveRecord
 * Adds `meta` table connection to ActiveRecord model.
 *
 * @property Meta[] $meta
 */
class MetaActiveRecord extends ActiveRecord
{
    /**
     * Owner model PK - first part of meta table key
     * (`meta.owner_id` == $model->id)
     * NB: owner tableName() is the second part of meta table key
     * (`meta.owner` = $model::tableName())
     * @return string
     */
    public static function meta_id_field() { return 'id'; }

    /**
     * Universal prefix for accessing metafields
     * (e.g. "meta_" in $model->meta_field_name)
     * Omitted in DB records
     * @return string
     */
    public static function meta_prefix() { return 'meta_'; }

    /**
     * stdObject to store model meta data
     * @var object
     */
    private $_meta;

    /**
     * A list of "dirty" meta attributes
     * @var array
     */
    private $_metaModified = [];

    const META_UNSET = 1;
    const META_MODIFIED = 2;
    const META_ADDED = 3;

    /**
     * Auto add relation to the meta table
     * @return \yii\db\ActiveQuery
     */
    public function getMeta() {
        return $this->hasMany(Meta::className(),
            ['owner_id' => static::meta_id_field()])->andWhere([Meta::tableName() . '.owner' => static::tableName()]);
    }

    /**
     * Save data to the meta table after model is saved
     * @param bool $insert
     * @param array $changedAttributes
     */
    public function afterSave($insert, $changedAttributes) {
        $this->saveMeta();
        parent::afterSave($insert, $changedAttributes);
    }

    /**
     * Delete data from the meta table after model is deleted
     */
    public function afterDelete() {
        $this->deleteMeta();
        parent::afterDelete();
    }

    /**
     * Returns true if attribute is related to meta (starts with meta prefix)
     * @param string $attr
     * @return bool
     */
    private function isMeta($attr) {
        return preg_match('#^' . static::meta_prefix() . '#', $attr);
    }

    /**
     * Meta cache function. Loads metadata from DB or from private property
     * @return \stdClass metadata object
     * @see $_meta
     */
    private function meta() {
        if (!$this->_meta) {
            // Initialize at first call
            $this->_meta = new \stdClass();
            if (!$this->isNewRecord && $this->{static::meta_id_field()}) {
                foreach ($this->meta as $meta_row) {
                    $this->_meta->{static::meta_prefix() . $meta_row->meta_key} = $meta_row->meta_value;
                }
            }
        }
        return $this->_meta;
    }

    /**
     * Reads meta value or calls parent getter
     * @param string $attr Attribute name
     * @return mixed attribute value
     */
    public function __get($attr) {
        if ($this->isMeta($attr)) {
            return isset($this->meta()->$attr) ? $this->meta()->$attr : null;
        } else {
            return parent::__get($attr);
        }
    }

    /**
     * Isset
     * @param string $attr Attribute name
     * @return bool
     */
    public function __isset($attr) {
        if ($this->isMeta($attr)) {
            return isset($this->meta()->$attr);
        } else {
            return parent::__isset($attr);
        }
    }

    /**
     * Unset
     * @param string $attr Attribute name
     */
    public function __unset($attr) {
        if ($this->isMeta($attr)) {
            if (isset($this->meta()->$attr)) {
                if (isset($this->_metaModified[$attr]) && $this->_metaModified[$attr] == self::META_ADDED) {
                    // unset what's to be added - not to add it at all
                    unset($this->_metaModified[$attr]);
                } else {
                    // unset what was modified or initially existed
                    $this->_metaModified[$attr] = self::META_UNSET;
                }
                unset($this->meta()->$attr);
            }
        } else {
            parent::__unset($attr);
        }
    }

    /**
     * Sets meta value or calls parent setter
     * @param string $attr Attribute name
     * @param string $value Attribute value
     */
    public function __set($attr, $value) {
        if ($this->isMeta($attr)) {
            if ($value == null) {
                // UNSET VALUE
                if (isset($this->meta()->$attr)) {
                    if (isset($this->_metaModified[$attr]) && $this->_metaModified[$attr] == self::META_ADDED) {
                        // unset what's to be added - not to add it at all
                        unset($this->_metaModified[$attr]);
                    } else {
                        // unset what was modified or initially existed
                        $this->_metaModified[$attr] = self::META_UNSET;
                    }
                    unset($this->meta()->$attr);
                }
            } else {
                // SET VALUE
                if (isset($this->meta()->$attr)) {
                    // field exists
                    if (isset($this->_metaModified[$attr]) && ($this->_metaModified[$attr] == self::META_ADDED)) {
                        // updating previously added field; do nothing - stay in add mode
                    } else {
                        // updating field that was deleted, modified before or initially loaded
                        $this->_metaModified[$attr] = self::META_MODIFIED;
                    }
                } else {
                    // non-existing field
                    if (isset($this->_metaModified[$attr]) && ($this->_metaModified[$attr] == self::META_UNSET)) {
                        // field initially existed, but to be deleted. Modify instead
                        $this->_metaModified[$attr] = self::META_MODIFIED;
                    } else {
                        // first access of non-existed field
                        $this->_metaModified[$attr] = self::META_ADDED;
                    }
                }
                $this->meta()->$attr = $value;
            }
        } else {
            parent::__set($attr, $value);
        }
    }

    /**
     * Saves modified meta values in DB
     * @todo: this can be optimised (batch insert, batch delete, batch update)
     */
    private function saveMeta() {
        if (empty($this->_metaModified)) {
            // no dirty attributes, nothing to deal with
            return;
        }

        $owner = static::tableName();
        $owner_id = $this->{static::meta_id_field()};
        $metaBatchInsert = [];

        $mpLen = strlen(static::meta_prefix());

        foreach ($this->_metaModified as $key => $mode) {
            // get key without prefix
            $db_key = substr($key, $mpLen);

            switch ($mode) {
                case self::META_ADDED:
                    $attrs = [
                        'owner' => $owner,
                        'owner_id' => $owner_id,
                        'meta_key' => $db_key,
                        'meta_value' => (string)$this->_meta->$key
                    ];
                    // the code below is very slow
                    $um = new Meta($attrs);
                    if ($um->validate()) {
                        $metaBatchInsert[] = $attrs;
                    } else {
                        throw new Exception('Failed to save invalid meta key: ' . $db_key . ' ' . json_encode($um->getFirstErrors(),
                                JSON_PRETTY_PRINT));
                    }
//                    $um->insert();
                    break;
                case self::META_MODIFIED:
                    Meta::updateAll(
                        [
                            'meta_value' => (string)$this->_meta->$key
                        ],
                        [
                            'owner' => $owner,
                            'owner_id' => $owner_id,
                            'meta_key' => $db_key
                        ]);
                    break;
                case self::META_UNSET:
                    Meta::deleteAll([
                        'owner' => $owner,
                        'owner_id' => $owner_id,
                        'meta_key' => $db_key
                    ]);
                    break;
            }
        }

        if (!empty($metaBatchInsert)) {
            Meta::getDb()->createCommand()->batchInsert(Meta::tableName(),
                ['owner', 'owner_id', 'meta_key', 'meta_value'], $metaBatchInsert)->execute();
        }

        $this->_metaModified = [];
    }

    /**
     * Deletes all meta records from DB (typically, after user record was deleted)
     */
    private function deleteMeta() {
        Meta::deleteAll([
            'owner' => $this->tableName(),
            'owner_id' => $this->{static::meta_id_field()}
        ]);
    }

    /**
     * Get all meta keys
     *
     * @param $prefixed bool to add 'meta_'
     * @return array
     */
    public function metaKeys($prefixed = true) {
        $keys = array_keys((array)$this->meta());
        if (!$prefixed) {
            /** @noinspection PhpUnusedParameterInspection */
            array_walk($keys, function (&$value, $key, $prefix) {
                $value = substr($value, strlen($prefix));
            }, static::meta_prefix());
        }

        return $keys;
    }

    /**
     * Get all attributes including metas
     *
     * @param $prefixed bool to add 'meta_'
     * @return array
     */
    public function attributesWithMeta($prefixed = true) {
        return array_merge($this->attributes(), $this->metaKeys($prefixed));
    }

    /**
     * @param null $condition
     * @param array $params
     * @return int
     */
    public static function deleteAll($condition = null, $params = []) {
        $ownerCondition = Meta::tableName() . '.owner = \'' . static::tableName() . '\'';

        if ($condition) {
            $metaParams = $params;

            // NB: $metaParams gets filled here even if was empty
            $where = static::getDb()->getQueryBuilder()->buildWhere(['and', $ownerCondition, $condition], $metaParams);

            // deleting metas under left join with owner's condition
            $sql = 'DELETE {{' . Meta::tableName() . '}}.* FROM {{' . Meta::tableName() . '}} INNER JOIN {{' . static::tableName() . '}} ON {{' . Meta::tableName() . '}}.owner_id = {{' . static::tableName() . '}}.' . static::meta_id_field() . ' ' . $where;

            static::getDb()->createCommand($sql, $metaParams)->execute();
        } else {
            // no condition means we delete all records of certain class
            // deleting all metas by owner type
            Meta::deleteAll($ownerCondition);
        }

        return parent::deleteAll($condition, $params);
    }

}
