<?php
#namespace ppi\Database\mysql;

/*
 * Session Management for PHP3
 *
 * Copyright (c) 1998-2000 NetUSE AG
 *                    Boris Erdmann, Kristian Koehntopp
 * Updated 2005 to use mysqli by Philip Ide
 * Various support functions added since, particularly shortcut functions and object-based records
 *
 * $Id: db_mysql.inc,v 1.2 2000/07/12 18:22:34 kk Exp $
 *
 */ 

class DB_Sql {
  
  /* public: connection parameters */
  var $ctype = __NAMESPACE__;
  var $Host     = "";
  var $Database = "";
  var $User     = "";
  var $Password = "";

  /* public: configuration parameters */
  var $Auto_Free     = 0;     ## Set to 1 for automatic mysql_free_result()
  var $Debug         = 0;     ## Set to 1 for debugging messages.
  var $Halt_On_Error = "yes"; ## "yes" (halt with message), "no" (ignore errors quietly), "report" (ignore errror, but spit a warning)
  var $Seq_Table     = "db_sequence";

  /* public: result array and current row number */
  var $Record   = array();
  var $Row;

  /* public: current error number and error text */
  var $Errno    = 0;
  var $Error    = "";

  /* public: this is an api revision, not a CVS revision. */
  var $type     = "mysql";
  var $revision = "1.2";

  /* private: link and query handles */
  var $Link_ID  = 0;
  var $Query_ID = 0;
  


  /* public: constructor */
  function DB_Sql($query = "") {
      $this->query($query);
  }

  /* public: some trivial reporting */
  function link_id() {
    return $this->Link_ID;
  }

  function query_id() {
    return $this->Query_ID;
  }

  /* public: connection management */
  function connect($Database = "", $Host = "", $User = "", $Password = "") {
    /* Handle defaults */
    if ("" == $Database)
      $Database = $this->Database;
    if ("" == $Host)
      $Host     = $this->Host;
    if ("" == $User)
      $User     = $this->User;
    if ("" == $Password)
      $Password = $this->Password;
      
    /* establish connection, select database */
    if ( !$this->Link_ID ) {
      $this->Link_ID=mysqli_connect($Host, $User, $Password, $Database);
      if (!$this->Link_ID) {
        $this->halt("connect($Host, $User, \$Password) failed.");
        return 0;
      }

    }
    
    return $this->Link_ID;
  }

  /* public: discard the query result */
  function free() {
      //@mysql_free_result($this->Query_ID);
    //mysqli_stmt_free_result($this->Query_ID);
    if( is_object( $this->Query_ID ) ){
      $this->Query_ID->close();
      $this->Query_ID = 0;
    }
  }

  function escape_string( $string = "" ){
    return mysqli_real_escape_string( $this->Link_ID, $string );
  }

  /* public: perform a query */
  function query($Query_String) {
    /* No empty queries, please, since PHP4 chokes on them. */
    if ($Query_String == "")
      /* The empty query string is passed on from the constructor,
       * when calling the class without a query, e.g. in situations
       * like these: '$db = new DB_Sql_Subclass;'
       */
      return 0;

    if (!$this->connect()) {
      return 0; /* we already complained in connect() about that. */
    };

    # New query, discard previous result.
    if ($this->Query_ID) {
      $this->free();
    }

    if ($this->Debug)
      printf("Debug: query = %s<br>\n", $Query_String);

    $this->Query_ID = mysqli_query( $this->Link_ID, $Query_String );
    $this->Row   = 0;
    /*
    $this->Errno = mysql_errno();
    $this->Error = mysql_error();
    */
    $this->Errno = mysqli_errno($this->Link_ID);
    $this->Error = mysqli_error($this->Link_ID);

    if (!$this->Query_ID) {
      $this->halt("Invalid SQL: ".$Query_String);
    }

    # Will return nada if it fails. That's fine.
    return $this->Query_ID;
  }

  function execute( $query_String ){
	  $this->query( $query_String );
    if( is_object( $this->Query_ID ) ){
	   return $this->next_record();
    }
    return $this->Query_ID;
  }

  function execute_as_obj( $query_String ){
      $this->query( $query_String );
      if( is_object( $this->Query_ID ) ){
        return $this->next_rec_as_obj();
      }
      return $this->Query_ID;
  }
  
