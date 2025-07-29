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
 * Language strings for Student Performance Predictor.
 *
 * @package    block_studentperformancepredictor
 * @copyright  2023 Your Name <[Email]>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['pluginname'] = 'Student Performance Predictor';
$string['studentperformancepredictor:addinstance'] = 'Add a new Student Performance Predictor block';
$string['studentperformancepredictor:myaddinstance'] = 'Add a new Student Performance Predictor block to Dashboard';
$string['studentperformancepredictor:managemodels'] = 'Manage prediction models';
$string['studentperformancepredictor:view'] = 'View Student Performance Predictor';
$string['studentperformancepredictor:viewallpredictions'] = 'View all student predictions';
$string['studentperformancepredictor:viewdashboard'] = 'View Student Performance Predictor dashboard';
$string['studentperformancepredictor:manageglobalmodels'] = 'Manage global prediction models';

// General strings
$string['studentperformance'] = 'Your Performance Prediction';
$string['courseperformance'] = 'Course Performance Overview';
$string['modelmanagement'] = 'Model Management';
$string['risk'] = 'Risk level';
$string['passingchance'] = 'Passing chance';
$string['failingchance'] = 'Failing chance';
$string['riskdistribution'] = 'Risk distribution';
$string['riskdistributionchart'] = 'Student Risk Distribution Chart'; 
$string['suggestedactivities'] = 'Suggested activities';
$string['generalstudy'] = 'General study recommendation';
$string['lastupdate'] = 'Last updated: {$a}';
$string['studentcount'] = 'Student count';
$string['nocoursecontext'] = 'This block must be added to a course page';
$string['errorrendingblock'] = 'An error occurred while rendering the block';
$string['charterror'] = 'Error loading chart';
$string['nocoursesfound'] = 'No courses found where you can view predictions';
$string['jsrequired'] = 'This chart requires JavaScript to be enabled';
$string['nosuggestions'] = 'No suggestions available at this time';
$string['studentpredictionstable'] = 'Table of Student Predictions';

// Risk levels
$string['highrisk_label'] = 'High risk';
$string['mediumrisk_label'] = 'Medium risk';
$string['lowrisk_label'] = 'Low risk';
$string['unknownrisk'] = 'Unknown risk';

