<?php

interface DataSource {

	public function save();
	public function getField($fieldName);
	public function setField($fieldName, $data);

	public static function recordsetFromQuery(Query $query, $tableName);

}