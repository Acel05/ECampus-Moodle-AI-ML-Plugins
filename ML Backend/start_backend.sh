#!/bin/bash
echo "Starting Student Performance Predictor ML Backend..."

# Check if Python is installed
if ! command -v python3 &> /dev/null; then
    echo "Python 3 is not installed"
    echo "Please install Python 3.8 or higher"
    exit 1
fi

# Check if venv exists, create if not
if [ ! -d "venv" ]; then
    echo "Creating virtual environment..."
    python3 -m venv venv
    if [ $? -ne 0 ]; then
        echo "Failed to create virtual environment"
        exit 1
    fi
fi

# Activate virtual environment
echo "Activating virtual environment..."
source venv/bin/activate

# Install dependencies if requirements.txt exists
if [ -f "requirements.txt" ]; then
    echo "Installing dependencies..."
    pip install -r requirements.txt
    if [ $? -ne 0 ]; then
        echo "Failed to install dependencies"
        exit 1
    fi
fi

# Create models directory if it doesn't exist
if [ ! -d "models" ]; then
    echo "Creating models directory..."
    mkdir models
fi

# Start the server
echo "Starting the server..."
echo "API will be available at http://localhost:5000"
echo "Press CTRL+C to stop the server"

# Run the server
uvicorn ml_backend:app --host 0.0.0.0 --port 5000 --reload

# Deactivate virtual environment when done
deactivate
