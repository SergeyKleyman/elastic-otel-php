<?php

/*
 * Copyright Elasticsearch B.V. and/or licensed to Elasticsearch B.V. under one
 * or more contributor license agreements. See the NOTICE file distributed with
 * this work for additional information regarding copyright
 * ownership. Elasticsearch B.V. licenses this file to you under
 * the Apache License, Version 2.0 (the "License"); you may
 * not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *  http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing,
 * software distributed under the License is distributed on an
 * "AS IS" BASIS, WITHOUT WARRANTIES OR CONDITIONS OF ANY
 * KIND, either express or implied.  See the License for the
 * specific language governing permissions and limitations
 * under the License.
 */

/** @noinspection PhpIllegalPsrClassPathInspection */

declare(strict_types=1);

namespace Elastic\OTel\Log;

use Elastic\OTel\Util\EnumUtilTrait;

/**
 * Code in this file is part of implementation internals and thus it is not covered by the backward compatibility.
 *
 * @internal
 */
enum LogLevel: int
{
    use EnumUtilTrait;

    case off      = 0;
    case critical = 1;
    case error    = 2;
    case warning  = 3;
    case info     = 4;
    case debug    = 5;
    case trace    = 6;

    public static function fromPsrLevel(string $level): ?self
    {
        return match ($level) {
            \Psr\Log\LogLevel::EMERGENCY, \Psr\Log\LogLevel::ALERT, \Psr\Log\LogLevel::CRITICAL => self::critical,
            \Psr\Log\LogLevel::ERROR => self::error,
            \Psr\Log\LogLevel::WARNING => self::warning,
            \Psr\Log\LogLevel::NOTICE, \Psr\Log\LogLevel::INFO => self::info,
            \Psr\Log\LogLevel::DEBUG => self::debug,
            default => null,
        };
    }
}
