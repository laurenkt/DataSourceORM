<?php

class MySQLDataSource implements DataSource {

	static public $state = array();

	/**
	 * Contains the name of the table. If this is not overridden in a child class then
	 * the name of the class will be used.
	 */
	protected $tableName;
	/**
	 * The 'record' contains a reference to the record this object represents in the monostate.
	 */
	protected $record = null;
	/**
	 * Contains the characteristics that will allow us to identify the record(s) and build
	 * a query.
	 */
	protected $characteristics = array();

	public function __construct($tableName, $primaryKeys = false)
	{
		$this->tableName = $tableName;

		# If the schema details have not been retrieved, retrieve them.
		if (empty(self::$state))
		{
			self::initState();
		}

		# If the given table does not exist, do not continue.
		if (!array_key_exists($this->tableName, self::$state))
		{
			throw new Exception("Given table '{$this->tableName}' does not exist.");
		}

		# Determine whether constructor was called with arguments.
		if ($primaryKeys !== false)
		{
			# Ensure the correct number of primary keys are given:
			if (count($primaryKeys) != count(self::$state[$this->tableName]['primary_keys']))
			{
				# The number of fields does not match up.
				throw new Exception('Mismatch between number of arguments given and number of primary keys in the schema.');
			}

			# Gather data for when query is built.
			foreach(self::$state[$this->tableName]['primary_keys'] as $index => $key)
			{
				$this->characteristics[$key] = $primaryKeys[$index];
			}
		}
		else
		{
			# There are no arguments.
			# Populate this object's data property with a framework of all the
			# fields available in the DB schema.
			foreach(self::$state[$this->tableName]['fields'] as $field)
			{
				# This registers that the field 'exists', but no data is set (and
				# any queries will use MySQL's NULL type).
				$this->record[$field] = null;
			}
		}
	}

	public function getField($property)
	{
		# We sure as hell ain't gonna go through with this if the property doesn't
		# exist.
		if (!in_array($property, self::$state[$this->tableName]['fields']))
		{
			throw new Exception("Property '{$property}' does not exist in table '{$this->tableName}'.");
		}

		# If there are characteristics set then we should load the data first.
		if (!empty($this->characteristics))
		{
			# Will load the data from the DB and then clear the characteristics array.
			$this->load();
			# Call this magic method again (with no characteristic) and forward
			# the results to the original caller.
			return $this->getField($property);
		}

		# And return the relevant property.
		return $this->record[$property];
	}

	public function setField($property, $data)
	{
		# We sure as hell ain't gonna go through with this if the property doesn't
		# exist.
		if (!in_array($property, self::$state[$this->tableName]['fields']))
		{
			throw new Exception("Property '{$property}' does not exist in table '{$this->tableName}'.");
		}

		# Data for this record has not been fetched yet we should do that first, 
		# else it will override our data when we do do it.
		if (!empty($this->characteristics))
		{
		    $this->load();
		}

		# Set the property.
		$this->record[$property] = $data;
	}
	
	private function bindRecordToCache($index)
	{
		$this->record = self::cacheAtIndexInTable($index, $this->tableName);
		$this->characteristics = null;
	}

	protected function load()
	{
		# Check that the environment is suitable for loading:
		# There must be some characteristics to gather a query from.
		if (empty($this->characteristics))
		{
			throw new Exception('There are no characteristics with which to determine a record.');
		}

		# If the characteristics are our primary keys then we should check the cache
		# to see if records already matching them have been retrieved.
		if (array_keys($this->characteristics) == array_values(self::$state[$this->tableName]['primary_keys']))
		{
			# The characteristics _are_ the primary keys.

			# Get the index; it is a hash if there are multiple keys, or the
			# key value if there is one.
			$index = count($this->characteristics) == 1 ? current(array_keys($this->characteristics)) : md5(serialize($this->characteristics));

			# Map this object to the existing record IF it exists.
			if (array_key_exists($index, self::$state[$this->tableName]['data']))
			{
				$this->bindRecordToCache($index);
				return;
			}
		}

		# If execution got this far then it is not an existing record.
		# Build the query.
		$whereClause = $this->buildWhereClause(array_keys($this->characteristics));
		$recordQuery = new Query("SELECT * FROM `{$this->tableName}` {$whereClause}", array_values($this->characteristics));
		$recordData = $recordQuery->fetch_assoc();
		# Get the index; it is a hash if there are multiple keys, or the
		# key value if there is one.
		$index = self::determineIndexForRecord($recordData, $this->tableName);
		# Commit record to monostate:
		self::$state[$this->tableName]['data'][$index] = $recordData;
		# Tether this object's record property to the monostate.
		$this->bindRecordToCache($index);
	}

