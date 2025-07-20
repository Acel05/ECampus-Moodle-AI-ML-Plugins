@echo off
echo ========================================================
echo  Student Performance Predictor Backend Starter
echo ========================================================
echo.
echo Starting backend service for Moodle...
echo.
echo Make sure you have installed the required Python packages:
echo pip install fastapi uvicorn scikit-learn pandas joblib python-dotenv
echo.

cd /d "%~dp0"

echo Checking for Python installation...
python --version 2>NUL
if %ERRORLEVEL% NEQ 0 (
    echo ERROR: Python not found! Please install Python 3.7 or higher.
    echo You can download Python from https://www.python.org/downloads/
    echo.
    echo Press any key to exit...
    pause > nul
    exit /b 1
)

echo Checking for required packages...
python -c "import fastapi, uvicorn, sklearn, pandas, joblib, dotenv" 2>NUL
if %ERRORLEVEL% NEQ 0 (
    echo WARNING: One or more required packages not found.
    echo Would you like to install the required packages now? (Y/N)
    set /p INSTALL=
    if /i "%INSTALL%"=="Y" (
        echo Installing required packages...
        pip install fastapi uvicorn scikit-learn pandas joblib python-dotenv
    ) else (
        echo Package installation skipped. The backend may not work correctly.
    )
)

echo.
echo Starting the backend server on http://localhost:5000
echo Press Ctrl+C to stop the server
echo.

REM Fix path issues for XAMPP/Windows
set PYTHONPATH=%~dp0;%PYTHONPATH%

python -m uvicorn ml_backend:app --host 0.0.0.0 --port 5000

echo.
if %ERRORLEVEL% NEQ 0 (
    echo ERROR: The backend server failed to start.
    echo Please check the error messages above for more information.
) else (
    echo Backend server stopped.
)

echo.
echo Press any key to exit...
pause > nul