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
];
