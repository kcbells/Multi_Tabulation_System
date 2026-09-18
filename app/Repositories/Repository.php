<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

abstract class Repository
{
    protected Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::instance();
    }
}
