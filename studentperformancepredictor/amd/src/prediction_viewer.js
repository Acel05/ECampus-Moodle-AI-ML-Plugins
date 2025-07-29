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
 * Prediction viewer JS for Student Performance Predictor.
 *
 * @module     block_studentperformancepredictor/prediction_viewer
 * @copyright  2023 Your Name <[Email]>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['jquery', 'core/ajax', 'core/notification', 'core/modal_factory', 'core/modal_events', 'core/str'], 
function($, Ajax, Notification, ModalFactory, ModalEvents, Str) {
    /**
     * Initialize suggestion management and prediction features.
     */
    var init = function() {
        // Handle marking suggestions as viewed
        $(document).on('click', '.spp-mark-viewed', function(e) {
            e.preventDefault();
            var button = $(this);
            var suggestionId = button.data('id');

            // Disable button to prevent multiple clicks
            button.prop('disabled', true);
            button.addClass('disabled');

            var promise = Ajax.call([{
                methodname: 'block_studentperformancepredictor_mark_suggestion_viewed',
                args: { suggestionid: suggestionId }
            }]);

            promise[0].done(function(response) {
                if (response.status) {
                    Str.get_string('viewed', 'block_studentperformancepredictor').done(function(viewedStr) {
                        button.replaceWith('<span class="badge bg-secondary">' + viewedStr + '</span>');
                    }).fail(function() {
                        button.replaceWith('<span class="badge bg-secondary">Viewed</span>');
                    });
                } else {
                    button.prop('disabled', false);
                    button.removeClass('disabled');
                    Notification.addNotification({ 
                        message: response.message || 'Unknown error', 
                        type: 'error' 
                    });
                }
            }).fail(function(error) {
                button.prop('disabled', false);
                button.removeClass('disabled');
                Notification.exception(error);
            });
        });

        // Handle marking suggestions as completed
        $(document).on('click', '.spp-mark-completed', function(e) {
            e.preventDefault();
            var button = $(this);
            var suggestionId = button.data('id');

            // Disable button to prevent multiple clicks
            button.prop('disabled', true);
            button.addClass('disabled');

            var promise = Ajax.call([{
                methodname: 'block_studentperformancepredictor_mark_suggestion_completed',
                args: { suggestionid: suggestionId }
            }]);

            promise[0].done(function(response) {
                if (response.status) {
                    Str.get_strings([
                        {key: 'completed', component: 'block_studentperformancepredictor'},
                        {key: 'viewed', component: 'block_studentperformancepredictor'}
                    ]).done(function(strings) {
                        button.replaceWith('<span class="badge bg-success">' + strings[0] + '</span>');
                        var viewedBtn = button.closest('.spp-suggestion-actions').find('.spp-mark-viewed');
                        if (viewedBtn.length) {
                            viewedBtn.replaceWith('<span class="badge bg-secondary">' + strings[1] + '</span>');
                        }
                    }).fail(function() {
                        button.replaceWith('<span class="badge bg-success">Completed</span>');
                        var viewedBtn = button.closest('.spp-suggestion-actions').find('.spp-mark-viewed');
                        if (viewedBtn.length) {
                            viewedBtn.replaceWith('<span class="badge bg-secondary">Viewed</span>');
                        }
                    });
                } else {
                    button.prop('disabled', false);
                    button.removeClass('disabled');
                    Notification.addNotification({ 
                        message: response.message || 'Unknown error', 
                        type: 'error' 
                    });
                }
            }).fail(function(error) {
                button.prop('disabled', false);
                button.removeClass('disabled');
                Notification.exception(error);
            });
        });

        // Handle teacher refresh predictions button
        $('.spp-refresh-predictions').on('click', function(e) {
            e.preventDefault();
            var button = $(this);

            // Disable button to prevent multiple clicks
            button.prop('disabled', true);
            button.addClass('disabled');

            var courseId = button.data('course-id');
            if (!courseId) {
                courseId = $('.block_studentperformancepredictor').data('course-id');
            }

            if (!courseId) {
                button.prop('disabled', false);
                button.removeClass('disabled');
                Str.get_string('error:nocourseid', 'block_studentperformancepredictor').done(function(msg) {
                    Notification.addNotification({ message: msg, type: 'error' });
                }).fail(function() {
                    Notification.addNotification({ message: 'No course ID', type: 'error' });
                });
                return;
            }

            Str.get_strings([
                {key: 'refreshconfirmation', component: 'block_studentperformancepredictor'},
                {key: 'refresh', component: 'block_studentperformancepredictor'},
                {key: 'cancel', component: 'moodle'},
                {key: 'refreshing', component: 'block_studentperformancepredictor'}
            ]).done(function(strings) {
                ModalFactory.create({
                    type: ModalFactory.types.SAVE_CANCEL,
                    title: strings[0],
                    body: strings[0]
                }).done(function(modal) {
                    modal.setSaveButtonText(strings[1]);

                    modal.getRoot().on(ModalEvents.save, function() {
                        var loadingMessage = $('<div class="spp-loading"><i class="fa fa-spinner fa-spin"></i> ' + strings[3] + '</div>');
                        button.after(loadingMessage);

                        var promise = Ajax.call([{
                            methodname: 'block_studentperformancepredictor_refresh_predictions',
                            args: { courseid: courseId }
                        }]);

                        promise[0].done(function(response) {
                            button.prop('disabled', false);
                            button.removeClass('disabled');
                            loadingMessage.remove();

                            if (response.status) {
                                Notification.addNotification({ message: response.message, type: 'success' });
                                setTimeout(function() { window.location.reload(); }, 1500);
                            } else {
                                Notification.addNotification({ message: response.message, type: 'error' });
                            }
                        }).fail(function(error) {
                            button.prop('disabled', false);
                            button.removeClass('disabled');
                            loadingMessage.remove();
                            Notification.exception(error);
                        });
                    });

                    modal.getRoot().on(ModalEvents.cancel, function() {
                        button.prop('disabled', false);
                        button.removeClass('disabled');
                    });

                    modal.show();
                });
            });
        });

        // Handle student generate prediction button with AJAX
        $('.spp-generate-prediction, .spp-update-prediction').on('click', function(e) {
            e.preventDefault();
            var button = $(this);
            var url = button.attr('href');

            // Disable button and show loading
            button.prop('disabled', true);
            button.closest('div').find('.spp-prediction-loading').show();

            // Extract course and user IDs
            var blockElement = $('.block_studentperformancepredictor');
            var courseId = blockElement.data('course-id');
            var userId = blockElement.data('user-id');

            // Add parameters to URL
            if (url.indexOf('?') !== -1) {
                url += '&redirect=0';
            } else {
                url += '?redirect=0';
            }

            // Call the endpoint
            $.ajax({
                url: url,
                method: 'GET',
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        // Show success message
                        Str.get_string('predictiongenerated', 'block_studentperformancepredictor').done(function(msg) {
                            Notification.addNotification({ message: msg, type: 'success' });
                        });

                        // Reload the page to show new prediction
                        setTimeout(function() {
                            window.location.reload();
                        }, 1000);
                    } else {
                        // Show error
                        button.prop('disabled', false);
                        button.closest('div').find('.spp-prediction-loading').hide();

                        Notification.addNotification({ 
                            message: response.error || 'Unknown error', 
                            type: 'error' 
                        });
                    }
                },
                error: function(xhr, status, error) {
                    // Handle error
                    button.prop('disabled', false);
                    button.closest('div').find('.spp-prediction-loading').hide();

                    Str.get_string('predictionerror', 'block_studentperformancepredictor').done(function(msg) {
                        Notification.addNotification({ message: msg, type: 'error' });
                    });
                }
            });
        });
    };

    return {
        init: init
    };
});
