<?php

defined('BASEPATH') or exit('No direct script access allowed');
/**
 * Compare_model class.
 * @extends CI_Model
 */
class Compare_model extends CI_Model {
    /**
     * __construct function.
     */
    public function __construct() {
        parent::__construct();
        $this->set_dir();
        $this->load->database(); // load the destination/live database
        $this->DB2 = $this->get_db_snapshot(); // load the source/development database
        $this->index1 = array();
        $this->tables1 = array();
        $this->exclude = array();
    }

    /**
     * @author Peter Kaufman
     * @description get_indices gets each index and puts it in one of two arrays
     * author of the sql: Aaron Brown, @link http://blog.9minutesnooze.com/mysql-information-schema-indexes/
     * @example get_indices();
     */
    public function get_indices() {
        $sql = "SELECT t.`name` AS `Table`, i.`name` AS `Index`, i.`TYPE`, GROUP_CONCAT(f.`name` ORDER BY f.`pos`) AS `Columns` FROM information_schema.innodb_sys_tables t JOIN information_schema.innodb_sys_indexes i USING (`table_id`) JOIN information_schema.innodb_sys_fields f USING (`index_id`) WHERE t.`name` LIKE '" . $this->db->database . "/%' GROUP BY 1,2;";
        $indices = $this->db->query($sql)->result();
        $id = 1;

        foreach ($indices as $index) {
            $index = (array)$index;
            $index['Table'] = str_replace($this->db->database . '/', '', $index['Table']);
            $this->index1[$index['Table'] . '-' . $index['Index']] = array(
            'table' => $index['Table'],
            'index' => $index['Index'],
            'column' => $index['Columns'],
            'type' => $index['TYPE'],
        );
        }
    }

    /**
     * @author Peter Kaufman
     * @description manage_indices gets each index and then decides which index to be output and which ones to add or drop
     * @example manage_indices();
     * @return [array] $sql_commands_to_run is a an array that represents the mysql type and a little string to add and or drop the index
     */
    public function manage_indices() {
        $indices_present = array();
        $indices_missing = array();
        $sql_commands_to_run = array();

        foreach ($this->DB2['indices'] as  $index) {
            if (in_array($index['table'], $this->exclude)) {
                // do nothing
            } else {
                if (in_array($index, $this->index1)) {
                    $indices_present[] = $index;
                } else {
                    $indices_missing[] = $index;
                }
            }
        }
        // check for unneeded indices
        foreach ($this->index1 as $index) {
            if (!(array_key_exists($index['table'] . '-' . $index['index'], $this->DB2['indices'])) && !(in_array($index['table'], $this->exclude))) {
                $sql_commands_to_run[] = 'ALTER TABLE `' . $index['table'] . '` DROP' . $this->get_type($index['type'], $index, 1);
            }
        }

        for ($i = 0; $i < count($indices_missing); $i++) {
            if (array_key_exists($indices_missing[$i]['table'] . '-' . $indices_missing[$i]['index'], $this->index1)) {
                $sql_commands_to_run[] = 'ALTER TABLE `' . $indices_missing[$i]['table'] . '` DROP' . $this->get_type($indices_missing[$i]['type'], $indices_missing[$i], 1);
                $sql_commands_to_run[] = 'ALTER TABLE `' . $indices_missing[$i]['table'] . '` ADD' . $this->get_type($indices_missing[$i]['type'], $indices_missing[$i], 0);
            } else {
                $sql_commands_to_run[] = 'ALTER TABLE `' . $indices_missing[$i]['table'] . '` ADD' . $this->get_type($indices_missing[$i]['type'], $indices_missing[$i], 0);
            }
        }

        return $sql_commands_to_run;
    }

