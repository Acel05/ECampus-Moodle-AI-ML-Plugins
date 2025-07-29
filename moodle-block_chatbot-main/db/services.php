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
* External services definition
*
* @package    block_chatbot
* @copyright  2023 Your Name
* @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
*/
 
defined('MOODLE_INTERNAL') || die();
 
$functions = [
    'block_chatbot_send_message' => [
        'classname'     => 'block_chatbot\external',
        'methodname'    => 'send_message',
        'description'   => 'Send a message to the chatbot',
        'type'          => 'write',
        'ajax'          => true,
        'loginrequired' => true,
    ],
    'block_chatbot_send_command' => [
        'classname'     => 'block_chatbot\external',
        'methodname'    => 'send_command',
        'description'   => 'Send a command to the chatbot',
        'type'          => 'write',
        'ajax'          => true,
        'loginrequired' => true,
    ],
];