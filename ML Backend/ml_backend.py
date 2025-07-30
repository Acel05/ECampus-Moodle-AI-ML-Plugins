#!/usr/bin/env python3
"""
Machine Learning API for Student Performance Prediction

A simple API that trains models and makes predictions.
"""

import os
import uuid
import time
import logging
import traceback
import tempfile
import shutil
from datetime import datetime
from typing import Dict, List, Optional, Any, Tuple, Union

import numpy as np
import pandas as pd
import joblib
from fastapi import FastAPI, HTTPException, Depends, Header, Request, status, BackgroundTasks
from fastapi import File, UploadFile, Form
from fastapi.responses import JSONResponse
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel, Field, validator
from dotenv import load_dotenv

# ML imports
from sklearn.preprocessing import StandardScaler, OneHotEncoder
from sklearn.compose import ColumnTransformer
from sklearn.pipeline import Pipeline
from sklearn.model_selection import train_test_split
from sklearn.metrics import accuracy_score, precision_score, recall_score, f1_score, roc_auc_score
from sklearn.ensemble import RandomForestClassifier, GradientBoostingClassifier
from sklearn.linear_model import LogisticRegression
from sklearn.svm import SVC
from sklearn.tree import DecisionTreeClassifier
from sklearn.neighbors import KNeighborsClassifier
from sklearn.impute import SimpleImputer

# Load environment variables
load_dotenv()

# Configure logging
logging.basicConfig(
    level=logging.INFO if os.getenv("DEBUG", "false").lower() == "true" else logging.WARNING,
    format='%(asctime)s - %(name)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger(__name__)

# Set up FastAPI app - simplified for API only
app = FastAPI(
    title="Student Performance Predictor API",
    description="Machine Learning API for Student Performance Prediction",
    version="1.0.0"
)

# Enable CORS
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

# Get API key from environment
API_KEY = os.getenv("API_KEY", "changeme")

# Storage paths
MODELS_DIR = os.path.join(os.getcwd(), os.getenv("MODELS_DIR", "models"))
os.makedirs(MODELS_DIR, exist_ok=True)

# Models cache
MODEL_CACHE = {}

# Pydantic models for requests and responses
class TrainRequest(BaseModel):
    courseid: int
    algorithm: str = "randomforest"  # Default to RandomForest
    target_column: str = "final_outcome"
    id_columns: List[str] = []
    test_size: float = 0.2

    class Config:
        # Allow arbitrary types for field values
        arbitrary_types_allowed = True

class TrainResponse(BaseModel):
    model_id: str
    algorithm: str
    metrics: Dict[str, Optional[float]]  # Allow None for metrics like roc_auc
    feature_names: List[str]
    target_classes: List[Any]
    trained_at: str
    training_time_seconds: float
    model_path: Optional[str] = None

class PredictRequest(BaseModel):
    model_id: str
    features: Dict[str, Any]

class PredictResponse(BaseModel):
    prediction: Any
    probability: float
    probabilities: List[float]
    model_id: str
    prediction_time: str

# API key verification
async def verify_api_key(x_api_key: str = Header(...)):
    if x_api_key != API_KEY:
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Invalid API key"
        )
    return x_api_key

# Exception handler
@app.exception_handler(Exception)
async def handle_exception(request: Request, exc: Exception):
    """
    Handle any unhandled exceptions and return a friendly error response.
    """
    error_id = str(uuid.uuid4())

    # Log the exception with a traceback
    logger.error(f"Error ID: {error_id} - Unhandled exception: {str(exc)}")
    logger.error(traceback.format_exc())

    # Return a friendly JSON response
    return JSONResponse(
        status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
        content={
            "detail": str(exc),
            "error_id": error_id,
            "type": type(exc).__name__
        }
    )

# Simple health check endpoint
@app.get("/health")
async def health_check():
    """
    Health check endpoint for monitoring.
    """
    try:
        # Check models directory exists
        if not os.path.exists(MODELS_DIR):
            os.makedirs(MODELS_DIR, exist_ok=True)

        # Check if we can write to the models directory
        test_file = os.path.join(MODELS_DIR, "healthcheck.txt")
        with open(test_file, "w") as f:
            f.write("Health check")
        os.remove(test_file)

        return {
            "status": "healthy",
            "time": datetime.now().isoformat(),
            "version": "1.0.0",
            "models_dir": MODELS_DIR,
            "models_count": len([f for f in os.listdir(MODELS_DIR) if f.endswith('.joblib')]),
            "environment": {
                "debug": os.getenv("DEBUG", "false"),
                "api_key_configured": API_KEY != "changeme"
            }
        }
    except Exception as e:
        logger.error(f"Health check failed: {str(e)}")
        return {
            "status": "unhealthy",
            "error": str(e),
            "time": datetime.now().isoformat()
        }

