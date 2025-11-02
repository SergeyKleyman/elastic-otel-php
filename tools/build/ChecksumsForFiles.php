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

use ElasticOTelTests\Util\AmbientContextForTests;
use ElasticOTelTests\Util\AssertEx;
use ElasticOTelTests\Util\ClassNameUtil;
use ElasticOTelTests\Util\DebugContext;
use ElasticOTelTests\Util\FileUtil;
use ElasticOTelTests\Util\JsonUtil;
use ElasticOTelTests\Util\Log\LogCategoryForTests;
use ElasticOTelTests\Util\Log\LoggableToString;
use ElasticOTelTests\Util\Log\Logger;
use ElasticOTelTests\Util\Log\SinkForTests as LogSinkForTests;
use PHPUnit\Framework\Assert;
use Throwable;

final class ChecksumsForFiles
{
    private const JSON_FILE_NAME = 'checksums_for_generated_files.json';
    private const JSON_CRC32_FILE_NAME = 'checksums_for_generated_files.json.crc32';
    private const DOT_GIT_ATTRIBUTES_FILE_NAME = '.gitattributes';
    public const FAILURE_EXIT_CODE = 1;

    /**
     * @param string[] $argv
     */
    public static function cmdLineGenerate(array $argv): void
    {
        self::runCmdLineImpl(
            function () use ($argv) {
                Assert::assertCount(2, $argv);
                self::generateForFilesInDirectory($argv[1]);
            }
        );
    }

    /**
     * @param string[] $argv
     */
    public static function cmdLineVerify(array $argv): void
    {
        self::runCmdLineImpl(
            function () use ($argv) {
                Assert::assertCount(2, $argv);
                self::verifyForFilesInDirectory($argv[1]);
            }
        );
    }

    /**
     * @param callable(): void $impl
     */
    private static function runCmdLineImpl(callable $impl): void
    {
        AmbientContextForTests::init(ClassNameUtil::fqToShort(__CLASS__));

        try {
            $impl();
        } catch (Throwable $throwable) {
            LogSinkForTests::writeLineToStdErr('Caught throwable: ' . LoggableToString::convert($throwable));
            exit(self::FAILURE_EXIT_CODE);
        }
    }

    private static function buildLogger(): Logger
    {
        return AmbientContextForTests::loggerFactory()->loggerForClass(LogCategoryForTests::TOOLS, __NAMESPACE__, __CLASS__, __FILE__);
    }

    private static function buildCheckSumsJsonEncoded(string $rootDirFullPath): string
    {
        Assert::assertDirectoryExists($rootDirFullPath);

        $relativeFilePathToMD5 = [];
        $relativeFilePathToFull = self::buildFilesList($rootDirFullPath);
        foreach ($relativeFilePathToFull as $relativeFilePath => $fullFilePath) {
            Assert::assertNotFalse($md5Hash = md5_file($fullFilePath));
            $relativeFilePathToMD5[$relativeFilePath] = $md5Hash;
        }

        return JsonUtil::encode($relativeFilePathToMD5, /* prettyPrint: */ true);
    }

    private static function generateForFilesInDirectory(string $rootDirFullPath): void
    {
        $jsonEncoded = self::buildCheckSumsJsonEncoded($rootDirFullPath);
        FileUtil::putContents(FileUtil::listToPath([$rootDirFullPath, self::JSON_FILE_NAME]), $jsonEncoded);
        FileUtil::putContents(FileUtil::listToPath([$rootDirFullPath, self::JSON_CRC32_FILE_NAME]), strval(crc32($jsonEncoded)));

        self::generateDotGitAttributesFile($rootDirFullPath);
    }