// Admin and models
$string['managemodels'] = 'Manage models';
$string['managedatasets'] = 'Manage datasets';
$string['refreshpredictions'] = 'Refresh predictions';
$string['refreshpredictionsdesc'] = 'Refresh all student performance predictions for this course using the active model. This may take some time.';
$string['trainnewmodel'] = 'Train new model';
$string['allmodels'] = 'All models';
$string['currentmodel'] = 'Current active model';
$string['modelname'] = 'Model name';
$string['algorithm'] = 'Algorithm';
$string['accuracy'] = 'Accuracy';
$string['status'] = 'Status';
$string['created'] = 'Created';
$string['actions'] = 'Actions';
$string['active'] = 'Active';
$string['inactive'] = 'Inactive';
$string['activate'] = 'Activate';
$string['deactivate'] = 'Deactivate';
$string['activatemodel'] = 'Activate model';
$string['activateglobalmodel'] = 'Activate global model';
$string['training'] = 'Training...';
$string['datasetname'] = 'Dataset name';
$string['datasetdescription'] = 'Dataset description';
$string['datasetfile'] = 'Dataset file';
$string['datasetformat'] = 'Dataset format';
$string['csvformat'] = 'CSV format';
$string['jsonformat'] = 'JSON format';
$string['uploaded'] = 'Uploaded';
$string['totalstudents'] = 'Total students';
$string['view'] = 'View';
$string['delete'] = 'Delete';
$string['refresh'] = 'Refresh';
$string['refreshing'] = 'Refreshing...';
$string['selectdataset'] = 'Select dataset';
$string['selectalgorithm'] = 'Select algorithm';
$string['selectcourse'] = 'Select a course';
$string['trainmodel'] = 'Train model';
$string['modelactivated'] = 'Model activated successfully';
$string['modeldeactivated'] = 'Model deactivated successfully';
$string['errorupdatingmodel'] = 'Error updating model status';
$string['modelnotincourse'] = 'The model does not belong to this course';
$string['uploading'] = 'Uploading...';
$string['upload'] = 'Upload';
$string['datasetsaved'] = 'Dataset saved successfully';
$string['datasetsaved_backend'] = 'Dataset saved successfully. It is now available for training models.';
$string['datasetsaveerror'] = 'Error saving dataset';
$string['uploadnewdataset'] = 'Upload new dataset';
$string['existingdatasets'] = 'Existing datasets';
$string['nocoursesavailable'] = 'No courses available where you can manage models';
$string['coursename'] = 'Course name';
$string['directorycreateerror'] = 'Could not create directory: {$a}';
$string['directorynotwritable'] = 'Directory is not writable: {$a}';
$string['nofileuploaded'] = 'No file was uploaded';
$string['fileuploaderror'] = 'Error uploading file';
$string['filetoolarge'] = 'The uploaded file is too large';
$string['filepartialuploaded'] = 'The file was only partially uploaded';
$string['invalidfileextension'] = 'Invalid file extension for the selected format';
$string['fileuploadfailed'] = 'File upload failed';
$string['datasetdeleted'] = 'Dataset deleted successfully';
$string['filedeleteerror'] = 'Error deleting dataset file';
$string['databasedeleteerror'] = 'Error deleting dataset record from database';
$string['datasetnotincourse'] = 'The dataset does not belong to this course';
$string['noactivemodel'] = 'No active model found for this course. Please train a model first.';
$string['noprediction'] = 'No prediction available. Please refresh predictions.';
$string['nodatasets'] = 'No datasets available. Please upload a dataset first.';
$string['datasetdeletecascade'] = 'Warning: Deleting a dataset will also delete all models trained from it.';
$string['datasetdeletecascadetitle'] = 'Delete dataset and associated models';
$string['columns'] = 'Columns';
$string['viewtasks'] = 'View task monitor';
$string['taskname'] = 'Task Name';
$string['nextruntime'] = 'Next Run Time';
$string['taskqueued'] = 'Queued';
$string['taskrunning'] = 'Running';
$string['notasks'] = 'No tasks found for this plugin.';
$string['property'] = 'Property';
$string['value'] = 'Value';
$string['back'] = 'Back';
$string['trainmodel_backenddesc'] = 'Train a model using this dataset and the Python backend.';
$string['training_model'] = 'Training model';
$string['training_already_scheduled'] = 'A model training task is already scheduled for this course.';
$string['model_training_queued'] = 'Model training has been queued. This process may take a few minutes.';
$string['model_training_queued_backend'] = 'Model training has been queued. The Python backend will handle the training process.';
$string['model_training_success_subject'] = 'Model training completed';
$string['model_training_success_message'] = 'The model "{$a->modelname}" for course "{$a->coursename}" has been trained successfully.';
$string['model_training_error_subject'] = 'Model training failed';
$string['model_training_error_message'] = 'There was an error training the model for course "{$a->coursename}": {$a->error}';
$string['dataset_not_found'] = 'The selected dataset was not found for this course.';
$string['task_scheduled_predictions'] = 'Scheduled prediction refresh';
$string['task_train_model'] = 'Train student performance prediction models';
$string['failed'] = 'Failed';