@app.post("/train", response_model=TrainResponse, dependencies=[Depends(verify_api_key)])
async def train_model(
    courseid: int = Form(...),
    algorithm: str = Form("randomforest"),
    target_column: str = Form("final_outcome", description="Name of the target column"),
    test_size: float = Form(0.2, description="Test split proportion"),
    id_columns: str = Form("", description="Comma-separated list of ID columns to ignore"),
    dataset_file: UploadFile = File(...)
):
    """
    Train a machine learning model with the uploaded dataset.
    """
    start_time = time.time()
    logger.info(f"Training request received for course {courseid} using {algorithm}")

    try:
        # Parse id_columns from comma-separated string
        id_columns_list = [col.strip() for col in id_columns.split(',')] if id_columns else []

        # Create a temporary file to store the uploaded dataset
        with tempfile.NamedTemporaryFile(delete=False, suffix=os.path.splitext(dataset_file.filename)[1]) as temp_file:
            # Copy the uploaded file to the temporary file
            shutil.copyfileobj(dataset_file.file, temp_file)
            temp_filepath = temp_file.name

        logger.info(f"Uploaded dataset saved to temporary file: {temp_filepath}")

        # Create a request object with the form data
        request = TrainRequest(
            courseid=courseid,
            algorithm=algorithm,
            target_column=target_column,
            id_columns=id_columns_list,
            test_size=test_size
        )

        # Load data
        file_extension = os.path.splitext(dataset_file.filename)[1].lower()
        try:
            if file_extension == '.csv':
                df = pd.read_csv(temp_filepath)
            elif file_extension == '.json':
                df = pd.read_json(temp_filepath)
            elif file_extension in ['.xlsx', '.xls']:
                df = pd.read_excel(temp_filepath)
            else:
                raise HTTPException(
                    status_code=status.HTTP_400_BAD_REQUEST,
                    detail=f"Unsupported file format: {file_extension}"
                )

            logger.info(f"Successfully loaded dataset with {len(df)} rows and {len(df.columns)} columns")
        except Exception as e:
            logger.error(f"Error loading dataset: {str(e)}")
            raise HTTPException(
                status_code=status.HTTP_400_BAD_REQUEST,
                detail=f"Error loading dataset: {str(e)}"
            )

        # Remove the temporary file after loading
        os.unlink(temp_filepath)

        # Use a default target column if not found
        if request.target_column not in df.columns:
            # Look for common target column names
            possible_targets = ['final_outcome', 'pass', 'outcome', 'grade', 'result', 'status']
            for col in possible_targets:
                if col in df.columns:
                    logger.info(f"Using '{col}' as target column instead of '{request.target_column}'")
                    request.target_column = col
                    break
            else:
                # Use the last column as target if none found
                request.target_column = df.columns[-1]
                logger.warning(f"Target column not found, using last column '{request.target_column}' as target")

        # Extract target and features
        y = df[request.target_column]
        X = df.drop(columns=[request.target_column] + request.id_columns)
        feature_names = X.columns.tolist()

        logger.info(f"Features: {feature_names}")
        logger.info(f"Target distribution: {y.value_counts().to_dict()}")

        # Identify numeric and categorical columns
        numeric_cols = X.select_dtypes(include=['int64', 'float64']).columns.tolist()
        categorical_cols = X.select_dtypes(include=['object', 'category', 'bool']).columns.tolist()

        # Create preprocessing pipeline
        preprocessor = ColumnTransformer(
            transformers=[
                ('num', Pipeline([
                    ('imputer', SimpleImputer(strategy='mean')),
                    ('scaler', StandardScaler())
                ]), numeric_cols),
                ('cat', Pipeline([
                    ('imputer', SimpleImputer(strategy='most_frequent')),
                    ('encoder', OneHotEncoder(drop='first', sparse=False, handle_unknown='ignore'))
                ]), categorical_cols)
            ],
            remainder='drop'
        )

        # Split data
        X_train, X_test, y_train, y_test = train_test_split(
            X, y, test_size=request.test_size, random_state=42
        )

        # Select algorithm
        if request.algorithm == 'randomforest':
            model = RandomForestClassifier(n_estimators=100, random_state=42)
        elif request.algorithm == 'logisticregression':
            model = LogisticRegression(max_iter=1000, random_state=42)
        elif request.algorithm == 'gradientboosting':
            model = GradientBoostingClassifier(n_estimators=100, random_state=42)
        elif request.algorithm == 'svm':
            model = SVC(probability=True, random_state=42)
        elif request.algorithm == 'decisiontree':
            model = DecisionTreeClassifier(random_state=42)
        elif request.algorithm == 'knn':
            model = KNeighborsClassifier(n_neighbors=5)
        else:
            logger.warning(f"Unsupported algorithm '{request.algorithm}', falling back to RandomForest")
            model = RandomForestClassifier(n_estimators=100, random_state=42)
            request.algorithm = 'randomforest'

        # Create pipeline
        pipeline = Pipeline([
            ('preprocessor', preprocessor),
            ('classifier', model)
        ])

        # Train the model
        logger.info(f"Training {request.algorithm} model")
        pipeline.fit(X_train, y_train)
        logger.info("Model training completed")

        # Evaluate
        y_pred = pipeline.predict(X_test)
        y_pred_proba = pipeline.predict_proba(X_test)

        # Calculate metrics
        metrics = {
            "accuracy": float(accuracy_score(y_test, y_pred)),
            "precision": float(precision_score(y_test, y_pred, average='weighted')),
            "recall": float(recall_score(y_test, y_pred, average='weighted')),
            "f1": float(f1_score(y_test, y_pred, average='weighted'))
        }

        # Add ROC AUC if it's a binary classification
        if len(np.unique(y)) == 2:
            try:
                metrics["roc_auc"] = float(roc_auc_score(y_test, y_pred_proba[:, 1]))
            except ValueError as e:
                logger.warning(f"ROC AUC not defined: {str(e)}")
                metrics["roc_auc"] = None

        # Convert all metric values to float or None
        metrics = {k: (float(v) if v is not None else None) for k, v in metrics.items()}

        logger.info(f"Model metrics: {metrics}")

        # Generate model ID
        model_id = str(uuid.uuid4())

        # Create course directory
        course_models_dir = os.path.join(MODELS_DIR, f"course_{request.courseid}")
        os.makedirs(course_models_dir, exist_ok=True)

        # Save model
        model_filename = f"{model_id}.joblib"
        model_path = os.path.join(course_models_dir, model_filename)

        model_data = {
            'pipeline': pipeline,
            'feature_names': feature_names,
            'algorithm': request.algorithm,
            'trained_at': datetime.now().isoformat(),
            'target_classes': list(pipeline.classes_),
            'metrics': metrics
        }

        joblib.dump(model_data, model_path)
        MODEL_CACHE[model_id] = model_data

        logger.info(f"Model saved to {model_path}")

        training_time = time.time() - start_time
        logger.info(f"Training completed in {training_time:.2f} seconds")

        return {
            "model_id": model_id,
            "algorithm": request.algorithm,
            "metrics": metrics,
            "feature_names": [str(f) for f in feature_names],
            "target_classes": [int(c) if isinstance(c, (np.integer, np.int64, np.int32)) else c for c in pipeline.classes_],
            "trained_at": datetime.now().isoformat(),
            "training_time_seconds": training_time,
            "model_path": model_path  # Added model path for the Moodle plugin
        }

    except HTTPException:
        raise
    except Exception as e:
        logger.exception(f"Error training model: {str(e)}")
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=f"Error training model: {str(e)}"
        )

