#!/usr/bin/env python3
"""
Machine Learning API for Student Performance Prediction

This API provides endpoints for training machine learning models and generating predictions
for student performance in Moodle courses. It communicates with the Moodle plugin
via a RESTful API interface and handles all ML-related operations.
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
from sklearn.model_selection import train_test_split, cross_val_score
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

# Set up FastAPI app
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

# Models cache for improved performance
MODEL_CACHE = {}

# Pydantic models for request/response validation
class TrainResponse(BaseModel):
    """Response model for the train endpoint"""
    model_id: str
    algorithm: str
    metrics: Dict[str, Any]
    feature_names: List[str]
    target_classes: List[Any]
    trained_at: str
    training_time_seconds: float
    model_path: Optional[str] = None

class PredictResponse(BaseModel):
    """Response model for the predict endpoint"""
    prediction: Any
    probability: float
    probabilities: List[float]
    model_id: str
    prediction_time: str
    features: Dict[str, Any] # Features included for better interpretability

# API key verification middleware
async def verify_api_key(x_api_key: str = Header(...)):
    """
    Verify that the API key in the request header matches the expected key.

    Args:
        x_api_key: API key from request header

    Returns:
        The API key if valid

    Raises:
        HTTPException: If API key is invalid
    """
    if x_api_key != API_KEY:
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Invalid API key"
        )
    return x_api_key

# Global exception handler
@app.exception_handler(Exception)
async def handle_exception(request: Request, exc: Exception):
    """
    Global exception handler to provide consistent error responses.

    Args:
        request: The request that caused the exception
        exc: The exception that was raised

    Returns:
        JSONResponse with error details
    """
    error_id = str(uuid.uuid4())
    logger.error(f"Error ID: {error_id} - Unhandled exception: {str(exc)}")
    logger.error(traceback.format_exc())
    return JSONResponse(
        status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
        content={
            "detail": str(exc),
            "error_id": error_id,
            "type": type(exc).__name__
        }
    )

@app.get("/health")
async def health_check():
    """
    Health check endpoint to verify the API is running correctly.

    Returns:
        Dict with health status information
    """
    try:
        if not os.path.exists(MODELS_DIR):
            os.makedirs(MODELS_DIR, exist_ok=True)
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
    Train a new machine learning model using the provided dataset.

    Args:
        courseid: The Moodle course ID
        algorithm: ML algorithm to use (default: randomforest)
        target_column: Column name containing the target variable
        test_size: Proportion of data to use for testing
        id_columns: Comma-separated list of columns to exclude from training
        dataset_file: CSV or JSON file containing training data

    Returns:
        TrainResponse with model details and metrics

    Raises:
        HTTPException: If training fails
    """
    start_time = time.time()
    logger.info(f"Training request received for course {courseid} using {algorithm}")

    try:
        id_columns_list = [col.strip() for col in id_columns.split(',')] if id_columns else []
        with tempfile.NamedTemporaryFile(delete=False, suffix=os.path.splitext(dataset_file.filename)[1]) as temp_file:
            shutil.copyfileobj(dataset_file.file, temp_file)
            temp_filepath = temp_file.name

        logger.info(f"Uploaded dataset saved to temporary file: {temp_filepath}")

        request_data = {
            "courseid": courseid,
            "algorithm": algorithm,
            "target_column": target_column,
            "id_columns": id_columns_list,
            "test_size": test_size
        }

        file_extension = os.path.splitext(dataset_file.filename)[1].lower()
        try:
            if file_extension == '.csv':
                df = pd.read_csv(temp_filepath)
            elif file_extension == '.json':
                df = pd.read_json(temp_filepath)
            elif file_extension in ['.xlsx', '.xls']:
                df = pd.read_excel(temp_filepath)
            else:
                raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail=f"Unsupported file format: {file_extension}")
            logger.info(f"Successfully loaded dataset with {len(df)} rows and {len(df.columns)} columns")
        except Exception as e:
            logger.error(f"Error loading dataset: {str(e)}")
            raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail=f"Error loading dataset: {str(e)}")

        os.unlink(temp_filepath)

        if len(df) < 30:
            logger.warning(f"Very small dataset with only {len(df)} samples. Model may not be reliable.")

        # Auto-detect target column if not found
        if request_data["target_column"] not in df.columns:
            possible_targets = ['final_outcome', 'pass', 'outcome', 'grade', 'result', 'status']
            for col in possible_targets:
                if col in df.columns:
                    logger.info(f"Using '{col}' as target column instead of '{request_data['target_column']}'")
                    request_data["target_column"] = col
                    break
            else:
                request_data["target_column"] = df.columns[-1]
                logger.warning(f"Target column not found, using last column '{request_data['target_column']}' as target")

        y = df[request_data["target_column"]]
        X = df.drop(columns=[request_data["target_column"]] + request_data["id_columns"])
        feature_names = X.columns.tolist()

        logger.info(f"Features: {feature_names}")
        logger.info(f"Target distribution: {y.value_counts().to_dict()}")

        # Handle class imbalance
        class_counts = y.value_counts().to_dict()
        if len(class_counts) > 1:
            imbalance_ratio = max(class_counts.values()) / min(class_counts.values())
            class_weight = 'balanced' if imbalance_ratio > 3 else None
            if class_weight:
                logger.warning(f"Significant class imbalance detected: ratio {imbalance_ratio:.2f}. Applying class weight balancing.")
        else:
            logger.warning("Only one class found in target. Model will not be useful for prediction.")
            class_weight = None

        # Identify column types for preprocessing
        numeric_cols = X.select_dtypes(include=['int64', 'float64']).columns.tolist()
        categorical_cols = X.select_dtypes(include=['object', 'category', 'bool']).columns.tolist()

        # Create appropriate encoder based on sklearn version
        try:
            encoder = OneHotEncoder(drop='first', sparse_output=False, handle_unknown='ignore')
        except TypeError:
            encoder = OneHotEncoder(drop='first', sparse=False, handle_unknown='ignore')

        # Create preprocessor for feature transformation
        preprocessor = ColumnTransformer(
            transformers=[
                ('num', Pipeline([('imputer', SimpleImputer(strategy='median')), ('scaler', StandardScaler())]), numeric_cols),
                ('cat', Pipeline([('imputer', SimpleImputer(strategy='most_frequent')), ('encoder', encoder)]), categorical_cols)
            ],
            remainder='drop'
        )

        # Split data for training and testing
        try:
            X_train, X_test, y_train, y_test = train_test_split(X, y, test_size=request_data["test_size"], random_state=42, stratify=y)
        except ValueError:
            logger.warning("Stratified split failed, falling back to random split")
            X_train, X_test, y_train, y_test = train_test_split(X, y, test_size=request_data["test_size"], random_state=42)

        # Define available models with optimized hyperparameters
        models = {
            'randomforest': RandomForestClassifier(n_estimators=100, max_depth=10, min_samples_split=5, min_samples_leaf=2, class_weight=class_weight, random_state=42),
            'logisticregression': LogisticRegression(C=1.0, class_weight=class_weight, max_iter=1000, random_state=42),
            'gradientboosting': GradientBoostingClassifier(n_estimators=100, learning_rate=0.1, max_depth=3, subsample=0.8, random_state=42),
            'svm': SVC(C=1.0, kernel='rbf', class_weight=class_weight, probability=True, random_state=42),
            'decisiontree': DecisionTreeClassifier(max_depth=5, min_samples_split=5, min_samples_leaf=2, class_weight=class_weight, random_state=42),
            'knn': KNeighborsClassifier(n_neighbors=5, weights='distance')
        }

        # Select model based on algorithm parameter, defaulting to RandomForest if not found
        model = models.get(request_data["algorithm"], models['randomforest'])
        if request_data["algorithm"] not in models:
            logger.warning(f"Unsupported algorithm '{request_data['algorithm']}', falling back to RandomForest")
            request_data["algorithm"] = 'randomforest'

        # Create complete pipeline with preprocessing and model
        pipeline = Pipeline([('preprocessor', preprocessor), ('classifier', model)])

        # Perform cross-validation for reliable performance estimation
        logger.info("Performing 5-fold cross-validation")
        cv_scores = cross_val_score(pipeline, X, y, cv=5, scoring='accuracy')
        cv_accuracy, cv_std = np.mean(cv_scores), np.std(cv_scores)
        logger.info(f"Cross-validation accuracy: {cv_accuracy:.4f} ± {cv_std:.4f}")

        # Train the final model on all training data
        logger.info(f"Training {request_data['algorithm']} model")
        pipeline.fit(X_train, y_train)
        logger.info("Model training completed")

        # Evaluate model on test set
        y_pred = pipeline.predict(X_test)
        y_pred_proba = pipeline.predict_proba(X_test)

        # Calculate performance metrics
        metrics = {
            "accuracy": float(accuracy_score(y_test, y_pred)),
            "cv_accuracy": float(cv_accuracy),
            "cv_std": float(cv_std),
            "precision": float(precision_score(y_test, y_pred, average='weighted', zero_division=0)),
            "recall": float(recall_score(y_test, y_pred, average='weighted', zero_division=0)),
            "f1": float(f1_score(y_test, y_pred, average='weighted', zero_division=0))
        }

        # Calculate ROC AUC for binary classification
        if len(np.unique(y)) == 2:
            try:
                metrics["roc_auc"] = float(roc_auc_score(y_test, y_pred_proba[:, 1]))
            except (ValueError, IndexError) as e:
                logger.warning(f"ROC AUC not defined: {str(e)}")
                metrics["roc_auc"] = None

        # Check for overfitting
        train_acc = accuracy_score(y_train, pipeline.predict(X_train))
        overfitting_ratio = train_acc / max(metrics["accuracy"], 0.001)
        metrics["overfitting_warning"] = overfitting_ratio > 1.2
        metrics["overfitting_ratio"] = float(overfitting_ratio)
        if metrics["overfitting_warning"]:
            logger.warning(f"Model may be overfitting: train accuracy={train_acc:.4f}, test accuracy={metrics['accuracy']:.4f}")

        # Extract feature importance if available
        if hasattr(pipeline.named_steps['classifier'], 'feature_importances_'):
            importances = pipeline.named_steps['classifier'].feature_importances_
            if len(importances) == len(feature_names):
                importance_dict = dict(zip(feature_names, importances))
                sorted_features = sorted(importance_dict.items(), key=lambda x: x[1], reverse=True)
                metrics["top_features"] = {str(k): float(v) for k, v in sorted_features[:10]}

        # Generate a unique model ID and save the model
        model_id = str(uuid.uuid4())
        course_models_dir = os.path.join(MODELS_DIR, f"course_{request_data['courseid']}")
        os.makedirs(course_models_dir, exist_ok=True)
        model_path = os.path.join(course_models_dir, f"{model_id}.joblib")

        # Save all model data
        model_data = {
            'pipeline': pipeline,
            'feature_names': feature_names,
            'algorithm': request_data["algorithm"],
            'trained_at': datetime.now().isoformat(),
            'target_classes': list(pipeline.classes_),
            'metrics': metrics,
            'cv_scores': cv_scores.tolist()
        }
        joblib.dump(model_data, model_path)
        MODEL_CACHE[model_id] = model_data
        logger.info(f"Model saved to {model_path}")

        training_time = time.time() - start_time
        logger.info(f"Training completed in {training_time:.2f} seconds")

        # Format response data
        return {
            "model_id": model_id,
            "algorithm": request_data["algorithm"],
            "metrics": metrics,
            "feature_names": [str(f) for f in feature_names],
            "target_classes": [int(c) if isinstance(c, (np.integer, np.int64, np.int32)) else c for c in pipeline.classes_],
            "trained_at": datetime.now().isoformat(),
            "training_time_seconds": training_time,
            "model_path": model_path
        }
    except HTTPException:
        raise
    except Exception as e:
        logger.exception(f"Error training model: {str(e)}")
        raise HTTPException(status_code=status.HTTP_500_INTERNAL_SERVER_ERROR, detail=f"Error training model: {str(e)}")

