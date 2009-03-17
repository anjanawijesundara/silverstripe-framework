<?php
/**
 * Abstract database connectivity class.
 * Sub-classes of this implement the actual database connection libraries
 * @package sapphire
 * @subpackage model
 */
abstract class Database extends Object {
	/**
	 * Connection object to the database.
	 * @param resource
	 */
	static $globalConn;
	
	/**
	 * If this is false, then information about database operations
	 * will be displayed, eg creation of tables.
	 * @param boolean
	 */
	public static $supressOutput = false;
	
	/**
	 * Execute the given SQL query.
	 * This abstract function must be defined by subclasses as part of the actual implementation.
	 * It should return a subclass of Query as the result.
	 * @param string $sql The SQL query to execute
	 * @param int $errorLevel The level of error reporting to enable for the query
	 * @return Query
	 */
	abstract function query($sql, $errorLevel = E_USER_ERROR);
	
	/**
	 * Get the autogenerated ID from the previous INSERT query.
	 * @return int
	 */
	abstract function getGeneratedID($table);
	
	/**
	 * Check if the connection to the database is active.
	 * @return boolean
	 */
	abstract function isActive();
	
	/**
	 * Create the database and connect to it. This can be called if the
	 * initial database connection is not successful because the database
	 * does not exist.
	 * 
	 * It takes no parameters, and should create the database from the information
	 * specified in the constructor.
	 * 
	 * @return boolean Returns true if successful
	 */
	abstract function createDatabase();
	
	/**
	 * Build the connection string from input
	 * @param array $parameters The connection details
	 * @return string $connect The connection string
	 **/
	abstract function getConnect($parameters);
	
	/**
	 * Create a new table.
	 * The table will have a single field - the integer key ID.
	 * @param string $table Name of table to create.
	 */
	abstract function createTable($table, $fields = null, $indexes = null);
	
	/**
	 * Alter a table's schema.
	 */
	abstract function alterTable($table, $newFields = null, $newIndexes = null, $alteredFields = null, $alteredIndexes = null);
	
	/**
	 * Rename a table.
	 * @param string $oldTableName The old table name.
	 * @param string $newTableName The new table name.
	 */
	abstract function renameTable($oldTableName, $newTableName);
	
	/**
	 * Create a new field on a table.
	 * @param string $table Name of the table.
	 * @param string $field Name of the field to add.
	 * @param string $spec The field specification, eg 'INTEGER NOT NULL'
	 */
	abstract function createField($table, $field, $spec);
	
	/**
	 * Change the database column name of the given field.
	 * 
	 * @param string $tableName The name of the tbale the field is in.
	 * @param string $oldName The name of the field to change.
	 * @param string $newName The new name of the field
	 */
	abstract function renameField($tableName, $oldName, $newName);

	/**
	 * Get a list of all the fields for the given table.
	 * Returns a map of field name => field spec.
	 * @param string $table The table name.
	 * @return array
	 */
	protected abstract function fieldList($table);
	
	/**
	 * Returns a list of all tables in the database.
	 * The table names will be in lower case.
	 * @return array
	 */
	protected abstract function tableList();
	
	
	/**
	 * Returns true if the given table exists in the database
	 */
	abstract function hasTable($tableName);
	
	/**
	 * Returns the enum values available on the given field
	 */
	abstract function enumValuesForField($tableName, $fieldName);
	
	/**
	 * The table list, generated by the tableList() function.
	 * Used by the requireTable() function.
	 * @var array
	 */
	protected $tableList;
	
	/**
	 * The field list, generated by the fieldList() function.
	 * An array of maps of field name => field spec, indexed
	 * by table name.
	 * @var array
	 */
	protected $fieldList;
	
	/**
	 * The index list for each table, generated by the indexList() function.
	 * An map from table name to an array of index names.
	 * @var array
	 */
	protected $indexList;
	
	
	/**
	 * Large array structure that represents a schema update transaction
	 */
	protected $schemaUpdateTransaction;
	
	/**
	 * Start a schema-updating transaction.
	 * All calls to requireTable/Field/Index will keep track of the changes requested, but not actually do anything.
	 * Once	
	 */
	function beginSchemaUpdate() {
		$this->tableList = $this->tableList();
		$this->indexList = null;
		$this->fieldList = null;
		$this->schemaUpdateTransaction = array();
	}
	
