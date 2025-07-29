@echo off
echo Starting Student Performance Predictor ML Backend...

:: Check if Python is installed
python --version > nul 2>&1
if %ERRORLEVEL% NEQ 0 (
    echo Python is not installed or not in PATH
    echo Please install Python 3.8 or higher
    pause
    exit /b 1
)

:: Check if venv exists, create if not
if not exist venv (
    echo Creating virtual environment...
    python -m venv venv
    if %ERRORLEVEL% NEQ 0 (
        echo Failed to create virtual environment
        pause
        exit /b 1
    )
)

:: Activate virtual environment
echo Activating virtual environment...
call venv\Scripts\activate

:: Install dependencies if requirements.txt exists
if exist requirements.txt (
    echo Installing dependencies...
    pip install -r requirements.txt
    if %ERRORLEVEL% NEQ 0 (
        echo Failed to install dependencies
        pause
        exit /b 1
    )
)

:: Create models directory if it doesn't exist
if not exist models (
    echo Creating models directory...
    mkdir models
)

:: Start the server
echo Starting the server...
echo API will be available at http://localhost:5000
echo Press CTRL+C to stop the server

:: Run the server
uvicorn ml_backend:app --host 0.0.0.0 --port 5000 --reload

:: Deactivate virtual environment when done
call deactivate
