# Student Performance Predictor - ML Backend

This is the machine learning backend for the Moodle Student Performance Predictor plugin.

## Railway Deployment

1. **Create a Railway account**
   - Sign up at [railway.app](https://railway.app)

2. **Create a new project on Railway**
   - From the Railway dashboard, click "New Project"
   - Select "Deploy from GitHub repo" 
   - Connect your GitHub account and select this repository

3. **Configure environment variables**
   - In your Railway project, go to Variables
   - Add the following variables:
     - `API_KEY`: Your secret API key (must match the key in Moodle plugin)
     - `DEBUG`: Set to `true` for debugging or `false` for production
     - `PORT`: Set to `8000` (Railway sets this automatically)

4. **Deploy the service**
   - Railway will automatically deploy your service
   - Wait for the deployment to complete

5. **Get your service URL**
   - After deployment, Railway will provide a URL for your service
   - This will look like `https://yourapp.railway.app`
   - Copy this URL for configuring the Moodle plugin

## Local Development

1. Create a virtual environment: `python -m venv venv`
2. Activate the environment:
   - Windows: `venv\Scripts\activate`
   - Linux/Mac: `source venv/bin/activate`
3. Install dependencies: `pip install -r requirements.txt`
4. Create a `.env` file with your API_KEY
5. Run the server: 
   - Windows: `start_backend.bat`
   - Linux/Mac: `./start_backend.sh`
6. Test locally at http://localhost:5000/health

## API Endpoints

- **Health Check**: `GET /health`
- **Train Model**: `POST /train`
- **Make Prediction**: `POST /predict`

## API Reference

### Health Check
GET /health

Returns the current status of the API.

### Train Model

POST /train Headers: X-API-Key: your_api_key

Body: { "courseid": 123, "dataset_filepath": "/path/to/dataset.csv", "algorithm": "randomforest", "target_column": "final_outcome", "id_columns": ["student_id"] }

Trains a new model using the specified dataset and algorithm.

### Make Prediction

POST /predict Headers: X-API-Key: your_api_key

Body: { "model_id": "model_uuid", "features": { "activity_level": 10, "submission_count": 5, "grade_average": 0.85, "grade_count": 12 } }

Makes a prediction using the specified model.
