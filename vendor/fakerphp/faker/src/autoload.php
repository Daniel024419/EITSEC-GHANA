<?php

/**
 * Simple autoloader that follow the PHP Standards Recommendation #0 (PSR-0)
 *
 * @see https://github.com/php-fig/fig-standards/blob/master/accepted/PSR-0.md for more informations.
 *
 * Code inspired from the SplClassLoader RFC
 * @see https://wiki.php.net/rfc/splclassloader#example_implementation
 */
spl_autoload_register(function ($className) {
    $className = ltrim($className, '\\');
    $filename = '';

    if ($lastNsPos = strripos($className, '\\')) {
        $namespace = substr($className, 0, $lastNsPos);
        $className = substr($className, $lastNsPos + 1);
        $filename = str_replace('\\', DIRECTORY_SEPARATOR, $namespace) . DIRECTORY_SEPARATOR;
    }
    $filename = __DIR__ . DIRECTORY_SEPARATOR . $filename . $className . '.php';

    if (file_exists($filename)) {
        require $filename;

        return true;
    }

    return false;
});
