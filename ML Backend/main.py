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
import traceback
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
from sklearn.preprocessing import OneHotEncoder, StandardScaler
from sklearn.compose import ColumnTransformer
from sklearn.pipeline import Pipeline

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
    features: Dict[str, Any]  # Changed to dictionary with feature names as keys

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

        # Identify numerical and categorical columns
        numerical_cols = X.select_dtypes(include=['int64', 'float64']).columns.tolist()
        categorical_cols = X.select_dtypes(include=['object', 'category']).columns.tolist()

        logger.info(f"Numerical columns: {numerical_cols}")
        logger.info(f"Categorical columns: {categorical_cols}")

        # Create preprocessing pipeline
        preprocessor = ColumnTransformer(
            transformers=[
                ('num', StandardScaler(), numerical_cols),
                ('cat', OneHotEncoder(drop='first', sparse=False, handle_unknown='ignore'), categorical_cols)
            ]
        )

        # Split data into training and testing sets
        from sklearn.model_selection import train_test_split
        X_train, X_test, y_train, y_test = train_test_split(
            X, y, test_size=request.test_size, random_state=42
        )

        # Choose the model
        model = None
        if request.algorithm == 'randomforest':
            from sklearn.ensemble import RandomForestClassifier
            clf = RandomForestClassifier(
                n_estimators=request.model_params.get('n_estimators', 100),
                random_state=42
            )
        elif request.algorithm == 'logisticregression':
            from sklearn.linear_model import LogisticRegression
            clf = LogisticRegression(
                C=request.model_params.get('C', 1.0),
                random_state=42,
                max_iter=1000,  # Increase max iterations for convergence
                multi_class='auto'  # Handle both binary and multi-class
            )
        elif request.algorithm == 'svm':
            from sklearn.svm import SVC
            clf = SVC(
                C=request.model_params.get('C', 1.0),
                probability=True,
                random_state=42
            )
        elif request.algorithm == 'decisiontree':
            from sklearn.tree import DecisionTreeClassifier
            clf = DecisionTreeClassifier(
                max_depth=request.model_params.get('max_depth', None),
                random_state=42
            )
        elif request.algorithm == 'knn':
            from sklearn.neighbors import KNeighborsClassifier
            clf = KNeighborsClassifier(
                n_neighbors=request.model_params.get('n_neighbors', 5)
            )
        else:
            raise HTTPException(
                status_code=status.HTTP_400_BAD_REQUEST,
                detail=f"Unsupported algorithm: {request.algorithm}"
            )

        # Create and train the full pipeline
        pipeline = Pipeline([
            ('preprocessor', preprocessor),
            ('classifier', clf)
        ])

        pipeline.fit(X_train, y_train)

        # Evaluate the model
        from sklearn.metrics import accuracy_score, precision_score, recall_score, f1_score, classification_report
        y_pred = pipeline.predict(X_test)

        # Determine if binary or multiclass
        unique_classes = np.unique(y)
        is_binary = len(unique_classes) == 2

        # Calculate metrics based on problem type
        if is_binary:
            metrics = {
                "accuracy": float(accuracy_score(y_test, y_pred)),
                "precision": float(precision_score(y_test, y_pred, zero_division=0)),
                "recall": float(recall_score(y_test, y_pred, zero_division=0)),
                "f1": float(f1_score(y_test, y_pred, zero_division=0))
            }
        else:
            # For multiclass, use weighted averages
            metrics = {
                "accuracy": float(accuracy_score(y_test, y_pred)),
                "precision": float(precision_score(y_test, y_pred, average='weighted', zero_division=0)),
                "recall": float(recall_score(y_test, y_pred, average='weighted', zero_division=0)),
                "f1": float(f1_score(y_test, y_pred, average='weighted', zero_division=0))
            }

        # Get detailed classification report for logging
        report = classification_report(y_test, y_pred)
        logger.info(f"Classification Report:\n{report}")

        # Generate a unique model ID
        model_id = str(uuid.uuid4())

        # Create a model directory for this course if it doesn't exist
        course_models_dir = os.path.join(MODELS_DIR, f"course_{request.courseid}")
        os.makedirs(course_models_dir, exist_ok=True)

        # Save the model with feature names
        model_filename = f"{model_id}.joblib"
        model_path = os.path.join(course_models_dir, model_filename)

        # Save the model with relevant metadata
        model_data = {
            'pipeline': pipeline,
            'feature_names': feature_names,
            'numerical_cols': numerical_cols,
            'categorical_cols': categorical_cols, 
            'algorithm': request.algorithm,
            'trained_at': datetime.now().isoformat(),
            'target_classes': list(pipeline.classes_),
            'metrics': metrics
        }

        joblib.dump(model_data, model_path)

        # Add to cache
        MODEL_CACHE[model_id] = model_data

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

    Requires a model_id and feature values as a dictionary with feature names as keys.
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
                        model_data = joblib.load(model_path)
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

        # Get the pipeline and feature names
        pipeline = model_data['pipeline']
        feature_names = model_data['feature_names']

        # Create a DataFrame from the input features
        try:
            input_df = pd.DataFrame([request.features])

            # Check for missing features and add them with default values
            for feat in feature_names:
                if feat not in input_df.columns:
                    logger.warning(f"Missing feature '{feat}' in input, setting to 0")
                    input_df[feat] = 0

            # Ensure the DataFrame has all required columns in the right order
            input_df = input_df[feature_names]

        except Exception as e:
            logger.error(f"Error creating input DataFrame: {str(e)}")
            raise HTTPException(
                status_code=status.HTTP_400_BAD_REQUEST,
                detail=f"Invalid feature format. Error: {str(e)}"
            )

        # Make prediction
        prediction = int(pipeline.predict(input_df)[0])

        # Get probabilities
        probabilities = pipeline.predict_proba(input_df)[0].tolist()

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
                            "trained_at": model_data.get('trained_at', ''),
                            "metrics": model_data.get('metrics', {})
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
    port = int(os.getenv("PORT", 8000))
    uvicorn.run(app, host=host, port=port)