    /**
     * @author Peter Kaufman
     * @description get_type returns the type of an index
     * @example get_type(3, $args, 0); returns PRIMARY KEY ( *column to add an index to* );
     * @param  [int]    $int   is the integer type of the mysql index type
     * @param  [array]  $args  is an array that contains the needed info about an index
     * @param  [int]    $atmpt is an integer that determines what is returned
     * @return [string] $type is a string that represents the mysql type and a little string
     */
    public function get_type($int, $args, $atmpt) {
        $type;
        $column = '';

        if ($int == 3) {
            $type = ' PRIMARY KEY ';
        } else {
            $type = ' INDEX ';
        }
        $columns = explode(',', $args['column']);

        for ($i = 0; $i < count($columns); $i++) {
            if ($i < count($columns) - 1) {
                $column .= '`' . $columns[$i] . '`, ';
            } else {
                $column .= '`' . $columns[$i] . '`';
            }
        }

        if ($atmpt == 0 && $int == 3) {
            return $type . '(' . $column . ');';
        } elseif ($atmpt == 0 && $int != 3) {
            return $type . ' `' . $args['index'] . '` (' . $column . ');';
        } elseif ($atmpt == 1 && $int != 3) {
            return $type . '`' . $args['index'] . '`;';
        } else {
            return $type;
        }
    }

