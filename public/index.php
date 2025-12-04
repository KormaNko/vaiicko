<?php

if (php_sapi_name() !== 'cli') {
    // Only set when an origin header is present (browser request)
    if (isset($_SERVER['HTTP_ORIGIN'])) {
        // Allow your frontend origin (Vite)
        header('Access-Control-Allow-Origin: http://localhost:5173');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
        header('Access-Control-Allow-Credentials: true');
    }

    // Handle preflight immediately
    if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        // echo nothing and exit so no further processing occurs
        exit();
    }
}

// Require the class loader to enable automatic loading of classes
require __DIR__ . '/../Framework/ClassLoader.php';

use Framework\Core\App;

try {
    // Create an instance of the App class
    $app = new App();

    // Run the application
    $app->run();
} catch (Exception $e) {
    // Handle any exceptions that occur during the application run
    die('An error occurred: ' . $e->getMessage());
}
