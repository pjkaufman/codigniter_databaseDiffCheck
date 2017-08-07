<?php

if (!defined('BASEPATH'))
    exit('No direct script access allowed');

class Compare extends MX_Controller
{

    function __construct()
    {
        parent::__construct();
        $this->DB1 = $this->load->database('default', TRUE); // load the source/development database
        $this->DB2 = $this->load->database('live', TRUE); // load the destination/live database
        $this->index1 = array();
        $this->index2 = array();
    }

    function index()
    {
      /*
       * This will become a list of SQL Commands to run on the Live database to bring it up to date
       */
      $sql_commands_to_run = array();

      /*
       * list the tables from both databases
       */
      $development_tables = $this->DB1->list_tables();
      $live_tables = $this->DB2->list_tables();
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
       * Before comparing tables, remove any tables from the list that will be created in the $tables_to_create array
       */
      $tables_to_update = array_diff($tables_to_update, $tables_to_create);
      /*
       * update tables, add/update/emove columns
       */
      $results = (is_array($tables_to_update) && !empty($tables_to_update)) ? $this->update_existing_tables($tables_to_update) : array() ;
      $sql_commands_to_run = array_merge($sql_commands_to_run, $results);
      /*
       * add, and or drop indices
       */
       $this->get_indices();
       $results = $this->manage_indices($this->index1, $this->index2);
       $sql_commands_to_run = array_merge($sql_commands_to_run, $results);
      if (is_array($sql_commands_to_run) && !empty($sql_commands_to_run))
      {
          echo "<h2>The database is out of Sync!</h2>\n";
          echo "<p>The following SQL commands need to be executed to bring the Live database tables up to date: </p>\n";
          echo "<pre style='padding: 20px; background-color: #FFFAF0;'>\n";
          foreach ($sql_commands_to_run as $sql_command)
          {
              echo "$sql_command\n";
          }
          echo "<pre>\n";
      }
      else
      {
          echo "<h2>The database appears to be up to date</h2>\n";
      }
      }
      function get_indices(){
        // author of the sql: Aaron Brown, link: http://blog.9minutesnooze.com/mysql-information-schema-indexes/
        $sql = "SELECT t.name AS `Table`, i.name AS `Index`, i.TYPE, GROUP_CONCAT(f.name ORDER BY f.pos) AS `Columns` FROM information_schema.innodb_sys_tables t JOIN information_schema.innodb_sys_indexes i USING (table_id) JOIN information_schema.innodb_sys_fields f USING (index_id) WHERE t.name LIKE '" . $this->DB1->database . "/%' GROUP BY 1,2;";
        $indices = $this->DB1->query($sql)->result();
        $id = 1;
        foreach ($indices as $index){
          $index = (array)$index;
          $index['Table'] = str_replace($this->DB1->database . '/', '', $index['Table']);
          $this->index1[$index['Table'] . '-' . $index['Index']] = array(
              'table'   =>  $index['Table'],
              'index'   =>  $index['Index'],
              'column'  =>  $index['Columns'],
              'type'    =>  $index['TYPE'],
          );
        }
        $sql = "SELECT t.name AS `Table`, i.name AS `Index`, i.TYPE, GROUP_CONCAT(f.name ORDER BY f.pos) AS `Columns` FROM information_schema.innodb_sys_tables t JOIN information_schema.innodb_sys_indexes i USING (table_id) JOIN information_schema.innodb_sys_fields f USING (index_id) WHERE t.name LIKE '" . $this->DB2->database . "/%' GROUP BY 1,2;";
        $indices = $this->DB2->query($sql)->result();
        foreach ($indices as $index){
          $index = (array)$index;
          $index['Table'] = str_replace($this->DB2->database . '/', '', $index['Table']);
          $this->index2[$index['Table'] . '-' . $index['Index']] = array(
              'table'   =>  $index['Table'],
              'index'   =>  $index['Index'],
              'column'  =>  $index['Columns'],
              'type'    =>  $index['TYPE'],
          );
        }
      }
      /**
      * Manage indices, add or drop them
      * @param array indices of db 1
      * @param array indices of db 2
      * @return array $sql_commands_to_run
      */
      function manage_indices($indices1, $indices2)
      {
        $indices_present = array();
        $indices_missing = array();
        $sql_commands_to_run = array();
        foreach ($indices1 as $index)
        {
          if(in_array($index, $indices2))
          {
            $indices_present[] = $index;
          }else
          {
            if($this->DB2->table_exists($index['table']))
            {
              $indices_missing[] = $index;
            }
          }
        }
        // check for unneeded indices
        foreach ($indices2 as $index)
        {
          if($this->DB1->table_exists($index['table']) && !(array_key_exists($index['table'] . '-' . $index['index'], $indices1)))
          {
              'ALTER TABLE `' . $indices_missing[$i]['table'] . '` DROP' . $this->get_type($indices_missing[$i]['type'], $indices_missing[$i], 1);
          }
        }
        for( $i = 0; $i < count($indices_missing); $i++)
        {
          if(array_key_exists($indices_missing[$i]['table'] . '-' . $indices_missing[$i]['index'], $this->index2))
          {
            $sql_commands_to_run[] = 'ALTER TABLE `' . $indices_missing[$i]['table'] . '` DROP' . $this->get_type($indices_missing[$i]['type'], $indices_missing[$i], 1);
            $sql_commands_to_run[] = 'ALTER TABLE `' . $indices_missing[$i]['table'] . '` ADD' . $this->get_type($indices_missing[$i]['type'], $indices_missing[$i], 0);
          } else
          {
            $sql_commands_to_run[] = 'ALTER TABLE `' . $indices_missing[$i]['table'] . '` ADD' . $this->get_type($indices_missing[$i]['type'], $indices_missing[$i], 0);
          }
        }

        return $sql_commands_to_run;
      }
      /**
       * @author Peter Kaufman
       * @description get_type returns the type of an index
       * @example get_type(3); returns PRIMARY
       * @access public
       * @param [int] $int is the integer type of the mysql index type
       * @param [array] $args is an array that conatians the needed info about an index
       * @param [int] $atmpt is an integer that determines what is returned
       * @return [string] a string that represents the mysql type and a little string
       */
      function get_type($int, $args, $atmpt)
      {
        $type;
        if($int == 3)
        {
          $type = ' PRIMARY KEY ';
        } elseif($int == 32)
        {
          $type = ' FULLTEXT INDEX ';
        } elseif($int == 64)
        {
          $type = ' SPATIAL INDEX ';
        } elseif($int == 2)
        {
          $type = ' UNIQUE INDEX ';
        } else
        {
          $type = ' INDEX ';
        }
        if($atmpt == 0 && $int == 3)
        {
          return $type . '(' . $args['column'] . ');';
        }elseif($atmpt == 0 && $int != 3)
        {
          return $type . ' `' . $args['index'] . '` (' . $args['column'] . ');';
        }elseif($atmpt == 1 && $int != 3)
        {
          return $type . '`' . $args['index'] . '`;';
        }else
        {
          return $type;
        }
      }

