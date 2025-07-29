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
* Settings for the EIRA Academic Assistant
*
* @package    block_chatbot
* @copyright  2023 Your Name
* @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
*/
 
defined('MOODLE_INTERNAL') || die;
 
if ($ADMIN->fulltree) {
    // Bot name
    $settings->add(new admin_setting_configtext(
        'block_chatbot/botname',
        get_string('botname', 'block_chatbot'),
        get_string('botnamedesc', 'block_chatbot'),
        'EIRA'
    ));
 
    // Welcome message
    $settings->add(new admin_setting_configtextarea(
        'block_chatbot/welcomemessage',
        get_string('welcomemessage', 'block_chatbot'),
        get_string('welcomemessagedesc', 'block_chatbot'),
        get_string('fullwelcome1', 'block_chatbot')
    ));
 
    // Help message
    $settings->add(new admin_setting_configtextarea(
        'block_chatbot/helpmessage',
        get_string('helpmessage', 'block_chatbot'),
        get_string('helpmessagedesc', 'block_chatbot'),
        get_string('fullwelcome2', 'block_chatbot')
    ));
 
    // Chatbot appearance
    $settings->add(new admin_setting_heading(
        'block_chatbot/appearanceheading',
        get_string('appearanceheading', 'block_chatbot'),
        ''
    ));
 
    // Theme color
    $settings->add(new admin_setting_configcolourpicker(
        'block_chatbot/themecolor',
        get_string('themecolor', 'block_chatbot'),
        get_string('themecolordesc', 'block_chatbot'),
        '#1177d1'
    ));
 
    // Show academic focus areas
    $settings->add(new admin_setting_configcheckbox(
        'block_chatbot/showfocusareas',
        get_string('showfocusareas', 'block_chatbot'),
        get_string('showfocusareasdesc', 'block_chatbot'),
        1
    ));
 
    // Academic resources
    $settings->add(new admin_setting_heading(
        'block_chatbot/resourcesheading',
        get_string('resourcesheading', 'block_chatbot'),
        get_string('resourcesheadingdesc', 'block_chatbot')
    ));
 
    // Study resources link
    $settings->add(new admin_setting_configtext(
        'block_chatbot/studyresourceslink',
        get_string('studyresourceslink', 'block_chatbot'),
        get_string('studyresourceslinkdesc', 'block_chatbot'),
        ''
    ));
 
    // Career services link
    $settings->add(new admin_setting_configtext(
        'block_chatbot/careerserviceslink',
        get_string('careerserviceslink', 'block_chatbot'),
        get_string('careerserviceslinkdesc', 'block_chatbot'),
        ''
    ));
 
    // Academic support link
    $settings->add(new admin_setting_configtext(
        'block_chatbot/academicsupportlink',
        get_string('academicsupportlink', 'block_chatbot'),
        get_string('academicsupportlinkdesc', 'block_chatbot'),
        ''
    ));
 
    // Advanced settings
    $settings->add(new admin_setting_heading(
        'block_chatbot/advancedheading',
        get_string('advancedheading', 'block_chatbot'),
        ''
    ));
 
    // Max message length
    $settings->add(new admin_setting_configtext(
        'block_chatbot/maxmessagelength',
        get_string('maxmessagelength', 'block_chatbot'),
        get_string('maxmessagelengthdesc', 'block_chatbot'),
        '1000',
        PARAM_INT
    ));
}