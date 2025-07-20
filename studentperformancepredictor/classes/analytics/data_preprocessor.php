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
 * Data preprocessor for Student Performance Predictor.
 *
 * @package    block_studentperformancepredictor
 * @copyright  2023 Your Name <your.email@example.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_studentperformancepredictor\analytics;

defined('MOODLE_INTERNAL') || die();

/**
 * Data preprocessor for student performance data.
 *
 * NOTE: As of the unified plugin architecture, all ML feature engineering and preprocessing
 * for model training and prediction is handled by the Python backend. This class is only
 * used for minimal validation or formatting before sending data to the backend.
 * Advanced preprocessing methods below are legacy and not used in the new workflow.
 */
class data_preprocessor {
    /** @var int Course ID */
    protected $courseid;

    /**
     * Constructor.
     * 
     * @param int $courseid Course ID
     */
    public function __construct($courseid) {
        $this->courseid = $courseid;
    }

    /**
     * Preprocess a dataset for training.
     *
     * In the new architecture, this should only perform minimal validation/formatting.
     * All ML preprocessing is handled by the Python backend.
     *
     * @param array $dataset Raw dataset
     * @return array Preprocessed dataset
     */
    public function preprocess_dataset($dataset) {
        // Minimal validation/formatting only. No ML feature engineering here.
        return $dataset;
    }

    /**
     * Legacy: Handle missing values in the dataset (not used in new backend-driven workflow).
     *
     * @param array $dataset Dataset with possible missing values
     * @return array Dataset with handled missing values
     */
    protected function handle_missing_values($dataset) {
        if (empty($dataset)) {
            return $dataset;
        }

        $columns = array_keys($dataset[0]);
        $columnMeans = array();
        $columnModes = array();
        $numRows = count($dataset);

        // Calculate means for numeric columns and modes for categorical columns
        foreach ($columns as $column) {
            $numericValues = array();
            $categoricalValues = array();
            foreach ($dataset as $row) {
                if (isset($row[$column]) && $row[$column] !== '' && $row[$column] !== null) {
                    if (is_numeric($row[$column])) {
                        $numericValues[] = $row[$column];
                    } else {
                        $categoricalValues[] = $row[$column];
                    }
                }
            }
            if (!empty($numericValues)) {
                $columnMeans[$column] = array_sum($numericValues) / count($numericValues);
            }
            if (!empty($categoricalValues)) {
                $valuesCount = array_count_values($categoricalValues);
                arsort($valuesCount);
                $columnModes[$column] = key($valuesCount);
            }
        }

        // Fill missing values with mean (numeric) or mode (categorical)
        foreach ($dataset as $i => $row) {
            foreach ($columns as $column) {
                if (!isset($row[$column]) || $row[$column] === '' || $row[$column] === null) {
                    if (isset($columnMeans[$column]) && (!isset($columnModes[$column]) || is_numeric($columnMeans[$column]))) {
                        $dataset[$i][$column] = $columnMeans[$column];
                    } else if (isset($columnModes[$column])) {
                        $dataset[$i][$column] = $columnModes[$column];
                    } else {
                        $dataset[$i][$column] = '';
                    }
                }
            }
        }
        return $dataset;
    }

    /**
     * Legacy: Encode categorical features to numeric values (not used in new backend-driven workflow).
     * 
     * @param array $dataset Dataset with categorical features
     * @return array Dataset with encoded features
     */
    protected function encode_categorical_features($dataset) {
        if (empty($dataset)) {
            return $dataset;
        }

        $columns = array_keys($dataset[0]);

        // Find categorical columns (non-numeric values)
        foreach ($columns as $column) {
            $hasNonNumeric = false;

            // Check a sample of rows to determine if column is categorical
            $sampleSize = min(10, count($dataset));
            for ($i = 0; $i < $sampleSize; $i++) {
                if (isset($dataset[$i][$column]) && !is_numeric($dataset[$i][$column])) {
                    $hasNonNumeric = true;
                    break;
                }
            }

            if ($hasNonNumeric) {
                // Create a mapping of unique values to numeric codes
                $uniqueValues = array();
                foreach ($dataset as $row) {
                    if (isset($row[$column]) && !isset($uniqueValues[$row[$column]])) {
                        $uniqueValues[$row[$column]] = count($uniqueValues);
                    }
                }

                // Apply the mapping
                foreach ($dataset as $i => $row) {
                    if (isset($row[$column])) {
                        $dataset[$i][$column] = isset($uniqueValues[$row[$column]]) ? 
                                             $uniqueValues[$row[$column]] : 0;
                    }
                }
            }
        }

        return $dataset;
    }

    /**
     * Legacy: Normalize numeric features to the range [0,1] (not used in new backend-driven workflow).
     * 
     * @param array $dataset Dataset with numeric features
     * @return array Dataset with normalized features
     */
    protected function normalize_features($dataset) {
        if (empty($dataset)) {
            return $dataset;
        }

        $columns = array_keys($dataset[0]);

        // Find min and max for each column
        $mins = array();
        $maxs = array();

        foreach ($columns as $column) {
            $values = array();
            foreach ($dataset as $row) {
                if (isset($row[$column]) && is_numeric($row[$column])) {
                    $values[] = $row[$column];
                }
            }

            if (!empty($values)) {
                $mins[$column] = min($values);
                $maxs[$column] = max($values);
            } else {
                $mins[$column] = 0;
                $maxs[$column] = 1;
            }
        }

        // Apply min-max normalization
        foreach ($dataset as $i => $row) {
            foreach ($columns as $column) {
                if (isset($row[$column]) && is_numeric($row[$column]) && 
                    $maxs[$column] > $mins[$column]) {
                    $dataset[$i][$column] = ($row[$column] - $mins[$column]) / 
                                         ($maxs[$column] - $mins[$column]);
                }
            }
        }

        return $dataset;
    }
}