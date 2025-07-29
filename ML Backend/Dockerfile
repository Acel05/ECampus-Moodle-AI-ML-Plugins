# Use Python as base image
FROM python:3.10-slim

# Set working directory
WORKDIR /app

# Copy requirements file
COPY requirements.txt .

# Install dependencies
RUN pip install --no-cache-dir -r requirements.txt

# Copy application code
COPY . .

# Create model directory with proper permissions
RUN mkdir -p models && chmod 777 models

# Expose port based on environment variable
EXPOSE 8000

# Start the application
CMD uvicorn ml_backend:app --host=0.0.0.0 --port=8000
