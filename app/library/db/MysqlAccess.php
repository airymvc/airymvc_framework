<?php

/**
 * AiryMVC Framework
 *
 * LICENSE
 *
 * This source file is subject to the new BSD license.
 *
 * It is also available at this URL: http://opensource.org/licenses/BSD-3-Clause
 * The project website URL: https://code.google.com/p/airymvc/
 *
 * @author: Hung-Fu Aaron Chang
 */

class MysqlAccess implements DbAccessInterface{

    private $dbConfigArray;
    private $queryStmt;
    private $selectStatement;
    private $selectPart;
    private $updatePart;
    private $deletePart;
    private $insertPart;
    private $joinPart;
    private $joinOnPart;
    private $wherePart;
    private $orderPart;
    private $groupPart;
    private $limitPart;
    private $keywords;
    private $queryType;

    function __construct($databaseId = 0) {
        $config = Config::getInstance();
        $configArray = $config->getDBConfig();
        $this->dbConfigArray = $configArray[$databaseId];
        $this->setKeywords();
    }

    /*
     * array (op of 'AND' or 'OR', array (op of 'like' or '=', array of (column => value)))
     * EX: array("AND"=>array("="=>array(field1=>value1, field2=>value2), ">"=>array(field3=>value3)))
     *     array(""=>array("="=>array(field1=>value1)))
     * if operators is null, all operators are "AND"
     * 
     * if it is after a inner join, should use "table.field1=>value1"
     * 
     */

    public function where($condition) {

    	$this->wherePart = " WHERE ";
        if (is_array($condition)) {
        	$this->wherePart .= $this->composeWhereByArray($condition);
        } else {
        	$this->wherePart .= $this->composeWhereByString($condition);
        }
        return $this;

    }
    
    protected function composeWhereByString($condition) {
    	$condition = $this->mysqlEscape($condition);
    	return "({$condition})";
    }
    
    protected function composeWhereByArray($condition) {
    	$wherePart = "";
        $ops = array_keys($condition);
        if (empty($ops[0])) {
            //NO "AND", "OR" 
            $keys = array_keys($condition[$ops[0]]);
            $opr = $keys[0];
            $fieldArray = $condition[$ops[0]][$opr];
            $sub_keys = array_keys($fieldArray);
            
            $wherePart = $this->attachWhere($wherePart, $sub_keys[0], $fieldArray, $opr);
            
        } else {   
        	//Multiple Join Conditions
            $firstOne = TRUE;
            foreach ($ops as $index => $op) {
                foreach ($condition[$op] as $mopr => $fv_pair) {
                    if (is_array($fv_pair)) {
                        $mkeys = array_keys($fv_pair);
                        foreach ($mkeys as $idx => $mfield) {
                            if ($firstOne) {
                            	$oprator = null;
                                $firstOne = FALSE;
                            } else {
                            	$oprator = $op;
                            }
                            $wherePart = $this->attachWhere($wherePart, $mfield, $fv_pair, $mopr, $oprator);
                        }
                    } else {
                        //@TODO: to consider if the error log is necessary here
                        //log the error
                        $message = "JOIN condition uses array but not a correct array";
                        throw new AiryException($message, 0);
                    }
                }
            }
        }
        return $wherePart;    	
    }
    
    
    private function attachWhere($whereString, $fieldKey, $fieldArray, $relationalOperator, $operator = null) {
        $pos = strpos($fieldKey, '.');
        $operator = is_null($operator) ? "" : strtoupper($operator);
        $key = "`{$fieldKey}`";
        if ($pos != false){
            $tf = explode (".", $fieldKey);
            $key = "`{$tf[0]}`.`{$tf[1]}`";
        }
        $whereString .= "{$operator} {$key} {$relationalOperator} '{$fieldArray[$fieldKey]}' ";
        return $whereString;    	
    }
    
    
    public function andWhere($opString) {
    	$opString = $this->mysqlEscape($opString);
    	$opString = " AND ({$opString})";
    	$this->wherePart .= $opString; 
    	return $this;  	
    }
    
    public function orWhere($opString) {
    	$opString = $this->mysqlEscape($opString);
    	$opString = " OR ({$opString})";
    	$this->wherePart .= $opString; 
    	return $this;    	
    }

    public function InWhere($in) {
    	$in = $this->mysqlEscape($in);
    	$opString = " IN ({$in})";
    	$this->wherePart .= $opString; 
    	return $this;    	
    }

