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

/** @noinspection PhpInternalEntityUsedInspection */

declare(strict_types=1);

use ElasticOTelTests\Util\RepoRootDir;
use ElasticOTelTests\Util\ExceptionUtil;

// Ensure that composer has installed all dependencies
if (!file_exists(dirname(__DIR__) . '/composer.lock')) {
    die("Dependencies must be installed using composer" . PHP_EOL);
}

// Disable deprecation notices starting from PHP 8.4
// Deprecated: funcAbc(): Implicitly marking parameter $xyz as nullable is deprecated, the explicit nullable type must be used instead
error_reporting(PHP_VERSION_ID < 80400 ? E_ALL : (E_ALL & ~E_DEPRECATED));

require __DIR__ . '/../vendor/autoload.php';
// Substitutes should be loaded IMMEDIATELY AFTER vendor
require __DIR__ . '/substitutes/load.php';

ExceptionUtil::runCatchLogRethrow(
    function (): void {
        RepoRootDir::setFullPath(__DIR__ . '/..');

        require __DIR__ . '/polyfills/load.php';
        require __DIR__ . '/elastic_otel_extension_stubs/load.php';
        require __DIR__ . '/dummyFuncForTestsWithoutNamespace.php';
        require __DIR__ . '/ElasticOTelTests/dummyFuncForTestsWithNamespace.php';
    }
);

/*
Dummy comment to verify PHP source code max allowed line length (which is 200).
PHP source code max allowed line length is configured in <repo root>/phpcs.xml

1--------10--------20--------30--------40--------50--------60--------70--------80--------90--------100-------110-------120-------130-------140-------150-------160-------170-------180-------190------->
|--------|---------|---------|---------|---------|---------|---------|---------|---------|---------|---------|---------|---------|---------|---------|---------|---------|---------|---------|---------|
*/
