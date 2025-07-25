#!/usr/bin/env python3
"""
Advanced Machine Learning Backend for Student Performance Predictor Moodle Plugin

This module provides a REST API for model training and prediction with enhanced
preprocessing, model selection, hyperparameter tuning, and evaluation.

Usage:
    uvicorn ml_backend:app --host 0.0.0.0 --port 5000

Requirements:
    - fastapi
    - uvicorn
    - scikit-learn>=1.0.0
    - pandas
    - numpy
    - joblib
    - python-dotenv
    - matplotlib
    - shap (optional for explainability)
"""

import os
import uuid
import json
import time
import logging
import traceback
from datetime import datetime
from typing import Dict, List, Optional, Union, Any, Tuple

import numpy as np
import pandas as pd
import joblib
import matplotlib.pyplot as plt
from fastapi import FastAPI, HTTPException, Depends, Header, Request, status, BackgroundTasks
from fastapi.responses import JSONResponse, FileResponse
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel, Field, validator
from dotenv import load_dotenv

# Advanced ML imports
from sklearn.preprocessing import StandardScaler, MinMaxScaler, RobustScaler, OneHotEncoder, OrdinalEncoder, LabelEncoder
from sklearn.compose import ColumnTransformer
from sklearn.pipeline import Pipeline
from sklearn.model_selection import train_test_split, GridSearchCV, StratifiedKFold
from sklearn.metrics import (
    accuracy_score, precision_score, recall_score, f1_score, roc_auc_score,
    confusion_matrix, classification_report, matthews_corrcoef, balanced_accuracy_score
)
from sklearn.ensemble import RandomForestClassifier, GradientBoostingClassifier, VotingClassifier
from sklearn.linear_model import LogisticRegression
from sklearn.svm import SVC
from sklearn.tree import DecisionTreeClassifier
from sklearn.neighbors import KNeighborsClassifier
from sklearn.feature_selection import SelectFromModel, RFE
from sklearn.impute import SimpleImputer, KNNImputer
from sklearn.base import BaseEstimator, TransformerMixin
from sklearn.utils.class_weight import compute_class_weight

# Try to import optional libraries
try:
    import shap
    SHAP_AVAILABLE = True
except ImportError:
    SHAP_AVAILABLE = False

# Load environment variables from .env file
load_dotenv()

# Configure logging
logging.basicConfig(
    level=logging.INFO if os.getenv("DEBUG", "false").lower() != "true" else logging.DEBUG,
    format='%(asctime)s - %(name)s - %(levelname)s - %(message)s',
    handlers=[
        logging.FileHandler("ml_backend.log"),
        logging.StreamHandler()
    ]
)
logger = logging.getLogger(__name__)