	function endSchemaUpdate() {
		foreach($this->schemaUpdateTransaction as $tableName => $changes) {
			switch($changes['command']) {
				case 'create':
					$this->createTable($tableName, $changes['newFields'], $changes['newIndexes']);
					break;
				
				case 'alter':
					$this->alterTable($tableName, $changes['newFields'], $changes['newIndexes'],
						$changes['alteredFields'], $changes['alteredIndexes']);
					break;
			}
		}
		$this->schemaUpdateTransaction = null;
	}
	
	// Transactional schema altering functions - they don't do anyhting except for update schemaUpdateTransaction
	
	function transCreateTable($table) {
		$this->schemaUpdateTransaction[$table] = array('command' => 'create', 'newFields' => array(), 'newIndexes' => array());
	}
	function transCreateField($table, $field, $schema) {
		$this->transInitTable($table);
		$this->schemaUpdateTransaction[$table]['newFields'][$field] = $schema;
	}
	function transCreateIndex($table, $index, $schema) {
		$this->transInitTable($table);
		$this->schemaUpdateTransaction[$table]['newIndexes'][$index] = $schema;
	}
	function transAlterField($table, $field, $schema) {
		$this->transInitTable($table);
		$this->schemaUpdateTransaction[$table]['alteredFields'][$field] = $schema;
	}
	function transAlterIndex($table, $index, $schema) {
		$this->transInitTable($table);
		$this->schemaUpdateTransaction[$table]['alteredIndexes'][$index] = $schema;
	}
	
	/**
	 * Handler for the other transXXX methods - mark the given table as being altered
	 * if it doesn't already exist
	 */
	protected function transInitTable($table) {
		if(!isset($this->schemaUpdateTransaction[$table])) {
			$this->schemaUpdateTransaction[$table] = array(
				'command' => 'alter',
				'newFields' => array(),
				'newIndexes' => array(),
				'alteredFields' => array(),
				'alteredIndexes' => array(),
			);
		}		
	}
	
	
	/**
	 * Generate the following table in the database, modifying whatever already exists
	 * as necessary.
	 * @param string $table The name of the table
	 * @param string $fieldSchema A list of the fields to create, in the same form as DataObject::$db
	 * @param string $indexSchema A list of indexes to create. See {@link requireIndex()}
	 */
	function requireTable($table, $fieldSchema = null, $indexSchema = null, $hasAutoIncPK=true) {
		if(!isset($this->tableList[strtolower($table)])) {
			$this->transCreateTable($table);
			Database::alteration_message("Table $table: created","created");
		} else {
			$this->checkAndRepairTable($table);
		}

		//DB ABSTRACTION: we need to convert this to a db-specific version:
		$this->requireField($table, 'ID', DB::getConn()->IdColumn(false, $hasAutoIncPK));
		
		// Create custom fields
		if($fieldSchema) {
			foreach($fieldSchema as $fieldName => $fieldSpec) {
				$fieldObj = eval(ViewableData::castingObjectCreator($fieldSpec));
				$fieldObj->setTable($table);
				$fieldObj->requireField();
			}
		}	

		// Create custom indexes
		if($indexSchema) {
			foreach($indexSchema as $indexName => $indexDetails) {
				$this->requireIndex($table, $indexName, $indexDetails);
			}
		}
	}

	/**
	 * If the given table exists, move it out of the way by renaming it to _obsolete_(tablename).
	 * @param string $table The table name.
	 */
	function dontRequireTable($table) {
		if(!isset($this->tableList)) $this->tableList = $this->tableList();
		if(isset($this->tableList[strtolower($table)])) {
			$suffix = '';
			while(isset($this->tableList[strtolower("_obsolete_{$table}$suffix")])) {
				$suffix = $suffix ? ($suffix+1) : 2;
			}
			$this->renameTable($table, "_obsolete_{$table}$suffix");
			Database::alteration_message("Table $table: renamed to _obsolete_{$table}$suffix","obsolete");
		}
	}
	
