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
 * Chart renderer for Student Performance Predictor.
 *
 * @module     block_studentperformancepredictor/chart_renderer
 * @copyright  2023 Your Name <[Email]>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['jquery', 'core/chartjs', 'core/str'], function($, Chart, Str) {

    /**
     * Initialize student prediction chart.
     */
    var init = function() {
        var chartElement = document.getElementById('spp-prediction-chart');
        if (!chartElement) {
            return;
        }

        try {
            var chartData = {};
            try {
                chartData = JSON.parse(chartElement.dataset.chartdata || '{"passprob":0,"failprob":100}');
            } catch (e) {
                console.error('Error parsing chart data:', e);
                chartData = {"passprob":0,"failprob":100};
            }

            var ctx = chartElement.getContext('2d');

            Str.get_strings([
                {key: 'passingchance', component: 'block_studentperformancepredictor'},
                {key: 'failingchance', component: 'block_studentperformancepredictor'}
            ]).done(function(labels) {
                new Chart(ctx, {
                    type: 'doughnut',
                    data: {
                        labels: labels,
                        datasets: [{
                            data: [chartData.passprob, chartData.failprob],
                            backgroundColor: [
                                '#28a745', // Green for passing
                                '#dc3545'  // Red for failing
                            ],
                            borderWidth: 1,
                            hoverBackgroundColor: [
                                '#218838', // Darker green on hover
                                '#c82333'  // Darker red on hover
                            ]
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        cutoutPercentage: 70,
                        legend: {
                            position: 'bottom',
                            labels: {
                                padding: 15,
                                boxWidth: 12
                            }
                        },
                        tooltips: {
                            callbacks: {
                                label: function(tooltipItem, data) {
                                    var dataset = data.datasets[tooltipItem.datasetIndex];
                                    var value = dataset.data[tooltipItem.index];
                                    return data.labels[tooltipItem.index] + ': ' + value + '%';
                                }
                            }
                        },
                        animation: {
                            animateScale: true,
                            animateRotate: true,
                            duration: 1000
                        }
                    }
                });
            }).fail(function() {
                // Fallback labels if string loading fails
                new Chart(ctx, {
                    type: 'doughnut',
                    data: {
                        labels: ['Passing', 'Failing'],
                        datasets: [{
                            data: [chartData.passprob, chartData.failprob],
                            backgroundColor: ['#28a745', '#dc3545'],
                            borderWidth: 1,
                            hoverBackgroundColor: ['#218838', '#c82333']
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        cutoutPercentage: 70
                    }
                });
            });
        } catch (e) {
            console.error('Error initializing chart:', e);
            Str.get_string('charterror', 'block_studentperformancepredictor').done(function(msg) {
                chartElement.innerHTML = '<div class="alert alert-warning">' + msg + '</div>';
            }).fail(function() {
                chartElement.innerHTML = '<div class="alert alert-warning">Chart error</div>';
            });
        }
    };

    /**
     * Initialize teacher view chart.
     */
    var initTeacherChart = function() {
        var chartElement = document.getElementById('spp-teacher-chart');
        if (!chartElement) {
            return;
        }

        try {
            var chartData = {};
            try {
                chartData = JSON.parse(chartElement.dataset.chartdata || '{"labels":[],"data":[]}');
            } catch (e) {
                console.error('Error parsing chart data:', e);
                chartData = {"labels":[],"data":[]};
            }

            var ctx = chartElement.getContext('2d');

            new Chart(ctx, {
                type: 'pie',
                data: {
                    labels: chartData.labels,
                    datasets: [{
                        data: chartData.data,
                        backgroundColor: [
                            '#dc3545',  // High risk - Red
                            '#ffc107',  // Medium risk - Yellow
                            '#28a745'   // Low risk - Green
                        ],
                        borderWidth: 1,
                        hoverBackgroundColor: [
                            '#c82333',  // Darker red on hover
                            '#e0a800',  // Darker yellow on hover
                            '#218838'   // Darker green on hover
                        ]
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 15,
                            boxWidth: 12
                        }
                    },
                    tooltips: {
                        callbacks: {
                            label: function(tooltipItem, data) {
                                var dataset = data.datasets[tooltipItem.datasetIndex];
                                var total = dataset.data.reduce(function(previousValue, currentValue) {
                                    return previousValue + currentValue;
                                });
                                var currentValue = dataset.data[tooltipItem.index];
                                var percentage = Math.round((currentValue / total) * 100);
                                return data.labels[tooltipItem.index] + ': ' + currentValue + ' (' + percentage + '%)';
                            }
                        }
                    },
                    animation: {
                        animateScale: true,
                        animateRotate: true,
                        duration: 1000
                    }
                }
            });
        } catch (e) {
            console.error('Error initializing teacher chart:', e);
            Str.get_string('charterror', 'block_studentperformancepredictor').done(function(msg) {
                chartElement.innerHTML = '<div class="alert alert-warning">' + msg + '</div>';
            }).fail(function() {
                chartElement.innerHTML = '<div class="alert alert-warning">Chart error</div>';
            });
        }
    };

    /**
     * Initialize admin view chart.
     */
    var initAdminChart = function() {
        var chartElement = document.getElementById('spp-admin-chart');
        if (!chartElement) {
            return;
        }

        try {
            var chartData = {};
            try {
                chartData = JSON.parse(chartElement.dataset.chartdata || '{"labels":[],"data":[]}');
            } catch (e) {
                console.error('Error parsing chart data:', e);
                chartData = {"labels":[],"data":[]};
            }

            var ctx = chartElement.getContext('2d');

            Str.get_string('studentcount', 'block_studentperformancepredictor').done(function(label) {
                new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: chartData.labels,
                        datasets: [{
                            label: label,
                            data: chartData.data,
                            backgroundColor: [
                                '#dc3545',  // High risk - Red
                                '#ffc107',  // Medium risk - Yellow
                                '#28a745'   // Low risk - Green
                            ],
                            borderWidth: 1,
                            hoverBackgroundColor: [
                                '#c82333',  // Darker red on hover
                                '#e0a800',  // Darker yellow on hover
                                '#218838'   // Darker green on hover
                            ]
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            yAxes: [{
                                ticks: {
                                    beginAtZero: true,
                                    precision: 0
                                },
                                gridLines: {
                                    drawBorder: true,
                                    color: 'rgba(0, 0, 0, 0.1)'
                                }
                            }],
                            xAxes: [{
                                gridLines: {
                                    display: false
                                }
                            }]
                        },
                        legend: {
                            display: false
                        },
                        tooltips: {
                            callbacks: {
                                label: function(tooltipItem, data) {
                                    var dataset = data.datasets[tooltipItem.datasetIndex];
                                    var total = dataset.data.reduce(function(previousValue, currentValue) {
                                        return previousValue + currentValue;
                                    });
                                    var currentValue = dataset.data[tooltipItem.index];
                                    var percentage = Math.round((currentValue / total) * 100);
                                    return data.labels[tooltipItem.index] + ': ' + currentValue + ' (' + percentage + '%)';
                                }
                            }
                        },
                        animation: {
                            duration: 1000,
                            easing: 'easeOutQuart'
                        }
                    }
                });
            }).fail(function() {
                new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: chartData.labels,
                        datasets: [{
                            label: 'Student count',
                            data: chartData.data,
                            backgroundColor: ['#dc3545', '#ffc107', '#28a745'],
                            borderWidth: 1,
                            hoverBackgroundColor: ['#c82333', '#e0a800', '#218838']
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false
                    }
                });
            });
        } catch (e) {
            console.error('Error initializing admin chart:', e);
            Str.get_string('charterror', 'block_studentperformancepredictor').done(function(msg) {
                chartElement.innerHTML = '<div class="alert alert-warning">' + msg + '</div>';
            }).fail(function() {
                chartElement.innerHTML = '<div class="alert alert-warning">Chart error</div>';
            });
        }
    };

    return {
        init: init,
        initTeacherChart: initTeacherChart,
        initAdminChart: initAdminChart
    };
});
