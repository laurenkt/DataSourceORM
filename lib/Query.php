<?php

/**
 * The Query class is used to create and manipulate queries and their results.
 */
class Query {

	/**
	 * The $link property is static (identical across all instances of the Query class) and simply represents
	 * the particular connection to the database in use.
	 */
	public static $link = 0;

	public static $database = '';

	public static $last_query = '';

	/**
	 * The final parsed string version of the query sent to the DBMS. Useful for debugging purposes, but not used
	 * aside from that.
	 */
	public $query = '';
	/**
	 * The $resource property points the DB extension to the results of the query sent. It must be stored because
	 * all operations are effectively done on this variable.
	 */
	public $resource;

	function __construct ($query)
	{
		# If there is no connection ('link') yet made, proceed to do it.
		if (Query::$link === 0)
		{
			# We call the 'connect' method statically. It should find the relevant details and connect to our
			# database. An exception will be thrown if there is an error.
			try {
				Query::connect();
			}
			catch (Exception $e) {
				# There has been an error in connecting, inform the model/class that is making a query.
				throw new Exception('No database connection could be found or made (error returned by connector was "'.$e->getMessage().'").');
			}
		}

		# If the instance is being called with other arguments they will need to be parsed into the query.
		# These are arguments not defined in this method definition - the reason for this is that there is no
		# limit to the number of parameters.
		if (func_num_args() > 1)
		{
			# Retrieve the arguments used from PHP directly.
			$functionArguments = func_get_args();

			# If an argument is an array we are going to assume that the contents of that array are the
			# parameters for our query - use them instead.
			if (is_array($functionArguments[1]))
			{
				# The array key is 1 not 0 because 0 would be the query string itself.
				$functionArguments = $functionArguments[1];
				# We need a blank value at the beginning of the array (which would be the $query argument
				# of the function were these arguments not passed as an array).
				array_unshift($functionArguments, 0);
			}

			# We need to know how many args there are to replace.
			$numberOfArguments = count($functionArguments);

			# The replacement key is '?' so explode the string around it to get all the parts we need to merge.
			$parts = explode('?', $query);
			# Reset $query as we will be rebuilding it part by part.
			$query = '';

			# We rebuild part by part instead of by searching and replacing through because data being parsed
			# into the query may also contain '?' symbols.
			for($i = 1; $i < $numberOfArguments; $i++)
			{
				# If the component is an object we should attempt to stringify it:
				if (is_object($functionArguments[$i]))
					$functionArguments[$i] = (string) $functionArguments[$i];

				# If the value is not numeric it will need to be escaped and wrapped in single quotes.
				$query .= $parts[$i - 1].
					(is_null($functionArguments[$i]) ?
						'NULL'
					:
						(is_string($functionArguments[$i]) ?
							"'".mysql_real_escape_string($functionArguments[$i], Query::$link)."'"
						:
							(double) $functionArguments[$i]));
			}

			# Add the last part of the query back in (after the final '?' symbol).
			$query .= $parts[$numberOfArguments - 1];
		}

		# We store our information as object properties so that they can be accessed if further methods are
		# called upon the results.
		Query::$last_query = $query;
		$this->query = $query;
		$this->resource = mysql_query($query, Query::$link);

		# We must also check to make sure the query was successful.
		if ($this->resource == false)
		{
			# This query is not successful, so throw it back to the programmer with the MySQL error generated
			# by the RDBMS engine.
			throw new Exception('Query execution failed ('.mysql_error(Query::$link).').');
		}
	}

	public static function getDatabaseName()
	{
		if (Query::$link === 0)
		{
			# We call the 'connect' method statically. It should find the relevant details and connect to our
			# database. An exception will be thrown if there is an error.
			try {
				Query::connect();
			}
			catch (Exception $e) {
				# There has been an error in connecting, inform the model/class that is making a query.
				throw new Exception('No database connection could be found or made (error returned by connector was "'.$e->getMessage().'").');
			}
		}

		return Query::$database;
	}