	/**
	 * Generate the given index in the database, modifying whatever already exists as necessary.
	 * 
	 * The keys of the array are the names of the index.
	 * The values of the array can be one of:
	 *  - true: Create a single column index on the field named the same as the index.
	 *  - array('type' => 'index|unique|fulltext', 'value' => 'FieldA, FieldB'): This gives you full
	 *    control over the index.
	 * 
	 * @param string $table The table name.
	 * @param string $index The index name.
	 * @param string|boolean $spec The specification of the index. See requireTable() for more information.
	 */
	function requireIndex($table, $index, $spec) {
		$newTable = false;
		
		//DB Abstraction: remove this ===true option as a possibility?
		if($spec === true) {
			$spec = "($index)";
		}
		
		//Indexes specified as arrays cannot be checked with this line: (it flattens out the array)
		if(!is_array($spec))
			$spec = ereg_replace(" *, *",",",$spec);

		if(!isset($this->tableList[strtolower($table)])) $newTable = true;

		if(!$newTable && !isset($this->indexList[$table])) {
			$this->indexList[$table] = $this->indexList($table);
		}
		
		//$index_alt=DB::getConn()->indexOrIndexAlt($index);
		// @todo Geoff to fix his faulty commit from r73214
		$index_alt = $index;
				
		//Fix up the index for database purposes
		$index=DB::getConn()->getDbSqlDefinition($table, $index, null, true);
		
		if(!$newTable) {
			if(is_array($this->indexList[$table][$index_alt])) {
				$array_spec = $this->indexList[$table][$index_alt]['spec'];
			} else {
				$array_spec = $this->indexList[$table][$index_alt];
			}
		}
		
		if($newTable || !isset($this->indexList[$table][$index_alt])) {
			$this->transCreateIndex($table, $index, $spec);
			Database::alteration_message("Index $table.$index: created as $spec","created");
		} else if($array_spec != DB::getConn()->convertIndexSpec($spec)) {
			$this->transAlterIndex($table, $index, $spec);
			$spec_msg=DB::getConn()->convertIndexSpec($spec);
			Database::alteration_message("Index $table.$index: changed to $spec_msg <i style=\"color: #AAA\">(from {$array_spec})</i>","changed");			
		}
	}

	/**
	 * Generate the given field on the table, modifying whatever already exists as necessary.
	 * @param string $table The table name.
	 * @param string $field The field name.
	 * @param array|string $spec The field specification. If passed in array syntax, the specific database
	 * 	driver takes care of the ALTER TABLE syntax. If passed as a string, its assumed to
	 * 	be prepared as a direct SQL framgment ready for insertion into ALTER TABLE. In this case you'll
	 * 	need to take care of database abstraction in your DBField subclass.  
	 */
	function requireField($table, $field, $spec) {
		//TODO: this is starting to get extremely fragmented.
		//There are two different versions of $spec floating around, and their content changes depending
		//on how they are structured.  This needs to be tidied up.
		
		$newTable = false;

		Profiler::mark('requireField');
		
		// backwards compatibility patch for pre 2.4 requireField() calls
		$spec_orig=$spec;
		if(!is_string($spec)) {
			// TODO: This is tempororary
			$spec['parts']['name'] = $field;
			$spec_orig['parts']['name'] = $field;
			//Convert the $spec array into a database-specific string
			$spec=DB::getConn()->$spec['type']($spec['parts'], true);
		}
		
		// Collations didn't come in until MySQL 4.1.  Anything earlier will throw a syntax error if you try and use
		// collations.
		// TODO: move this to the MySQLDatabase file, or drop it altogether?
		if(!$this->supportsCollations()) {
			$spec = eregi_replace(' *character set [^ ]+( collate [^ ]+)?( |$)','\\2',$spec);
		}
		
		if(!isset($this->tableList[strtolower($table)])) $newTable = true;

		if(!$newTable && !isset($this->fieldList[$table])) {
			$this->fieldList[$table] = $this->fieldList($table);
		}
		
		// Get the value of this field.
		if(is_array($spec))
			$specValue=$spec['data_type'];
		else $specValue=$spec;

		// We need to get db-specific versions of the ID column:
		if($spec_orig==DB::getConn()->IdColumn() || $spec_orig==DB::getConn()->IdColumn(true))
			$specValue=DB::getConn()->IdColumn(true);
		
		if(!$newTable) {
			if(is_array($this->fieldList[$table][$field])) {
				$fieldValue = $this->fieldList[$table][$field]['data_type'];
			} else {
				$fieldValue = $this->fieldList[$table][$field];
			}
		}
		
		// Get the version of the field as we would create it. This is used for comparison purposes to see if the
		// existing field is different to what we now want
		if(is_array($spec_orig)) {
			$spec_orig=DB::getConn()->$spec_orig['type']($spec_orig['parts']);
		}
		
		if($newTable || $fieldValue=='') {
			Profiler::mark('createField');
			
			$this->transCreateField($table, $field, $spec_orig);
			Profiler::unmark('createField');
			Database::alteration_message("Field $table.$field: created as $spec_orig","created");
		} else if($fieldValue != $specValue) {
			// If enums are being modified, then we need to fix existing data in the table.
			// Update any records where the enum is set to a legacy value to be set to the default.
			// One hard-coded exception is SiteTree - the default for this is Page.
			
			if(substr($specValue, 0, 4) == "enum") {
				$newStr = preg_replace("/(^enum\s*\(')|('$\).*)/i","",$spec_orig);
				$new = preg_split("/'\s*,\s*'/", $newStr);
				
				$oldStr = preg_replace("/(^enum\s*\(')|('$\).*)/i","", $fieldValue);
				$old = preg_split("/'\s*,\s*'/", $newStr);

				$holder = array();
				foreach($old as $check) {
					if(!in_array($check, $new)) {
						$holder[] = $check;
					}
				}
				if(count($holder)) {
					$default = explode('default ', $spec_orig);
					$default = $default[1];
					if($default == "'SiteTree'") $default = "'Page'";
					$query = "UPDATE \"$table\" SET $field=$default WHERE $field IN (";
					for($i=0;$i+1<count($holder);$i++) {
						$query .= "'{$holder[$i]}', ";
					}
					$query .= "'{$holder[$i]}')";
					DB::query($query);
					$amount = DB::affectedRows();
					Database::alteration_message("Changed $amount rows to default value of field $field (Value: $default)");
				}
			}
			Profiler::mark('alterField');
			$this->transAlterField($table, $field, $spec_orig);
			Profiler::unmark('alterField');
			Database::alteration_message("Field $table.$field: changed to $spec_orig <i style=\"color: #AAA\">(from {$fieldValue})</i>","changed");
		}
		Profiler::unmark('requireField');
	}
	