# Set up FastAPI app
app = FastAPI(
    title="Advanced Student Performance Predictor API",
    description="Machine Learning API for the Moodle Student Performance Predictor block with enhanced capabilities",
    version="2.0.0"
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
REPORTS_DIR = os.path.join(os.getcwd(), os.getenv("REPORTS_DIR", "reports"))

# Create directories if they don't exist
for directory in [MODELS_DIR, DATASETS_DIR, REPORTS_DIR]:
    os.makedirs(directory, exist_ok=True)

# Models cache to avoid reloading models for each prediction
MODEL_CACHE = {}

# Custom transformer for handling outliers
class OutlierHandler(BaseEstimator, TransformerMixin):
    def __init__(self, method='clip', threshold=3.0):
        self.method = method
        self.threshold = threshold
        self.feature_means_ = None
        self.feature_stds_ = None

    def fit(self, X, y=None):
        self.feature_means_ = np.mean(X, axis=0)
        self.feature_stds_ = np.std(X, axis=0)
        return self

    def transform(self, X, y=None):
        X_copy = X.copy()

        if self.method == 'clip':
            lower_bound = self.feature_means_ - self.threshold * self.feature_stds_
            upper_bound = self.feature_means_ + self.threshold * self.feature_stds_
            return np.clip(X_copy, lower_bound, upper_bound)
        elif self.method == 'remove':
            # This is a placeholder - in reality, we'd need to handle this differently
            # since removing rows would break the pipeline
            return X_copy
        else:
            return X_copy

# Pydantic models for API requests and responses
class HealthResponse(BaseModel):
    status: str
    time: str
    version: str
    python_version: str = Field(default_factory=lambda: platform.python_version())
    scikit_learn_version: str = Field(default_factory=lambda: sklearn.__version__)

class ModelParams(BaseModel):
    """Parameters for model training"""
    # General parameters
    test_size: Optional[float] = 0.2
    random_state: Optional[int] = 42
    class_weight: Optional[str] = "balanced"  # None, "balanced", or "balanced_subsample"

    # Algorithm-specific parameters
    # Random Forest
    n_estimators: Optional[int] = 100
    max_depth: Optional[int] = None
    min_samples_split: Optional[int] = 2
    min_samples_leaf: Optional[int] = 1

    # Logistic Regression
    C: Optional[float] = 1.0
    penalty: Optional[str] = "l2"
    solver: Optional[str] = "lbfgs"
    max_iter: Optional[int] = 1000

    # SVM
    kernel: Optional[str] = "rbf"
    gamma: Optional[str] = "scale"

    # KNN
    n_neighbors: Optional[int] = 5
    weights: Optional[str] = "uniform"

    # Preprocessing
    scaler: Optional[str] = "standard"  # "standard", "minmax", "robust" 
    imputer: Optional[str] = "mean"     # "mean", "median", "most_frequent", "knn"
    handle_outliers: Optional[bool] = False
    outlier_method: Optional[str] = "clip"  # "clip", "remove"
    outlier_threshold: Optional[float] = 3.0

    # Feature selection
    feature_selection: Optional[bool] = False
    feature_selection_method: Optional[str] = "rfe"  # "rfe", "model_based"
    feature_selection_threshold: Optional[float] = 0.05

    # Hyperparameter tuning
    do_hyperparameter_tuning: Optional[bool] = False
    cv_folds: Optional[int] = 3

    @validator('test_size')
    def validate_test_size(cls, v):
        if not 0.0 < v < 1.0:
            raise ValueError('test_size must be between 0 and 1')
        return v

    @validator('n_estimators', 'max_iter', 'n_neighbors', 'cv_folds')
    def validate_positive_int(cls, v):
        if v is not None and v <= 0:
            raise ValueError('must be a positive integer')
        return v

class PreprocessingOptions(BaseModel):
    """Options for data preprocessing"""
    handle_missing: Optional[bool] = True
    handle_outliers: Optional[bool] = False
    normalize_data: Optional[bool] = True
    categorical_encoding: Optional[str] = "onehot"  # "onehot", "ordinal", "target"
    feature_selection: Optional[bool] = False
    balance_classes: Optional[bool] = False
    balance_method: Optional[str] = "smote"  # "smote", "random_under", "random_over"

class TrainRequest(BaseModel):
    courseid: int
    dataset_filepath: str
    algorithm: str
    userid: Optional[int] = None
    target_column: Optional[str] = "final_outcome"
    id_columns: Optional[List[str]] = []  # Columns to exclude from training
    params: Optional[ModelParams] = Field(default_factory=ModelParams)
    preprocessing: Optional[PreprocessingOptions] = Field(default_factory=PreprocessingOptions)
    create_report: Optional[bool] = True

    class Config:
        schema_extra = {
            "example": {
                "courseid": 123,
                "dataset_filepath": "/path/to/dataset.csv",
                "algorithm": "randomforest",
                "target_column": "final_outcome",
                "id_columns": ["student_id", "course_id"],
                "params": {
                    "test_size": 0.2,
                    "random_state": 42,
                    "n_estimators": 100,
                    "max_depth": 10,
                    "scaler": "standard",
                    "feature_selection": True
                },
                "create_report": True
            }
        }

class TrainResponse(BaseModel):
    model_id: str
    model_path: str
    algorithm: str
    metrics: Dict[str, float]
    feature_importance: Optional[Dict[str, float]]
    report_path: Optional[str]
    feature_names: List[str]
    target_classes: List[Any]
    trained_at: str
    training_time_seconds: float

class PredictRequest(BaseModel):
    model_id: str
    features: Dict[str, Any]  # Feature name to value mapping
    explanation: Optional[bool] = False  # Whether to return SHAP values

class PredictionResult(BaseModel):
    prediction: Any
    probabilities: List[float]
    confidence: float
    explanation: Optional[Dict[str, float]] = None

class PredictResponse(BaseModel):
    results: PredictionResult
    model_id: str
    prediction_time: str
    processing_time_ms: float

class ModelListItem(BaseModel):
    model_id: str
    algorithm: str
    trained_at: str
    metrics: Dict[str, float]
    feature_count: int
    target_classes: List[Any]

class ModelListResponse(BaseModel):
    models: List[ModelListItem]
    count: int

class ModelDetailResponse(BaseModel):
    model_id: str
    algorithm: str
    trained_at: str
    metrics: Dict[str, float]
    feature_names: List[str]
    target_classes: List[Any]
    feature_importance: Optional[Dict[str, float]]
    training_params: Dict[str, Any]
    preprocessing_params: Dict[str, Any]

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
    error_id = str(uuid.uuid4())
    logger.exception(f"Error ID: {error_id} - Unhandled exception")
    return JSONResponse(
        status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
        content={
            "detail": str(exc),
            "error_id": error_id,
            "traceback": traceback.format_exc() if os.getenv("DEBUG", "false").lower() == "true" else None
        }
    )

@app.get("/health", response_model=HealthResponse)
async def health_check():
    """Health check endpoint to verify the API is running"""
    import platform
    import sklearn

    return {
        "status": "ok",
        "time": datetime.now().isoformat(),
        "version": "2.0.0",
        "python_version": platform.python_version(),
        "scikit_learn_version": sklearn.__version__
    }

def create_model_report(model_data: Dict, X_test: pd.DataFrame, y_test: pd.Series, 
                        model_id: str, courseid: int) -> str:
    """
    Create a comprehensive model evaluation report with visualizations.

    Args:
        model_data: Dictionary containing model information
        X_test: Test features
        y_test: Test target values
        model_id: Unique model identifier
        courseid: Course ID

    Returns:
        Path to the generated report file
    """
    try:
        pipeline = model_data['pipeline']
        feature_names = model_data['feature_names']
        algorithm = model_data['algorithm']

        # Create a directory for this report
        report_dir = os.path.join(REPORTS_DIR, f"course_{courseid}", model_id)
        os.makedirs(report_dir, exist_ok=True)

        # Get predictions
        y_pred = pipeline.predict(X_test)
        y_pred_proba = pipeline.predict_proba(X_test)

        # Create report components
        report_parts = []

        # 1. Basic model information
        report_parts.append(f"# Model Evaluation Report\n")
        report_parts.append(f"- **Model ID:** {model_id}")
        report_parts.append(f"- **Algorithm:** {algorithm}")
        report_parts.append(f"- **Generated:** {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}\n")

        # 2. Classification Report
        report_parts.append("## Classification Report\n")
        report_parts.append("```")
        report_parts.append(classification_report(y_test, y_pred))
        report_parts.append("```\n")

        # 3. Confusion Matrix
        cm = confusion_matrix(y_test, y_pred)
        plt.figure(figsize=(8, 6))
        plt.imshow(cm, interpolation='nearest', cmap=plt.cm.Blues)
        plt.title('Confusion Matrix')
        plt.colorbar()

        classes = pipeline.classes_
        tick_marks = np.arange(len(classes))
        plt.xticks(tick_marks, classes, rotation=45)
        plt.yticks(tick_marks, classes)

        fmt = 'd'
        thresh = cm.max() / 2.
        for i, j in np.ndindex(cm.shape):
            plt.text(j, i, format(cm[i, j], fmt),
                    horizontalalignment="center",
                    color="white" if cm[i, j] > thresh else "black")

        plt.ylabel('True label')
        plt.xlabel('Predicted label')
        plt.tight_layout()
        
        # Save confusion matrix figure
        cm_path = os.path.join(report_dir, "confusion_matrix.png")
        plt.savefig(cm_path)
        plt.close()

        report_parts.append("## Confusion Matrix\n")
        report_parts.append(f"![Confusion Matrix]({os.path.basename(cm_path)})\n")

        # 4. Feature Importance
        try:
            if hasattr(pipeline[-1], 'feature_importances_'):
                feature_importance = pipeline[-1].feature_importances_
                sorted_idx = np.argsort(feature_importance)

                # Get feature names after preprocessing
                preprocessor = pipeline[0]
                if hasattr(preprocessor, 'get_feature_names_out'):
                    feature_names_out = preprocessor.get_feature_names_out()
                else:
                    feature_names_out = [f"feature_{i}" for i in range(len(feature_importance))]

                plt.figure(figsize=(10, 8))
                plt.barh(range(len(sorted_idx)), feature_importance[sorted_idx])
                plt.yticks(range(len(sorted_idx)), [feature_names_out[i] for i in sorted_idx])
                plt.title('Feature Importance')
                plt.tight_layout()

                # Save feature importance figure
                fi_path = os.path.join(report_dir, "feature_importance.png")
                plt.savefig(fi_path)
                plt.close()

                report_parts.append("## Feature Importance\n")
                report_parts.append(f"![Feature Importance]({os.path.basename(fi_path)})\n")

            elif hasattr(pipeline[-1], 'coef_'):
                # For linear models like Logistic Regression
                coefs = pipeline[-1].coef_

                # For multi-class, take average absolute coefficient across classes
                if coefs.ndim > 1:
                    coefs = np.abs(coefs).mean(axis=0)

                # Get feature names after preprocessing
                preprocessor = pipeline[0]
                if hasattr(preprocessor, 'get_feature_names_out'):
                    feature_names_out = preprocessor.get_feature_names_out()
                else:
                    feature_names_out = [f"feature_{i}" for i in range(len(coefs))]

                sorted_idx = np.argsort(coefs)

                plt.figure(figsize=(10, 8))
                plt.barh(range(len(sorted_idx)), coefs[sorted_idx])
                plt.yticks(range(len(sorted_idx)), [feature_names_out[i] for i in sorted_idx])
                plt.title('Feature Coefficients')
                plt.tight_layout()

                # Save coefficients figure
                coef_path = os.path.join(report_dir, "feature_coefficients.png")
                plt.savefig(coef_path)
                plt.close()

                report_parts.append("## Feature Coefficients\n")
                report_parts.append(f"![Feature Coefficients]({os.path.basename(coef_path)})\n")
        except Exception as e:
            logger.warning(f"Could not generate feature importance plot: {str(e)}")
            report_parts.append("## Feature Importance\n")
            report_parts.append("Feature importance could not be calculated for this model.\n")

        # 5. ROC Curve for binary classification
        if len(pipeline.classes_) == 2:
            from sklearn.metrics import roc_curve, auc
            fpr, tpr, _ = roc_curve(y_test, y_pred_proba[:, 1])
            roc_auc = auc(fpr, tpr)

            plt.figure(figsize=(8, 6))
            plt.plot(fpr, tpr, label=f'ROC curve (area = {roc_auc:.2f})')
            plt.plot([0, 1], [0, 1], 'k--')
            plt.xlim([0.0, 1.0])
            plt.ylim([0.0, 1.05])
            plt.xlabel('False Positive Rate')
            plt.ylabel('True Positive Rate')
            plt.title('Receiver Operating Characteristic')
            plt.legend(loc="lower right")
            plt.tight_layout()

            # Save ROC curve figure
            roc_path = os.path.join(report_dir, "roc_curve.png")
            plt.savefig(roc_path)
            plt.close()

            report_parts.append("## ROC Curve\n")
            report_parts.append(f"![ROC Curve]({os.path.basename(roc_path)})\n")

        # 6. Precision-Recall Curve for imbalanced datasets
        if len(pipeline.classes_) == 2:
            from sklearn.metrics import precision_recall_curve, average_precision_score
            precision, recall, _ = precision_recall_curve(y_test, y_pred_proba[:, 1])
            avg_precision = average_precision_score(y_test, y_pred_proba[:, 1])

            plt.figure(figsize=(8, 6))
            plt.plot(recall, precision, label=f'PR curve (AP = {avg_precision:.2f})')
            plt.xlabel('Recall')
            plt.ylabel('Precision')
            plt.ylim([0.0, 1.05])
            plt.xlim([0.0, 1.0])
            plt.title('Precision-Recall Curve')
            plt.legend(loc="lower left")
            plt.tight_layout()

            # Save PR curve figure
            pr_path = os.path.join(report_dir, "pr_curve.png")
            plt.savefig(pr_path)
            plt.close()

            report_parts.append("## Precision-Recall Curve\n")
            report_parts.append(f"![Precision-Recall Curve]({os.path.basename(pr_path)})\n")

        # 7. Write full report to file
        report_md = os.path.join(report_dir, "report.md")
        with open(report_md, 'w') as f:
            f.write('\n'.join(report_parts))

        # 8. Create a simple HTML version (could use a markdown to HTML converter for better results)
        report_html = os.path.join(report_dir, "report.html")
        with open(report_html, 'w') as f:
            f.write("<html><head><title>Model Report</title></head><body>")
            f.write(f"<h1>Model Evaluation Report for {model_id}</h1>")

            # Add images with proper paths
            f.write("<h2>Confusion Matrix</h2>")
            f.write(f"<img src='confusion_matrix.png' alt='Confusion Matrix'>")

            if os.path.exists(os.path.join(report_dir, "feature_importance.png")):
                f.write("<h2>Feature Importance</h2>")
                f.write(f"<img src='feature_importance.png' alt='Feature Importance'>")

            if os.path.exists(os.path.join(report_dir, "feature_coefficients.png")):
                f.write("<h2>Feature Coefficients</h2>")
                f.write(f"<img src='feature_coefficients.png' alt='Feature Coefficients'>")

            if len(pipeline.classes_) == 2:
                f.write("<h2>ROC Curve</h2>")
                f.write(f"<img src='roc_curve.png' alt='ROC Curve'>")

                f.write("<h2>Precision-Recall Curve</h2>")
                f.write(f"<img src='pr_curve.png' alt='Precision-Recall Curve'>")

            f.write("</body></html>")

        return report_html

    except Exception as e:
        logger.exception(f"Error creating model report: {str(e)}")
        return None

def get_feature_importances(model, feature_names) -> Dict[str, float]:
    """Extract feature importance from a trained model."""
    try:
        importances = {}

        # For tree-based models
        if hasattr(model, 'feature_importances_'):
            for name, importance in zip(feature_names, model.feature_importances_):
                importances[name] = float(importance)

        # For linear models
        elif hasattr(model, 'coef_'):
            # For multi-class, take the mean absolute coefficient
            if model.coef_.ndim > 1:
                coefs = np.abs(model.coef_).mean(axis=0)
            else:
                coefs = np.abs(model.coef_)

            for name, coef in zip(feature_names, coefs):
                importances[name] = float(coef)

        # Sort by importance (descending)
        return dict(sorted(importances.items(), key=lambda x: x[1], reverse=True))

    except Exception as e:
        logger.warning(f"Could not extract feature importance: {str(e)}")
        return {}

def preprocess_data(df: pd.DataFrame, 
                   target_column: str, 
                   id_columns: List[str],
                   preprocessing: PreprocessingOptions) -> Tuple[pd.DataFrame, pd.Series, List[str], List[str]]:
    """
    Preprocess the dataset before model training.

    Args:
        df: Input DataFrame
        target_column: Name of the target variable column
        id_columns: Columns to exclude from training (e.g., IDs)
        preprocessing: Preprocessing options

    Returns:
        Tuple containing:
        - Processed features DataFrame
        - Target series
        - List of numerical column names
        - List of categorical column names
    """
    # Make a copy to avoid modifying the original
    df = df.copy()

    # Check if target column exists
    if target_column not in df.columns:
        raise ValueError(f"Target column '{target_column}' not found in dataset")

    # Extract target and remove excluded columns
    y = df[target_column]
    X = df.drop(columns=[target_column] + id_columns)

    # Identify numeric and categorical columns
    numeric_cols = X.select_dtypes(include=['int64', 'float64']).columns.tolist()
    categorical_cols = X.select_dtypes(include=['object', 'category', 'bool']).columns.tolist()

    logger.info(f"Identified {len(numeric_cols)} numeric features and {len(categorical_cols)} categorical features")

    # Basic data checks
    logger.info(f"Data shape before preprocessing: {X.shape}")
    logger.info(f"Missing values per column: {X.isna().sum().sum()}")

    # Check for duplicate rows
    dup_count = X.duplicated().sum()
    if dup_count > 0:
        logger.warning(f"Found {dup_count} duplicate rows in the dataset")

    # Check target distribution
    logger.info(f"Target distribution: {y.value_counts().to_dict()}")

    return X, y, numeric_cols, categorical_cols

@app.post("/train", response_model=TrainResponse, dependencies=[Depends(verify_api_key)])
async def train_model(request: TrainRequest, background_tasks: BackgroundTasks):
    """
    Train a machine learning model with the provided dataset.
    """
    start_time = time.time()
    logger.info(f"Training request received for course {request.courseid} using {request.algorithm}")

    try:
        # Normalize filepath for cross-platform compatibility
        dataset_filepath = request.dataset_filepath.replace('\\', '/')

        # Check if file exists
        if not os.path.exists(dataset_filepath):
            error_msg = f"Dataset file not found: {dataset_filepath}"
            logger.error(error_msg)
            raise HTTPException(
                status_code=status.HTTP_404_NOT_FOUND,
                detail=error_msg
            )

        # Load data based on file format
        file_extension = os.path.splitext(request.dataset_filepath)[1].lower()
        if file_extension == '.csv':
            df = pd.read_csv(request.dataset_filepath)
        elif file_extension == '.json':
            df = pd.read_json(request.dataset_filepath)
        elif file_extension in ['.xlsx', '.xls']:
            df = pd.read_excel(request.dataset_filepath)
        else:
            raise HTTPException(
                status_code=status.HTTP_400_BAD_REQUEST,
                detail=f"Unsupported file format: {file_extension}"
            )

        logger.info(f"Dataset loaded with {df.shape[0]} rows and {df.shape[1]} columns")

        # Preprocess data
        try:
            X, y, numeric_cols, categorical_cols = preprocess_data(
                df, 
                request.target_column, 
                request.id_columns,
                request.preprocessing
            )
        except ValueError as e:
            raise HTTPException(
                status_code=status.HTTP_400_BAD_REQUEST,
                detail=str(e)
            )

        # Keep track of feature names
        feature_names = X.columns.tolist()

        # Create preprocessing steps
        preprocessing_steps = []

        # 1. Handle missing values
        if request.preprocessing.handle_missing:
            if request.params.imputer == "mean":
                numeric_imputer = SimpleImputer(strategy='mean')
            elif request.params.imputer == "median":
                numeric_imputer = SimpleImputer(strategy='median')
            elif request.params.imputer == "most_frequent":
                numeric_imputer = SimpleImputer(strategy='most_frequent')
            elif request.params.imputer == "knn":
                numeric_imputer = KNNImputer(n_neighbors=5)
            else:
                numeric_imputer = SimpleImputer(strategy='mean')

            categorical_imputer = SimpleImputer(strategy='most_frequent')
        else:
            numeric_imputer = 'passthrough'
            categorical_imputer = 'passthrough'

        # 2. Handle outliers in numeric features
        if request.preprocessing.handle_outliers and request.params.handle_outliers:
            outlier_handler = OutlierHandler(
                method=request.params.outlier_method,
                threshold=request.params.outlier_threshold
            )
        else:
            outlier_handler = 'passthrough'

        # 3. Scaling numeric features
        if request.preprocessing.normalize_data:
            if request.params.scaler == "standard":
                scaler = StandardScaler()
            elif request.params.scaler == "minmax":
                scaler = MinMaxScaler()
            elif request.params.scaler == "robust":
                scaler = RobustScaler()
            else:
                scaler = StandardScaler()
        else:
            scaler = 'passthrough'

        # 4. Encoding categorical features
        if request.preprocessing.categorical_encoding == "onehot":
            categorical_encoder = OneHotEncoder(drop='first', sparse=False, handle_unknown='ignore')
        elif request.preprocessing.categorical_encoding == "ordinal":
            categorical_encoder = OrdinalEncoder(handle_unknown='use_encoded_value', unknown_value=-1)
        else:
            categorical_encoder = OneHotEncoder(drop='first', sparse=False, handle_unknown='ignore')

        # Create preprocessing pipeline
        if numeric_cols and categorical_cols:
            # Both numeric and categorical features
            preprocessor = ColumnTransformer(
                transformers=[
                    ('num', Pipeline([
                        ('imputer', numeric_imputer),
                        ('outlier_handler', outlier_handler),
                        ('scaler', scaler)
                    ]), numeric_cols),
                    ('cat', Pipeline([
                        ('imputer', categorical_imputer),
                        ('encoder', categorical_encoder)
                    ]), categorical_cols)
                ],
                remainder='drop'
            )
        elif numeric_cols:
            # Only numeric features
            preprocessor = Pipeline([
                ('imputer', numeric_imputer),
                ('outlier_handler', outlier_handler),
                ('scaler', scaler)
            ])
        elif categorical_cols:
            # Only categorical features
            preprocessor = Pipeline([
                ('imputer', categorical_imputer),
                ('encoder', categorical_encoder)
            ])
        else:
            raise HTTPException(
                status_code=status.HTTP_400_BAD_REQUEST,
                detail="No valid features found in dataset"
            )

        # Split data into training and testing sets
        X_train, X_test, y_train, y_test = train_test_split(
            X, y, test_size=request.params.test_size, 
            random_state=request.params.random_state,
            stratify=y if request.preprocessing.balance_classes else None
        )

        # Class weights for imbalanced datasets
        if request.params.class_weight == "balanced":
            class_weights = "balanced"
        elif request.params.class_weight == "balanced_subsample":
            class_weights = "balanced_subsample"
        else:
            class_weights = None

        # Choose and configure the model
        model = None
        if request.algorithm == 'randomforest':
            model = RandomForestClassifier(
                n_estimators=request.params.n_estimators,
                max_depth=request.params.max_depth,
                min_samples_split=request.params.min_samples_split,
                min_samples_leaf=request.params.min_samples_leaf,
                class_weight=class_weights,
                random_state=request.params.random_state
            )
        elif request.algorithm == 'logisticregression':
            model = LogisticRegression(
                C=request.params.C,
                penalty=request.params.penalty,
                solver=request.params.solver,
                max_iter=request.params.max_iter,
                class_weight=class_weights,
                random_state=request.params.random_state,
                multi_class='auto'
            )
        elif request.algorithm == 'svm':
            model = SVC(
                C=request.params.C,
                kernel=request.params.kernel,
                gamma=request.params.gamma,
                probability=True,
                class_weight=class_weights,
                random_state=request.params.random_state
            )
        elif request.algorithm == 'decisiontree':
            model = DecisionTreeClassifier(
                max_depth=request.params.max_depth,
                min_samples_split=request.params.min_samples_split,
                min_samples_leaf=request.params.min_samples_leaf,
                class_weight=class_weights,
                random_state=request.params.random_state
            )
        elif request.algorithm == 'knn':
            model = KNeighborsClassifier(
                n_neighbors=request.params.n_neighbors,
                weights=request.params.weights
            )
        elif request.algorithm == 'gradientboosting':
            model = GradientBoostingClassifier(
                n_estimators=request.params.n_estimators,
                max_depth=request.params.max_depth,
                learning_rate=0.1,
                random_state=request.params.random_state
            )
        elif request.algorithm == 'ensemble':
            # Create a voting classifier with multiple models
            estimators = [
                ('rf', RandomForestClassifier(
                    n_estimators=100, 
                    random_state=request.params.random_state
                )),
                ('lr', LogisticRegression(
                    C=1.0, 
                    max_iter=1000, 
                    random_state=request.params.random_state
                )),
                ('gb', GradientBoostingClassifier(
                    n_estimators=100, 
                    random_state=request.params.random_state
                ))
            ]
            model = VotingClassifier(estimators=estimators, voting='soft')
        else:
            raise HTTPException(
                status_code=status.HTTP_400_BAD_REQUEST,
                detail=f"Unsupported algorithm: {request.algorithm}"
            )

        # Create the full pipeline
        pipeline = Pipeline([
            ('preprocessor', preprocessor),
            ('classifier', model)
        ])

        # Optional: Feature selection
        if request.preprocessing.feature_selection and request.params.feature_selection:
            if request.params.feature_selection_method == "rfe":
                # Add Recursive Feature Elimination
                selector = RFE(
                    estimator=model,
                    n_features_to_select=int(len(feature_names) * (1 - request.params.feature_selection_threshold)),
                    step=1
                )
                pipeline = Pipeline([
                    ('preprocessor', preprocessor),
                    ('selector', selector),
                    ('classifier', model)
                ])
            elif request.params.feature_selection_method == "model_based":
                # Add feature selection based on feature importance
                selector = SelectFromModel(
                    estimator=model,
                    threshold="mean"
                )
                pipeline = Pipeline([
                    ('preprocessor', preprocessor),
                    ('selector', selector),
                    ('classifier', model)
                ])

        # Optional: Hyperparameter tuning
        if request.params.do_hyperparameter_tuning:
            logger.info("Starting hyperparameter tuning")

            # Define parameter grid based on algorithm
            param_grid = {}
            if request.algorithm == 'randomforest':
                param_grid = {
                    'classifier__n_estimators': [50, 100, 200],
                    'classifier__max_depth': [None, 10, 20, 30],
                    'classifier__min_samples_split': [2, 5, 10]
                }
            elif request.algorithm == 'logisticregression':
                param_grid = {
                    'classifier__C': [0.1, 1.0, 10.0],
                    'classifier__solver': ['liblinear', 'lbfgs']
                }
            elif request.algorithm == 'svm':
                param_grid = {
                    'classifier__C': [0.1, 1.0, 10.0],
                    'classifier__kernel': ['linear', 'rbf'],
                    'classifier__gamma': ['scale', 'auto']
                }
            elif request.algorithm == 'knn':
                param_grid = {
                    'classifier__n_neighbors': [3, 5, 7, 9],
                    'classifier__weights': ['uniform', 'distance']
                }

            # Skip if empty grid or ensemble
            if param_grid and request.algorithm != 'ensemble':
                cv = StratifiedKFold(n_splits=request.params.cv_folds, shuffle=True, random_state=request.params.random_state)
                grid_search = GridSearchCV(
                    pipeline, 
                    param_grid, 
                    cv=cv, 
                    scoring='accuracy', 
                    n_jobs=-1, 
                    verbose=1
                )
                grid_search.fit(X_train, y_train)

                # Update pipeline with best estimator
                pipeline = grid_search.best_estimator_
                logger.info(f"Best parameters: {grid_search.best_params_}")
            else:
                # Train the pipeline without hyperparameter tuning
                pipeline.fit(X_train, y_train)
        else:
            # Train the pipeline without hyperparameter tuning
            pipeline.fit(X_train, y_train)

        # Evaluate the model
        y_pred = pipeline.predict(X_test)
        y_pred_proba = pipeline.predict_proba(X_test)

        # Calculate metrics based on problem type (binary or multiclass)
        unique_classes = np.unique(y)
        is_binary = len(unique_classes) == 2

        metrics = {
            "accuracy": float(accuracy_score(y_test, y_pred)),
            "balanced_accuracy": float(balanced_accuracy_score(y_test, y_pred))
        }

        if is_binary:
            metrics.update({
                "precision": float(precision_score(y_test, y_pred, zero_division=0)),
                "recall": float(recall_score(y_test, y_pred, zero_division=0)),
                "f1": float(f1_score(y_test, y_pred, zero_division=0)),
                "roc_auc": float(roc_auc_score(y_test, y_pred_proba[:, 1])),
                "mcc": float(matthews_corrcoef(y_test, y_pred))
            })
        else:
            # For multiclass, use weighted averages
            metrics.update({
                "precision": float(precision_score(y_test, y_pred, average='weighted', zero_division=0)),
                "recall": float(recall_score(y_test, y_pred, average='weighted', zero_division=0)),
                "f1": float(f1_score(y_test, y_pred, average='weighted', zero_division=0)),
                "mcc": float(matthews_corrcoef(y_test, y_pred))
            })

            # ROC AUC for multiclass
            try:
                from sklearn.preprocessing import label_binarize
                from sklearn.metrics import roc_auc_score

                classes = np.unique(y)
                y_test_bin = label_binarize(y_test, classes=classes)

                if y_pred_proba.shape[1] > 2:
                    metrics["roc_auc"] = float(roc_auc_score(y_test_bin, y_pred_proba, multi_class='ovr'))
            except Exception as e:
                logger.warning(f"Could not calculate ROC AUC for multiclass: {str(e)}")

        # Get detailed classification report for logging
        report = classification_report(y_test, y_pred)
        logger.info(f"Classification Report:\n{report}")

        # Generate a unique model ID
        model_id = str(uuid.uuid4())

        # Create a model directory for this course if it doesn't exist
        course_models_dir = os.path.join(MODELS_DIR, f"course_{request.courseid}")
        os.makedirs(course_models_dir, exist_ok=True)

        # Extract feature importance
        feature_importance = get_feature_importances(pipeline[-1], feature_names)

        # Generate model report in background if requested
        report_path = None
        if request.create_report:
            background_tasks.add_task(
                create_model_report,
                model_data={
                    'pipeline': pipeline,
                    'feature_names': feature_names,
                    'algorithm': request.algorithm
                },
                X_test=X_test,
                y_test=y_test,
                model_id=model_id,
                courseid=request.courseid
            )
            report_path = f"/reports/course_{request.courseid}/{model_id}/report.html"

        # Save the model with all relevant metadata
        model_filename = f"{model_id}.joblib"
        model_path = os.path.join(course_models_dir, model_filename)

        model_data = {
            'pipeline': pipeline,
            'feature_names': feature_names,
            'numeric_cols': numeric_cols,
            'categorical_cols': categorical_cols,
            'algorithm': request.algorithm,
            'trained_at': datetime.now().isoformat(),
            'target_classes': list(pipeline.classes_),
            'metrics': metrics,
            'feature_importance': feature_importance,
            'training_params': request.params.dict(),
            'preprocessing_params': request.preprocessing.dict(),
            'report_path': report_path
        }

        joblib.dump(model_data, model_path)

        # Add to cache
        MODEL_CACHE[model_id] = model_data

        training_time = time.time() - start_time
        logger.info(f"Model {model_id} trained successfully in {training_time:.2f} seconds with accuracy {metrics['accuracy']}")

        return {
            "model_id": model_id,
            "model_path": model_path,
            "algorithm": request.algorithm,
            "metrics": metrics,
            "feature_importance": feature_importance,
            "report_path": report_path,
            "feature_names": feature_names,
            "target_classes": list(pipeline.classes_),
            "trained_at": datetime.now().isoformat(),
            "training_time_seconds": training_time
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
    start_time = time.time()
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

        # Get the pipeline and feature information
        pipeline = model_data['pipeline']
        feature_names = model_data['feature_names']
        target_classes = model_data['target_classes']

        # Create a DataFrame from the input features
        try:
            # Convert the features dictionary to a DataFrame
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
        prediction = pipeline.predict(input_df)[0]

        # Get probabilities
        probabilities = pipeline.predict_proba(input_df)[0].tolist()

        # Calculate confidence (highest probability)
        confidence = float(max(probabilities))

        # Generate explanation if requested and SHAP is available
        explanation = None
        if request.explanation and SHAP_AVAILABLE:
            try:
                # Create a SHAP explainer
                explainer = shap.Explainer(pipeline[-1], pipeline[0].transform(input_df))
                shap_values = explainer(pipeline[0].transform(input_df))

                # Convert SHAP values to a dictionary
                # For classification, take the predicted class's SHAP values
                pred_class_idx = list(target_classes).index(prediction)
                if hasattr(shap_values, 'values'):
                    if len(shap_values.values.shape) == 3:  # Multi-class
                        shap_values_dict = dict(zip(feature_names, shap_values.values[0, pred_class_idx, :]))
                    else:  # Binary
                        shap_values_dict = dict(zip(feature_names, shap_values.values[0]))
                else:
                    # Fallback if values attribute is not available
                    shap_values_dict = {}

                # Sort by absolute value for importance
                explanation = dict(sorted(
                    shap_values_dict.items(), 
                    key=lambda x: abs(x[1]), 
                    reverse=True
                ))
            except Exception as e:
                logger.warning(f"Error generating SHAP explanation: {str(e)}")

        processing_time = (time.time() - start_time) * 1000  # Convert to milliseconds

        logger.info(f"Prediction: {prediction}, Confidence: {confidence:.4f}, Processing time: {processing_time:.2f}ms")

        return {
            "results": {
                "prediction": prediction,
                "probabilities": probabilities,
                "confidence": confidence,
                "explanation": explanation
            },
            "model_id": request.model_id,
            "prediction_time": datetime.now().isoformat(),
            "processing_time_ms": processing_time
        }

    except Exception as e:
        logger.exception(f"Error making prediction: {str(e)}")
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=f"Error making prediction: {str(e)}"
        )

@app.get("/models/{course_id}", response_model=ModelListResponse, dependencies=[Depends(verify_api_key)])
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
                            "trained_at": model_data.get('trained_at', ''),
                            "metrics": model_data.get('metrics', {}),
                            "feature_count": len(model_data.get('feature_names', [])),
                            "target_classes": model_data.get('target_classes', [])
                        })
                    except Exception as e:
                        logger.warning(f"Error loading model {model_id}: {str(e)}")

        # Sort models by training date (newest first)
        models.sort(key=lambda x: x.get('trained_at', ''), reverse=True)

        return {
            "models": models,
            "count": len(models)
        }

    except Exception as e:
        logger.exception(f"Error listing models: {str(e)}")
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=f"Error listing models: {str(e)}"
        )