    public function innerJoin($tables) {
        //INNER JOIN messages INNER JOIN languages
        $tables = $this->mysqlEscape($tables);

        foreach ($tables as $index => $tbl) {
        	$addon = "";
            if ($index != 0) {
            	$addon = $this->joinPart;
            }
            $this->joinPart = "{$addon} INNER JOIN `{$tbl}`";
        }
        return $this;
    }
    

    /*
     * conditions represent 
     * Ex: array ("" => array(array("=", table1=>field1, table2=>field2)))
     *     array ("AND" => array(array("=", table1=>field1, table2=>field2), array("<>", table3=>field3, table2=>field2)
     *                   , array("<>", table4=>field4, table3=>field3)), 
     *              "OR"=> array(array("=", table5=>field5, table6=>field6)))
     * operators represent "AND",  "OR" its squence matters.
     * if operators is null, all operators are "AND"
     * 
     * SELECT * FROM `event` INNER JOIN `event_report` INNER JOIN `member` 
     * ON `table1`.`field1` = `table2`.`field2`AND `table3`.`field3` <> `table2`.`field2`AND `table4`.`field4` <> `table3`.`field3`
     * OR `table5`.`field5` = `table6`.`field6` LIMIT 0, 10
     * 
     */

    public function joinOn($condition) {
        $this->joinOnPart = " ON ";
        if (is_array($condition)) {
        	$this->joinOnPart = $this->composeJoinOnByArray($this->joinOnPart, $condition);
        } else {
        	$this->joinOnPart = $this->composeJoinOnByString($this->joinOnPart, $condition);
        }
        return $this;
    }
    
    public function andJoinOn($condition) {
        $this->joinOnPart .= " AND {$condition}";
        return $this;
    }
    
    public function orJoinOn($condition) {
        $this->joinOnPart .= " OR {$condition}";
        return $this;
    }
    
    private function composeJoinOnByString($joinOnString, $conditionString) {
    	$joinOnString .= $conditionString;
    	return $joinOnString;
    }
    
    private function composeJoinOnByArray($joinOnString, $condition) {
        $ops = array_keys($condition);
        
        if (empty($ops[0])) {
            //NO "AND", "OR" 
            $joinOnString = $this->attachJoinOn($joinOnString, $condition[$ops[0]][0]);
        } else {   
        	//Multiple Join Conditions
            if ((count($ops) == 1))  {
                $op = $ops[0];
                $tfPairs = $condition[$op];
                if (count($tfPairs) == 1) {
                    $tfPair = $tfPairs[0];
                    $joinOnString = $this->attachJoinOn($joinOnString, $tfPair);      
                    return $joinOnString;
                }
				$joinOnString = $this->attachPairs($joinOnString, $tfPairs, $op);
                return $joinOnString;
            }
            foreach ($ops as $index => $op) {
                $tfPairs = $condition[$op]; 
                if (count($tfPairs) == 1 && $index > 0) { 
                    $tfPair = $tfPairs[0]; 
                    $joinOnString = $this->attachJoinOn($joinOnString, $tfPair, null, $op);   
                } elseif (count($tfPairs) > 1) {
					$joinOnString = $this->attachPairs($joinOnString, $tfPairs, $op);
                }              
            }
            
        }  
        return $joinOnString;  	
    }
    
    private function attachPairs($joinOnString, $tfPairs, $op) {
        foreach ($tfPairs as $idx => $tfPair) {
                 $operation = $op;
                 if (count($tfPairs) - 1 == $idx) {
                     $operation = null;  
                 }
                 $joinOnString = $this->attachJoinOn($joinOnString, $tfPair, $operation);
        }
        return $joinOnString;    	
    }
    
    private function attachJoinOn($joinOnString, $tf_pair, $op = null, $leadingOp = null) {
        $op = is_null($op) ? "" : $op;
        $leadingOp = is_null($leadingOp) ? "" : (" " . $leadingOp);	
        $mkeys = array_keys($tf_pair);
        $mopr = $tf_pair[0];
        $mtable1 = $mkeys[1];
        $mtable2 = $mkeys[2];
        $joinOnString .= $leadingOp . " `" . $mtable1 . "`.`" . $tf_pair[$mtable1] . "` " . $mopr . " `" . $mtable2 . "`.`" . $tf_pair[$mtable2] . "` ". $op;
        
        return $joinOnString;    	
    }