  function insert_id()
  {
    return mysqli_insert_id($this->Link_ID);
  }


  /* public: walk result set */
  function next_record() {
    if (!$this->Query_ID) {
      $this->halt("next_record called with no query pending.");
      return 0;
    }

    $this->Record = mysqli_fetch_array($this->Query_ID);
    $this->Row   += 1;
    /*
    $this->Errno = mysql_errno();
    $this->Error = mysql_error();
    */
    $this->Errno = mysqli_errno($this->Link_ID);
    $this->Error = mysqli_error($this->Link_ID);

    $stat = is_array($this->Record);
    if (!$stat && $this->Auto_Free) {
      $this->free();
    }
    return $stat;
  }

  function locate( $col, $value ){
  	$a = 0;
  	while( $this->next_record() ){
  		$a++;
  		if( $this->f($col) == $value ){
  			return $a;
  		}
  	}
  	return 0;
  }

  function as_obj(){
    return json_decode( json_encode( $this->Record ) );
  }

  function next_rec_as_obj(){
    if( $this->next_record() ){
      return $this->as_obj();
    }
    return 0;
  }

  function fetchRecord(){
    return (object) $this->Record;
  }

  function fetchNextRecord(){
    if( $this->next_record() ){
      return $this->fetchRecord();
    }
    return false;
  }

  /* public: position in result set */
  function seek($pos = 0) {
    $status = mysqli_data_seek($this->Query_ID, $pos);
    if ($status)
      $this->Row = $pos;
    else {
      $this->halt("seek($pos) failed: result has ".$this->num_rows()." rows");

      /* half assed attempt to save the day, 
       * but do not consider this documented or even
       * desireable behaviour.
       */
      mysqli_data_seek($this->Query_ID, $this->num_rows());
      $this->Row = $this->num_rows;
      return 0;
    }

    return 1;
  }

  /* public: table locking */
  function lock($table, $mode="write") {
    $this->connect();
    
    $query="lock tables ";
    if (is_array($table)) {
      while (list($key,$value)=each($table)) {
        if ($key=="read" && $key!=0) {
          $query.="$value read, ";
        } else {
          $query.="$value $mode, ";
        }
      }
      $query=substr($query,0,-2);
    } else {
      $query.="$table $mode";
    }
    $res = mysqli_query( $this->Link_ID, $query );
    if (!$res) {
      $this->halt("lock($table, $mode) failed.");
      return 0;
    }
    return $res;
  }
  
  function unlock() {
    $this->connect();

    $res = mysqli_query( $this->Link_ID, "unlock tables");
    if (!$res) {
      $this->halt("unlock() failed.");
      return 0;
    }
    return $res;
  }


  /* public: evaluate the result (size, width) */
  function affected_rows() {
    return mysqli_affected_rows($this->Link_ID);
  }

  function num_rows() {
    return mysqli_num_rows($this->Query_ID);
  }

  function num_fields() {
    return mysqli_num_fields($this->Query_ID);
  }

  function record() {
    if( $this->Query_ID ){
      return $this->Record;
    }
    return false;
  }


  /* public: shorthand notation */
  function nf() {
    return $this->num_rows();
  }

  function np() {
    print $this->num_rows();
  }

  function f($Name) {
    if(isset($this->Record[$Name]))
      return $this->Record[$Name];
    else 
      return "";
  }

  function af( $flist ){
    $r = array();
    foreach( $flist as $fname ){
      $r[$fname] = $this->Record[$fname];
    }
    return $r;
  }

  function p($Name) {
    print $this->Record[$Name];
  }

  /* public: sequence numbers */
  function nextid($seq_name) {
    $this->connect();
    
    if ($this->lock($this->Seq_Table)) {
      /* get sequence number (locked) and increment */
      $q  = sprintf("select nextid from %s where seq_name = '%s'",
                $this->Seq_Table,
                $seq_name);
      $id  = mysqli_query($this->Link_ID, $q);
      $res = mysqli_fetch_array($id);
      
      /* No current value, make one */
      if (!is_array($res)) {
        $currentid = 0;
        $q = sprintf("insert into %s values('%s', %s)",
                 $this->Seq_Table,
                 $seq_name,
                 $currentid);
        $id = mysqli_query( $this->Link_ID, $q );
      } else {
        $currentid = $res["nextid"];
      }
      $nextid = $currentid + 1;
      $q = sprintf("update %s set nextid = '%s' where seq_name = '%s'",
               $this->Seq_Table,
               $nextid,
               $seq_name);
      $id = mysqli_query( $this->Link_ID, $q );
      $this->unlock();
    } else {
      $this->halt("cannot lock ".$this->Seq_Table." - has it been created?");
      return 0;
    }
    return $nextid;
  }

