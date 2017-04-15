<?php

namespace rsmike\metafields;

use yii\base\InvalidParamException;
use yii\db\ActiveQuery;

/**
 * Class MetaActiveQuery
 * Adds `meta` table connection to ActiveQuery.
 */
class MetaActiveQuery extends ActiveQuery
{
    /*
     * Connected meta fields with their inner names
     *
     * ['nationality' => 'M_nationality.meta_value',
     *  'country' => 'M_country.meta_value' ]
     *
     * */
    private $cf = [];

    const DEFAULT_PREFIX = "M";

    /**
     * @param $field
     * @return mixed
     */
    public function mfName($field) {
        $fieldName = $this->mfShorten($field);
        if (empty($this->cf[$fieldName])) {
            throw new InvalidParamException('Meta field [' . $field . '] was not connected yet. Use metaConnectField');
        }
        return $this->cf[$fieldName];
    }

    /**
     * Shortcut for 'meta_field = value' filter. Skips empty values
     * @param $field
     * @param $value
     * @return MetaActiveQuery
     */
    public function metaEqual($field, $value) {
        if (!empty($value)) {
            $this->metaConnectField($field);
            $this->andWhere([$this->mfName($field) => $value]);
        }
        return $this;
    }

    /**
     * Shortcut for 'meta_field LIKE value' filter. Skips empty values
     * @param $field
     * @param $value
     * @return MetaActiveQuery
     */
    public function metaLike($field, $value) {
        return $this->metaAndWhere(['like', $field, $value], true);
    }

    /**
     * @param $condition [] condition[1] must be meta field name, e.g. ['=','meta_country','54']
     * @param bool $filter Set to true to skip empty conditions (AR Search Model filter mode)
     * @return $this
     */
    public function metaAndWhere($condition, $filter = false) {
        if ($filter) {
            $condition = $this->filterCondition($condition);
        }
        if (!empty($condition)) {
            $this->metaConnectField($condition[1]);
            $condition[1] = $this->mfName($condition[1]);
            $this->andWhere($condition);
        }
        return $this;
    }

    public function metaAndJsonCondition($field, $keyCondtion, $valueCondition) {
        $this->metaConnectField($field);

        // TODO: there seems to be a bug in MySQL 5.7. Adding "JSON_VALID" to condition list unexplainably fixes sudden JSON_EXTRACT error in WHERE clause. Check it back later.

        $validator_fixer = 'JSON_VALID(' . $this->mfName($field) . ')';
        $this->andWhere($validator_fixer . ' AND ' . $this->metaGetJson($field,
                $keyCondtion,
                key($valueCondition)) . ' ' . current($valueCondition));
    }

    /**
     * Returns SQL part to select sub-value from JSON meta-field.
     *
     * @example Example data contents in 'meta_languages':
     * {
     *   0:{'language': 156, 'fluency': 34, 'info': 'some text'},
     *   1: {'language': 159, 'fluency': 80, 'info': 'second row'}
     * }
     *
     * metaGetJson('languages', ['language'=>159], 'fluency') will return SQL code to get 'fluency' value from second data row (80), casted to UNSIGNED
     *
     * @param $metaField string Meta field containing JSON data.
     * @param $keyCondition array Key=>value pair to select one JSON objects by inner key and value.
     * @param $valueField string Key name to get data from. Example:
     * @param string $cast cast value to some type. Defaults to unsigned integer
     * @return string Part of SQL query
     */
    public function metaGetJson($metaField, $keyCondition, $valueField, $cast = 'UNSIGNED') {
        $jsonRootPath = $this->mfName($metaField) . "->'$.*'";
        $jsonSearchPath = $this->mfName($metaField) . "->'$.*." . key($keyCondition) . "'";

        $jsonValueGroup = "JSON_SEARCH(" . $jsonSearchPath . ",'one','" . current($keyCondition) . "')";
        $jsonValuePath = "CONCAT(JSON_UNQUOTE(" . $jsonValueGroup . "),'." . $valueField . "')";
        $getValue = 'JSON_EXTRACT(' . $jsonRootPath . ',' . $jsonValuePath . ')';

        if ($cast) {
            return "CAST($getValue as $cast)";
        } else {
            return $getValue;
        }
    }

    /**
     * @param string|array $field full/short name for meta field ('meta_nationality' or 'nationality'), or array of names
     * @param string $alias_prefix
     * @return $this
     */
    public function metaConnectField($field, $alias_prefix = self::DEFAULT_PREFIX) {
        if (is_array($field)) {
            foreach ($field as $f) {
                $this->metaConnectField($f);
            }
        } else {
            $fieldShort = $this->mfShorten($field);
            if (empty($this->cf[$fieldShort])) {
                /* @var MetaActiveRecord $modelClass Parent AR class (e.g. Tutors) */
                $modelClass = $this->modelClass;

                $tableAlias = $alias_prefix . '_' . $fieldShort; // M_nationality
                // (M_nationality.owner_id = users.id AND
                $on = '(' . $tableAlias . '.owner_id = ' . $modelClass::tableName() . '.id AND '
                    // M_nationality.owner = 'users' AND
                    . $tableAlias . '.owner = \'' . $modelClass::tableName() . '\'' . ' AND '
                    // M_nationality.meta_key = 'country'
                    . $tableAlias . '.meta_key = \'' . $fieldShort . '\')';

                $this->leftJoin(Meta::tableName() . ' ' . $tableAlias, $on);

                $this->cf[$fieldShort] = $tableAlias . '.meta_value';
            }
        }

        return $this;
    }

    private function mfShorten($field) {
        /* @var MetaActiveRecord $modelClass Parent AR class (e.g. Tutors) */
        $modelClass = $this->modelClass;
        // get meta field name (without leading 'meta_' if it exists)
        return (0 === strpos($field, $modelClass::meta_prefix())) ? substr($field,
            strlen($modelClass::meta_prefix())) : $field;
    }

}
