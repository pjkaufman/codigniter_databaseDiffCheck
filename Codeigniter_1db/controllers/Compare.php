<?php

if (!defined('BASEPATH')) {
    exit('No direct script access allowed');
}

class Compare extends MX_Controller {
    public function __construct() {
        parent::__construct();
        $this->load->model('compare_model');
    }

    public function index() {
        /*
         * This will become a list of SQL Commands to run on the Live database to bring it up to date
         */
        $sql_commands_to_run = array();
        /*
         * list the tables from both databases
         */
        $development_tables = $this->compare_model->table_list(2);
        $live_tables = $this->compare_model->table_list(1);
        /*
         * list any tables that need to be created or dropped
         */
        $tables_to_create = array_diff($development_tables, $live_tables);
        $tables_to_drop = array_diff($live_tables, $development_tables);
        /**
         * Create/Drop any tables that are not in the Live database.
         */
        $results = (is_array($tables_to_create) && !empty($tables_to_create)) ? $this->compare_model->manage_tables($tables_to_create, 'create') : array();
        $sql_commands_to_run = array_merge($sql_commands_to_run, $results);
        $results = (is_array($tables_to_drop) && !empty($tables_to_drop)) ? $this->compare_model->manage_tables($tables_to_drop, 'drop') : array();
        $sql_commands_to_run = array_merge($sql_commands_to_run, $results);
        $tables_to_update = $this->compare_model->compare_table_structures($development_tables, $live_tables);
        /*
         * Before comparing tables, remove any tables from the list that will be created or dropped in the $tables_to_create array
         */
        $tables_to_update = array_diff($tables_to_update, $tables_to_create);
        $tables_to_update = array_diff($tables_to_update, $tables_to_drop);
        $this->compare_model->update_exclusion($tables_to_drop, $tables_to_create);
        /*
         * update tables, add/update/remove columns
         */
        $results = (is_array($tables_to_update) && !empty($tables_to_update)) ? $this->compare_model->update_existing_tables($tables_to_update) : array();
        $sql_commands_to_run = array_merge($sql_commands_to_run, $results);
        /*
         * add, and or drop indices
         */
        $this->compare_model->get_indices();
        $results = $this->compare_model->manage_indices();
        $sql_commands_to_run = array_merge($sql_commands_to_run, $results);

        if (is_array($sql_commands_to_run) && !empty($sql_commands_to_run)) {
            echo "<h2>The database is out of Sync!</h2>\n";
            echo "<p>The following SQL commands need to be executed to bring the Live database tables up to date: </p>\n";
            echo "<pre style='padding: 20px; background-color: #FFFAF0;'>\n";

            foreach ($sql_commands_to_run as $sql_command) {
                echo "$sql_command\n";
            }
            echo "<pre>\n";
        } else {
            echo "<h2>The database appears to be up to date</h2>\n";
        }
    }

    public function take_snapshot() {
        $this->compare_model->create_db_snapshot();
        echo 'Snapshot created';
    }
}
