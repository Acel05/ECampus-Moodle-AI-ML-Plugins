#!/usr/bin/env python3
"""
Machine Learning API for Student Performance Prediction

An enhanced API that trains models and makes predictions with confidence intervals.
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
from scipy import stats

# ML imports
from sklearn.preprocessing import StandardScaler, OneHotEncoder, RobustScaler
from sklearn.compose import ColumnTransformer
from sklearn.pipeline import Pipeline
from sklearn.model_selection import train_test_split, cross_val_score, StratifiedKFold
from sklearn.metrics import accuracy_score, precision_score, recall_score, f1_score, roc_auc_score, confusion_matrix
from sklearn.ensemble import RandomForestClassifier, GradientBoostingClassifier
from sklearn.linear_model import LogisticRegression
from sklearn.svm import SVC
from sklearn.tree import DecisionTreeClassifier
from sklearn.neighbors import KNeighborsClassifier
from sklearn.impute import SimpleImputer
from sklearn.feature_selection import SelectFromModel
from sklearn.calibration import CalibratedClassifierCV

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
    description="Enhanced Machine Learning API for Student Performance Prediction with confidence intervals",
    version="1.1.0"
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

# Pydantic models for responses
class TrainResponse(BaseModel):
    model_id: str
    algorithm: str
    metrics: Dict[str, Any]
    feature_names: List[str]
    target_classes: List[Any]
    trained_at: str
    training_time_seconds: float
    model_path: Optional[str] = None

class PredictResponse(BaseModel):
    prediction: Any
    probability: float
    probabilities: List[float]
    confidence_interval: Optional[Dict[str, float]] = None
    model_id: str
    prediction_time: str
    features: Dict[str, Any]

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

# Simple health check endpoint
@app.get("/health")
async def health_check():
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
            "version": "1.1.0",
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

def calculate_confidence_interval(prob, n=100, confidence=0.95):
    """Calculate confidence interval for a probability using Wilson score interval."""
    if n <= 0:
        return {"lower": prob, "upper": prob, "confidence": confidence}

    z = stats.norm.ppf((1 + confidence) / 2)
    factor = z / np.sqrt(n)

    # Wilson score interval
    denominator = 1 + z**2/n
    center = (prob + z**2/(2*n)) / denominator
    interval = factor * np.sqrt(prob * (1 - prob) / n + z**2/(4*n**2)) / denominator

    lower = max(0, center - interval)
    upper = min(1, center + interval)

    return {
        "lower": float(lower),
        "upper": float(upper), 
        "confidence": confidence
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

        # Data quality checks
        if len(df) < 30:
            logger.warning(f"Very small dataset with only {len(df)} samples. Model may not be reliable.")

        # Check for missing values
        missing_pct = df.isnull().mean() * 100
        high_missing_cols = missing_pct[missing_pct > 50].index.tolist()
        if high_missing_cols:
            logger.warning(f"Columns with >50% missing values: {high_missing_cols}")
            # Drop columns with too many missing values
            df = df.drop(columns=high_missing_cols)
            logger.info(f"Dropped {len(high_missing_cols)} columns with too many missing values")

        # Handle target column
        if request_data["target_column"] not in df.columns:
            possible_targets = ['final_outcome', 'pass', 'outcome', 'grade', 'result', 'status', 'final_grade', 'passed']
            for col in possible_targets:
                if col in df.columns:
                    logger.info(f"Using '{col}' as target column instead of '{request_data['target_column']}'")
                    request_data["target_column"] = col
                    break
            else:
                request_data["target_column"] = df.columns[-1]
                logger.warning(f"Target column not found, using last column '{request_data['target_column']}' as target")

        # Preprocess target - convert to binary if needed
        y = df[request_data["target_column"]]

        # Handle non-numeric targets
        if y.dtype == 'object' or y.dtype.name == 'category':
            logger.info(f"Converting categorical target to numeric. Original values: {y.unique()}")

            # Map common passing terms to 1, failing terms to 0
            if len(y.unique()) > 2:
                # Try to map based on common terms
                pass_terms = ['pass', 'passed', 'complete', 'completed', 'success', 'successful', 'satisfactory', 'yes', 'y', 'true', 't']
                fail_terms = ['fail', 'failed', 'incomplete', 'unsatisfactory', 'no', 'n', 'false', 'f']

                def map_target(val):
                    if not isinstance(val, str):
                        return val
                    val_lower = str(val).lower()
                    if any(term in val_lower for term in pass_terms):
                        return 1
                    if any(term in val_lower for term in fail_terms):
                        return 0
                    return val

                y = y.apply(map_target)

                # If still not binary, use label encoder
                if len(y.unique()) > 2:
                    from sklearn.preprocessing import LabelEncoder
                    le = LabelEncoder()
                    y = le.fit_transform(y)
                    logger.info(f"Applied LabelEncoder to target. New values: {np.unique(y)}")

            else:
                # Map the two unique values to 0 and 1
                unique_vals = y.unique()
                mapping = {unique_vals[0]: 0, unique_vals[1]: 1}
                y = y.map(mapping)
                logger.info(f"Mapped target values {unique_vals} to {list(mapping.values())}")

        # For regression-like targets, convert to binary based on median
        elif len(y.unique()) > 10:
            median = y.median()
            logger.info(f"Converting numeric target to binary using median {median} as threshold")
            y = (y >= median).astype(int)

        # Print target distribution
        logger.info(f"Target distribution: {pd.Series(y).value_counts().to_dict()}")

        # Feature selection
        X = df.drop(columns=[request_data["target_column"]] + request_data["id_columns"])

        # Remove constant features
        constant_features = [col for col in X.columns if X[col].nunique() <= 1]
        if constant_features:
            logger.info(f"Removing {len(constant_features)} constant features")
            X = X.drop(columns=constant_features)

        # Handle highly correlated features
        if len(X.select_dtypes(include=['number']).columns) > 10:
            numeric_X = X.select_dtypes(include=['number'])
            try:
                corr_matrix = numeric_X.corr().abs()
                upper = corr_matrix.where(np.triu(np.ones(corr_matrix.shape), k=1).astype(bool))
                high_corr_cols = [column for column in upper.columns if any(upper[column] > 0.95)]

                if high_corr_cols:
                    logger.info(f"Removing {len(high_corr_cols)} highly correlated features")
                    X = X.drop(columns=high_corr_cols)
            except Exception as e:
                logger.warning(f"Error computing correlations: {str(e)}")

        feature_names = X.columns.tolist()
        logger.info(f"Final feature count: {len(feature_names)}")
        logger.info(f"Features: {feature_names[:10]}{'...' if len(feature_names) > 10 else ''}")

        # Prepare preprocessing pipeline
        numeric_cols = X.select_dtypes(include=['int64', 'float64']).columns.tolist()
        categorical_cols = X.select_dtypes(include=['object', 'category', 'bool']).columns.tolist()

        logger.info(f"Numeric features: {len(numeric_cols)}, Categorical features: {len(categorical_cols)}")

        # Create robust preprocessing pipeline
        try:
            encoder = OneHotEncoder(drop='first', sparse_output=False, handle_unknown='ignore')
        except TypeError:
            # For older scikit-learn versions
            encoder = OneHotEncoder(drop='first', sparse=False, handle_unknown='ignore')

        # Use RobustScaler for better handling of outliers
        preprocessor = ColumnTransformer(
            transformers=[
                ('num', Pipeline([
                    ('imputer', SimpleImputer(strategy='median')), 
                    ('scaler', RobustScaler())
                ]), numeric_cols),
                ('cat', Pipeline([
                    ('imputer', SimpleImputer(strategy='most_frequent')), 
                    ('encoder', encoder)
                ]), categorical_cols)
            ],
            remainder='drop'
        )

        # Handle class imbalance
        class_counts = pd.Series(y).value_counts().to_dict()
        if len(class_counts) > 1:
            imbalance_ratio = max(class_counts.values()) / min(class_counts.values())
            class_weight = 'balanced' if imbalance_ratio > 3 else None
            if imbalance_ratio > 3:
                logger.warning(f"Significant class imbalance detected: ratio {imbalance_ratio:.2f}. Applying class weight balancing.")
        else:
            logger.warning("Only one class found in target. Model will not be useful for prediction.")
            class_weight = None

        # Train-test split with stratification when possible
        try:
            X_train, X_test, y_train, y_test = train_test_split(X, y, test_size=request_data["test_size"], 
                                                               random_state=42, stratify=y)
        except ValueError:
            logger.warning("Stratified split failed, falling back to random split")
            X_train, X_test, y_train, y_test = train_test_split(X, y, test_size=request_data["test_size"], 
                                                              random_state=42)

        # Define models with optimized hyperparameters
        models = {
            'randomforest': RandomForestClassifier(
                n_estimators=100, 
                max_depth=10, 
                min_samples_split=5, 
                min_samples_leaf=2, 
                class_weight=class_weight, 
                random_state=42,
                n_jobs=-1  # Use all cores
            ),
            'logisticregression': LogisticRegression(
                C=1.0, 
                class_weight=class_weight, 
                max_iter=1000, 
                solver='liblinear',  # More robust for small datasets
                random_state=42
            ),
            'gradientboosting': GradientBoostingClassifier(
                n_estimators=100, 
                learning_rate=0.1, 
                max_depth=3, 
                subsample=0.8, 
                random_state=42
            ),
            'svm': SVC(
                C=1.0, 
                kernel='rbf', 
                class_weight=class_weight, 
                probability=True, 
                random_state=42
            ),
            'decisiontree': DecisionTreeClassifier(
                max_depth=5, 
                min_samples_split=5, 
                min_samples_leaf=2, 
                class_weight=class_weight, 
                random_state=42
            ),
            'knn': KNeighborsClassifier(
                n_neighbors=5, 
                weights='distance'
            )
        }

        # Select model based on algorithm parameter or fall back to RandomForest
        model = models.get(request_data["algorithm"], models['randomforest'])
        if request_data["algorithm"] not in models:
            logger.warning(f"Unsupported algorithm '{request_data['algorithm']}', falling back to RandomForest")
            request_data["algorithm"] = 'randomforest'

        # Create pipeline with preprocessor and classifier
        pipeline = Pipeline([
            ('preprocessor', preprocessor), 
            ('classifier', model)
        ])

        # Perform k-fold cross-validation (k=5)
        logger.info("Performing 5-fold cross-validation with stratification")
        cv = StratifiedKFold(n_splits=5, shuffle=True, random_state=42)
        cv_scores = cross_val_score(pipeline, X, y, cv=cv, scoring='accuracy')
        cv_accuracy, cv_std = np.mean(cv_scores), np.std(cv_scores)
        logger.info(f"Cross-validation accuracy: {cv_accuracy:.4f} ± {cv_std:.4f}")

        # Train the model
        logger.info(f"Training {request_data['algorithm']} model")
        pipeline.fit(X_train, y_train)
        logger.info("Model training completed")

        # Apply probability calibration for better confidence estimates
        if hasattr(pipeline.named_steps['classifier'], 'predict_proba'):
            logger.info("Applying probability calibration for reliable confidence estimates")
            calibrated_classifier = CalibratedClassifierCV(
                pipeline, 
                method='isotonic', 
                cv='prefit'  # Use already fitted classifier
            )
            try:
                calibrated_classifier.fit(X_test, y_test)
                pipeline = calibrated_classifier
                logger.info("Probability calibration completed")
            except Exception as e:
                logger.warning(f"Probability calibration failed: {str(e)}")

        # Generate predictions and evaluate model
        y_pred = pipeline.predict(X_test)
        y_pred_proba = pipeline.predict_proba(X_test)

        # Calculate comprehensive metrics
        metrics = {
            "accuracy": float(accuracy_score(y_test, y_pred)),
            "cv_accuracy": float(cv_accuracy),
            "cv_std": float(cv_std),
            "precision": float(precision_score(y_test, y_pred, average='weighted', zero_division=0)),
            "recall": float(recall_score(y_test, y_pred, average='weighted', zero_division=0)),
            "f1": float(f1_score(y_test, y_pred, average='weighted', zero_division=0)),
            "k_folds": 5  # Explicitly record k value used for cross-validation
        }

        # Compute ROC AUC for binary classification
        if len(np.unique(y)) == 2:
            try:
                metrics["roc_auc"] = float(roc_auc_score(y_test, y_pred_proba[:, 1]))
            except (ValueError, IndexError) as e:
                logger.warning(f"ROC AUC calculation failed: {str(e)}")
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
            try:
                # Extract preprocessor to get transformed feature names
                preprocessor = pipeline.named_steps['preprocessor']
                feature_names_out = []

                # Try to get feature names from the preprocessor
                for name, trans, cols in preprocessor.transformers_:
                    if hasattr(trans, 'get_feature_names_out'):
                        feature_names_out.extend(trans.get_feature_names_out(cols))
                    else:
                        # Fallback for older scikit-learn versions
                        feature_names_out.extend([f"{name}_{col}" for col in cols])

                importances = pipeline.named_steps['classifier'].feature_importances_

                # Ensure lengths match
                if len(importances) == len(feature_names_out):
                    importance_dict = dict(zip(feature_names_out, importances))
                    sorted_features = sorted(importance_dict.items(), key=lambda x: x[1], reverse=True)
                    metrics["top_features"] = {str(k): float(v) for k, v in sorted_features[:10]}
                else:
                    # Fallback to unnamed features
                    top_indices = np.argsort(importances)[-10:][::-1]
                    metrics["top_features"] = {f"feature_{i}": float(importances[i]) for i in top_indices}
            except Exception as e:
                logger.warning(f"Error extracting feature importance: {str(e)}")

        # Add confusion matrix
        try:
            cm = confusion_matrix(y_test, y_pred)
            metrics["confusion_matrix"] = cm.tolist()
        except Exception as e:
            logger.warning(f"Error computing confusion matrix: {str(e)}")

        # Calculate confidence intervals for predictions
        # Use the effective sample size for confidence intervals
        effective_n = min(len(X_test), 100)  # Cap at 100 to avoid overconfidence
        metrics["confidence_interval"] = calculate_confidence_interval(
            metrics["accuracy"], 
            n=effective_n, 
            confidence=0.95
        )

        # Generate unique model ID and save the model
        model_id = str(uuid.uuid4())
        course_models_dir = os.path.join(MODELS_DIR, f"course_{request_data['courseid']}")
        os.makedirs(course_models_dir, exist_ok=True)
        model_path = os.path.join(course_models_dir, f"{model_id}.joblib")

        # Store model metadata
        model_data = {
            'pipeline': pipeline,
            'feature_names': feature_names,
            'algorithm': request_data["algorithm"],
            'trained_at': datetime.now().isoformat(),
            'target_classes': list(np.unique(y)),
            'metrics': metrics,
            'cv_scores': cv_scores.tolist(),
            'effective_sample_size': effective_n
        }

        # Save model to disk and cache
        joblib.dump(model_data, model_path)
        MODEL_CACHE[model_id] = model_data
        logger.info(f"Model saved to {model_path}")

        # Calculate training time
        training_time = time.time() - start_time
        logger.info(f"Training completed in {training_time:.2f} seconds")

        # Return comprehensive model information
        return {
            "model_id": model_id,
            "algorithm": request_data["algorithm"],
            "metrics": metrics,
            "feature_names": [str(f) for f in feature_names],
            "target_classes": [int(c) if isinstance(c, (np.integer, np.int64, np.int32)) else c for c in np.unique(y)],
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
    try:
        model_id = request.get("model_id")
        features = request.get("features")

        if not model_id:
            raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail="model_id is required")
        if not features:
            raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail="features are required")

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
        effective_n = model_data.get('effective_sample_size', 50)  # Default to 50 if not stored

        logger.info(f"Model feature names: {feature_names[:5]}{'...' if len(feature_names) > 5 else ''}")

        # Prepare input data
        try:
            input_df = pd.DataFrame([features] if not is_batch else features)

            # Handle missing columns
            for feat in feature_names:
                if feat not in input_df.columns:
                    logger.info(f"Adding missing feature {feat} with default value 0")
                    input_df[feat] = 0

            # Select only columns that match the model's feature names
            valid_features = [f for f in feature_names if f in input_df.columns]
            input_df = input_df[valid_features]

            # Log shape info for debugging
            logger.info(f"Input data shape: {input_df.shape}")

            # Additional validation to ensure data matches model expectations
            if input_df.shape[1] == 0:
                raise ValueError("No valid features found in input data")

        except Exception as e:
            logger.error(f"Error preparing input data: {str(e)}")
            raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail=f"Invalid feature format: {str(e)}")

        # Make prediction
        logger.info("Making prediction")
        try:
            predictions = pipeline.predict(input_df).tolist()
            probabilities = pipeline.predict_proba(input_df).tolist()
            logger.info(f"Prediction successful: {predictions[:5]}{'...' if len(predictions) > 5 else ''}")
        except Exception as e:
            logger.error(f"Error during prediction: {str(e)}")
            raise HTTPException(status_code=status.HTTP_500_INTERNAL_SERVER_ERROR, detail=f"Error during prediction: {str(e)}")

        # Process results based on batch or single prediction
        if is_batch:
            # For batch predictions, get probability of positive class
            positive_class_idx = 1 if len(pipeline.classes_) == 2 and 1 in pipeline.classes_ else 0
            positive_probs = [probs[positive_class_idx] for probs in probabilities] if len(pipeline.classes_) == 2 else [max(probs) for probs in probabilities]

            # Calculate confidence intervals for each prediction
            confidence_intervals = [
                calculate_confidence_interval(prob, n=effective_n) for prob in positive_probs
            ]

            return {
                "predictions": predictions,
                "probabilities": positive_probs,
                "confidence_intervals": confidence_intervals,
                "model_id": model_id,
                "prediction_time": datetime.now().isoformat(),
                "features": features  # Return the batch of features
            }
        else:
            # For single prediction
            prediction = predictions[0]

            # Get probability of prediction class or positive class for binary classification
            if len(pipeline.classes_) == 2:
                positive_class_idx = 1 if 1 in pipeline.classes_ else 0
                probability = float(probabilities[0][positive_class_idx])
            else:
                pred_idx = list(pipeline.classes_).index(prediction)
                probability = float(probabilities[0][pred_idx])

            # Calculate confidence interval for the prediction
            confidence_interval = calculate_confidence_interval(probability, n=effective_n)

            # Enhanced prediction response with confidence interval
            return {
                "prediction": prediction,
                "probability": probability,
                "probabilities": probabilities[0],
                "confidence_interval": confidence_interval,
                "model_id": model_id,
                "prediction_time": datetime.now().isoformat(),
                "features": features  # Return the input features
            }

    except HTTPException:
        raise
    except Exception as e:
        logger.exception(f"Unhandled error in prediction: {str(e)}")
        raise HTTPException(status_code=status.HTTP_500_INTERNAL_SERVER_ERROR, detail=f"Error making prediction: {str(e)}")

@app.get("/debug/filesystem", dependencies=[Depends(verify_api_key)])
async def debug_filesystem(path: str = None):
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

@app.get("/models/{course_id}", dependencies=[Depends(verify_api_key)])
async def list_models(course_id: int):
    """List all trained models for a specific course"""
    course_models_dir = os.path.join(MODELS_DIR, f"course_{course_id}")

    if not os.path.exists(course_models_dir):
        return {"models": []}

    models = []
    for filename in os.listdir(course_models_dir):
        if filename.endswith('.joblib'):
            model_id = filename.split('.')[0]
            model_path = os.path.join(course_models_dir, filename)

            try:
                # Load model metadata without loading the full model
                model_data = joblib.load(model_path)

                models.append({
                    "model_id": model_id,
                    "algorithm": model_data.get('algorithm', 'unknown'),
                    "accuracy": model_data.get('metrics', {}).get('accuracy', 0),
                    "trained_at": model_data.get('trained_at', ''),
                    "file_size_mb": round(os.path.getsize(model_path) / (1024 * 1024), 2)
                })
            except Exception as e:
                logger.error(f"Error loading model {model_id}: {str(e)}")
                models.append({
                    "model_id": model_id,
                    "error": str(e),
                    "file_path": model_path
                })

    return {"models": models}

@app.get("/model/{model_id}", dependencies=[Depends(verify_api_key)])
async def get_model_details(model_id: str):
    """Get detailed information about a specific model"""

    # Try to find the model file
    model_path = None
    for root, _, files in os.walk(MODELS_DIR):
        if f"{model_id}.joblib" in files:
            model_path = os.path.join(root, f"{model_id}.joblib")
            break

    if not model_path:
        raise HTTPException(status_code=404, detail=f"Model with ID {model_id} not found")

    try:
        # Load model metadata
        model_data = joblib.load(model_path)

        # Extract key information, but not the actual model pipeline
        return {
            "model_id": model_id,
            "algorithm": model_data.get('algorithm', 'unknown'),
            "metrics": model_data.get('metrics', {}),
            "feature_names": model_data.get('feature_names', []),
            "target_classes": model_data.get('target_classes', []),
            "trained_at": model_data.get('trained_at', ''),
            "file_path": model_path,
            "file_size_mb": round(os.path.getsize(model_path) / (1024 * 1024), 2)
        }
    except Exception as e:
        logger.error(f"Error loading model {model_id}: {str(e)}")
        raise HTTPException(status_code=500, detail=f"Error loading model: {str(e)}")

if __name__ == "__main__":
    import uvicorn
    port = int(os.getenv("PORT", 8000))
    debug = os.getenv("DEBUG", "false").lower() == "true"
    print(f"Starting Enhanced Student Performance Prediction API on port {port}, debug={debug}")
    uvicorn.run(app, host="0.0.0.0", port=port)