      /**
      * Manage tables, create or drop them
      * @param array $tables
      * @param string $action
      * @return array $sql_commands_to_run
      */
      function manage_tables($tables, $action)
      {
      $sql_commands_to_run = array();

      if ($action == 'create')
      {
          foreach ($tables as $table)
          {
              $query = $this->DB1->query("SHOW CREATE TABLE `$table` -- create tables");
              $table_structure = $query->row_array();
              $sql_commands_to_run[] = $table_structure["Create Table"] . ";";
          }
      }

      if ($action == 'drop')
      {
          foreach ($tables as $table)
          {
              $sql_commands_to_run[] = "DROP TABLE $table;";
          }
      }

      return $sql_commands_to_run;
      }

      /**
      * Go through each table, compare their sql structure
      * @param array $development_tables
      * @param array $live_tables
      */
      function compare_table_structures($development_tables, $live_tables)
      {
      $tables_need_updating = array();

      $live_table_structures = $development_table_structures = array();

      /*
       * generate the sql for each table in the development database
       */
      foreach ($development_tables as $table)
      {

          $query = $this->DB1->query("SHOW CREATE TABLE `$table` -- dev");
          $table_structure = $query->row_array();
          $development_table_structures[$table] = $table_structure["Create Table"];
      }

      /*
       * generate the sql for each table in the live database
       */
      foreach ($live_tables as $table)
      {
          $query = $this->DB2->query("SHOW CREATE TABLE `$table` -- live");
          $table_structure = $query->row_array();
          $live_table_structures[$table] = $table_structure["Create Table"];
      }

      /*
       * compare the development sql to the live sql
       */
      foreach ($development_tables as $table)
      {
          $development_table = $development_table_structures[$table];
          $live_table = (isset($live_table_structures[$table])) ? $live_table_structures[$table] : '';

          if ($this->count_differences($development_table, $live_table) > 0)
          {
              $tables_need_updating[] = $table;
          }
      }
      return $tables_need_updating;
      }

