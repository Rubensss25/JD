<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>404 - Page Not Found</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f8f9fa;
            margin: 0;
            padding: 40px 20px;
            text-align: center;
        }
        
        .container {
            max-width: 500px;
            margin: 0 auto;
        }
        
        .error-code {
            font-size: 72px;
            font-weight: 300;
            color: #6c757d;
            margin-bottom: 20px;
        }
        
        .error-title {
            font-size: 24px;
            color: #343a40;
            margin-bottom: 15px;
            font-weight: 400;
        }
        
        .error-description {
            font-size: 16px;
            color: #6c757d;
            margin-bottom: 30px;
            line-height: 1.5;
        }
        
        .actions {
            display: flex;
            gap: 15px;
            justify-content: center;
            flex-wrap: wrap;
        }
        
        .btn {
            padding: 10px 20px;
            text-decoration: none;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            font-size: 14px;
            color: #495057;
            background: white;
            transition: all 0.2s ease;
        }
        
        .btn:hover {
            background: #f8f9fa;
            border-color: #adb5bd;
        }
        
        .btn-primary {
            background: #007bff;
            color: white;
            border-color: #007bff;
        }
        
        .btn-primary:hover {
            background: #0056b3;
            border-color: #0056b3;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="error-code">404</div>
        <h1 class="error-title">Page Not Found</h1>
        <p class="error-description">
            The page you're looking for doesn't exist.
        </p>
        
        <div class="actions">
            <a href="/JD/index.php" class="btn btn-primary">Go Home</a>
        </div>
    </div>
</body>
</html>