// New strings for backend integration
$string['nostudentdata'] = 'No comprehensive student data found for prediction.';
$string['errorpredicting'] = 'Error occurred during prediction.';
$string['trainingfailed'] = 'Model training failed.';
$string['invalidinput'] = 'Invalid input provided.';
$string['invaliddataset'] = 'Invalid dataset selected or dataset not found.';
$string['invalidcourseid'] = 'Invalid course ID.';
$string['error:nocourseid'] = 'Course ID could not be determined.';
$string['tablesnotinstalled'] = 'The Student Performance Predictor database tables are not installed. Please try reinstalling the plugin or contact your administrator. <a href="{$a}">Go to plugin installer</a>';
$string['configurebackend'] = 'Configure ML Backend';
$string['predictionbackendstatus'] = 'Prediction backend status';
$string['online'] = 'Online';
$string['offline'] = 'Offline';
$string['apiendpoints'] = 'API endpoints';
$string['apiversioninfo'] = 'API version information';
$string['apidebugmode'] = 'API debug mode';
$string['apiratelimits'] = 'API rate limits';
$string['apikeymanagement'] = 'API key management';
$string['connectiontest'] = 'Connection test';
$string['runtest'] = 'Run test';
$string['testresults'] = 'Test results';
$string['technicaldetails'] = 'Technical details';
$string['viewtechnicaldetails'] = 'View technical details';
$string['hidetechnicaldetails'] = 'Hide technical details';

// Suggestion actions
$string['markasviewed'] = 'Mark as viewed';
$string['markascompleted'] = 'Mark as completed';
$string['viewed'] = 'Viewed';
$string['completed'] = 'Completed';
$string['suggestion_marked_viewed'] = 'Suggestion marked as viewed';
$string['suggestion_marked_viewed_error'] = 'Error marking suggestion as viewed';
$string['suggestion_marked_completed'] = 'Suggestion marked as completed';
$string['suggestion_marked_completed_error'] = 'Error marking suggestion as completed';

// Algorithms
$string['algorithm_logisticregression'] = 'Logistic Regression';
$string['algorithm_randomforest'] = 'Random Forest';
$string['algorithm_svm'] = 'Support Vector Machine (SVM)';
$string['algorithm_decisiontree'] = 'Decision Tree';
$string['algorithm_knn'] = 'K-Nearest Neighbors';
$string['algorithmsettings'] = 'Algorithm settings';
$string['algorithmsettings_desc'] = 'Configure default algorithm settings for model training.';
$string['defaultalgorithm'] = 'Default algorithm';
$string['defaultalgorithm_desc'] = 'The default algorithm to use when training new models';
$string['algorithmparameters'] = 'Algorithm parameters';
$string['hyperparameters'] = 'Hyperparameters';
$string['advancedoptions'] = 'Advanced options';

// Reports
$string['detailedreport'] = 'Detailed report';
$string['backtocourse'] = 'Back to course';
$string['predictiondetails'] = 'Prediction details';
$string['predictionfor'] = 'Prediction for {$a}';
$string['nopredictionavailable'] = 'No prediction is available for this student';
$string['predictiongenerated'] = 'Prediction generated successfully';
$string['predictionerror'] = 'Error generating prediction';
$string['errorloadingprediction'] = 'Error loading prediction data';
$string['viewdetails'] = 'View details';
$string['somepredictionsmissing'] = 'There are {$a} students without predictions. Consider refreshing predictions.';
$string['studentswithoutpredictions_backend'] = '{$a} students currently do not have predictions. Consider refreshing all predictions.';
$string['refreshallpredictions'] = 'Refresh all predictions';
$string['refreshexplanation'] = 'Refreshing predictions will generate new predictions for all students based on their current activity and performance data.';
$string['backtomodels'] = 'Back to models';
$string['currentpredictionstats'] = 'Current prediction statistics';
$string['lastrefreshtime'] = 'Last refresh: {$a}';
$string['downloadreport'] = 'Download report';
$string['exportdata'] = 'Export data';