    /**
     * @author Peter Kaufman
     * @description manage_tables creates or drops them
     * @example manage_tables($tables, $action);
     * @param  [array]  $tables is an array of tables in a db
     * @param  [string] $action is as tring which determines whether or not the table will be created or dropped
     * @return [array]  $sql_commands_to_run is an array that represents the mysql code to execute to create or drop tables
     */
    public function manage_tables($tables, $action) {
        if ($action == 'create') {
            foreach ($tables as $table) {
                $sql_commands_to_run[] = $this->DB2['create'][$table]['Create Table'] . ';';
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
     * @param  [array] $development_tables is an array of all tables from the desired db that are present in the db to change
     * @param  [array] $live_tables        is an array tof all tables not to be dropped in the db to change
     * @return [array] $tables_need_updating is an array that contains the name of the table(s) that need to be updated
     */
    public function compare_table_structures($development_tables, $live_tables) {
        $tables_need_updating = array();
        $live_table_structures = $development_table_structures = array();
        /*
         * generate the sql for each table in the development database
         */
        foreach ($development_tables as $table) {
            $development_table_structures[$table] = $this->DB2['create'][$table]['Create Table'];
        }
        /*
         * generate the sql for each table in the live database
         */
        foreach ($live_tables as $table) {
            $query = $this->db->query("SHOW CREATE TABLE `$table` -- live");
            $table_structure = $query->row_array();
            $live_table_structures[$table] = $table_structure['Create Table'];
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
     * @param  [string] $old is a string that represents the old string
     * @param  [string] $new is a string that represents the newstring
     * @return [int]    $differences is an integer that represents the amount of differences in the strings
     */
    private function count_differences($old, $new) {
        $differences = 0;
        $old = trim(preg_replace('/\s+/', '', $old));
        $new = trim(preg_replace('/\s+/', '', $new));

        if ($old == $new) {
            return $differences;
        }
        $old = explode(' ', $old);
        $new = explode(' ', $new);
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
     * @param  [array] $tables is an array of tables that are not the same in the two db's
     * @return [array] $sql_commands_to_run is an array that represents the sql needed to update the desired db's tables
     */
    public function update_existing_tables($tables) {
        $sql_commands_to_run = array();
        $this->table_field_data($this->db, $tables, 0);
        /*
         * add, remove or update any fields in $table_structure_live
         */
        $sql_commands_to_run = array_merge($sql_commands_to_run, $this->determine_field_changes('regular', $tables));
        $sql_commands_to_run = array_merge($sql_commands_to_run, $this->determine_field_changes('reverse', $tables));

        return $sql_commands_to_run;
    }

    /**
     * @author Gordon Murray && Peter Kaufman
     * @description table_field_data when given a database and a table, compile an array of field meta data
     * @example table_field_data($database, $tables, $db) returns nothing
     * @param [array] $database is an array of the db having its meta data collected
     * @param [array] $tables   is an array of tables to update
     * @param [int]   $db       tells the function which variable to store the db meta data in
     */
    private function table_field_data($database, $tables, $db) {
        $result = $database->query("SELECT DISTINCT(CONCAT(a.`TABLE_NAME`, `COLUMN_NAME`)) as 'distinct', a.`TABLE_NAME` as `table`, `COLUMN_NAME` as `name`,  `COLUMN_TYPE` as `type`, `COLUMN_DEFAULT` as `default`, `EXTRA` as `extra`, `IS_NULLABLE`,  `AUTO_INCREMENT` as `auto_increment` FROM information_schema.COLUMNS a  LEFT JOIN INFORMATION_SCHEMA.TABLES b ON a.`TABLE_NAME` = b.`TABLE_NAME` WHERE a.`TABLE_SCHEMA` = '" . $database->database . "' Order by a.`TABLE_NAME`;")->result();

        foreach ($result as $row) {
            $row = (array)$row;
            array_shift($row);

            if (!(in_array($row['table'], $this->exclude))) {
                $this->tables1[$row['table']][$row['name']] = $row;
            }
        }

        if ($db != 0) {
            return $this->tables1;
        }
    }

    /**
     * @author Gordon Murray && Peter Kaufman
     * @description determine_field_changes edits table columns by adding, updating, and removing them
     * @example determine_field_changes($type, $tables) returns *the sql needed to update the desired db's tables*
     * @param  [int]   $type   tells the function what type of table modification to do
     * @param  [array] $tables tells the function what tables to check for a change
     * @return [array] $sql_commands_to_run is an array that represents the sql to run to add, edit, or remove a column
     */
    private function determine_field_changes($type, $tables) {
        $sql_commands_to_run = array();

        if ($type == 'regular') {
            $source_field_structures = $this->DB2;
            $destination_field_structures = $this->tables1;
            /**
             * loop through the source (usually development) database.
             */
            foreach ($source_field_structures as $table => $fields) {
                $n = 0;

                if ($table != 'create' && $table != 'indices' && in_array($table, $tables)) {
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
                                    $modify_field = "ALTER TABLE `$table` MODIFY COLUMN `" . $field['name'] . '` ' . $field['type'];
                                    $modify_field .= (isset($field['IS_NULLABLE']) && $field['IS_NULLABLE'] != 'NO') ? ' NULL ' : ' NOT NULL ';
                                    $modify_field .= (isset($field['default']) && $field['default'] != null) ? ' DEFAULT \'' . $field['default'] . '\'' : '';
                                    $modify_field .= (isset($field['extra']) && $field['extra'] != '') ? $field['extra'] : '';

                                    if ($n == 0) {
                                        $modify_field .= ';';
                                    } else {
                                        $modify_field .= (isset($previous_field) && $previous_field != '') ? ' AFTER `' . $previous_field . '`' : '';
                                        $modify_field .= ';';
                                    }
                                }
                                $previous_field = $field['name'];
                            }

                            if ($modify_field != '' && !in_array($modify_field, $sql_commands_to_run)) {
                                if ($field['extra'] != '' && $field['auto_increment'] != null) {
                                    $sql_commands_to_run[] = $modify_field;
                                    $sql_commands_to_run[] = "ALTER TABLE $table AUTO_INCREMENT " . $field['auto_increment'] . ';';
                                } else {
                                    $sql_commands_to_run[] = $modify_field;
                                }
                            }
                            $n++;
                        } else {
                            /*
                            * Add
                            */
                            $add_field = "ALTER TABLE `$table` ADD COLUMN `" . $field['name'] . '` ' . $field['type'];
                            $add_field .= (isset($fields['IS_NULLABLE']) && $fields['IS_NULLABLE'] != 'NO') ? ' NULL ' : ' NOT NULL ';
                            $add_field .= (isset($field['default']) && $field['default'] != null) ? ' DEFAULT \'' . $field['default'] . '\'' : '';
                            $add_field .= (isset($previous_field) && $previous_field != '') ? ' AFTER `' . $previous_field . '`' : ' FIRST';
                            $add_field .= (isset($fields['extra']) && $fields['extra'] != '') ? ' INT ' . $fields['extra'] . ' = ' . $fields['auto_increment'] : '';
                            $add_field .= ';';
                            $sql_commands_to_run[] = $add_field;
                        }
                    }
                }
            }
        } else {
            $source_field_structures = $this->tables1;
            $destination_field_structures = $this->DB2;
            /**
             * loop through the source (usually development) database.
             */
            foreach ($source_field_structures as $table => $fields) {
                foreach ($fields as $field) {
                    /*
                     * DELETE COLUMN
                     */
                    if ($this->in_array_recursive($field['name'], $destination_field_structures[$table])) {
                        // shouldn't be acted upon because the column is in the original file
                    } else {
                        $delete_field = "ALTER TABLE `$table` DROP COLUMN `" . $field['name'] . '`;';
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
     * @param  [array]   $needle   is an array that is the column to be searched for
     * @param  [array]   $haystack is an array that contains the db where the column will be searched for
     * @param  [boolean] $strict   determines how strict the comparison will be
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

    /**
     * @author Peter Kaufman
     * @description update_exclusion updates the list of tables to exclude from being checked for differences
     * @example update_exclusion($drop, $add);
     * @since 8-25-17
     * @last_updated 8-25-17
     * @param  [array] $drop list of table names to be dropped
     * @param  [array] $add  list of tables names that are to be created
     * @return [void]
     */
    public function update_exclusion($drop, $add) {
        $this->exclude = array_merge($drop, $add);
    }

    /**
     * @author Peter Kaufman
     * @description table_list returns the desired db's table names
     * @example table_list($db);
     * @since 8-25-17
     * @last_updated 8-25-17
     * @param  [int]   $db db to be used
     * @return [array] the array of table names of the db desired
     */
    public function table_list($db) {
        if ($db == 1) {
            return $this->db->list_tables();
        } else {
            $list = array();

            foreach (array_keys($this->DB2) as $key) {
                if ($key != 'indices' && $key != 'create') {
                    $list[] = $key;
                }
            }

            return $list;
        }
    }

    /**
     * @author Peter Kaufman
     * @description create_db_snapshot creates a snapshot of the db which includes tables,
     * create statements, column and column fields, and indices
     * @example create_db_snapshot();
     * @since 8-25-17
     * @last_updated 8-26-17
     * @return [void]
     */
    public function create_db_snapshot() {
        $db = array();
        $db = $this->table_field_data($this->db, '', 1);
        $this->get_indices();

        $db['indices'] = $this->index1;

        foreach (array_keys($db) as $index) {
            if ($index != 'indices' && $index != 'create') {
                $db['create'][$index] = $query = $this->db->query("SHOW CREATE TABLE `$index` -- dev")->row_array();
            }
        }

        $file = fopen(getcwd() . '\dbsnapshot.json', 'w');
        fwrite($file, json_encode($db));
        fclose($file);
    }

    /**
     * @author Peter Kaufmna
     * @description get_db_snapshot gets the snapshot of the db and returns it
     * @example get_db_snapshot();
     * @since 8-25-17
     * @last_updated 8-25-17
     * @return [array] the array is the db snapshot
     */
    public function get_db_snapshot() {
        return json_decode(file_get_contents(getcwd() . '\dbsnapshot.json'), true);
    }

    /**
     * @author Peter Kaufman
     * @description set_dir sets the directory for where the db snapshot is
     * @example set_dir();
     * @since 8-25-17
     * @last_updated 8-25-17
     */
    private function set_dir() {
        getcwd();
        chdir('application');
        chdir('modules');
        chdir('compare');
    }
}