      /**
      * Count differences in 2 sql statements
      * @param string $old
      * @param string $new
      * @return int $differences
      */
      function count_differences($old, $new)
      {
      $differences = 0;
      $old = trim(preg_replace('/\s+/', '', $old));
      $new = trim(preg_replace('/\s+/', '', $new));

      if ($old == $new)
      {
          return $differences;
      }

      $old = explode(" ", $old);
      $new = explode(" ", $new);
      $length = max(count($old), count($new));

      for ($i = 0; $i < $length; $i++)
      {
          if ($old[$i] != $new[$i])
          {
              $differences++;
          }
      }

      return $differences;
      }

      /**
      * Given an array of tables that differ from DB1 to DB2, update DB2
      * @param array $tables
      */
      function update_existing_tables($tables)
      {
      $sql_commands_to_run = array();
      $table_structure_development = array();
      $table_structure_live = array();

      if (is_array($tables) && !empty($tables))
      {
          foreach ($tables as $table)
          {
              $table_structure_development[$table] = $this->table_field_data( $this->DB1, $table);
              $table_structure_live[$table] = $this->table_field_data( $this->DB2, $table);
          }
      }

      /*
       * add, remove or update any fields in $table_structure_live
       */
      $sql_commands_to_run = array_merge($sql_commands_to_run, $this->determine_field_changes($table_structure_development, $table_structure_live, 'regular'));
      $sql_commands_to_run = array_merge($sql_commands_to_run, $this->determine_field_changes($table_structure_live, $table_structure_development, 'reverse'));
      return $sql_commands_to_run;
      }

      /**
      * Given a database and a table, compile an array of field meta data
      * @param array $database
      * @param string $table
      * @return array $fields
      */
      function table_field_data($database, $table)
      {
      if($database->table_exists($table))
      {
      $result = $database->query("SELECT COLUMN_NAME as `name`,  COLUMN_TYPE as `type`, COLUMN_DEFAULT as `default`, EXTRA as `extra`, IS_NULLABLE,  AUTO_INCREMENT as `auto_increment` FROM information_schema.COLUMNS a  LEFT JOIN INFORMATION_SCHEMA.TABLES b ON a.TABLE_NAME = b.TABLE_NAME WHERE a.TABLE_SCHEMA = '" . $database->database . "' AND a.TABLE_NAME = '" . $table . "';")->result();

      foreach ($result as $row)
      {
          $fields[] = (array)$row;
      }
      }

      return $fields;
      }