// Settings
$string['backendsettings'] = 'Backend integration';
$string['backendsettings_desc'] = 'Configure the Python backend for model training and prediction.';
$string['python_api_url'] = 'Python backend API URL';
$string['python_api_url_desc'] = 'The URL of the Python backend endpoint (e.g., https://your-app-name.up.railway.app).';
$string['python_api_key'] = 'Python backend API key';
$string['python_api_key_desc'] = 'The API key for authenticating requests to the Python backend.';
$string['riskthresholds'] = 'Risk thresholds';
$string['riskthresholds_desc'] = 'Thresholds for determining risk levels based on pass probability';
$string['lowrisk'] = 'Low risk threshold';
$string['lowrisk_desc'] = 'Students with pass probability above this value are considered low risk (0-1)';
$string['mediumrisk'] = 'Medium risk threshold';
$string['mediumrisk_desc'] = 'Students with pass probability above this value but below the low risk threshold are considered medium risk (0-1)';
$string['predictionthresholds'] = 'Prediction thresholds';

// Tasks and notifications
$string['prediction_refresh_complete_subject'] = 'Prediction refresh completed';
$string['prediction_refresh_complete_message'] = 'The prediction refresh for course {$a->coursename} has completed. Processed {$a->total} students with {$a->success} successful predictions and {$a->errors} errors.';
$string['prediction_refresh_complete_small'] = 'Prediction refresh completed';
$string['predictionsrefreshqueued'] = 'Prediction refresh has been queued';
$string['predictionsrefresherror'] = 'Error queueing prediction refresh';
$string['refreshconfirmation'] = 'Are you sure you want to refresh predictions for all students? This may take some time.';
$string['refresherror'] = 'Error refreshing predictions';
$string['confirmactivate'] = 'Are you sure you want to activate this model? This will deactivate any currently active model.';
$string['confirmdeactivate'] = 'Are you sure you want to deactivate this model? No predictions will be generated until another model is activated.';
$string['confirmdeletedataset'] = 'Are you sure you want to delete this dataset? This will also delete all models trained with it.';
$string['invalidrequest'] = 'Invalid request';
$string['actionerror'] = 'Error performing action';
$string['uploaderror'] = 'Error uploading file';
$string['datasetformaterror'] = 'Error with dataset format. Please check the file.';
$string['datasetuploadretry'] = 'Please try uploading the dataset again.';

// Events
$string['event_model_trained'] = 'Prediction model trained';

// Suggestions strings
$string['suggestion_forum_low'] = 'Engaging in this forum discussion will help deepen your understanding of the course material.';
$string['suggestion_resource_low'] = 'Reviewing this resource will reinforce your knowledge of key concepts.';
$string['suggestion_quiz_medium'] = 'Taking this quiz will help identify areas where you need to focus more attention.';
$string['suggestion_forum_medium'] = 'Participating in this forum discussion will help clarify concepts you may be struggling with.';
$string['suggestion_assign_medium'] = 'Completing this assignment will strengthen your skills and understanding.';
$string['suggestion_resource_medium'] = 'Studying this resource is important for improving your understanding of the course material.';
$string['suggestion_quiz_high'] = 'This quiz is critical for your success. Taking it will help identify key areas for improvement.';
$string['suggestion_forum_high'] = 'Actively participating in this forum is essential for your success in this course.';
$string['suggestion_assign_high'] = 'Completing this assignment is urgent and will significantly impact your course performance.';
$string['suggestion_resource_high'] = 'This resource contains critical information you need to review immediately.';
$string['suggestion_workshop_high'] = 'This peer assessment activity will provide valuable feedback to improve your understanding.';
$string['suggestion_time_management'] = 'Consider creating a study schedule to better manage your coursework.';
$string['suggestion_engagement'] = 'Try to engage more regularly with the course materials and activities.';
$string['suggestion_study_group'] = 'Consider forming or joining a study group with classmates to discuss course topics.';
$string['suggestion_instructor_help'] = 'It would be beneficial to schedule a meeting with your instructor to discuss your progress.';
$string['suggestion_targeted_area'] = 'This is particularly important for improving your understanding of {$a->area}.';
$string['suggestion_weak_area'] = 'Focus more attention on {$a->area} as your performance in this area needs improvement.';
$string['personalizedsuggestions'] = 'Personalized suggestions';
$string['actionsuggestion'] = 'Suggested action';
$string['suggestedresources'] = 'Suggested resources';
$string['usesuggestions'] = 'Use these suggestions to improve your performance';
$string['accessresource'] = 'Access resource';
$string['resourcewillhelp'] = 'This resource will help you improve your performance';

