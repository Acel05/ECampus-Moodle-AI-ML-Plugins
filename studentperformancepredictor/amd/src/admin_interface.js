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
 * Admin interface JS for Student Performance Predictor.
 *
 * @module     block_studentperformancepredictor/admin_interface
 * @copyright  2023 Your Name <[Email]>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['jquery', 'core/ajax', 'core/str', 'core/notification', 'core/modal_factory', 'core/modal_events'], 
function($, Ajax, Str, Notification, ModalFactory, ModalEvents) {

    /**
     * Initialize admin interface.
     * 
     * @param {int} courseId Course ID
     */
    var init = function(courseId) {
        try {
            // Handle dataset upload form
            $('#spp-dataset-upload-form').on('submit', function(e) {
                e.preventDefault();

                // Validate form
                var form = $(this)[0];
                if (!form.checkValidity()) {
                    if (typeof form.reportValidity === 'function') {
                        form.reportValidity();
                    }
                    return;
                }

                var formData = new FormData(form);
                var submitButton = $(this).find('input[type="submit"]');
                var originalText = submitButton.val();
                submitButton.prop('disabled', true);

                // Load 'uploading' string asynchronously for button and status
                Str.get_string('uploading', 'moodle').done(function(uploadingStr) {
                    submitButton.val(uploadingStr);
                    $('#spp-upload-status').html(
                        '<div class="spp-loading"><i class="fa fa-spinner fa-spin"></i>' + uploadingStr + '</div>'
                    );
                }).fail(function() {
                    submitButton.val('Uploading...');
                    $('#spp-upload-status').html(
                        '<div class="spp-loading"><i class="fa fa-spinner fa-spin"></i>Uploading...</div>'
                    );
                });

                $.ajax({
                    url: M.cfg.wwwroot + '/blocks/studentperformancepredictor/admin/upload_dataset.php',
                    type: 'POST',
                    data: formData,
                    contentType: false,
                    processData: false,
                    success: function(response) {
                        submitButton.prop('disabled', false);
                        submitButton.val(originalText);
                        $('#spp-upload-status').empty();

                        try {
                            // Properly handle response which might be string or object
                            var responseData;
                            if (typeof response === 'string') {
                                try {
                                    responseData = JSON.parse(response);
                                } catch (e) {
                                    console.error('Invalid JSON response', response);
                                    Notification.exception(new Error('Invalid server response'));
                                    return;
                                }
                            } else {
                                responseData = response;
                            }

                            if (responseData && responseData.success) {
                                // Show success message
                                var msg = responseData.message;
                                if (!msg) {
                                    Str.get_string('datasetsaved', 'block_studentperformancepredictor').done(function(s) {
                                        Notification.addNotification({ message: s, type: 'success' });
                                    });
                                } else {
                                    Notification.addNotification({ message: msg, type: 'success' });
                                }
                                setTimeout(function() { window.location.reload(); }, 1500);
                            } else {
                                var msg = responseData ? responseData.message : '';
                                if (!msg) {
                                    Str.get_string('datasetsaveerror', 'block_studentperformancepredictor').done(function(s) {
                                        Notification.addNotification({ message: s, type: 'error' });
                                    });
                                } else {
                                    Notification.addNotification({ message: msg, type: 'error' });
                                }
                            }
                        } catch (e) {
                            console.error('Error handling response:', e);
                            Str.get_string('datasetsaveerror', 'block_studentperformancepredictor').done(function(s) {
                                Notification.addNotification({ message: s, type: 'error' });
                            });
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('AJAX error:', status, error);
                        submitButton.prop('disabled', false);
                        submitButton.val(originalText);
                        $('#spp-upload-status').empty();

                        // Get response error message if available
                        var errorMessage = error;
                        try {
                            var response = JSON.parse(xhr.responseText);
                            if (response && response.message) {
                                errorMessage = response.message;
                            }
                        } catch (e) {
                            // Use default error message if JSON parsing fails
                        }

                        Str.get_string('uploaderror', 'block_studentperformancepredictor').done(function(s) {
                            Notification.addNotification({ message: s + ': ' + errorMessage, type: 'error' });
                        });
                    }
                });
            });

            // Handle model training form
            $('#spp-train-model-form').on('submit', function(e) {
                e.preventDefault();
                var datasetId = $('#datasetid').val();
                var algorithm = $('#algorithm').val();
                if (!datasetId) {
                    Str.get_string('selectdataset', 'block_studentperformancepredictor').done(function(s) {
                        Notification.addNotification({ message: s, type: 'error' });
                    });
                    return;
                }
                var submitButton = $(this).find('input[type="submit"]');
                var originalText = submitButton.val();
                submitButton.prop('disabled', true);
                Str.get_string('training', 'block_studentperformancepredictor').done(function(trainingStr) {
                    submitButton.val(trainingStr);
                });

                // Using traditional form submission for better file handling compatibility
                var form = $(this)[0];
                form.submit();
            });

            // Handle dataset deletion
            $('.spp-delete-dataset').on('click', function(e) {
                e.preventDefault();

                var button = $(this);
                var datasetId = button.data('dataset-id');

                button.prop('disabled', true);

                // Confirm deletion.
                Str.get_strings([
                    {key: 'confirmdeletedataset', component: 'block_studentperformancepredictor'},
                    {key: 'delete', component: 'core'},
                    {key: 'cancel', component: 'core'}
                ]).done(function(strings) {
                    ModalFactory.create({
                        type: ModalFactory.types.SAVE_CANCEL,
                        title: strings[0],
                        body: strings[0]
                    }).done(function(modal) {
                        modal.setSaveButtonText(strings[1]);

                        // When the user confirms deletion
                        modal.getRoot().on(ModalEvents.save, function() {
                            $.ajax({
                                url: M.cfg.wwwroot + '/blocks/studentperformancepredictor/admin/ajax_delete_dataset.php',
                                type: 'POST',
                                data: {
                                    datasetid: datasetId,
                                    courseid: courseId,
                                    sesskey: M.cfg.sesskey
                                },
                                dataType: 'json',
                                success: function(response) {
                                    if (response.success) {
                                        // Reload page to update dataset list.
                                        window.location.reload();
                                    } else {
                                        button.prop('disabled', false);

                                        // Show error message.
                                        Notification.addNotification({
                                            message: response.message,
                                            type: 'error'
                                        });
                                    }
                                },
                                error: function(xhr, status, error) {
                                    button.prop('disabled', false);

                                    // Try to parse error message from response
                                    var errorMessage = error;
                                    try {
                                        var response = JSON.parse(xhr.responseText);
                                        if (response && response.message) {
                                            errorMessage = response.message;
                                        }
                                    } catch (e) {
                                        // Use default error message
                                    }

                                    // Show error message.
                                    Str.get_string('actionerror', 'block_studentperformancepredictor').done(function(s) {
                                        Notification.addNotification({
                                            message: s + ': ' + errorMessage,
                                            type: 'error'
                                        });
                                    });
                                }
                            });
                        });

                        // When the modal is cancelled, re-enable the button
                        modal.getRoot().on(ModalEvents.cancel, function() {
                            button.prop('disabled', false);
                        });

                        modal.show();
                    }).catch(function(error) {
                        console.error('Error creating modal:', error);
                        button.prop('disabled', false);
                    });
                }).catch(function(error) {
                    console.error('Error loading strings:', error);
                    button.prop('disabled', false);
                });
            });

            // Handle model toggle (activate/deactivate)
            $('.spp-toggle-model-status').on('click', function(e) {
                e.preventDefault();

                var button = $(this);
                var modelId = button.data('model-id');
                var isActive = button.data('is-active');

                button.prop('disabled', true);

                // Confirm action based on current state
                var confirmKey = isActive ? 'confirmdeactivate' : 'confirmactivate';

                Str.get_strings([
                    {key: confirmKey, component: 'block_studentperformancepredictor'},
                    {key: isActive ? 'deactivate' : 'activate', component: 'block_studentperformancepredictor'},
                    {key: 'cancel', component: 'core'}
                ]).done(function(strings) {
                    ModalFactory.create({
                        type: ModalFactory.types.SAVE_CANCEL,
                        title: strings[0],
                        body: strings[0]
                    }).done(function(modal) {
                        modal.setSaveButtonText(strings[1]);

                        // When user confirms
                        modal.getRoot().on(ModalEvents.save, function() {
                            $.ajax({
                                url: M.cfg.wwwroot + '/blocks/studentperformancepredictor/admin/ajax_toggle_model.php',
                                type: 'POST',
                                data: {
                                    modelid: modelId,
                                    courseid: courseId,
                                    active: isActive ? 0 : 1,
                                    sesskey: M.cfg.sesskey
                                },
                                dataType: 'json',
                                success: function(response) {
                                    if (response.success) {
                                        window.location.reload();
                                    } else {
                                        button.prop('disabled', false);
                                        Notification.addNotification({
                                            message: response.message,
                                            type: 'error'
                                        });
                                    }
                                },
                                error: function(xhr, status, error) {
                                    button.prop('disabled', false);

                                    var errorMessage = error;
                                    try {
                                        var response = JSON.parse(xhr.responseText);
                                        if (response && response.message) {
                                            errorMessage = response.message;
                                        }
                                    } catch (e) {
                                        // Use default message
                                    }

                                    Str.get_string('actionerror', 'block_studentperformancepredictor').done(function(s) {
                                        Notification.addNotification({
                                            message: s + ': ' + errorMessage,
                                            type: 'error'
                                        });
                                    });
                                }
                            });
                        });

                        // When cancelled
                        modal.getRoot().on(ModalEvents.cancel, function() {
                            button.prop('disabled', false);
                        });

                        modal.show();
                    });
                });
            });

        } catch (e) {
            console.error('Error initializing admin interface:', e);
            // Show a generic error notification
            Str.get_string('jserror', 'moodle').done(function(s) {
                Notification.exception(new Error(s + ': ' + e.message));
            });
        }
    };

    return {
        init: init
    };
});