<?php
if (!defined('ABSPATH')) {
    exit;
}
spl_autoload_register(function ($className) {
    if (!defined('COINSNAP_SERVER_PATH')){
	define( 'COINSNAP_SERVER_PATH', 'stores' );  
    }
    $libName = 'Coinsnap';
    
    // Abort here if we do not try to load Coinsnap namespace.
    if (strpos($className, $libName) !== 0) {
        return;
    }

    // Convert namespace and class to file path.
    $filePath =  __DIR__ . str_replace([$libName, '\\'], ['', DIRECTORY_SEPARATOR], $className).'.php';
    if (file_exists($filePath)) {
        require_once($filePath);
        return;
    }
});