// Privacy strings
$string['privacy:metadata:block_spp_predictions'] = 'Information about student performance predictions';
$string['privacy:metadata:block_spp_predictions:modelid'] = 'The ID of the model used for prediction';
$string['privacy:metadata:block_spp_predictions:courseid'] = 'The ID of the course the prediction is for';
$string['privacy:metadata:block_spp_predictions:userid'] = 'The ID of the user the prediction is for';
$string['privacy:metadata:block_spp_predictions:passprob'] = 'The predicted probability of passing';
$string['privacy:metadata:block_spp_predictions:riskvalue'] = 'The calculated risk level';
$string['privacy:metadata:block_spp_predictions:predictiondata'] = 'Additional prediction details';
$string['privacy:metadata:block_spp_predictions:timecreated'] = 'Time the prediction was created';
$string['privacy:metadata:block_spp_predictions:timemodified'] = 'Time the prediction was last modified';

$string['privacy:metadata:block_spp_suggestions'] = 'Information about suggestions for improving student performance';
$string['privacy:metadata:block_spp_suggestions:predictionid'] = 'The ID of the prediction this suggestion is based on';
$string['privacy:metadata:block_spp_suggestions:courseid'] = 'The ID of the course this suggestion is for';
$string['privacy:metadata:block_spp_suggestions:userid'] = 'The ID of the user this suggestion is for';
$string['privacy:metadata:block_spp_suggestions:cmid'] = 'The course module ID being suggested';
$string['privacy:metadata:block_spp_suggestions:resourcetype'] = 'The type of resource being suggested';
$string['privacy:metadata:block_spp_suggestions:resourceid'] = 'The ID of the resource being suggested';
$string['privacy:metadata:block_spp_suggestions:priority'] = 'The priority of the suggestion';
$string['privacy:metadata:block_spp_suggestions:reason'] = 'The reason for the suggestion';
$string['privacy:metadata:block_spp_suggestions:timecreated'] = 'Time the suggestion was created';
$string['privacy:metadata:block_spp_suggestions:viewed'] = 'Whether the suggestion has been viewed';
$string['privacy:metadata:block_spp_suggestions:completed'] = 'Whether the suggestion has been completed';

$string['privacy:predictionpath'] = 'Prediction {$a}';
$string['privacy:suggestionpath'] = 'Suggestion {$a}';

// Backend monitoring strings
$string['backendmonitoring'] = 'Backend monitoring';
$string['backendmonitoring_desc'] = 'Tools to monitor the Python ML backend';
$string['testbackend'] = 'Test backend connection';
$string['testbackendbutton'] = 'Test connection';
$string['testingconnection'] = 'Testing connection to ML backend';
$string['testingbackendurl'] = 'Testing URL: {$a}';
$string['backendconnectionsuccess'] = 'Success! Connection to ML backend established.';
$string['backendconnectionfailed'] = 'Connection failed with HTTP code: {$a}';
$string['backendconnectionerror'] = 'Connection error: {$a}';
$string['backenddetails'] = 'Backend details';
$string['errormessage'] = 'Error message';
$string['troubleshootingguide'] = 'Troubleshooting guide';
$string['troubleshoot1'] = 'Verify the Python backend is running (uvicorn ml_backend:app)';
$string['troubleshoot2'] = 'Ensure the API URL in settings is correct (e.g., https://your-app-name.up.railway.app)';
$string['troubleshoot3'] = 'Check that the API key matches the one in the backend .env file';
$string['troubleshoot4'] = 'For Windows/XAMPP users: Make sure port 5000 is not blocked by firewall';
$string['troubleshoot5'] = 'Try running the backend with administrator privileges';
$string['startbackendcommand'] = 'Command to start backend';
$string['backsettings'] = 'Back to settings';
$string['debugsettings'] = 'Debug settings';
$string['debugsettings_desc'] = 'Configure debugging options';
$string['enabledebug'] = 'Enable debug mode';
$string['enabledebug_desc'] = 'Show detailed error messages and log additional information';
$string['jserror'] = 'JavaScript error';
$string['trainingschedulefailed'] = 'Failed to schedule training task';
$string['debugoutput'] = 'Debug output';
$string['viewlogs'] = 'View logs';

