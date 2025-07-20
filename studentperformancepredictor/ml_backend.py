#!/usr/bin/env python3
"""
Machine Learning Backend for Student Performance Predictor Moodle Plugin

This module provides a REST API for model training and prediction.
It's designed to be run separately from Moodle and communicate via HTTP.

Usage:
    uvicorn ml_backend:app --host 0.0.0.0 --port 5000

Requirements:
    - fastapi
    - uvicorn
    - scikit-learn
    - pandas
    - joblib
    - python-dotenv
"""

import os
import uuid
import json
import logging
from datetime import datetime
from typing import Dict, List, Optional, Union, Any

import pandas as pd
import numpy as np
import joblib
from fastapi import FastAPI, HTTPException, Depends, Header, Request, status
from fastapi.responses import JSONResponse
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel, Field
from dotenv import load_dotenv

# Load environment variables from .env file
load_dotenv()

# Configure logging
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(name)s - %(levelname)s - %(message)s',
    handlers=[
        logging.FileHandler("ml_backend.log"),
        logging.StreamHandler()
    ]
)
logger = logging.getLogger(__name__)

# Set up FastAPI app
app = FastAPI(
    title="Student Performance Predictor API",
    description="Machine Learning API for the Moodle Student Performance Predictor block",
    version="1.0.0"
)

# Enable CORS for XAMPP/local development
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],  # In production, restrict this to your Moodle server
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

# Get API key from environment
API_KEY = os.getenv("API_KEY", "changeme")

# Storage paths
MODELS_DIR = os.path.join(os.getcwd(), os.getenv("MODELS_DIR", "models"))
DATASETS_DIR = os.path.join(os.getcwd(), os.getenv("DATASETS_DIR", "datasets"))

# Create directories if they don't exist
os.makedirs(MODELS_DIR, exist_ok=True)
os.makedirs(DATASETS_DIR, exist_ok=True)

# Models cache to avoid reloading models for each prediction
MODEL_CACHE = {}

# Request and response models
class HealthResponse(BaseModel):
    status: str
    time: str
    version: str

class TrainRequest(BaseModel):
    courseid: int
    dataset_filepath: str
    algorithm: str
    userid: Optional[int] = None
    target_column: Optional[str] = "final_outcome"
    test_size: Optional[float] = 0.2
    model_params: Optional[Dict] = {}

class TrainResponse(BaseModel):
    model_id: str
    model_path: str
    algorithm: str
    metrics: Dict
    feature_names: List[str]
    trained_at: str

class PredictRequest(BaseModel):
    model_id: str
    features: List[float]

class PredictResponse(BaseModel):
    prediction: int
    probabilities: List[float]
    model_id: str
    prediction_time: str

# API key verification dependency
async def verify_api_key(x_api_key: str = Header(...)):
    if x_api_key != API_KEY:
        logger.warning("Invalid API key attempt")
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Invalid API key"
        )
    return x_api_key

# Exception handler for all errors
@app.exception_handler(Exception)
async def general_exception_handler(request: Request, exc: Exception):
    logger.exception("Unhandled exception")
    return JSONResponse(
        status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
        content={"detail": str(exc)}
    )

@app.get("/health", response_model=HealthResponse)
async def health_check():
    """Health check endpoint to verify the API is running"""
    return {
        "status": "ok",
        "time": datetime.now().isoformat(),
        "version": "1.0.0"
    }

