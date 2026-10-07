<?php
declare(strict_types=1);

namespace ServerHealth\Check;

use ServerHealth\Result;

interface CheckInterface
{
    /** @return Result[] */
    public function run(): array;
}