	/**
	 * If the given field exists, move it out of the way by renaming it to _obsolete_(fieldname).
	 * 
	 * @param string $table
	 * @param string $fieldName
	 */
	function dontRequireField($table, $fieldName) {
		$fieldList = $this->fieldList($table);
		if(array_key_exists($fieldName, $fieldList)) {
			$suffix = '';
			while(isset($fieldList[strtolower("_obsolete_{$fieldName}$suffix")])) {
				$suffix = $suffix ? ($suffix+1) : 2;
			}
			$this->renameField($table, $fieldName, "_obsolete_{$fieldName}$suffix");
			Database::alteration_message("Field $table.$fieldName: renamed to $table._obsolete_{$fieldName}$suffix","obsolete");
		}
	}

	/**
	 * Execute a complex manipulation on the database.
	 * A manipulation is an array of insert / or update sequences.  The keys of the array are table names,
	 * and the values are map containing 'command' and 'fields'.  Command should be 'insert' or 'update',
	 * and fields should be a map of field names to field values, including quotes.  The field value can
	 * also be a SQL function or similar.
	 * @param array $manipulation
	 */
	function manipulate($manipulation) {
		foreach($manipulation as $table => $writeInfo) {
			
			if(isset($writeInfo['fields']) && $writeInfo['fields']) {
				$fieldList = $columnList = $valueList = array();
				foreach($writeInfo['fields'] as $fieldName => $fieldVal) {
					$fieldList[] = "\"$fieldName\" = $fieldVal";
					$columnList[] = "\"$fieldName\"";

					// Empty strings inserted as null in INSERTs.  Replacement of Database::replace_with_null().
					if($fieldVal === "''") $valueList[] = "null";
					else $valueList[] = $fieldVal;
				}
				
				if(!isset($writeInfo['where']) && isset($writeInfo['id'])) {
					$writeInfo['where'] = "\"ID\" = " . (int)$writeInfo['id'];
				}
				
				switch($writeInfo['command']) {
					case "update":
						// Test to see if this update query shouldn't, in fact, be an insert
						if($this->query("SELECT \"ID\" FROM \"$table\" WHERE $writeInfo[where]")->value()) {
							$fieldList = implode(", ", $fieldList);
							$sql = "update \"$table\" SET $fieldList where $writeInfo[where]";
							$this->query($sql);
							break;
						}
						
						// ...if not, we'll skip on to the insert code

					case "insert":
						if(!isset($writeInfo['fields']['ID']) && isset($writeInfo['id'])) {
							$columnList[] = "\"ID\"";
							$valueList[] = (int)$writeInfo['id'];
						}
						
						$columnList = implode(", ", $columnList);
						$valueList = implode(", ", $valueList);
						$sql = "insert into \"$table\" ($columnList) VALUES ($valueList)";
						$this->query($sql);
						break;

					default:
						$sql = null;
						user_error("Database::manipulate() Can't recognise command '$writeInfo[command]'", E_USER_ERROR);
				}
			}
		}
	}
	