@app.post("/predict", dependencies=[Depends(verify_api_key)])
async def predict(request: dict):
    """
    Make a prediction using a trained model.
    Support both single and batch predictions.
    """
    try:
        model_id = request.get("model_id")
        features = request.get("features")

        if not model_id:
            raise HTTPException(
                status_code=status.HTTP_400_BAD_REQUEST,
                detail="model_id is required"
            )

        if not features:
            raise HTTPException(
                status_code=status.HTTP_400_BAD_REQUEST,
                detail="features are required"
            )

        # Check if features is a list of lists/dicts (batch) or just a single list/dict
        is_batch = isinstance(features, list) and len(features) > 0 and isinstance(features[0], (list, dict))

        # Log the request for debugging
        logger.info(f"Prediction request for model {model_id}")
        logger.info(f"Feature data type: {'batch' if is_batch else 'single'}")

        # Load model
        model_data = None
        if model_id in MODEL_CACHE:
            model_data = MODEL_CACHE[model_id]
            logger.info(f"Using cached model {model_id}")
        else:
            # Search for model file
            found = False
            for root, dirs, files in os.walk(MODELS_DIR):
                for file in files:
                    if file == f"{model_id}.joblib":
                        model_path = os.path.join(root, file)
                        logger.info(f"Loading model from {model_path}")
                        model_data = joblib.load(model_path)
                        MODEL_CACHE[model_id] = model_data
                        found = True
                        break
                if found:
                    break

            if not found:
                logger.error(f"Model with ID {model_id} not found")
                raise HTTPException(
                    status_code=status.HTTP_404_NOT_FOUND,
                    detail=f"Model with ID {model_id} not found"
                )

        # Get pipeline and feature names
        pipeline = model_data['pipeline']
        feature_names = model_data['feature_names']
        logger.info(f"Model feature names: {feature_names}")

        # Create DataFrame from input
        try:
            if is_batch:
                logger.info(f"Processing batch prediction with {len(features)} samples")
                input_df = pd.DataFrame(features)
            else:
                logger.info("Processing single prediction")
                input_df = pd.DataFrame([features])

            # Add missing features with default values
            for feat in feature_names:
                if feat not in input_df.columns:
                    logger.info(f"Adding missing feature {feat} with default value 0")
                    input_df[feat] = 0

            # Ensure correct column order and only use known features
            valid_features = [f for f in feature_names if f in input_df.columns]
            input_df = input_df[valid_features]
            logger.info(f"Input data shape: {input_df.shape}")

        except Exception as e:
            logger.error(f"Error preparing input data: {str(e)}")
            raise HTTPException(
                status_code=status.HTTP_400_BAD_REQUEST,
                detail=f"Invalid feature format: {str(e)}"
            )

        # Make prediction
        logger.info("Making prediction")
        try:
            predictions = pipeline.predict(input_df).tolist()
            probabilities = pipeline.predict_proba(input_df).tolist()
            logger.info(f"Prediction successful: {predictions}")
            logger.info(f"Probabilities: {probabilities}")
        except Exception as e:
            logger.error(f"Error during prediction: {str(e)}")
            raise HTTPException(
                status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
                detail=f"Error during prediction: {str(e)}"
            )

        # Format response based on batch or single prediction
        if is_batch:
            # Get probabilities for the positive class (usually class 1)
            # This works for binary classification
            if len(pipeline.classes_) == 2:
                positive_class_idx = 1 if 1 in pipeline.classes_ else 0
                positive_probs = [probs[positive_class_idx] for probs in probabilities]
            else:
                # For multiclass, return probability of predicted class
                positive_probs = [max(probs) for probs in probabilities]

            return {
                "predictions": predictions,
                "probabilities": positive_probs,
                "model_id": model_id,
                "prediction_time": datetime.now().isoformat()
            }
        else:
            prediction = predictions[0]

            # Get probability of predicted class (for binary, get prob of class 1)
            if len(pipeline.classes_) == 2:
                # For binary classification, return prob of positive class (usually 1)
                positive_class_idx = 1 if 1 in pipeline.classes_ else 0
                probability = float(probabilities[0][positive_class_idx])
            else:
                # For multiclass, return probability of predicted class
                pred_idx = list(pipeline.classes_).index(prediction)
                probability = float(probabilities[0][pred_idx])

            return {
                "prediction": prediction,
                "probability": probability,
                "probabilities": probabilities[0],
                "model_id": model_id,
                "prediction_time": datetime.now().isoformat()
            }

    except HTTPException:
        raise
    except Exception as e:
        logger.exception(f"Unhandled error in prediction: {str(e)}")
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=f"Error making prediction: {str(e)}"
        )