	/**
	 * The fetch_row method provides the functionality of the mysql_fetch_row function for this specific set of
	 * results.
	 *
	 * Each time it is called it will move the internal pointer along one and return the results associated with
	 * that index in array form.
	 *
	 * It will automatically unescape string data.
	 * 
	 * @return mixed Array of data associated with this record.
	 */
	public function fetch_row ()
	{
		$result = mysql_fetch_row($this->resource);

		# This will traverse the array containing the result data and unescape any strings that would have been
		# escaped when entered into the database.
		if (is_array($result))
		{
			array_walk($result, create_function('$data, $key', 'return is_string($data) ? stripslashes($data) : $data;'));
		}

		return $result;
	}

	/**
	 * The fetch_assoc method provides the functionality of the mysql_fetch_assoc function for this specific set of
	 * results. This means that the field names will be returned (as array keys) along with the record data.
	 *
	 * Each time it is called it will move the internal pointer along one and return the results associated with
	 * that index in array form.
	 *
	 * It will automatically unescape string data.
	 * 
	 * @return mixed Array of data associated with this record.
	 */
	public function fetch_assoc ()
	{
		$result = mysql_fetch_assoc($this->resource);

		# This will traverse the array containing the result data and unescape any strings that would have been
		# escaped when entered into the database.
		if (is_array($result))
		{
			array_walk($result, create_function('$data, $key', 'return is_string($data) ? stripslashes($data) : $data;'));
		}

		return $result;
	}

	/**
	 * Returns the number of rows contained in this Query object instance.
	 *
	 * @return int Number of rows contained in this Query object instance.
	 */
	public function num_rows ()
	{
		return mysql_num_rows($this->resource);
	}

	/**
	 * Returns the last autoincrement id assigned by the DBMS.
	 * 
	 * @return int Last ID inserted.
	 */
	function insert_id ()
	{
		return mysql_insert_id(Query::$link);
	}

	/**
	 * The static method 'insert' will insert a set of data into a given table.
	 *
	 * The $values parameter should be an array where the $values represent data to be inserted and the
	 * arrays keys represent the fields they are to be inserted into.
	 *
	 * @param $table string
	 * @param $values array
	 * @return int ID of record inserted.
	 */
	public static function insert ($table, $values)
	{
		# We need to generate the '?' symbols for use in query construct this class uses.
		$questionMarks = str_repeat('?, ', count($values) - 1).'?';
		# We also need to generate the field names in the format ... `Field1`, `Field2`, `Field3` ...
		$fields = '`'.implode('`, `', array_keys($values)).'`';

		# Create a new instance of the Query class with the query we have built and the values given.
		try {
			return $query = new Query("INSERT INTO `{$table}` ({$fields}) VALUES ({$questionMarks});", array_values($values));
		}
		catch (Exception $e) {
			# There was an error inserting data into the database. Forward the exception.
			throw new Exception('Record could not be inserted into the database. Error given by query handler was "'.$e->getMessage().'".');
		}
	}

	/**
	 * This method closes the active connection to the database.
	 */
	public static function close ()
	{
		return mysql_close(Query::$link);
	}

	/**
	 * This static method will open a connect to the database specified in the config file with the connection
	 * details specified in the same file.
	 *
	 * This method is called automatically if there is no connection.
	 */
	public static function connect ()
	{
		# We need to access the information from the config file which is stored in an array called $config.
		require ROOT_DIR.'_config.php';

		Query::$database = $config['database'];

		# Attempt to make the connection and assign the resulting resource to the static $link property.
		Query::$link = mysql_connect($config['server'], $config['user'], $config['password']);

		# It is possible there was a connection failure...
		if (Query::$link == false)
		{
			# If so, we must reset the link property to 0 (as it is useless to us).
			Query::$link = 0;
			# We must also inform the caller that the connection couldn't be made.
			throw new Exception('Connection to database server could not be made.');
		}

		# We need to select the relevant database to use.
		if (!mysql_select_db($config['database'], Query::$link))
		{
			# The database couldn't be selected (though the connection has been made) - it probably doesn't
			# exist. Throw an exception to indicate error.
			throw new Exception('Error occured when selecting database to use.');
		}
	}

	public static function escape_string ($str)
	{
		return mysql_real_escape_string($str);
	}

}