@app.post("/predict", dependencies=[Depends(verify_api_key)])
async def predict(request: dict):
    """
    Generate predictions using a trained model.

    Args:
        request: Dict containing model_id and features

    Returns:
        Dict with prediction results

    Raises:
        HTTPException: If prediction fails or model not found
    """
    try:
        model_id = request.get("model_id")
        features = request.get("features")

        if not model_id:
            raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail="model_id is required")
        if not features:
            raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail="features are required")

        # Check if this is a batch prediction request
        is_batch = isinstance(features, list) and len(features) > 0 and isinstance(features[0], (list, dict))
        logger.info(f"Prediction request for model {model_id} ({'batch' if is_batch else 'single'})")

        # Load model from cache or disk
        model_data = MODEL_CACHE.get(model_id)
        if not model_data:
            found = False
            for root, _, files in os.walk(MODELS_DIR):
                if f"{model_id}.joblib" in files:
                    model_path = os.path.join(root, f"{model_id}.joblib")
                    logger.info(f"Loading model from {model_path}")
                    model_data = joblib.load(model_path)
                    MODEL_CACHE[model_id] = model_data
                    found = True
                    break
            if not found:
                logger.error(f"Model with ID {model_id} not found")
                raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail=f"Model with ID {model_id} not found")

        pipeline = model_data['pipeline']
        feature_names = model_data['feature_names']
        logger.info(f"Model feature names: {feature_names}")

        # Prepare input data
        try:
            input_df = pd.DataFrame([features] if not is_batch else features)
            # Add missing features with default values
            for feat in feature_names:
                if feat not in input_df.columns:
                    logger.info(f"Adding missing feature {feat} with default value 0")
                    input_df[feat] = 0

            # Keep only the features the model knows about
            valid_features = [f for f in feature_names if f in input_df.columns]
            input_df = input_df[valid_features]
            logger.info(f"Input data shape: {input_df.shape}")
        except Exception as e:
            logger.error(f"Error preparing input data: {str(e)}")
            raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail=f"Invalid feature format: {str(e)}")

        # Make predictions
        logger.info("Making prediction")
        try:
            predictions = pipeline.predict(input_df).tolist()
            probabilities = pipeline.predict_proba(input_df).tolist()
            logger.info(f"Prediction successful: {predictions}")
        except Exception as e:
            logger.error(f"Error during prediction: {str(e)}")
            raise HTTPException(status_code=status.HTTP_500_INTERNAL_SERVER_ERROR, detail=f"Error during prediction: {str(e)}")

        # Format response differently for batch vs. single prediction
        if is_batch:
            positive_class_idx = 1 if len(pipeline.classes_) == 2 and 1 in pipeline.classes_ else 0
            positive_probs = [probs[positive_class_idx] for probs in probabilities] if len(pipeline.classes_) == 2 else [max(probs) for probs in probabilities]

            return {
                "predictions": predictions,
                "probabilities": positive_probs,
                "model_id": model_id,
                "prediction_time": datetime.now().isoformat(),
                "features": features  # Return the batch of features
            }
        else:
            prediction = predictions[0]
            if len(pipeline.classes_) == 2:
                positive_class_idx = 1 if 1 in pipeline.classes_ else 0
                probability = float(probabilities[0][positive_class_idx])
            else:
                pred_idx = list(pipeline.classes_).index(prediction)
                probability = float(probabilities[0][pred_idx])

            return {
                "prediction": prediction,
                "probability": probability,
                "probabilities": probabilities[0],
                "model_id": model_id,
                "prediction_time": datetime.now().isoformat(),
                "features": features  # Return the single set of features
            }

    except HTTPException:
        raise
    except Exception as e:
        logger.exception(f"Unhandled error in prediction: {str(e)}")
        raise HTTPException(status_code=status.HTTP_500_INTERNAL_SERVER_ERROR, detail=f"Error making prediction: {str(e)}")

