<?php

abstract class Model {

	private $dataSource;

	public function __construct()
	{
		# Get table name from a constant or from class title.
		$tableName = isset($this->tableName) ? $this->tableName : strtolower(get_class($this));

		$args = func_num_args() ? func_get_args() : false;

		if ($args)
		{
			if ($args[0] instanceof MySQLDataSource)
			{
				$this->dataSource = $args[0];
				return;
			}
		}

		$this->dataSource = new MySQLDataSource($tableName, $args);
	}

	public static function mapQueryToModel(Query $query, $tableName, $modelName)
	{
		$models = array();
		$recordset = MySQLDataSource::recordsetFromQuery($query, $tableName);
		foreach($recordset as $record)
		{
			$models[] = new $modelName($record);
		}
		return $models;
	}

	public function __call($methodName, $arguments = null)
	{
		# Check that method starts with get/set.
		if (strlen($methodName) > 3)
		{
			$action = substr($methodName, 0, 3); // get or set
			$field = substr($methodName, 3); // whatever follows the get or set.
			$fieldConvertedToUnderscores = '';
			$lengthOfString = strlen($field);
			for($i = 0; $i < $lengthOfString; $i++)
			{
				$capitalLetter = strtoupper($field[$i]);
				if (($i > 0) && !strcmp($capitalLetter, $field[$i]))
				{
					$fieldConvertedToUnderscores .= '_';
				}
				$fieldConvertedToUnderscores .= strtolower($field[$i]);
			}
			if ($action == 'get')
			{
				return $this->dataSource->getField($fieldConvertedToUnderscores);
			}
			else if ($action == 'set');
			{
				return $this->dataSource->setField($fieldConvertedToUnderscores, $arguments[0]);
			}
		}
		throw new Exception("Method '{$methodName}' does not exist.");
	}

	public function save()
	{
		$this->dataSource->save();
	}

}