// Global model strings
$string['globalmodelsettings'] = 'Global model settings';
$string['globalmodelsettings_desc'] = 'Configure settings for the global prediction model.';
$string['enableglobalmodel'] = 'Enable global prediction model';
$string['enableglobalmodel_desc'] = 'When enabled, a global model can be trained and used across all courses. This is useful for new courses or courses with limited data.';
$string['prefercoursemodelsfirst'] = 'Prefer course-specific models';
$string['prefercoursemodelsfirst_desc'] = 'When enabled, course-specific models are used first if available, falling back to the global model if needed.';
$string['trainglobalmodel'] = 'Train global model';
$string['trainglobalmodel_desc'] = 'A global model uses data from multiple courses to predict student performance across all courses. This is especially useful for new courses or those with limited historical data.';
$string['existingglobalmodels'] = 'Existing global models';
$string['globalmodeldisabled'] = 'Global models are currently disabled in the plugin settings.';
$string['enableglobalmodelsettings'] = 'Enable global models in settings';
$string['globaldatasetmanagement'] = 'Global dataset management';
$string['globaldatasetexplanation'] = 'Global datasets can be used to train models that apply across all courses. These are particularly useful for providing predictions in new courses or courses with limited historical data.';
$string['usingcrosscoursemodel'] = 'Using cross-course prediction model';
$string['manageglobalmodels'] = 'Manage global models';

// Dashboard and selector strings
$string['courseselectorlabel'] = 'Select course to view';
$string['refreshinterval'] = 'Prediction refresh interval';
$string['refreshinterval_desc'] = 'Minimum time in hours between automatic prediction refreshes for a course';
$string['multiplecoursesavailable'] = 'Multiple courses available';
$string['nocourseselected'] = 'No course selected';
$string['viewperformancein'] = 'View performance in';
$string['automaticrefresh'] = 'Automatic refresh';
$string['enableautomaticrefresh'] = 'Enable automatic refresh';
$string['refreshschedule'] = 'Refresh schedule';