  /* public: return table metadata */
  function metadata($table='',$full=false) {
    $count = 0;
    $id    = 0;
    $res   = array();

    /*
     * Due to compatibility problems with Table we changed the behavior
     * of metadata();
     * depending on $full, metadata returns the following values:
     *
     * - full is false (default):
     * $result[]:
     *   [0]["table"]  table name
     *   [0]["name"]   field name
     *   [0]["type"]   field type
     *   [0]["len"]    field length
     *   [0]["flags"]  field flags
     *
     * - full is true
     * $result[]:
     *   ["num_fields"] number of metadata records
     *   [0]["table"]  table name
     *   [0]["name"]   field name
     *   [0]["type"]   field type
     *   [0]["len"]    field length
     *   [0]["flags"]  field flags
     *   ["meta"][field name]  index of field named "field name"
     *   The last one is used, if you have a field name, but no index.
     *   Test:  if (isset($result['meta']['myfield'])) { ...
     */

    // if no $table specified, assume that we are working with a query
    // result
    if ($table) {
      $this->connect();
      $id = @mysql_list_fields($this->Database, $table);
      if (!$id)
        $this->halt("Metadata query failed.");
    } else {
      $id = $this->Query_ID; 
      if (!$id)
        $this->halt("No query specified.");
    }
 
    $count = @mysql_num_fields($id);

    // made this IF due to performance (one if is faster than $count if's)
    if (!$full) {
      for ($i=0; $i<$count; $i++) {
        $res[$i]["table"] = @mysql_field_table ($id, $i);
        $res[$i]["name"]  = @mysql_field_name  ($id, $i);
        $res[$i]["type"]  = @mysql_field_type  ($id, $i);
        $res[$i]["len"]   = @mysql_field_len   ($id, $i);
        $res[$i]["flags"] = @mysql_field_flags ($id, $i);
      }
    } else { // full
      $res["num_fields"]= $count;
    
      for ($i=0; $i<$count; $i++) {
        $res[$i]["table"] = @mysql_field_table ($id, $i);
        $res[$i]["name"]  = @mysql_field_name  ($id, $i);
        $res[$i]["type"]  = @mysql_field_type  ($id, $i);
        $res[$i]["len"]   = @mysql_field_len   ($id, $i);
        $res[$i]["flags"] = @mysql_field_flags ($id, $i);
        $res["meta"][$res[$i]["name"]] = $i;
      }
    }
    
    // free the result only if we were called on a table
    if ($table) @mysql_free_result($id);
    return $res;
  }

  /* private: error handling */
  function halt($msg) {
    $this->Error = mysqli_error($this->Link_ID);
    $this->Errno = mysqli_errno($this->Link_ID);
    if ($this->Halt_On_Error == "no")
      return;

    $this->haltmsg($msg);
    $this->sqlerror($msg);
    
    if ($this->Halt_On_Error != "report")
      die("<br>Session halted.");
  }

  function haltmsg($msg) {
    printf("\n<br>Oh dear, we got an error");
    //printf("</td></tr></table><b>Database error:</b> %s<br>\n", $msg);
    printf("\n<b>MySQL Error</b>: %s (%s)<br>\n",
      $this->Errno,
      $this->Error);

  }

  function table_names() {
    $this->query("SHOW TABLES");
    $i=0;
    while ($info=mysqli_fetch_row($this->Query_ID))
     {
      $return[$i]["table_name"]= $info[0];
      $return[$i]["tablespace_name"]=$this->Database;
      $return[$i]["database"]=$this->Database;
      $i++;
     }
   return $return;
  }

	function sqlerror( $data ){
		$en = $this->Errno;
		$em = $this->Error;
		$msg = "Database error: $data\nMySQL Error: $en ($em)";
		file_put_contents('sql_error', "\n$data", FILE_APPEND );
	}
}

?>