@app.get("/debug/filesystem", dependencies=[Depends(verify_api_key)])
async def debug_filesystem(path: str = None):
    """
    Debug endpoint to inspect the filesystem (only available in debug mode).

    Args:
        path: Optional path to inspect

    Returns:
        Dict with filesystem information

    Raises:
        HTTPException: If debug mode is not enabled
    """
    if not os.getenv("DEBUG", "false").lower() == "true":
        raise HTTPException(status_code=403, detail="Debug mode not enabled")

    result = {
        "current_dir": os.getcwd(),
        "models_dir": MODELS_DIR,
        "models_dir_exists": os.path.exists(MODELS_DIR),
        "models_dir_writable": os.access(MODELS_DIR, os.W_OK),
        "models_content": os.listdir(MODELS_DIR) if os.path.exists(MODELS_DIR) else []
    }

    if path:
        result["path_exists"] = os.path.exists(path)
        if result["path_exists"]:
            result.update({
                "path_is_file": os.path.isfile(path),
                "path_is_dir": os.path.isdir(path),
                "path_size": os.path.getsize(path) if os.path.isfile(path) else None,
                "path_content": os.listdir(path) if os.path.isdir(path) else None
            })
    return result

@app.get("/models", dependencies=[Depends(verify_api_key)])
async def list_models(courseid: Optional[int] = None):
    """
    List all available models, optionally filtered by course ID.

    Args:
        courseid: Optional course ID to filter models

    Returns:
        List of model metadata
    """
    models = []

    for root, dirs, files in os.walk(MODELS_DIR):
        for file in files:
            if file.endswith('.joblib'):
                try:
                    model_path = os.path.join(root, file)
                    model_id = os.path.splitext(file)[0]

                    # Load model metadata without loading the full model
                    model_data = joblib.load(model_path)

                    # Extract course ID from directory path
                    dir_name = os.path.basename(root)
                    model_courseid = int(dir_name.replace('course_', '')) if dir_name.startswith('course_') else 0

                    # Skip if filtering by course ID and this model doesn't match
                    if courseid is not None and model_courseid != courseid:
                        continue

                    # Add model to list
                    models.append({
                        'model_id': model_id,
                        'courseid': model_courseid,
                        'algorithm': model_data.get('algorithm', 'unknown'),
                        'trained_at': model_data.get('trained_at', 'unknown'),
                        'metrics': model_data.get('metrics', {}),
                        'feature_count': len(model_data.get('feature_names', [])),
                        'target_classes': model_data.get('target_classes', [])
                    })
                except Exception as e:
                    logger.warning(f"Error loading model {file}: {str(e)}")

    return {
        'models': models,
        'count': len(models)
    }

