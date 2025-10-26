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

namespace ElasticOTelTools\Build;

use PHPUnit\Framework\Assert;

final class ChecksumsForFiles
{
    /**
     * @param string[] $argv
     */
    public static function cmdLineGenerate(array $argv): void
    {
        Assert::assertCount(2, $argv);
        self::generateForFilesInDirectory($argv[1]);
    }

    /**
     * @param string[] $argv
     */
    public static function cmdLineVerify(array $argv): void
    {
        Assert::assertCount(2, $argv);
        self::verifyForFilesInDirectory($argv[1]);
    }

    private static function generateForFilesInDirectory(string $fullPathToDir): void
    {
        Assert::assertDirectoryExists($fullPathToDir);

    }

    private static function verifyForFilesInDirectory(string $fullPathToDir): void
    {
        Assert::assertDirectoryExists($fullPathToDir);

    }

    private static function buildFilesList(string $fullPathToDir): void
    {
        
    }
}
