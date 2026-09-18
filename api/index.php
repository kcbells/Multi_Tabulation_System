<?php
/**
 * JSON API front controller.  Usage: api/index.php?r=events.get&id=3
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

// Uploads on a slow signal and OCR on large photos can take a long time: no time limit.
@set_time_limit(0);

(new App\Core\Router(require APP_ROOT . '/app/routes.php'))->dispatch(new App\Core\Request());
