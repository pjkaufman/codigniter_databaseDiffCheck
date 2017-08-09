<?php
class db_compare{

  function __construct($db1, $db2){

    $this->db1 = $db1['db'];
    $this->db2 = $db2['db'];
    $this->DB1 = mysqli_connect($db1['host'],$db1['username'],$db1['password'],$db1['db']);
    $this->DB2 = mysqli_connect($db2['host'],$db2['username'],$db2['password'],$db2['db']);
    $this->tables1 = array();
    $this->tables2 = array();
    $this->exclude = array();

    if (mysqli_connect_errno()){
      echo "Failed to connect to MySQL: " . mysqli_connect_error();
    }

    $this->compare();
  }

  private function compare(){

  /*
   * This will become a list of SQL Commands to run on the Live database to bring it up to date
   */
  $sql_commands_to_run = array();
  /*
   * list the tables from both databases
   */
  $result = mysqli_query($this->DB1, "SHOW TABLES");
  $development_tables = $this->array_2d_to_1d(mysqli_fetch_all($result, MYSQLI_NUM));
  $result = mysqli_query($this->DB2, "SHOW TABLES");
  $live_tables = $this->array_2d_to_1d(mysqli_fetch_all($result, MYSQLI_NUM));
  /*
   * list any tables that need to be created or dropped
   */
  $tables_to_create = array_diff($development_tables, $live_tables);
  $tables_to_drop = array_diff($live_tables, $development_tables);
  /**
   * Create/Drop any tables that are not in the Live database
   */
  $results =(is_array($tables_to_create) && !empty($tables_to_create)) ? $this->manage_tables($tables_to_create, 'create') : array() ;
  $sql_commands_to_run = array_merge($sql_commands_to_run, $results);
  $results = (is_array($tables_to_drop) && !empty($tables_to_drop)) ? $this->manage_tables($tables_to_drop, 'drop') : array() ;
  $sql_commands_to_run = array_merge($sql_commands_to_run, $results);
  $tables_to_update = $this->compare_table_structures($development_tables, $live_tables);
  /*
   * Before comparing tables, remove any tables from the list that will be created or dropped in the $tables_to_create array
   */
  $tables_to_update = array_diff($tables_to_update, $tables_to_create);
  $tables_to_update = array_diff($tables_to_update, $tables_to_drop);
  $this->exclude = array_merge($tables_to_drop, $tables_to_create);
  /*
   * update tables, add/update/remove columns
   */
  $results = (is_array($tables_to_update) && !empty($tables_to_update)) ? $this->update_existing_tables($tables_to_update) : array() ;
  $sql_commands_to_run = array_merge($sql_commands_to_run, $results);
  /*
   * add, and or drop indices
   */
   $this->get_indices();
   $results = $this->manage_indices($this->index1, $this->index2);
   $sql_commands_to_run = array_merge($sql_commands_to_run, $results);

  if (is_array($sql_commands_to_run) && !empty($sql_commands_to_run)){

    echo "<h2>The database is out of Sync!</h2>\n";
    echo "<p>The following SQL commands need to be executed to bring the Live database tables up to date: </p>\n";
    echo "<pre style='padding: 20px; background-color: #FFFAF0;'>\n";

    foreach ($sql_commands_to_run as $sql_command){

      echo "$sql_command\n";
    }

    echo "<pre>\n";
  }else{

    echo "<h2>The database appears to be up to date</h2>\n";
  }
  }
  /**
  * @author Doug
  * @description array_2d_to_1d converts a 2d array to a 1d array
  * @link https://stackoverflow.com/questions/6914105/2d-multidimensional-array-to-1d-array-in-php
  * @example array_2d_to_1d($result); returns 1d array
  * @access private
  * @param [array] $input_array is a 2d array to be converted to a 1d array
  * @return [array] $output_array is a 1d array
  */
  private function array_2d_to_1d ($input_array) {

    $output_array = array();

    for ($i = 0; $i < count($input_array); $i++) {
      for ($j = 0; $j < count($input_array[$i]); $j++) {

        $output_array[] = $input_array[$i][$j];
      }
    }

    return $output_array;
}
  /**
  * @author Peter Kaufman
  * @description get_indices gets each index and puts it in one of two arrays
  * author of the sql: Aaron Brown, @link http://blog.9minutesnooze.com/mysql-information-schema-indexes/
  * @example get_indices();
  * @access private
  * @return void
  */
  private function get_indices() {

    $sql = "SELECT t.`name` AS `Table`, i.`name` AS `Index`, i.`TYPE`, GROUP_CONCAT(f.`name` ORDER BY f.`pos`) AS `Columns` FROM information_schema.innodb_sys_tables t JOIN information_schema.innodb_sys_indexes i USING (`table_id`) JOIN information_schema.innodb_sys_fields f USING (`index_id`) WHERE t.`name` LIKE '" . $this->db1 . "/%' GROUP BY 1,2;";
    $indices = mysqli_fetch_all($this->DB1->query($sql), MYSQLI_ASSOC);
    $id = 1;
    foreach ($indices as $index) {

      $index = (array)$index;
      $index['Table'] = str_replace($this->db1 . '/', '', $index['Table']);
      $this->index1[$index['Table'] . '-' . $index['Index']] = array(
          'table'   =>  $index['Table'],
          'index'   =>  $index['Index'],
          'column'  =>  $index['Columns'],
          'type'    =>  $index['TYPE'],
      );
    }

    $sql = "SELECT t.`name` AS `Table`, i.`name` AS `Index`, i.`TYPE`, GROUP_CONCAT(f.`name` ORDER BY f.`pos`) AS `Columns` FROM information_schema.innodb_sys_tables t JOIN information_schema.innodb_sys_indexes i USING (`table_id`) JOIN information_schema.innodb_sys_fields f USING (`index_id`) WHERE t.`name` LIKE '" . $this->db2 . "/%' GROUP BY 1,2;";
    $indices = mysqli_fetch_all($this->DB2->query($sql), MYSQLI_ASSOC);
    foreach ($indices as $index) {

      $index = (array)$index;
      $index['Table'] = str_replace($this->db2 . '/', '', $index['Table']);
      $this->index2[$index['Table'] . '-' . $index['Index']] = array(
          'table'   =>  $index['Table'],
          'index'   =>  $index['Index'],
          'column'  =>  $index['Columns'],
          'type'    =>  $index['TYPE'],
      );
    }
  }
  /**
  * @author Peter Kaufman
  * @description manage_indices gets each index and then decides which index to be output and which ones to add or drop
  * @example manage_indices($indices1, $indices2);
  * @access private
  * @param [array] $indices1 is the indices of db 1
  * @param [array] $indices2 is the indices of db 2
  * @return [array] $sql_commands_to_run is a an array that represents the mysql type and a little string to add and or drop the index
  */
  private function manage_indices($indices1, $indices2) {

    $indices_present = array();
    $indices_missing = array();
    $sql_commands_to_run = array();
    foreach ($indices1 as $index) {

      if(in_array($index['table'], $this->exclude)) {
        // do nothing
      }else {
        if(in_array($index, $indices2)) {

          $indices_present[] = $index;
        }else {

          $indices_missing[] = $index;
        }
      }
    }
    // check for unneeded indices
    foreach ($indices2 as $index) {
      if(!(array_key_exists($index['table'] . '-' . $index['index'], $indices1))) {

          $sql_commands_to_run[] = 'ALTER TABLE `' . $index['table'] . '` DROP' . $this->get_type($index['type'], $index, 1);
      }
    }

    for( $i = 0; $i < count($indices_missing); $i++) {

      if(array_key_exists($indices_missing[$i]['table'] . '-' . $indices_missing[$i]['index'], $this->index2)) {

        $sql_commands_to_run[] = 'ALTER TABLE `' . $indices_missing[$i]['table'] . '` DROP' . $this->get_type($indices_missing[$i]['type'], $indices_missing[$i], 1);
        $sql_commands_to_run[] = 'ALTER TABLE `' . $indices_missing[$i]['table'] . '` ADD' . $this->get_type($indices_missing[$i]['type'], $indices_missing[$i], 0);
      }else {

        $sql_commands_to_run[] = 'ALTER TABLE `' . $indices_missing[$i]['table'] . '` ADD' . $this->get_type($indices_missing[$i]['type'], $indices_missing[$i], 0);
      }
    }

    return $sql_commands_to_run;
  }
  /**
  * @author Peter Kaufman
  * @description get_type returns the type of an index
  * @example get_type(3, $args, 0); returns PRIMARY KEY ( *column to add an index to* );
  * @access private
  * @param [int] $int is the integer type of the mysql index type
  * @param [array] $args is an array that contains the needed info about an index
  * @param [int] $atmpt is an integer that determines what is returned
  * @return [string] $type is a string that represents the mysql type and a little string
  */
  private function get_type($int, $args, $atmpt) {
    $type;
    $column = '';
    if($int == 3) {

      $type = ' PRIMARY KEY ';
    }else {

      $type = ' INDEX ';
    }

    $columns = explode(',', $args['column']);
    for ($i = 0; $i < count($columns); $i++) {
      if ($i < count($columns) - 1) {

        $column .= '`' . $columns[$i] . '`, ';
      }else {

        $column .= '`' . $columns[$i] . '`';
      }
    }

    if($atmpt == 0 && $int == 3) {

      return $type . '(' . $column . ');';
    }elseif($atmpt == 0 && $int != 3) {

      return $type . ' `' . $args['index'] . '` (' . $column . ');';
    }elseif($atmpt == 1 && $int != 3) {

      return $type . '`' . $args['index'] . '`;';
    }else {

      return $type;
    }
  }
  /**
  * @author Peter Kaufman
  * @description manage_tables creates or drops them
  * @example manage_tables($tables, $action);
  * @access private
  * @param [array] $tables is an array of tables in a db
  * @param [string] $action is as tring which determines whether or not the table will be created or dropped
  * @return [array] $sql_commands_to_run is an array that represents the mysql code to execute to create or drop tables
  */
  private function manage_tables($tables, $action) {

    $sql_commands_to_run = array();
    if ($action == 'create') {

        foreach ($tables as $table) {

            $query = $this->DB1->query("SHOW CREATE TABLE `$table` -- create tables");
            $table_structure = $this->array_2d_to_1d(mysqli_fetch_all($query, MYSQLI_NUM));
            array_shift($table_structure);
            $sql_commands_to_run[] = $table_structure[0] . ";";
        }
    }

    if ($action == 'drop') {
        foreach ($tables as $table) {

            $sql_commands_to_run[] = "DROP TABLE $table;";
        }
    }

    return $sql_commands_to_run;
  }
  /**
  * @author Gordon Murray
  * @description compare_table_structures compares table structures and returns an array of tables to update
  * @example compare_table_structures($development_tables, $live_tables);
  * @access private
  * @param [array] $development_tables is an array of all tables from the desired db that are present in the db to change
  * @param [array] $live_tables is an array tof all tables not to be dropped in the db to change
  * @return [array] $tables_need_updating is an array that contains the name of the table(s) that need to be updated
  */
  private function compare_table_structures($development_tables, $live_tables) {
    $tables_need_updating = array();
    $live_table_structures = $development_table_structures = array();
    /*
     * generate the sql for each table in the development database
     */
    foreach ($development_tables as $table) {

        $query = $this->DB1->query("SHOW CREATE TABLE `$table` -- dev");
        $table_structure = $this->array_2d_to_1d(mysqli_fetch_all($query, MYSQLI_NUM));
        array_shift($table_structure);
        $development_table_structures[$table] = $table_structure[0];
    }
    /*
     * generate the sql for each table in the live database
     */
    foreach ($live_tables as $table) {

        $query = $this->DB2->query("SHOW CREATE TABLE `$table` -- live");
        $table_structure = $this->array_2d_to_1d(mysqli_fetch_all($query, MYSQLI_NUM));
        array_shift($table_structure);
        $live_table_structures[$table] = $table_structure[0];
    }
    /*
     * compare the development sql to the live sql
     */
    foreach ($development_tables as $table) {

      $development_table = $development_table_structures[$table];
      $live_table = (isset($live_table_structures[$table])) ? $live_table_structures[$table] : '';

      if ($this->count_differences($development_table, $live_table) > 0) {

        $tables_need_updating[] = $table;
      }
    }

    return $tables_need_updating;
  }
  /**
  * @author Gordon Murray
  * @description compare_table_structures compares table structures and returns an array of tables to update
  * @example count_differences('blob', 'ou foo'); returns 6
  * @access private
  * @param [string] $old is a string that represents the old string
  * @param [string] $new is a string that represents the newstring
  * @return [int] $differences is an integer that represents the amount of differences in the strings
  */
  private function count_differences($old, $new) {

    $differences = 0;
    $old = trim(preg_replace('/\s+/', '', $old));
    $new = trim(preg_replace('/\s+/', '', $new));

    if ($old == $new) {

      return $differences;
    }

    $old = explode(" ", $old);
    $new = explode(" ", $new);
    $length = max(count($old), count($new));
    for ($i = 0; $i < $length; $i++) {
      if ($old[$i] != $new[$i]) {

          $differences++;
      }
    }

    return $differences;
  }
  /**
  * @author Gordon Murray && Peter Kaufman
  * @description update_existing_tables updates the existing records by modifying, adding, and removing columns
  * @example update_existing_tables($tables); returns *the sql needed to update the desired db's tables*
  * @access private
  * @param [array] $tables is an array of tables that are not the same in the two db's
  * @return [array] $sql_commands_to_run is an array that represents the sql needed to update the desired db's tables
  */
  private function update_existing_tables($tables) {

    $sql_commands_to_run = array();
    $table_structure_development = array();
    $table_structure_live = array();
    $this->table_field_data($this->DB1, $tables, 0);
    $this->table_field_data($this->DB2, $tables, 1);
    /*
     * add, remove or update any fields in $table_structure_live
     */
    $sql_commands_to_run = array_merge($sql_commands_to_run, $this->determine_field_changes('regular'));
    $sql_commands_to_run = array_merge($sql_commands_to_run, $this->determine_field_changes('reverse'));

    return $sql_commands_to_run;
  }
  /**
  * @author Gordon Murray && Peter Kaufman
  * @description table_field_data when given a database and a table, compile an array of field meta data
  * @example table_field_data($database, $tables, $db) returns nothing
  * @access private
  * @param [array] $database is an array of the db having its meta data collected
  * @param [array] $tables is an array of tables to update
  * @param [int] $db tells the function which variable to store the db meta data in
  * @return void
  */
  private function table_field_data($database, $tables, $db) {

    $db_name;

    if($db == 0) {

      $db_name = $this->db1;
    }else {

      $db_name = $this->db2;
    }

    $result = mysqli_fetch_all($database->query("SELECT DISTINCT(CONCAT(a.`TABLE_NAME`, `COLUMN_NAME`)) as 'distinct', a.`TABLE_NAME` as `table`, `COLUMN_NAME` as `name`,  `COLUMN_TYPE` as `type`, `COLUMN_DEFAULT` as `default`, `EXTRA` as `extra`, `IS_NULLABLE`,  `AUTO_INCREMENT` as `auto_increment` FROM information_schema.COLUMNS a  LEFT JOIN INFORMATION_SCHEMA.TABLES b ON a.`TABLE_NAME` = b.`TABLE_NAME` WHERE a.`TABLE_SCHEMA` = '" . $db_name . "' Order by a.`TABLE_NAME`;"), MYSQLI_ASSOC);
    foreach ($result as $row) {

      array_shift($row);
      if(!(in_array($row['table'], $this->exclude))) {
        if($db == 0) {

          $this->tables1[$row['table']][$row['name']] = $row;
        }else {

          $this->tables2[$row['table']][$row['name']] = $row;
        }
      }
    }
  }
  /**
  * @author Gordon Murray && Peter Kaufman
  * @description determine_field_changes edits table columns by adding, updating, and removing them
  * @example determine_field_changes($type) returns *the sql needed to update the desired db's tables*
  * @access private
  * @param [int] $type tells the function what type of table modification to do
  * @return [array] $sql_commands_to_run is an array that represents the sql to run to add, edit, or remove a column
  */
  private function determine_field_changes($type) {

    $sql_commands_to_run = array();
    if($type == 'regular') {

      $source_field_structures = $this->tables1;
      $destination_field_structures = $this->tables2;
      /**
       * loop through the source (usually development) database
       */
      foreach ($source_field_structures as $table => $fields) {

        $n = 0;
        foreach ($fields as $field) {
          if ($this->in_array_recursive($field['name'], $destination_field_structures[$table])) {

              $modify_field = '';
              /*
               * Check for required modifications
               */
               if (isset($fields[$field['name']]) && isset($destination_field_structures[$table][$field['name']]) && ($field['name'] == $destination_field_structures[$table][$field['name']]['name'])) {

                 $differences = array_diff($field, $destination_field_structures[$table][$field['name']]);

                 if (is_array($differences) && !empty($differences)) {
                   // ALTER TABLE `bugs` MODIFY COLUMN `site_name` varchar(255) NULL DEFAULT NULL AFTER `type`;
                   $modify_field = "ALTER TABLE `$table` MODIFY COLUMN `" . $field['name'] . "` " . $field['type'];
                   $modify_field .= (isset($field['IS_NULLABLE']) && $field['IS_NULLABLE'] != 'NO') ? ' NULL ' : ' NOT NULL ';
                   $modify_field .= (isset($field['default']) && $field['default'] != NULL ) ? 'DEFAULT ' . $field['default'] . '' : 'DEFAULT NULL';
                   $modify_field .= (isset($field['extra']) && $field['extra'] != '') ? $field['extra'] : '';
                   if ($n == 0) {

                     $modify_field .= ';';
                   }else {

                     $modify_field .= (isset($previous_field) && $previous_field != '') ? ' AFTER `' . $previous_field . '`': '';
                     $modify_field .= ';';
                   }
                 }

                 $previous_field = $field['name'];
               }
               if ($modify_field != '' && !in_array($modify_field, $sql_commands_to_run)) {
                 if($field['extra'] != '' &&  $field['auto_increment'] != NULL) {

                   $sql_commands_to_run[] = $modify_field;
                   $sql_commands_to_run[] = "ALTER TABLE $table AUTO_INCREMENT " . $field['auto_increment'] . ";";
                 }else {

                   $sql_commands_to_run[] = $modify_field;
                 }
               }

               $n++;
             }else {
               /*
               * Add
               */
               $add_field = "ALTER TABLE `$table` ADD COLUMN `" . $field['name'] . "` " . $field['type'];
               $add_field .= (isset($fields['IS_NULLABLE']) && $fields['IS_NULLABLE'] != 'NO') ? ' NULL ' : ' NOT NULL ';
               $add_field .= (isset($field['default']) && $field['default'] != NULL ) ? 'DEFAULT ' . $field['default'] . '' : 'DEFAULT NULL';
               $add_field .= (isset($previous_field) && $previous_field != '') ? ' AFTER `' . $previous_field . '`' : '';
               $add_field .= (isset($fields['extra']) && $fields['extra'] != '') ?  ' INT ' . $fields['extra'] . ' = ' . $fields['auto_increment'] : '';
               $add_field .= ';';
               $sql_commands_to_run[] = $add_field;
            }
          }
        }
      }else {

        $source_field_structures = $this->tables2;
        $destination_field_structures = $this->tables1;
        /**
         * loop through the source (usually development) database
         */
        foreach ($source_field_structures as $table => $fields) {
          foreach ($fields as $field) {
            /*
             * DELETE COLUMN
             */
            if ($this->in_array_recursive($field['name'], $destination_field_structures[$table])) {
              // shouldn't be acted upon because the column is in the original file
            }else {

              $delete_field = "ALTER TABLE `$table` DROP COLUMN `" . $field['name'] . "`;";
              $sql_commands_to_run[] = $delete_field;
            }
          }
        }
      }

      return $sql_commands_to_run;
  }
  /**
  * @author Gordon Murray
  * @description in_array_recursive searches for a column in a table recursively
  * @example in_array_recursive($needle, $haystack,false); returns true if the needle is in the haystack and false otherwise
  * @access private
  * @param [array] $needle is an array that is the column to be searched for
  * @param [array] $haystack is an array that contains the db where the column will be searched for
  * @param [boolean] $strict determines how strict the comparison will be
  * @return [boolean] true if found, false otherwise
  */
  private function in_array_recursive($needle, $haystack, $strict = false) {

    foreach ($haystack as $array => $item) {

      $item = $item['name']; // look in the name field only

      if (($strict ? $item === $needle : $item == $needle) || (is_array($item) && in_array_recursive($needle, $item, $strict))) {

        return true;
      }
    }

    return false;
  }
  }
?>