@app.delete("/models/{model_id}", dependencies=[Depends(verify_api_key)])
async def delete_model(model_id: str):
    """
    Delete a model by ID.

    Args:
        model_id: ID of the model to delete

    Returns:
        Dict with deletion status

    Raises:
        HTTPException: If model not found or deletion fails
    """
    model_found = False

    for root, _, files in os.walk(MODELS_DIR):
        if f"{model_id}.joblib" in files:
            model_path = os.path.join(root, f"{model_id}.joblib")
            try:
                # Remove from cache if present
                if model_id in MODEL_CACHE:
                    del MODEL_CACHE[model_id]

                # Delete the file
                os.remove(model_path)
                model_found = True

                logger.info(f"Model {model_id} deleted successfully")
                return {"success": True, "message": f"Model {model_id} deleted successfully"}
            except Exception as e:
                logger.error(f"Error deleting model {model_id}: {str(e)}")
                raise HTTPException(
                    status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
                    detail=f"Error deleting model: {str(e)}"
                )

    if not model_found:
        raise HTTPException(
            status_code=status.HTTP_404_NOT_FOUND,
            detail=f"Model with ID {model_id} not found"
        )

@app.get("/model/{model_id}", dependencies=[Depends(verify_api_key)])
async def get_model_details(model_id: str):
    """
    Get detailed information about a specific model.

    Args:
        model_id: ID of the model

    Returns:
        Dict with model details

    Raises:
        HTTPException: If model not found
    """
    # Try to get from cache first
    if model_id in MODEL_CACHE:
        model_data = MODEL_CACHE[model_id]
        return format_model_response(model_id, model_data)

    # Otherwise look in filesystem
    for root, _, files in os.walk(MODELS_DIR):
        if f"{model_id}.joblib" in files:
            model_path = os.path.join(root, f"{model_id}.joblib")
            try:
                model_data = joblib.load(model_path)
                MODEL_CACHE[model_id] = model_data  # Add to cache
                return format_model_response(model_id, model_data)
            except Exception as e:
                logger.error(f"Error loading model {model_id}: {str(e)}")
                raise HTTPException(
                    status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
                    detail=f"Error loading model: {str(e)}"
                )

    raise HTTPException(
        status_code=status.HTTP_404_NOT_FOUND,
        detail=f"Model with ID {model_id} not found"
    )

