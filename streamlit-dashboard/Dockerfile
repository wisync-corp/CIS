FROM python:3.12-slim
WORKDIR /app
ENV PYTHONUNBUFFERED=1 HOME=/tmp
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt
COPY .streamlit ./.streamlit
COPY Dashboard.py .
USER 10001
CMD ["streamlit","run","Dashboard.py","--server.address=0.0.0.0","--server.port=8501","--server.baseUrlPath=dashboard","--server.headless=true","--browser.gatherUsageStats=false","--client.showErrorDetails=none","--client.toolbarMode=minimal"]