	/** Replaces "\'\'" with "null", recursively walks through the given array. 
	 * @param string $array Array where the replacement should happen
	 */
	static function replace_with_null(&$array) {
		$array = ereg_replace('= *\'\'', "= null", $array);
		
		if(is_array($array)) {
			foreach($array as $key => $value) {
				if(is_array($value)) {
					array_walk($array, array(Database, 'replace_with_null'));
				}
			}
		}
		
		return $array;
	} 

	/**
	 * Error handler for database errors.
	 * All database errors will call this function to report the error.  It isn't a static function;
	 * it will be called on the object itself and as such can be overridden in a subclass.
	 * @todo hook this into a more well-structured error handling system.
	 * @param string $msg The error message.
	 * @param int $errorLevel The level of the error to throw.
	 */
	function databaseError($msg, $errorLevel = E_USER_ERROR) {
		user_error($msg, $errorLevel);
	}
	
	/**
	 * Enable supression of database messages.
	 */
	function quiet() {
		Database::$supressOutput = true;
	}
	
	static function alteration_message($message,$type=""){
		if(!Database::$supressOutput) {
			$color = "";
			switch ($type){
				case "created":
					$color = "green";
					break;
				case "obsolete":
					$color = "red";
					break;
				case "error":
					$color = "red";
					break;
				case "deleted":
					$color = "red";
					break;						
				case "changed":
					$color = "blue";
					break;
				case "repaired":
					$color = "blue";
					break;
				default:
					$color="";
			}
			echo "<li style=\"color: $color\">$message</li>";
		}
	}

	/**
	 * Convert a SQLQuery object into a SQL statement
	 */
	public function sqlQueryToString(SQLQuery $sqlQuery) {
		if (!$sqlQuery->from) return '';
		$distinct = $sqlQuery->distinct ? "DISTINCT " : "";
		if($sqlQuery->delete) {
			$text = "DELETE ";
		} else if($sqlQuery->select) {
			$text = "SELECT $distinct" . implode(", ", $sqlQuery->select);
		}
		$text .= " FROM " . implode(" ", $sqlQuery->from);

		if($sqlQuery->where) $text .= " WHERE (" . $sqlQuery->getFilter(). ")";
		if($sqlQuery->groupby) $text .= " GROUP BY " . implode(", ", $sqlQuery->groupby);
		if($sqlQuery->having) $text .= " HAVING ( " . implode(" ) AND ( ", $sqlQuery->having) . " )";
		if($sqlQuery->orderby) $text .= " ORDER BY " . $sqlQuery->orderby;

		if($sqlQuery->limit) {
			$limit = $sqlQuery->limit;
			// Pass limit as array or SQL string value
			if(is_array($limit)) {
				if(!array_key_exists('limit',$limit)) user_error('SQLQuery::limit(): Wrong format for $limit', E_USER_ERROR);

				if(isset($limit['start']) && is_numeric($limit['start']) && isset($limit['limit']) && is_numeric($limit['limit'])) {
					$combinedLimit = "$limit[limit] OFFSET $limit[start]";
				} elseif(isset($limit['limit']) && is_numeric($limit['limit'])) {
					$combinedLimit = (int)$limit['limit'];
				} else {
					$combinedLimit = false;
				}
				if(!empty($combinedLimit)) $text .= " LIMIT " . $combinedLimit;

			} else {
				$text .= " LIMIT " . $sqlQuery->limit;
			}
		}
		
		return $text;
	}
	
}