@app.post("/train", response_model=TrainResponse, dependencies=[Depends(verify_api_key)])
async def train_model(request: TrainRequest):
    """
    Train a machine learning model with the provided dataset.
    """
    logger.info(f"Training request received for course {request.courseid} using {request.algorithm}")
    logger.debug(f"Full request data: {request}")

    try:
        # Normalize filepath for Windows compatibility
        dataset_filepath = request.dataset_filepath.replace('\\', '/')
        logger.debug(f"Normalized file path: {dataset_filepath}")

        # Check if file exists
        if not os.path.exists(dataset_filepath):
            error_msg = f"Dataset file not found: {dataset_filepath}"
            logger.error(error_msg)
            raise HTTPException(
                status_code=status.HTTP_404_NOT_FOUND,
                detail=error_msg
            )

        # Log file information
        logger.debug(f"File exists: {os.path.exists(dataset_filepath)}")
        logger.debug(f"File size: {os.path.getsize(dataset_filepath)} bytes")
        logger.debug(f"File readable: {os.access(dataset_filepath, os.R_OK)}")

        # Determine file format based on extension
        file_extension = os.path.splitext(request.dataset_filepath)[1].lower()
        if file_extension == '.csv':
            df = pd.read_csv(request.dataset_filepath)
        elif file_extension == '.json':
            df = pd.read_json(request.dataset_filepath)
        else:
            raise HTTPException(
                status_code=status.HTTP_400_BAD_REQUEST,
                detail=f"Unsupported file format: {file_extension}"
            )

        # Check if target column exists
        if request.target_column not in df.columns:
            raise HTTPException(
                status_code=status.HTTP_400_BAD_REQUEST,
                detail=f"Target column '{request.target_column}' not found in dataset"
            )

        # Prepare data
        X = df.drop(columns=[request.target_column])
        y = df[request.target_column]

        # Keep track of feature names
        feature_names = X.columns.tolist()

        # Handle categorical features
        categorical_columns = X.select_dtypes(include=['object']).columns
        X = pd.get_dummies(X, columns=categorical_columns, drop_first=True)

        # Split data into training and testing sets
        from sklearn.model_selection import train_test_split
        X_train, X_test, y_train, y_test = train_test_split(
            X, y, test_size=request.test_size, random_state=42
        )

        # Choose and train the model
        model = None
        if request.algorithm == 'randomforest':
            from sklearn.ensemble import RandomForestClassifier
            model = RandomForestClassifier(
                n_estimators=request.model_params.get('n_estimators', 100),
                random_state=42
            )
        elif request.algorithm == 'logisticregression':
            from sklearn.linear_model import LogisticRegression
            model = LogisticRegression(
                C=request.model_params.get('C', 1.0),
                random_state=42,
                max_iter=1000  # Increase max iterations for convergence
            )
        elif request.algorithm == 'svm':
            from sklearn.svm import SVC
            model = SVC(
                C=request.model_params.get('C', 1.0),
                probability=True,
                random_state=42
            )
        elif request.algorithm == 'decisiontree':
            from sklearn.tree import DecisionTreeClassifier
            model = DecisionTreeClassifier(
                max_depth=request.model_params.get('max_depth', None),
                random_state=42
            )
        elif request.algorithm == 'knn':
            from sklearn.neighbors import KNeighborsClassifier
            model = KNeighborsClassifier(
                n_neighbors=request.model_params.get('n_neighbors', 5)
            )
        else:
            raise HTTPException(
                status_code=status.HTTP_400_BAD_REQUEST,
                detail=f"Unsupported algorithm: {request.algorithm}"
            )

        # Train the model
        model.fit(X_train, y_train)

        # Evaluate the model
        from sklearn.metrics import accuracy_score, precision_score, recall_score, f1_score
        y_pred = model.predict(X_test)
        metrics = {
            "accuracy": float(accuracy_score(y_test, y_pred)),
            "precision": float(precision_score(y_test, y_pred, zero_division=0)),
            "recall": float(recall_score(y_test, y_pred, zero_division=0)),
            "f1": float(f1_score(y_test, y_pred, zero_division=0))
        }

        # Generate a unique model ID
        model_id = str(uuid.uuid4())

        # Create a model directory for this course if it doesn't exist
        course_models_dir = os.path.join(MODELS_DIR, f"course_{request.courseid}")
        os.makedirs(course_models_dir, exist_ok=True)

        # Save the model with feature names
        model_filename = f"{model_id}.joblib"
        model_path = os.path.join(course_models_dir, model_filename)
        joblib.dump({
            'model': model,
            'feature_names': feature_names,
            'algorithm': request.algorithm,
            'trained_at': datetime.now().isoformat()
        }, model_path)

        # Add to cache
        MODEL_CACHE[model_id] = {
            'model': model,
            'feature_names': feature_names
        }

        logger.info(f"Model {model_id} trained successfully with accuracy {metrics['accuracy']}")

        return {
            "model_id": model_id,
            "model_path": model_path,
            "algorithm": request.algorithm,
            "metrics": metrics,
            "feature_names": feature_names,
            "trained_at": datetime.now().isoformat()
        }

    except Exception as e:
        stack_trace = traceback.format_exc()
        error_msg = f"Error training model: {str(e)}\n{stack_trace}"
        logger.exception(error_msg)
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=error_msg
        )