@app.get("/models/{course_id}/{model_id}", response_model=ModelDetailResponse, dependencies=[Depends(verify_api_key)])
async def get_model_details(course_id: int, model_id: str):
    """Get detailed information about a specific model"""
    logger.info(f"Getting details for model {model_id} in course {course_id}")

    try:
        model_path = os.path.join(MODELS_DIR, f"course_{course_id}", f"{model_id}.joblib")

        if not os.path.exists(model_path):
            raise HTTPException(
                status_code=status.HTTP_404_NOT_FOUND,
                detail=f"Model with ID {model_id} not found for course {course_id}"
            )

        model_data = joblib.load(model_path)

        return {
            "model_id": model_id,
            "algorithm": model_data.get('algorithm', 'unknown'),
            "trained_at": model_data.get('trained_at', ''),
            "metrics": model_data.get('metrics', {}),
            "feature_names": model_data.get('feature_names', []),
            "target_classes": model_data.get('target_classes', []),
            "feature_importance": model_data.get('feature_importance', {}),
            "training_params": model_data.get('training_params', {}),
            "preprocessing_params": model_data.get('preprocessing_params', {})
        }

    except HTTPException:
        raise
    except Exception as e:
        logger.exception(f"Error getting model details: {str(e)}")
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=f"Error getting model details: {str(e)}"
        )