      /**
      * Given to arrays of table fields, add/edit/remove fields
      * @param type $source_field_structures
      * @param type $destination_field_structures
      */
      function determine_field_changes($source_field_structures, $destination_field_structures, $type)
      {
      $sql_commands_to_run = array();
      if($type == 'regular'){
        /**
         * loop through the source (usually development) database
         */
        foreach ($source_field_structures as $table => $fields)
        {
            foreach ($fields as $field)
            {
                if ($this->in_array_recursive($field["name"], $destination_field_structures[$table]))
                {
                    $modify_field = '';
                    /*
                     * Check for required modifications
                     */
                    for ($n = 0; $n < count($fields); $n++)
                    {
                        if (isset($fields[$n]) && isset($destination_field_structures[$table][$n]) && ($fields[$n]["name"] == $destination_field_structures[$table][$n]["name"]))
                        {
                            $differences = array_diff($fields[$n], $destination_field_structures[$table][$n]);

                            if (is_array($differences) && !empty($differences))
                            {
                                // ALTER TABLE `bugs` MODIFY COLUMN `site_name`  varchar(255) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL AFTER `type`;
                                // ALTER TABLE `bugs` MODIFY COLUMN `message`  varchar(255) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL AFTER `site_name`;
                                $modify_field = "ALTER TABLE $table MODIFY COLUMN `" . $fields[$n]["name"] . "` " . $fields[$n]["type"];
                                $modify_field .= (isset($fields[$n]["IS_NULLABLE"]) && $fields[$n]["IS_NULLABLE"] != 'NO') ? ' NULL ' : ' NOT NULL ';
                                $modify_field .= (isset($fields[$n]["default"]) && $fields[$n]["default"] != NULL) ? ' DEFAULT \'' . $fields[$n]["default"] . '\'' : '';
                                $modify_field .= (isset($fields[$n]["extra"]) && $fields[$n]["extra"] != '') ?  ' INT ' . $fields[$n]["extra"] . ' = ' . $fields[$n]["auto_increment"] : '';
                                if ($n == 0) {
                                  $modify_field .= ';';
                                } else{
                                  $modify_field .= (isset($previous_field) && $previous_field != '') ? ' AFTER ' . $previous_field : '';
                                  $modify_field .= ';';
                                }
                            }
                            $previous_field = $fields[$n]["name"];
                        }

                        if ($modify_field != '' && !in_array($modify_field, $sql_commands_to_run)){
                            $sql_commands_to_run[] = $modify_field;
                          }
                    }
                }
                else
                {
                    /*
                     * Add
                     */
                    $add_field = "ALTER TABLE $table ADD COLUMN `" . $field["name"] . "` " . $field["type"];
                    $add_field .= (isset($fields["IS_NULLABLE"]) && $fields["IS_NULLABLE"] != 'NO') ? ' NULL ' : ' NOT NULL ';
                    $add_field .= (isset($field["default"]) && $field["default"] != null) ? ' DEFAULT \'' . $field["default"] . '\'' : '';
                    $add_field .= (isset($previous_field) && $previous_field != '') ? ' AFTER ' . $previous_field : '';
                    $add_field .= (isset($fields["extra"]) && $fields["extra"] != '') ?  ' INT ' . $fields["extra"] . ' = ' . $fields["auto_increment"] : '';
                    $add_field .= ';';
                    $sql_commands_to_run[] = $add_field;
                }
            }
        }
      }else{
      {
        /**
         * loop through the source (usually development) database
         */
        foreach ($source_field_structures as $table => $fields)
        {
            foreach ($fields as $field)
            {
              /*
               * DELETE COLUMN
               */
                if($this->in_array_recursive($field["name"], $destination_field_structures[$table])){
                  // shouldn't be acted upon because the column is in the original file
              }else{
                $delete_field = "ALTER TABLE $table DROP COLUMN `" . $field["name"] . "`;";
                $sql_commands_to_run[] = $delete_field;
              }
            }
        }
      }
      }
      return $sql_commands_to_run;
      }

      /**
      * Recursive version of in_array
      * @param type $needle
      * @param type $haystack
      * @param type $strict
      * @return boolean
      */
      function in_array_recursive($needle, $haystack, $strict = false)
      {
      foreach ($haystack as $array => $item)
      {
          $item = $item["name"]; // look in the name field only
          if (($strict ? $item === $needle : $item == $needle) || (is_array($item) && in_array_recursive($needle, $item, $strict)))
          {
              return true;
          }
      }

      return false;
      }

      }
?>