@app.post("/predict", response_model=PredictResponse, dependencies=[Depends(verify_api_key)])
async def predict(request: PredictRequest):
    """
    Make a prediction using a trained model.

    Requires a model_id and feature values matching the model's expected features.
    """
    logger.info(f"Prediction request received for model {request.model_id}")

    try:
        # Load model (from cache or disk)
        model_data = None
        if request.model_id in MODEL_CACHE:
            model_data = MODEL_CACHE[request.model_id]
        else:
            # Search for the model file
            found = False
            for root, dirs, files in os.walk(MODELS_DIR):
                for file in files:
                    if file == f"{request.model_id}.joblib":
                        model_path = os.path.join(root, file)
                        model_dict = joblib.load(model_path)
                        model_data = {
                            'model': model_dict['model'],
                            'feature_names': model_dict['feature_names']
                        }
                        MODEL_CACHE[request.model_id] = model_data
                        found = True
                        break
                if found:
                    break

            if not found:
                raise HTTPException(
                    status_code=status.HTTP_404_NOT_FOUND,
                    detail=f"Model with ID {request.model_id} not found"
                )

        # Get the model and feature names
        model = model_data['model']
        feature_names = model_data['feature_names']

        # Check if number of features matches
        if len(request.features) != len(feature_names):
            logger.warning(f"Feature count mismatch: expected {len(feature_names)}, got {len(request.features)}")

            # Handle fewer features by padding with zeros
            if len(request.features) < len(feature_names):
                logger.info("Padding features with zeros")
                features = request.features + [0] * (len(feature_names) - len(request.features))
            else:
                # Truncate extra features
                logger.info("Truncating extra features")
                features = request.features[:len(feature_names)]
        else:
            features = request.features

        # Convert to numpy array
        features_array = np.array(features).reshape(1, -1)

        # Make prediction
        prediction = int(model.predict(features_array)[0])

        # Get probabilities (if available)
        probabilities = []
        if hasattr(model, 'predict_proba'):
            probabilities = model.predict_proba(features_array)[0].tolist()
        else:
            # If no probabilities available, create binary probabilities
            probabilities = [1-prediction, prediction] if prediction in [0, 1] else [0, 0]

        logger.info(f"Prediction: {prediction}, Probabilities: {probabilities}")

        return {
            "prediction": prediction,
            "probabilities": probabilities,
            "model_id": request.model_id,
            "prediction_time": datetime.now().isoformat()
        }

    except Exception as e:
        logger.exception(f"Error making prediction: {str(e)}")
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=f"Error making prediction: {str(e)}"
        )

@app.get("/models/{course_id}", dependencies=[Depends(verify_api_key)])
async def list_models(course_id: int):
    """List all models available for a specific course"""
    logger.info(f"Listing models for course {course_id}")

    try:
        models = []
        course_models_dir = os.path.join(MODELS_DIR, f"course_{course_id}")

        if os.path.exists(course_models_dir):
            for filename in os.listdir(course_models_dir):
                if filename.endswith(".joblib"):
                    model_id = os.path.splitext(filename)[0]
                    model_path = os.path.join(course_models_dir, filename)

                    try:
                        model_data = joblib.load(model_path)
                        models.append({
                            "model_id": model_id,
                            "algorithm": model_data.get('algorithm', 'unknown'),
                            "feature_names": model_data.get('feature_names', []),
                            "trained_at": model_data.get('trained_at', '')
                        })
                    except Exception as e:
                        logger.warning(f"Error loading model {model_id}: {str(e)}")

        return {"models": models}

    except Exception as e:
        logger.exception(f"Error listing models: {str(e)}")
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=f"Error listing models: {str(e)}"
        )

@app.delete("/models/{model_id}", dependencies=[Depends(verify_api_key)])
async def delete_model(model_id: str):
    """Delete a model by its ID"""
    logger.info(f"Delete request for model {model_id}")

    try:
        # Remove from cache if present
        if model_id in MODEL_CACHE:
            del MODEL_CACHE[model_id]

        # Search for the model file
        found = False
        for root, dirs, files in os.walk(MODELS_DIR):
            for file in files:
                if file == f"{model_id}.joblib":
                    model_path = os.path.join(root, file)
                    os.remove(model_path)
                    found = True
                    logger.info(f"Model {model_id} deleted successfully")
                    break
            if found:
                break

        if not found:
            raise HTTPException(
                status_code=status.HTTP_404_NOT_FOUND,
                detail=f"Model with ID {model_id} not found"
            )

        return {"status": "success", "message": f"Model {model_id} deleted successfully"}

    except Exception as e:
        logger.exception(f"Error deleting model: {str(e)}")
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=f"Error deleting model: {str(e)}"
        )

# For testing purposes
if __name__ == "__main__":
    import uvicorn
    host = os.getenv("HOST", "0.0.0.0")
    port = int(os.getenv("PORT", 5000))
    uvicorn.run(app, host=host, port=port)