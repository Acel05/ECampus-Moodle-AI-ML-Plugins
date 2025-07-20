# Student Performance Predictor for Moodle

## Overview

The Student Performance Predictor is a Moodle block plugin that uses machine learning to predict student performance in courses. It helps instructors identify at-risk students and provides personalized recommendations for students to improve their performance.

## Directory Structure Explanation

- **admin/** - Administrative interface files
  - `activatemodel.php` - Handles model activation/deactivation
  - `ajax_delete_dataset.php` - AJAX handler for dataset deletion
  - `ajax_refresh_predictions.php` - AJAX handler for refreshing predictions
  - `ajax_toggle_model.php` - AJAX handler for toggling model status
  - `managedatasets.php` - Interface for managing training datasets
  - `managemodels.php` - Interface for managing prediction models
  - `refreshpredictions.php` - Handles prediction refreshing
  - `train_model.php` - Initiates model training
  - `upload_dataset.php` - Handles dataset uploads
  - `viewdataset.php` - Shows dataset details
  - `viewtasks.php` - Shows task monitoring interface

- **amd/src/** - JavaScript modules
  - `admin_interface.js` - Admin interface functionality
  - `chart_renderer.js` - Chart visualization functionality
  - `prediction_viewer.js` - Prediction viewing and interaction

- **classes/** - PHP classes
  - **analytics/** - ML integration classes
    - `data_preprocessor.php` - Prepares data for ML
    - `model_trainer.php` - Trains prediction models
    - `predictor.php` - Makes predictions using models
    - `suggestion_generator.php` - Generates improvement suggestions
    - `training_manager.php` - Manages training workflow
  - **event/** - Event handlers
    - `model_trained.php` - Event triggered when model training completes
  - **external/** - External API
    - `api.php` - External API endpoints
  - **output/** - Output renderers
    - `renderer.php` - Main renderer
    - `student_view.php` - Student dashboard renderer
    - `teacher_view.php` - Teacher dashboard renderer
  - **privacy/** - GDPR compliance
    - `provider.php` - Privacy API implementation
  - **task/** - Background tasks
    - `adhoc_train_model.php` - Ad-hoc training task
    - `refresh_predictions.php` - Refreshes predictions
    - `scheduled_predictions.php` - Scheduled prediction updates

- **datasets/** - Directory for storing training datasets

- **db/** - Database and access definitions
  - `access.php` - Capability definitions
  - `install.xml` - Database schema
  - `services.php` - Web service definitions
  - `tasks.php` - Task definitions
  - `upgrade.php` - Plugin upgrade handling

- **lang/en/** - Language strings
  - `block_studentperformancepredictor.php` - English language strings

- **models/** - Directory for storing trained models

- **pix/** - Plugin images
  - `icon.png` - Plugin icon

- **templates/** - Mustache templates
  - `admin_settings.mustache` - Admin settings template
  - `prediction_details.mustache` - Prediction details template
  - `student_dashboard.mustache` - Student dashboard template
  - `teacher_dashboard.mustache` - Teacher dashboard template

- `block_studentperformancepredictor.php` - Main block class
- `lib.php` - Library functions
- `ml_backend.py` - Python ML backend
- `README.md` - Documentation
- `reports.php` - Detailed reports page
- `settings.php` - Plugin settings
- `styles.css` - CSS styles
- `version.php` - Version information

## Requirements

- Moodle 4.1 or higher
- PHP 7.4 or higher
- Python 3.7 or higher (for the ML backend)
- Required Python packages:
  - fastapi
  - uvicorn
  - scikit-learn
  - pandas
  - joblib
  - python-dotenv

## Installation

### Step 1: Install the Moodle Plugin

1. Download the plugin zip file
2. Log in to your Moodle site as an administrator
3. Go to Site administration > Plugins > Install plugins
4. Upload the plugin zip file
5. Follow the on-screen instructions to complete the installation

### Step 2: Set up the Python Backend

1. Navigate to the plugin directory:

cd /path/to/moodle/blocks/studentperformancepredictor

2. Install required Python packages:

pip install fastapi uvicorn scikit-learn pandas joblib python-dotenv

3. Create a `.env` file (based on .env.example) to set your API key:

API_KEY=your_secure_key_here

4. Start the Python backend:

uvicorn ml_backend:app --host 0.0.0.0 --port 5000

For Windows/XAMPP users:
- Make sure port 5000 is not blocked by Windows Firewall
- You may need to run the command prompt as administrator

### Step 3: Configure the Plugin

1. In Moodle, go to Site administration > Plugins > Blocks > Student Performance Predictor
2. Configure the backend URL (default: http://localhost:5000)
3. Enter the API key you set in the `.env` file
4. Adjust risk thresholds and other settings as needed

## How to Use the Plugin

### For Administrators

1. **Add the Block**: Add the Student Performance Predictor block to a course page
2. **Manage Datasets**:
   - Go to "Manage datasets" in the block
   - Upload a training dataset (CSV or JSON format)
   - The dataset should contain student activity features and a final outcome column
3. **Train Models**:
   - Go to "Manage models" in the block
   - Select a dataset and algorithm
   - Click "Train Model" to start the training process
4. **Activate Models**:
   - Once training is complete, activate the model to enable predictions
   - Only one model can be active per course

### For Teachers

1. **View Dashboard**:
   - The block shows an overview of student risk distribution
   - See how many students are at high, medium, and low risk
2. **Access Detailed Reports**:
   - Click "Detailed report" to see individual student predictions
   - Sort and filter students by risk level
3. **Refresh Predictions**:
   - Click "Refresh predictions" to update predictions with current data
4. **Intervene with At-Risk Students**:
   - Identify students who need help based on risk levels
   - Use the system's suggestions to guide interventions

### For Students

1. **View Personal Prediction**:
   - See their own performance prediction and risk level
   - View the probability of passing the course
2. **Access Personalized Suggestions**:
   - Review recommendations for improvement
   - Suggestions are tailored to their specific situation and risk level
3. **Track Progress**:
   - Mark suggestions as viewed or completed
   - Monitor changes in prediction as they complete activities

## How the Plugin Works

### Data Flow and Architecture

1. **Data Collection**:
   - The plugin collects student activity data from Moodle (course access, assignment submissions, quiz attempts, etc.)
   - Administrators upload historical datasets for training

2. **Model Training**:
   - The training dataset is sent to the Python backend
   - ML models are trained using scikit-learn algorithms
   - Model files and metadata are stored for future use

3. **Prediction Generation**:
   - When predictions are requested, current student data is sent to the backend
   - The active model makes predictions about student outcomes
   - Results are stored in the Moodle database

4. **Suggestion Generation**:
   - Based on predictions, the system generates tailored suggestions
   - Suggestions prioritize activities that could help improve performance
   - Different suggestions are provided based on risk level

5. **Visualization and Reporting**:
   - Predictions and statistics are visualized in dashboards
   - Charts show risk distribution and prediction confidence
   - Reports allow detailed analysis of student performance

### Backend-Frontend Integration

The plugin uses a hybrid architecture:
- PHP code in Moodle handles the user interface, data management, and workflow
- Python backend provides the machine learning capabilities
- Communication happens via REST API calls between Moodle and the Python service

## Troubleshooting

### Common Issues

1. **Backend Connection Errors**:
   - Verify the Python backend is running
   - Check the API URL in plugin settings
   - Ensure the API key matches in both Moodle settings and .env file
   - Check if firewall is blocking port 5000

2. **Model Training Failures**:
   - Check the dataset format (must include target/outcome column)
   - Ensure Python dependencies are installed correctly
   - Check ML backend logs for detailed error messages

3. **Missing Predictions**:
   - Verify an active model exists for the course
   - Try refreshing predictions manually
   - Check if students have sufficient activity data for prediction

4. **Performance Issues**:
   - Large datasets may require more processing time
   - Consider scheduling prediction updates during off-peak hours

## License

This plugin is licensed under the GNU General Public License v3.0.