    public function select($columns, $table, $distinct = null) {
        $this->queryType = "SELECT";
        if (is_null($distinct)) {
            $selectString = 'SELECT ';
        } else {
            $selectString = 'SELECT DISTINCT ';         
        }
        
        if (is_array($columns)) {
        	$this->selectPart = $this->composeSelectByArray($selectString, $columns, $table);
        } else {
        	$this->selectPart = $this->composeSelectByString($selectString, $columns, $table);
        }
        
        return $this;
    }
    
    private function composeSelectByArray($selectString, $columns, $table) {
    	$selectPart = $selectString;
        foreach ($columns as $index => $col) {
            if ($index == count($columns) - 1) {
                $selectPart .= $col . " FROM `" . $table . "`";
            } else {
                $selectPart .= $col . ", ";
            }
        }  
        return $selectPart;  	
    }
    
    private function composeSelectByString($selectString, $columnString, $table) {
    	$selectPart = $selectString . $columnString ." FROM `" . $table . "`";
    	return $selectPart;
    }

    /*
     * $table @string : the name of the table
     * $columns @array : the columns array(column_name => column_value, column_name1 => column_value1)
     */

    public function update($columns, $table) {
        $this->queryType = "UPDATE";
        $this->updatePart = "UPDATE `" . $table . "` SET ";
        $size = count($columns) - 1;
        $n = 0;
        foreach ($columns as $column_index => $column_value) {
        	$lastAppend = "', ";
            if ($n == $size) {
                $lastAppend = "'";
            }
            $this->updatePart .= "`" . $column_index . "`='" . $column_value . $lastAppend;
            $n++;
        }

        return $this;
    }

    /*
     * $table @string : the name of the table
     * $columns @array : the columns array(column_name => column_value, column_name1 => column_value1)
     * 
     * $keywords like TIMESTAMP, it needs to be taken care of 
     */

    public function insert($columns, $table) {
        $this->queryType = "INSERT";
        $this->insertPart = "INSERT INTO " . $table . " ( ";
        $size = count($columns) - 1;
        $n = 0;
        foreach ($columns as $column_index => $column_value) {
        	$attach = "`, ";
            if ($n == $size) {
            	$attach = "`) VALUES (";
            }
            $this->insertPart = $this->insertPart . "`" . $column_index . $attach;
            $n++;
        }

        $n = 0;
        foreach ($columns as $column_index => $column_value) {
        	$middle = "'";
            $last = "', ";
            if ($n == $size) {
            	$middle = "'";
            	$last = "')";
            }
            if (array_key_exists($column_value, $this->keywords)) {
            	$middle = "";
            	$last = "";
            }
            $this->insertPart = $this->insertPart . $middle . $column_value . $last;
            $n++;
        }

        return $this;
    }

    /*
     * $table @string : the name of the table
     */

    public function delete($table) {
        $table = $this->mysqlEscape($table);
        $this->queryType = "DELETE";
        $this->deletePart = "DELETE FROM " . $table;
        return $this;
    }

    /*
     *  $offset @int
     *  $interval @int
     */

    public function limit($offset, $interval) {
        $this->limitPart = "";
        $offset = (!is_null($offset)) ? $this->mysqlEscape($offset) : $offset;
        $interval = $this->mysqlEscape($interval);
        $insert = "";
        if (!is_null($offset)) {
        	$insert = trim($offset);         
        }
        $this->limitPart = " LIMIT " . $insert . ", " . trim($interval);
        return $this;
    }

    /*
     *  $column @string: column name in the database
     *  $if_desc @int: null or 1
     */

    public function orderBy($column, $ifDesc = NULL) {
    	$this->orderPart = "";
        $column = $this->mysqlEscape($column);
        $desc = "";
        if ($ifDesc != NULL) {
        	$desc = " DESC";
        }
        $this->orderPart .= " ORDER BY " . $column . $desc;
        return $this;
    }
    
    /*
     *  $column @string: column name in the database
     */
    public function groupBy($column) {
    	$this->groupPart = "";
        $column = $this->mysqlEscape($column);
        $this->groupPart = " GROUP BY " . $column;
        return $this;
    }
    
    
    public function execute() {

        $con = mysql_connect($this->dbConfigArray['host'],$this->dbConfigArray['id'],$this->dbConfigArray['pwd']);
        mysql_set_charset($this->dbConfigArray['encoding'] ,$con);
                
        if (!$con) {
            die('Could not connect: ' . mysql_error());
        }
        mysql_select_db($this->dbConfigArray['database'], $con);
        $mysql_results = mysql_query($this->getStatement());
        
        if (!$mysql_results) {
            die('Could not query:' . mysql_error());
        }
        mysql_close($con);
        $this->cleanAll();
        
        return $mysql_results;
    }