# Add a debug endpoint for file system checking
@app.get("/debug/filesystem", dependencies=[Depends(verify_api_key)])
async def debug_filesystem(path: str = None):
    """Debug endpoint to check filesystem access."""
    if not os.getenv("DEBUG", "false").lower() == "true":
        raise HTTPException(status_code=403, detail="Debug mode not enabled")

    result = {
        "current_dir": os.getcwd(),
        "models_dir": MODELS_DIR,
        "models_dir_exists": os.path.exists(MODELS_DIR),
        "models_dir_writable": os.access(MODELS_DIR, os.W_OK),
        "models_content": []
    }

    if os.path.exists(MODELS_DIR):
        result["models_content"] = os.listdir(MODELS_DIR)

    if path and os.path.exists(path):
        result["path_exists"] = True
        result["path_is_file"] = os.path.isfile(path)
        result["path_is_dir"] = os.path.isdir(path)
        result["path_size"] = os.path.getsize(path) if os.path.isfile(path) else None
        if os.path.isdir(path):
            result["path_content"] = os.listdir(path)
    else:
        result["path_exists"] = False

    return result

# For deployment
if __name__ == "__main__":
    import uvicorn

    port = int(os.getenv("PORT", 8000))
    debug = os.getenv("DEBUG", "false").lower() == "true"

    print(f"Starting Student Performance Prediction API on port {port}, debug={debug}")

    uvicorn.run(app, host="0.0.0.0", port=port)