@app.delete("/models/{course_id}/{model_id}", dependencies=[Depends(verify_api_key)])
async def delete_model(course_id: int, model_id: str):
    """Delete a model by its ID within a specific course"""
    logger.info(f"Delete request for model {model_id} in course {course_id}")

    try:
        # Remove from cache if present
        if model_id in MODEL_CACHE:
            del MODEL_CACHE[model_id]

        # Check if model file exists
        model_path = os.path.join(MODELS_DIR, f"course_{course_id}", f"{model_id}.joblib")
        if not os.path.exists(model_path):
            raise HTTPException(
                status_code=status.HTTP_404_NOT_FOUND,
                detail=f"Model with ID {model_id} not found for course {course_id}"
            )

        # Delete the model file
        os.remove(model_path)

        # Delete associated report if it exists
        report_dir = os.path.join(REPORTS_DIR, f"course_{course_id}", model_id)
        if os.path.exists(report_dir):
            import shutil
            shutil.rmtree(report_dir)

        logger.info(f"Model {model_id} deleted successfully")

        return {
            "status": "success", 
            "message": f"Model {model_id} deleted successfully"
        }

    except HTTPException:
        raise
    except Exception as e:
        logger.exception(f"Error deleting model: {str(e)}")
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=f"Error deleting model: {str(e)}"
        )

@app.get("/reports/{course_id}/{model_id}/{filename}", dependencies=[Depends(verify_api_key)])
async def get_report_file(course_id: int, model_id: str, filename: str):
    """Retrieve a specific report file for a model"""
    file_path = os.path.join(REPORTS_DIR, f"course_{course_id}", model_id, filename)

    if not os.path.exists(file_path):
        raise HTTPException(
            status_code=status.HTTP_404_NOT_FOUND,
            detail=f"Report file not found"
        )

    # Determine content type based on file extension
    content_type = "text/html"
    if filename.endswith(".png"):
        content_type = "image/png"
    elif filename.endswith(".jpg") or filename.endswith(".jpeg"):
        content_type = "image/jpeg"
    elif filename.endswith(".md"):
        content_type = "text/markdown"

    return FileResponse(
        path=file_path, 
        media_type=content_type,
        filename=filename
    )

# For testing purposes
if __name__ == "__main__":
    import uvicorn
    import platform

    host = os.getenv("HOST", "0.0.0.0")
    port = int(os.getenv("PORT", 8000))

    print(f"Starting Student Performance Prediction API on {host}:{port}")
    print(f"Python version: {platform.python_version()}")

    uvicorn.run(app, host=host, port=port)
