<?php
// db/tasks.php

defined('MOODLE_INTERNAL') || die();

$tasks = [
    [
        'classname' => 'block_studentperformancepredictor\task\scheduled_predictions',
        'blocking' => 0,
        'minute' => '0',
        'hour' => '*/6', // Run every 6 hours
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
        'disabled' => 0,
    ],

    // Make sure we have the adhoc task registered
    [
        'classname' => 'block_studentperformancepredictor\task\adhoc_train_model',
        'blocking' => 0,
        'minute' => '*',
        'hour' => '*',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
        'disabled' => 0,
        'adhoc' => true
    ],
];