// Error handling improvements
$string['backendconnectionerror'] = 'Could not connect to the prediction backend. Please check your settings and make sure the backend service is running.';
$string['invalidmodelresponse'] = 'Invalid response received from the model training service.';
$string['incompletemodel'] = 'The trained model data is incomplete.';
$string['modelloadingerror'] = 'Error loading the prediction model.';
$string['featuremissingerror'] = 'One or more required features are missing from the student data.';
$string['refreshallpredictionsconfirm'] = 'Are you sure you want to refresh predictions for all students? This process may take several minutes.';
$string['predictionsupdated'] = 'Predictions updated successfully.';
$string['predictionsfailed'] = 'Failed to update predictions.';
$string['modeltrainingqueued'] = 'Model training has been queued and will start shortly.';
$string['datasetprocessing'] = 'Dataset is being processed...';
$string['trainingmodel'] = 'Training model...';
$string['railwaydeployment'] = 'Railway deployment instructions';
$string['railwaydeployment_desc'] = 'Instructions for deploying the ML backend on Railway.';
$string['deploymentsteps'] = 'Deployment steps:';
$string['deploystep1'] = '1. Create a new project in Railway';
$string['deploystep2'] = '2. Connect to your GitHub repository with the ML backend code';
$string['deploystep3'] = '3. Configure environment variables: API_KEY, DEBUG, PORT';
$string['deploystep4'] = '4. Start the deployment';
$string['deploystep5'] = '5. Copy the generated URL to the Python API URL setting';
$string['modeltrainingprogress'] = 'Model training in progress...';
$string['backendapiurl'] = 'Backend API URL';
$string['backendapikey'] = 'Backend API Key';
$string['predictionjobqueued'] = 'Prediction job has been queued and will run shortly.';
$string['jobstatus'] = 'Job status';
$string['fetchingpredictions'] = 'Fetching predictions...';
$string['processingdata'] = 'Processing data...';
$string['trainingcomplete'] = 'Training complete';
$string['trainingfailed'] = 'Training failed';
$string['modelmetrics'] = 'Model metrics';
$string['datapreprocessing'] = 'Data preprocessing';
$string['uploadingdataset'] = 'Uploading dataset...';
$string['preparingdata'] = 'Preparing data...';
$string['datavalidation'] = 'Data validation';
$string['validatingdata'] = 'Validating data...';
$string['dataimportcomplete'] = 'Data import complete';
$string['errorprocessingdata'] = 'Error processing data';
$string['retryupload'] = 'Retry upload';
$string['backendstarting'] = 'Backend starting...';
$string['backendready'] = 'Backend ready';
$string['connectingtobackend'] = 'Connecting to backend...';
$string['connectionestablished'] = 'Connection established';
$string['connectionfailed'] = 'Connection failed';
$string['retryconnection'] = 'Retry connection';
$string['trainingstarted'] = 'Training started';
$string['trainingprogress'] = 'Training progress';
$string['preparingmodel'] = 'Preparing model...';
$string['generatingfeatures'] = 'Generating features...';
$string['trainingphase'] = 'Training phase';
$string['evaluationphase'] = 'Evaluation phase';
$string['finalizingmodel'] = 'Finalizing model...';
$string['savingmodel'] = 'Saving model...';
$string['modelready'] = 'Model ready';
$string['modelactivated'] = 'Model activated';
$string['predictionsgenerated'] = 'Predictions generated';
$string['suggestionsgenerated'] = 'Suggestions generated';
$string['modelaccuracy'] = 'Model accuracy';
$string['modelevaluation'] = 'Model evaluation';
$string['modelperformance'] = 'Model performance';
$string['modelcomparison'] = 'Model comparison';
$string['modelselection'] = 'Model selection';
$string['railwayhelp'] = 'Help with Railway';
$string['apidocs'] = 'API documentation';
$string['enableapidebug'] = 'Enable API debug mode';
$string['disableapidebug'] = 'Disable API debug mode';
$string['resetapikey'] = 'Reset API key';
$string['resetapikeyconfirm'] = 'Are you sure you want to reset the API key? All current connections will be invalidated.';
$string['apikeyresetsuccessful'] = 'API key reset successful';

// Student prediction strings
$string['generateprediction'] = 'Generate my prediction';
$string['generatingprediction'] = 'Generating prediction...';
$string['updateprediction'] = 'Update prediction';
$string['updatingprediction'] = 'Updating prediction...';
$string['predictiongenerated'] = 'Prediction generated successfully';
$string['predictionerror'] = 'Error generating prediction';
$string['predictionfailed'] = 'Failed to generate prediction';
$string['performancehistory'] = 'Performance History';
$string['improveperformance'] = 'Improve performance';
$string['performancetrend'] = 'Performance trend';
$string['improving'] = 'Improving';
$string['declining'] = 'Declining';
$string['stable'] = 'Stable';
$string['latestprediction'] = 'Latest prediction';
$string['viewallhistory'] = 'View all prediction history';
$string['predictionnote'] = 'Note: This prediction is based on your current activity and performance in this course.';
$string['clicktorefresh'] = 'Click to refresh your prediction';
$string['predictionrefreshed'] = 'Your prediction has been refreshed';
$string['nopredicitiontext'] = 'No prediction available yet. Click the button below to generate your first prediction.';
$string['predictionnewuser'] = 'Welcome! Generate your first prediction to see how you\'re doing in this course.';
