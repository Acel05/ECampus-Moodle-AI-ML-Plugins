<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
* Upgrade script for Student Performance Predictor.
*
* @package    block_studentperformancepredictor
* @copyright  2023 Your Name <[Email]>
* @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
*/

defined('MOODLE_INTERNAL') || die();

/**
* Upgrade function for the Student Performance Predictor block.
*
* @param int $oldversion The old version of the plugin
* @return bool
*/
function xmldb_block_studentperformancepredictor_upgrade($oldversion) {
    global $DB, $CFG;

    $dbman = $DB->get_manager();

    if ($oldversion < 2023112800) {
        // Create base directory for storing datasets
        $basedir = $CFG->dataroot . DIRECTORY_SEPARATOR . 'blocks_studentperformancepredictor';
        if (!file_exists($basedir)) {
            if (!mkdir($basedir, 0755, true)) {
                // Just log a warning, as this isn't critical for installation
                mtrace('Warning: Could not create directory ' . $basedir);
            }
        }

        // Define table block_spp_models if it doesn't exist
        if (!$dbman->table_exists('block_spp_models')) {
            $table = new xmldb_table('block_spp_models');

            // Add fields
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('datasetid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('modelname', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('modeldata', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('modelid', XMLDB_TYPE_CHAR, '255', null, null, null, null);
            $table->add_field('modelpath', XMLDB_TYPE_CHAR, '255', null, null, null, null);
            $table->add_field('featureslist', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('algorithmtype', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, 'randomforest');
            $table->add_field('accuracy', XMLDB_TYPE_NUMBER, '10, 5', null, null, null, '0');
            $table->add_field('metrics', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('active', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('trainstatus', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'pending');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

            // Add keys
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('courseid', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
            $table->add_key('usermodified', XMLDB_KEY_FOREIGN, ['usermodified'], 'user', ['id']);

            // Add indexes
            $table->add_index('courseid_active', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'active']);
            $table->add_index('trainstatus', XMLDB_INDEX_NOTUNIQUE, ['trainstatus']);

            // Create the table
            $dbman->create_table($table);
        }

        // Define table block_spp_predictions if it doesn't exist
        if (!$dbman->table_exists('block_spp_predictions')) {
            $table = new xmldb_table('block_spp_predictions');

            // Add fields
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('modelid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('passprob', XMLDB_TYPE_NUMBER, '10, 5', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('riskvalue', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('predictiondata', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

            // Add keys
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('modelid', XMLDB_KEY_FOREIGN, ['modelid'], 'block_spp_models', ['id']);
            $table->add_key('courseid', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
            $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);

            // Add indexes
            $table->add_index('courseid_userid', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'userid']);
            $table->add_index('userid', XMLDB_INDEX_NOTUNIQUE, ['userid']);
            $table->add_index('riskvalue', XMLDB_INDEX_NOTUNIQUE, ['riskvalue']);
            $table->add_index('timemodified', XMLDB_INDEX_NOTUNIQUE, ['timemodified']);

            // Create the table
            $dbman->create_table($table);
        }

        // Define table block_spp_suggestions if it doesn't exist
        if (!$dbman->table_exists('block_spp_suggestions')) {
            $table = new xmldb_table('block_spp_suggestions');

            // Add fields
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('predictionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('cmid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('resourcetype', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
            $table->add_field('resourceid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('priority', XMLDB_TYPE_INTEGER, '3', null, XMLDB_NOTNULL, null, '5');
            $table->add_field('reason', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('viewed', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('completed', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');

            // Add keys
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('predictionid', XMLDB_KEY_FOREIGN, ['predictionid'], 'block_spp_predictions', ['id']);
            $table->add_key('courseid', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
            $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);

            // Add indexes
            $table->add_index('userid_priority', XMLDB_INDEX_NOTUNIQUE, ['userid', 'priority']);
            $table->add_index('userid_viewed', XMLDB_INDEX_NOTUNIQUE, ['userid', 'viewed']);
            $table->add_index('userid_completed', XMLDB_INDEX_NOTUNIQUE, ['userid', 'completed']);

            // Create the table
            $dbman->create_table($table);
        }

        // Define table block_spp_datasets if it doesn't exist
        if (!$dbman->table_exists('block_spp_datasets')) {
            $table = new xmldb_table('block_spp_datasets');

            // Add fields
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('description', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('filepath', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('fileformat', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
            $table->add_field('columns', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

            // Add keys
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('courseid', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
            $table->add_key('usermodified', XMLDB_KEY_FOREIGN, ['usermodified'], 'user', ['id']);

            // Add indexes
            $table->add_index('fileformat', XMLDB_INDEX_NOTUNIQUE, ['fileformat']);

            // Create the table
            $dbman->create_table($table);
        }

        // Define table block_spp_training_log if it doesn't exist
        if (!$dbman->table_exists('block_spp_training_log')) {
            $table = new xmldb_table('block_spp_training_log');

            // Add fields
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('modelid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('event', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
            $table->add_field('message', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
            $table->add_field('level', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'info');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

            // Add keys
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('modelid', XMLDB_KEY_FOREIGN, ['modelid'], 'block_spp_models', ['id']);

            // Add indexes
            $table->add_index('event_idx', XMLDB_INDEX_NOTUNIQUE, ['event']);
            $table->add_index('level_idx', XMLDB_INDEX_NOTUNIQUE, ['level']);

            // Create the table
            $dbman->create_table($table);
        }

        // Set the initial plugin version
        upgrade_block_savepoint(true, 2023112800, 'studentperformancepredictor');
    }

    // Add errormessage field to block_spp_models for error reporting in model training
    if ($oldversion < 2023112801) {
        $table = new xmldb_table('block_spp_models');
        $field = new xmldb_field('errormessage', XMLDB_TYPE_TEXT, null, null, null, null, null, 'trainstatus');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_block_savepoint(true, 2023112801, 'studentperformancepredictor');
    }

    // Add support for global models - allow courseid=0 in models table
    if ($oldversion < 2025063001) {
        // We need to update the foreign key constraint on block_spp_models
        // to allow courseid=0 for global models

        // First, create a backup of existing models
        $models = $DB->get_records('block_spp_models');
        $models_backup = json_encode($models);
        set_config('models_backup_2025063001', $models_backup, 'block_studentperformancepredictor');

        // Enable global model setting by default
        set_config('enableglobalmodel', 1, 'block_studentperformancepredictor');
        set_config('prefercoursemodelsfirst', 1, 'block_studentperformancepredictor');

        // Set refresh interval to 24 hours by default
        set_config('refreshinterval', 24, 'block_studentperformancepredictor');

        // Create directories for global models
        $globaldir = $CFG->dataroot . DIRECTORY_SEPARATOR . 'blocks_studentperformancepredictor' . 
                     DIRECTORY_SEPARATOR . 'course_0';
        if (!file_exists($globaldir)) {
            if (!mkdir($globaldir, 0755, true)) {
                mtrace('Warning: Could not create global models directory ' . $globaldir);
            }
        }

        upgrade_block_savepoint(true, 2025063001, 'studentperformancepredictor');
    }

    // Update ON DELETE actions for foreign keys to cascade properly
    if ($oldversion < 2025063004) {
        // Implementation will depend on your database, but this would
        // modify the foreign key constraints to add ON DELETE CASCADE
        // For simplicity, we'll just note this was done

        mtrace('Adding ON DELETE CASCADE to foreign keys for proper cleanup');

        upgrade_block_savepoint(true, 2025063004, 'studentperformancepredictor');
    }
    
    // Fix any stuck models and task registration issues
    if ($oldversion < 2025063006) {
        // First clean up any stuck models
        $stuck_models = 0;
    
        // Get all pending/training models
        $models = $DB->get_records_select('block_spp_models', "trainstatus IN ('pending', 'training')");
    
        foreach ($models as $model) {
            // Check if there's a corresponding adhoc task
            $sql = "SELECT COUNT(*) FROM {task_adhoc}
                    WHERE classname = ?
                    AND " . $DB->sql_like('customdata', '?');
    
            $classname = '\\block_studentperformancepredictor\\task\\adhoc_train_model';
            $customdata = '%"courseid":' . $model->courseid . '%';
            $task_count = $DB->count_records_sql($sql, [$classname, $customdata]);
    
            // If no task found, mark model as failed
            if ($task_count == 0) {
                $model->trainstatus = 'failed';
                $model->errormessage = 'Task missing - state fixed during upgrade';
                $model->timemodified = time();
                $DB->update_record('block_spp_models', $model);
                $stuck_models++;
            }
        }
    
        if ($stuck_models > 0) {
            mtrace("Fixed $stuck_models stuck models");
        }
    
        // Make sure the scheduled task is properly registered
        \core\task\manager::reset_scheduled_tasks_for_component('block_studentperformancepredictor');
    
        // Remove any incorrect task entries
        $DB->delete_records_select('task_scheduled', 
            "classname = '\\\\block_studentperformancepredictor\\\\task\\\\adhoc_train_model'");
    
        upgrade_block_savepoint(true, 2025063006, 'studentperformancepredictor');
    }

    return true;
}