    private static function verifyForFilesInDirectory(string $rootDirFullPath): void
    {
        Assert::assertDirectoryExists($rootDirFullPath);

        $crc32ReadFromFile = AssertEx::stringIsInt(FileUtil::getContents(FileUtil::listToPath([$rootDirFullPath, self::JSON_CRC32_FILE_NAME])));
        $jsonEncodedReadFromFile = FileUtil::getContents(FileUtil::listToPath([$rootDirFullPath, self::JSON_FILE_NAME]));
        $crc32FromJsonEncoded = crc32($jsonEncodedReadFromFile);
        Assert::assertSame($crc32ReadFromFile, $crc32FromJsonEncoded);

        $jsonEncodedForCurrentFiles = self::buildCheckSumsJsonEncoded($rootDirFullPath);
        Assert::assertSame($jsonEncodedReadFromFile, $jsonEncodedForCurrentFiles);

        self::verifyDotGitAttributesFile($rootDirFullPath);
    }

    public static function adaptPath(string $fullPath): string
    {
        return FileUtil::adaptUnixDirectorySeparators(FileUtil::normalizePath($fullPath));
    }

    private static function shouldGenerateChecksumForFile(string $fileRelativePath): bool
    {
        static $filesToExclude = [self::DOT_GIT_ATTRIBUTES_FILE_NAME, self::JSON_FILE_NAME, self::JSON_CRC32_FILE_NAME];
        return !in_array($fileRelativePath, $filesToExclude, /* strict: */ true);
    }

    /**
     * @return array<string, string>
     */
    private static function buildFilesList(string $rootDirFullPath): array
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);

        $loggerProxyDebug = self::buildLogger()->ifDebugLevelEnabledNoLine(__FUNCTION__);

        $result = [];
        $rootDirAdaptedPath = AssertEx::isNonEmptyString(self::adaptPath($rootDirFullPath));
        $loggerProxyDebug && $loggerProxyDebug->includeStackTrace()->log(__LINE__, '', compact('rootDirAdaptedPath'));
        $dbgCtx->add(compact('rootDirAdaptedPath'));

        $dbgCtx->pushSubScope();
        foreach (FileUtil::iterateOverFilesInDirectoryRecursively($rootDirFullPath) as $fileInfo) {
            $dbgCtx->resetTopSubScope(compact('fileInfo'));
            if (!$fileInfo->isFile()) {
                continue;
            }
            $loggerProxyDebug && $loggerProxyDebug->log(__LINE__, '', ['fileInfo->getRealPath' => $fileInfo->getRealPath()]);
            $fileRelativePath = FileUtil::relativePath(AssertEx::isNonEmptyString(self::adaptPath($fileInfo->getRealPath())), $rootDirAdaptedPath);
            $loggerProxyDebug && $loggerProxyDebug->log(__LINE__, '', compact('fileRelativePath'));
            if (!self::shouldGenerateChecksumForFile($fileRelativePath)) {
                continue;
            }
            Assert::assertArrayNotHasKey($fileRelativePath, $result);
            $result[$fileRelativePath] = $fileInfo->getRealPath();
        }
        $dbgCtx->popSubScope();

        ksort(/* ref */ $result, SORT_STRING);
        return $result;
    }

    private static function buildDotGitAttributesFileContents(): string
    {
        $result = '';
        foreach ([self::JSON_FILE_NAME, self::JSON_CRC32_FILE_NAME] as $fileName) {
            $result .= $fileName . ' binary' . "\n";
        }
        return $result;
    }

    private static function generateDotGitAttributesFile(string $rootDirFullPath): void
    {
        FileUtil::putContents(FileUtil::listToPath([$rootDirFullPath, self::DOT_GIT_ATTRIBUTES_FILE_NAME]), self::buildDotGitAttributesFileContents());
    }

    private static function verifyDotGitAttributesFile(string $rootDirFullPath): void
    {
        $expectedContents = self::buildDotGitAttributesFileContents();
        $dotGitAttributesFile = FileUtil::listToPath([$rootDirFullPath, self::DOT_GIT_ATTRIBUTES_FILE_NAME]);
        Assert::assertFileExists($dotGitAttributesFile);
        $actualContents = FileUtil::getContents($dotGitAttributesFile);
        Assert::assertSame($expectedContents, $actualContents);
    }
}