    /**
     * @return the $dbConfigArray
     */
    public function getdbConfigArray() {
        return $this->dbConfigArray;
    }

    /**
     * @return the $queryStmt
     */
    public function getStatement() {
        //Combine every part of the query statement
        switch ($this->queryType) {
            case "SELECT":
                $this->queryStmt = null;
                $this->queryStmt = $this->selectPart . $this->joinPart . $this->joinOnPart
                        . $this->wherePart . $this->groupPart . $this->orderPart . $this->limitPart; 
                break;
            case "UPDATE":
                $this->queryStmt = null;
                $this->queryStmt = $this->updatePart . $this->wherePart;
                break;
            case "INSERT":
                $this->queryStmt = null;
                $this->queryStmt = $this->insertPart;
                break;
            case "DELETE":
                $this->queryStmt = null;
                $this->queryStmt = $this->deletePart . $this->wherePart;
                break;
        }
        return $this->queryStmt;
    }
    
    public function getSelectStatement(){
        if ($this->queryType != "SELECT") {
            return null;
        }
        $this->selectStatement = null;
        $this->selectStatement = $this->selectPart . $this->joinPart . $this->joinOnPart
                           . $this->wherePart . $this->groupPart . $this->orderPart . $this->limitPart;         
        return $this->selectStatement;
    }
    
    public function cleanAll(){
        $this->queryType = "";
        $this->selectPart = "";
        $this->joinPart = "";
        $this->joinOnPart = "";
        $this->wherePart = "";
        $this->orderPart = "";
        $this->limitPart = "";
        $this->updatePart = "";
        $this->insertPart = "";
        $this->deletePart = "";
        $this->groupPart = "";
    }

    /**
     * @param field_type $dbConfigArray
     */
    public function setdbConfigArray($dbConfigArray) {
        $this->dbConfigArray = $dbConfigArray;
    }

    /**
     * @param field_type $queryStmt
     */
    public function setStatement($queryStmt) {
        $this->queryStmt = $queryStmt;
    }

    public function setKeywords() {
        $this->keywords['CURRENT_TIMESTAMP'] = "CURRENT_TIMESTAMP";
    }

    function mysqlEscape($content) {
        /**
         * Need to add connection in order to avoid ODBC errors here 
         */
        $con = mysql_connect($this->dbConfigArray['host'],$this->dbConfigArray['id'],$this->dbConfigArray['pwd']);
        mysql_set_charset($this->dbConfigArray['encoding'] ,$con);
        //check if $content is an array
        if (is_array($content)) {
            foreach ($content as $key => $value) {
                $content[$key] = mysql_real_escape_string($value);
            }
        } else {
            //check if $content is not an array
            $content = mysql_real_escape_string($content);
        }
        mysql_close($con);
        return $content;
    }
    
    //The following getter is for unit tests
    
	/**
	 * @return the $selectPart
	 */
	public function getSelectPart() {
		return $this->selectPart;
	}

	/**
	 * @return the $updatePart
	 */
	public function getUpdatePart() {
		return $this->updatePart;
	}

	/**
	 * @return the $deletePart
	 */
	public function getDeletePart() {
		return $this->deletePart;
	}

	/**
	 * @return the $insertPart
	 */
	public function getInsertPart() {
		return $this->insertPart;
	}

	/**
	 * @return the $joinPart
	 */
	public function getJoinPart() {
		return $this->joinPart;
	}

	/**
	 * @return the $joinOnPart
	 */
	public function getJoinOnPart() {
		return $this->joinOnPart;
	}

	/**
	 * @return the $wherePart
	 */
	public function getWherePart() {
		return $this->wherePart;
	}

	/**
	 * @return the $orderPart
	 */
	public function getOrderPart() {
		return $this->orderPart;
	}

	/**
	 * @return the $groupPart
	 */
	public function getGroupPart() {
		return $this->groupPart;
	}

	/**
	 * @return the $limitPart
	 */
	public function getLimitPart() {
		return $this->limitPart;
	}


 

}

?>