def format_model_response(model_id: str, model_data: dict) -> dict:
    """
    Format model data for API response.

    Args:
        model_id: Model ID
        model_data: Raw model data from joblib

    Returns:
        Dict with formatted model information
    """
    # Extract course ID from directory path if possible
    try:
        for root, _, files in os.walk(MODELS_DIR):
            if f"{model_id}.joblib" in files:
                dir_name = os.path.basename(root)
                courseid = int(dir_name.replace('course_', '')) if dir_name.startswith('course_') else 0
                break
        else:
            courseid = 0
    except:
        courseid = 0

    # Format and sanitize model data
    return {
        'model_id': model_id,
        'courseid': courseid,
        'algorithm': model_data.get('algorithm', 'unknown'),
        'trained_at': model_data.get('trained_at', 'unknown'),
        'metrics': model_data.get('metrics', {}),
        'feature_names': model_data.get('feature_names', []),
        'target_classes': model_data.get('target_classes', []),
        'cv_scores': model_data.get('cv_scores', [])
    }

if __name__ == "__main__":
    import uvicorn
    port = int(os.getenv("PORT", 8000))
    debug = os.getenv("DEBUG", "false").lower() == "true"
    print(f"Starting Student Performance Prediction API on port {port}, debug={debug}")
    uvicorn.run(app, host="0.0.0.0", port=port)