/**
 * Abstract query-result class.
 * Once again, this should be subclassed by an actual database implementation.  It will only
 * ever be constructed by a subclass of Database.  The result of a database query - an iteratable object that's returned by DB::Query
 *
 * Primarily, the Query class takes care of the iterator plumbing, letting the subclasses focusing
 * on providing the specific data-access methods that are required: {@link nextRecord()}, {@link numRecords()}
 * and {@link seek()}
 * @package sapphire
 * @subpackage model
 */
abstract class Query extends Object implements Iterator {
	/**
	 * The current record in the interator.
	 * @var array
	 */
	private $currentRecord = null;
	
	/**
	 * The number of the current row in the interator.
	 * @var int
	 */
	private $rowNum = -1;

	/**
	 * Return an array containing all values in the leftmost column.
	 * @return array
	 */
	public function column() {
		$column = array();
		foreach($this as $record) {
			$column[] = reset($record);
		}
		return $column;
	}

	/**
	 * Return an array containing all values in the leftmost column, where the keys are the
	 * same as the values.
	 * @return array
	 */
	public function keyedColumn() {
		$column = array();
		foreach($this as $record) {
			$val = reset($record);
			$column[$val] = $val;
		}
		return $column;
	}

	/**
	 * Return a map from the first column to the second column.
	 * @return array
	 */
	public function map() {
		$column = array();
		foreach($this as $record) {
			$key = reset($record);
			$val = next($record);
			$column[$key] = $val;
		}
		return $column;
	}

	/**
	 * Returns the next record in the iterator.
	 * @return array
	 */
	public function record() {
		return $this->next();
	}

	/**
	 * Returns the first column of the first record.
	 * @return string
	 */
	public function value() {
		foreach($this as $record) {
			return reset($record);
		}
	}

	/**
	 * Return an HTML table containing the full result-set
	 */
	public function table() {
		$first = true;
		$result = "<table>\n";
		
		foreach($this as $record) {
			if($first) {
				$result .= "<tr>";
				foreach($record as $k => $v) {
					$result .= "<th>" . Convert::raw2xml($k) . "</th> ";
 				}
				$result .= "</tr> \n";
			}

			$result .= "<tr>";
			foreach($record as $k => $v) {
				$result .= "<td>" . Convert::raw2xml($v) . "</td> ";
			}
			$result .= "</tr> \n";
			
			$first = false;
		}
		
		if($first) return "No records found";
		return $result;
	}
	
	/**
	 * Iterator function implementation. Rewind the iterator to the first item and return it.
	 * Makes use of {@link seek()} and {@link numRecords()}, takes care of the plumbing.
	 * @return array
	 */
	public function rewind() {
		if($this->numRecords() > 0) {
			return $this->seek(0);
		}
	}

	/**
	 * Iterator function implementation. Return the current item of the iterator.
	 * @return array
	 */
	public function current() {
		if(!$this->currentRecord) {
			return $this->next();
		} else {
			return $this->currentRecord;
		}
	}

	/**
	 * Iterator function implementation. Return the first item of this iterator.
	 * @return array
	 */
	public function first() {
		$this->rewind();
		return $this->current();
	}

	/**
	 * Iterator function implementation. Return the row number of the current item.
	 * @return int
	 */
	public function key() {
		return $this->rowNum;
	}

	/**
	 * Iterator function implementation. Return the next record in the iterator.
	 * Makes use of {@link nextRecord()}, takes care of the plumbing.
	 * @return array
	 */
	public function next() {
		$this->currentRecord = $this->nextRecord();
		$this->rowNum++;
		return $this->currentRecord;
	}

	/**
	 * Iterator function implementation. Check if the iterator is pointing to a valid item.
	 * @return boolean
	 */
	public function valid() {
	 	return $this->current() !== false;
	}

	/**
	 * Return the next record in the query result.
	 * @return array
	 */
	abstract function nextRecord();

	/**
	 * Return the total number of items in the query result.
	 * @return int
	 */
	abstract function numRecords();

	/**
	 * Go to a specific row number in the query result and return the record.
	 * @param int $rowNum Tow number to go to.
	 * @return array
	 */
	abstract function seek($rowNum);
}

?>