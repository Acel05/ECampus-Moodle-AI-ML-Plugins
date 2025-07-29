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
 * Settings for the Student Performance Predictor block.
 *
 * @package    block_studentperformancepredictor
 * @copyright  2023 Your Name <[Email]>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

if ($ADMIN->fulltree) {
    // Backend integration settings
    $settings->add(new admin_setting_heading('block_studentperformancepredictor/backendsettings',
        get_string('backendsettings', 'block_studentperformancepredictor'),
        get_string('backendsettings_desc', 'block_studentperformancepredictor')));

    // For Railway deployment, the default URL should match the Railway app URL
    $settings->add(new admin_setting_configtext('block_studentperformancepredictor/python_api_url',
        get_string('python_api_url', 'block_studentperformancepredictor'),
        get_string('python_api_url_desc', 'block_studentperformancepredictor'),
        'https://your-railway-app-name.up.railway.app'));

    $settings->add(new admin_setting_configpasswordunmask('block_studentperformancepredictor/python_api_key',
        get_string('python_api_key', 'block_studentperformancepredictor'),
        get_string('python_api_key_desc', 'block_studentperformancepredictor'),
        'changeme'));

    // Global model settings
    $settings->add(new admin_setting_heading('block_studentperformancepredictor/globalmodelsettings',
        get_string('globalmodelsettings', 'block_studentperformancepredictor'),
        get_string('globalmodelsettings_desc', 'block_studentperformancepredictor')));

    $settings->add(new admin_setting_configcheckbox('block_studentperformancepredictor/enableglobalmodel',
        get_string('enableglobalmodel', 'block_studentperformancepredictor'),
        get_string('enableglobalmodel_desc', 'block_studentperformancepredictor'),
        1)); // Enable global models by default

    $settings->add(new admin_setting_configcheckbox('block_studentperformancepredictor/prefercoursemodelsfirst',
        get_string('prefercoursemodelsfirst', 'block_studentperformancepredictor'),
        get_string('prefercoursemodelsfirst_desc', 'block_studentperformancepredictor'),
        1));

    // Refresh interval for automatic predictions
    $settings->add(new admin_setting_configtext('block_studentperformancepredictor/refreshinterval',
        get_string('refreshinterval', 'block_studentperformancepredictor'),
        get_string('refreshinterval_desc', 'block_studentperformancepredictor'),
        24, PARAM_INT));

    // Risk threshold settings
    $settings->add(new admin_setting_heading('block_studentperformancepredictor/riskthresholds',
        get_string('riskthresholds', 'block_studentperformancepredictor'),
        get_string('riskthresholds_desc', 'block_studentperformancepredictor')));

    $settings->add(new admin_setting_configtext('block_studentperformancepredictor/lowrisk',
        get_string('lowrisk', 'block_studentperformancepredictor'),
        get_string('lowrisk_desc', 'block_studentperformancepredictor'),
        0.7, PARAM_FLOAT));

    $settings->add(new admin_setting_configtext('block_studentperformancepredictor/mediumrisk',
        get_string('mediumrisk', 'block_studentperformancepredictor'),
        get_string('mediumrisk_desc', 'block_studentperformancepredictor'),
        0.4, PARAM_FLOAT));

    // Algorithm settings
    $settings->add(new admin_setting_heading('block_studentperformancepredictor/algorithmsettings',
        get_string('algorithmsettings', 'block_studentperformancepredictor'),
        get_string('algorithmsettings_desc', 'block_studentperformancepredictor')));

    $algorithms = [
        'randomforest' => get_string('algorithm_randomforest', 'block_studentperformancepredictor'),
        'logisticregression' => get_string('algorithm_logisticregression', 'block_studentperformancepredictor'),
        'svm' => get_string('algorithm_svm', 'block_studentperformancepredictor'),
        'decisiontree' => get_string('algorithm_decisiontree', 'block_studentperformancepredictor'),
        'knn' => get_string('algorithm_knn', 'block_studentperformancepredictor')
    ];

    $settings->add(new admin_setting_configselect('block_studentperformancepredictor/defaultalgorithm',
        get_string('defaultalgorithm', 'block_studentperformancepredictor'),
        get_string('defaultalgorithm_desc', 'block_studentperformancepredictor'),
        'randomforest', $algorithms));

    // Python backend monitoring settings
    $settings->add(new admin_setting_heading('block_studentperformancepredictor/backendmonitoring',
        get_string('backendmonitoring', 'block_studentperformancepredictor', '', true),
        get_string('backendmonitoring_desc', 'block_studentperformancepredictor', '', true)));

    // Add a button to test the backend connection
    $testbackendurl = new moodle_url('/blocks/studentperformancepredictor/admin/testbackend.php');
    $settings->add(new admin_setting_description(
        'block_studentperformancepredictor/testbackend',
        get_string('testbackend', 'block_studentperformancepredictor', '', true),
        html_writer::link($testbackendurl, get_string('testbackendbutton', 'block_studentperformancepredictor'), 
            ['class' => 'btn btn-secondary', 'target' => '_blank'])
    ));

    // Debug mode settings
    $settings->add(new admin_setting_heading('block_studentperformancepredictor/debugsettings',
        get_string('debugsettings', 'block_studentperformancepredictor', '', true),
        get_string('debugsettings_desc', 'block_studentperformancepredictor', '', true)));

    $settings->add(new admin_setting_configcheckbox('block_studentperformancepredictor/enabledebug',
        get_string('enabledebug', 'block_studentperformancepredictor', '', true),
        get_string('enabledebug_desc', 'block_studentperformancepredictor', '', true),
        0));
    
    // Add a button to test the backend connection
    $testbackendurl = new moodle_url('/blocks/studentperformancepredictor/admin/testbackend.php');
    $settings->add(new admin_setting_description(
        'block_studentperformancepredictor/testbackend',
        get_string('testbackend', 'block_studentperformancepredictor', '', true),
        html_writer::link($testbackendurl, get_string('testbackendbutton', 'block_studentperformancepredictor'), 
            ['class' => 'btn btn-secondary', 'target' => '_blank'])
    ));
    
    // Add a button to the debug tool
    $debugtoolurl = new moodle_url('/blocks/studentperformancepredictor/admin/debug_tool.php');
    $settings->add(new admin_setting_description(
        'block_studentperformancepredictor/debugtool',
        get_string('debugtool', 'block_studentperformancepredictor', '', true),
        html_writer::link($debugtoolurl, get_string('debugtoolbutton', 'block_studentperformancepredictor'), 
            ['class' => 'btn btn-warning', 'target' => '_blank'])
    ));
}
