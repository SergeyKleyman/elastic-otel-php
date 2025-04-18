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

/**
 * @noinspection PhpInternalEntityUsedInspection
 */

declare(strict_types=1);

namespace OpenTelemetry\Instrumentation;

use Closure;
use Elastic\OTel\InstrumentationBridge;
use Throwable;

/**
 * Code in this file is part of implementation internals, and thus it is not covered by the backward compatibility.
 *
 * Called by OTel instrumentations
 *
 * @internal
 *
 * @phpstan-param ?string $class The hooked function's class. Null for a global/built-in function.
 * @phpstan-param string $function The hooked function's name.
 * @phpstan-param ?(Closure(?object $thisObj, array<mixed> $params, string $class, string $function, ?string $filename, ?int $lineno): (void|array<mixed>)) $pre
 *                                 return value is modified parameters
 * @phpstan-param ?(Closure(?object $thisObj, array<mixed> $params, mixed $returnValue, ?Throwable $throwable): mixed) $post
 *                                 return value is modified return value
 *
 * @return bool Whether the observer was successfully added
 *
 * @see https://github.com/open-telemetry/opentelemetry-php-instrumentation
 */
function hook(
    ?string $class,
    string $function,
    ?Closure $pre = null,
    ?Closure $post = null,
): bool {
    return InstrumentationBridge::singletonInstance()->hook($class, $function, $pre, $post);
}