	public function save()
	{
		# Branch off differently depending on whether to INSERT or UPDATE:
		if (!empty($this->characteristics))
		{
			# This means the original data has not been loaded... we had best sodding do this
			# first.
			$this->load();
			# Then call this function again.
			$this->save();
			return;
		}
		else
		{
			# Essentially, if characteristics is null then this is an existing record,
			# otherwise this record needs to be inserted.
			if (is_null($this->characteristics))
			{
				# We must UPDATE
				$query = "UPDATE {$this->tableName} SET ";
				foreach($this->record as $key => &$value)
				{
					$query .= "`{$key}` = ?,";
				}
				$query = substr($query, 0, -1);
				$query .= ' '.$this->buildWhereClause(self::$state[$this->tableName]['primary_keys']);
				
				
				$updateQuery = new Query(
					$query,
					array_merge(array_values($this->record), array_values($this->primaryKeys()))
				);
			}
			else
			{
				# We must INSERT
				$insertQuery = Query::insert($this->tableName, $this->record);
				if (array_key_exists('auto_increment_field', self::$state[$this->tableName]))
				{
					$this->record[self::$state[$this->tableName]['auto_increment_field']] = $insertQuery->insert_id();
				}
				# We need to place the record into the cache equipped with an index.
				$index = count(
					self::$state[$this->tableName]['primary_keys']
				) == 1 ?
					$this->record[self::$state[$this->tableName]['primary_keys'][0]] :
					md5(serialize($this->primaryKeys()));
				self::$state[$this->tableName]['data'][$index] = $this->record;
				$this->bindRecordToCache($index);
			}
		}
	}

	public static function recordsetFromQuery(Query $query, $tableName)
	{
		if (empty(self::$state))
		{
			self::initState();
		}

		$records = array();

		while($recordData = $query->fetch_assoc())
		{
			# Get the index; it is a hash if there are multiple keys, or the
			# key value if there is one.
			$index = self::determineIndexForRecord($recordData, $tableName);

			# Commit record to monostate:
			self::$state[$tableName]['data'][$index] = $recordData;
			
			$record = new MySQLDataSource($tableName);
			$record->bindRecordToCache($index);

			$records[] = $record;
		}

		return $records;
	}

	private function buildWhereClause($fieldsToSearch)
	{
		$keyConditionPairs = array();
		foreach ($fieldsToSearch as $field)
		{
			$keyConditionPairs[] = "`{$field}` = ?";
		}
		return 'WHERE '.implode(' AND ', $keyConditionPairs);
	}

	/**
	 * Ostensibly, a filter on the record data which returns only the fields which are
	 * primary keys.
	 */
	private function primaryKeys()
	{
		return array_intersect_key(
			$this->record,
			array_fill_keys(
				self::$state[$this->tableName]['primary_keys'],
				null
			)
		);
	}

	private static function initState()
	{
		# We get the schema details through the information_schema tables rather than the
		# SHOW style queries because of the limitations in retrieving specific information
		# through SHOW (we would need many queries, and to filter them through PHP).
		$schemaStructureQuery = new Query('
			SELECT
					table_name,
					column_name,
					column_key,
					extra
			FROM
					information_schema.columns
			WHERE
					table_schema = ?
			ORDER BY
					table_name,
					ordinal_position
		', Query::getDatabaseName());

		# column_key actually describes whether a field is a primary key or not.
		while (list($table, $field, $key_type, $extra) = $schemaStructureQuery->fetch_row())
		{
			# Add the field to the schema structure array.
			self::$state[$table]['fields'][] = $field;
			self::$state[$table]['data'] = array();

			# If the field is an auto_increment field, mark this (it will need to be
			# taken into account when inserting into the DB)
			if ($extra == 'auto_increment')
			{
				self::$state[$table]['auto_increment_field'] = $field;
			}
			# If the field is a primary key, take this into account too. This will be
			# needed in all queries related to this table.
			if ($key_type == 'PRI')
			{
				self::$state[$table]['primary_keys'][] = $field;
			}
		}

	}

	private static function &cacheAtIndexInTable($index, $tableName)
	{
		if (array_key_exists($index, self::$state[$tableName]['data']))
		{
			return self::$state[$tableName]['data'][$index];
		}
		else
		{
			throw new Exception('No cached record at index.');
		}
	}

	private static function determineIndexForRecord(&$recordData, $tableName)
	{
		return count(self::$state[$tableName]['primary_keys']) == 1
		?
			$recordData[self::$state[$tableName]['primary_keys'][0]]
			:
			md5(
				serialize(
					array_intersect_key(
						$recordData,
						array_fill_keys(
							self::$state[$tableName]['primary_keys'],
							null
						)
					)
				)
			);
	}

}