<?php

namespace rsmike\metafields;

use yii\db\ActiveRecord;

/**
 * This is the model class for table "meta".
 *
 * @property integer $owner_id
 * @property string $owner
 * @property string $meta_key
 * @property string $meta_value
 *
 */
class Meta extends ActiveRecord
{
    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return 'meta';
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [['owner_id', 'meta_key', 'owner'], 'required'],
            [['owner_id'], 'integer'],
            [['owner'], 'string', 'max' => 45],
            [['meta_key'], 'string', 'max' => 200],
            [['meta_value'], 'string', 'max' => 20000],
        ];
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels()
    {
        return [
            'owner' => 'Owner entity',
            'owner_id' => 'Owner ID',
            'meta_key' => 'Meta Key',
            'meta_value' => 'Meta Value',
        ];
    }

    public static function searchIdByValue($owner,$key,$value) {
        if (!trim($value)) { return null; }
        $value = static::getDb()
            ->createCommand('SELECT owner_id FROM meta WHERE (owner = :oid) AND (meta_key = :mk) AND (meta_value = :mv)')
            ->bindValue(':oid', $owner)
            ->bindValue(':mk', $key)
            ->bindValue(':mv', $value)
            ->queryScalar();
        return $value?:null;
    }

}
