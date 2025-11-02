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

declare(strict_types=1);

namespace ElasticOTelTests\ComponentTests;

use Elastic\OTel\Util\ArrayUtil;
use Elastic\OTel\Util\TextUtil;
use ElasticOTelTests\ComponentTests\Util\ComponentTestCaseBase;
use ElasticOTelTests\ComponentTests\Util\PollingCheck;
use ElasticOTelTests\Util\AssertEx;
use ElasticOTelTests\Util\ClassNameUtil;
use ElasticOTelTests\Util\DataProviderForTestBuilder;
use ElasticOTelTests\Util\DebugContext;
use ElasticOTelTests\Util\FileUtil;
use ElasticOTelTests\Util\JsonUtil;
use ElasticOTelTests\Util\Log\LoggableToJsonEncodable;
use ElasticOTelTests\Util\MixedMap;
use ElasticOTelTests\Util\OsUtil;
use ElasticOTelTests\Util\RandomUtil;
use ElasticOTelTests\Util\RangeUtil;
use ElasticOTelTests\Util\RepoRootDir;
use ElasticOTelTools\Build\ChecksumsForFiles;
use PHPUnit\Framework\Assert;

/**
 * @group does_not_require_external_services
 */
final class ChecksumsForFilesTest extends ComponentTestCaseBase
{
    private const DEPTH_KEY = 'depth';
    private const WIDTH_KEY = 'width';

    /**
     * @param non-negative-int $width
     * @param non-negative-int $currentDepth
     * @param non-negative-int $depth
     * @param array<non-negative-int, list<string>> $directories
     * @param array<non-negative-int, list<string>> $createdFiles
     */
    private static function createDummyFileSystemEntries(string $dirFullPath, int $width, int $depthIndex, int $depth, string $nameSuffix, array &$directories, array &$files): void
    {
        ArrayUtil::getValueOrSetIfKeyDoesNotExist($depthIndex, $directories, []);
        $directories[$depthIndex][] = $dirFullPath;
        ArrayUtil::getValueOrSetIfKeyDoesNotExist($depthIndex, $files, []);
        $filesForDepthIndex =& $files[$depthIndex];

        foreach (RangeUtil::generateUpTo($width) as $widthIndex) {
            $fileFullPath = FileUtil::listToPath([$dirFullPath, 'file_' . $widthIndex . '_' . $depthIndex . $nameSuffix]);
            FileUtil::putContents($fileFullPath, $widthIndex === 0 ? '' : ('Contents of ' . $fileFullPath));
            $filesForDepthIndex[] = $dirFullPath;

            $subDirFullPath = FileUtil::listToPath([$dirFullPath, 'dir_' . $widthIndex . '_' . $depthIndex . $nameSuffix]);
            Assert::assertTrue(mkdir($subDirFullPath, recursive: true));
            if ($depthIndex !== $depth) {
                self::createDummyFileSystemEntries($subDirFullPath, $width, $depthIndex + 1, $depth, $nameSuffix, $directories, $files);
            }
        }
    }

    private static function logThrowable(string $outputLine): void
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);

        $expectedPrefix = 'Caught throwable: {';

        if (!TextUtil::isPrefixOf($expectedPrefix, $outputLine)) {
            return;
        }

        $jsonEncodedThrowable = substr($outputLine, strlen($expectedPrefix) - 1);
        $dbgCtx->add(compact('jsonEncodedThrowable'));
        $throwableAsPropArray = AssertEx::isArray(JsonUtil::decode($jsonEncodedThrowable, /* asAssocArray: */ true));
        if (ArrayUtil::getValueIfKeyExists(LoggableToJsonEncodable::DEBUG_CONTEXT_KEY, $throwableAsPropArray, /* out */ $debugContextPropValue)) {
            $message = ArrayUtil::getValueIfKeyExistsElse(LoggableToJsonEncodable::MESSAGE_KEY, $throwableAsPropArray, /* fallbackValue: */ null);
            ($loggerProxy = self::getLoggerStatic(__NAMESPACE__, __CLASS__, __FILE__)->ifWarningLevelEnabled(__LINE__, __FUNCTION__))
            && $loggerProxy->log('Found mention of a caught throwable', compact('message', 'debugContextPropValue'));
        }
    }

    private static function execCommand(string $command, bool $shouldExitWithSuccessCode = true): int
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);
        $output = [];
        self::assertNotFalse(exec($command . ' 2>&1', /* ref */ $output, /* ref */ $exitCode));
        $dbgCtx->add(compact('exitCode', 'output'));
        if ($exitCode !== 0) {
            foreach ($output as $line) {
                self::logThrowable($line);
            }
        }
        if ($shouldExitWithSuccessCode) {
            self::assertSame(0, $exitCode);
        } else {
            self::assertNotEquals(0, $exitCode);
        }
        return $exitCode;
    }

    /**
     * @param list<string> $argv
     */
    private static function execScriptInToolsBuild(string $scriptName, array $argv, int $expectedExitCode): void
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);
        $scriptFullPath = RepoRootDir::adaptRelativeUnixStylePath('tools/build/' . $scriptName);
        $argsAsOneString = '';
        foreach ($argv as $arg) {
            $argsAsOneString .= "\"$arg\"";
        }
        $exitCode = self::execCommand("php $scriptFullPath $argsAsOneString", shouldExitWithSuccessCode: $expectedExitCode === 0);
        self::assertSame($expectedExitCode, $exitCode);
    }

    /**
     * @return iterable<string, array{MixedMap}>
     */
    public static function dataProviderForTestGenerateVerify(): iterable
    {
        return self::adaptDataProviderForTestBuilderToSmokeToDescToMixedMap(
            (new DataProviderForTestBuilder())
                ->addKeyedDimensionAllValuesCombinable(self::DEPTH_KEY, [0, 1, 2])
                ->addKeyedDimensionAllValuesCombinable(self::WIDTH_KEY, [0, 1, 2, 5])
        );
    }

    private function ensureEmptyTempDirectory(string $dirFullPath): void
    {
        if (file_exists($dirFullPath)) {
            self::assertStringStartsWith(sys_get_temp_dir() . '/', $dirFullPath);
            self::assertDirectoryExists($dirFullPath);
            self::execCommand("rm -rf \"$dirFullPath\"");
        }
        $pollingCheckResult = (new PollingCheck(
            "directory `$dirFullPath' is not visible anymore after being deleted",
            30 * 1000 * 1000, /* <- 30 seconds - maxWaitTimeInMicroseconds */
        ))->run(
            function () use ($dirFullPath): bool {
                return !file_exists($dirFullPath);
            },
        );
        self::assertTrue($pollingCheckResult);

        self::assertTrue(mkdir($dirFullPath, recursive: true));
        self::assertDirectoryExists($dirFullPath);
    }

    /**
     * @dataProvider dataProviderForTestGenerateVerify
     */
    public function testGenerateVerify(MixedMap $testArgs): void
    {
        self::assertFalse(OsUtil::isWindows());

        DebugContext::getCurrentScope(/* out */ $dbgCtx);
        $logger = self::getLoggerStatic(__NAMESPACE__, __CLASS__, __FILE__);
        $loggerProxyDebug = $logger->ifDebugLevelEnabledNoLine(__FUNCTION__);

        $depth = AssertEx::isNonNegativeInt($testArgs->getInt(self::DEPTH_KEY));
        $width = AssertEx::isNonNegativeInt($testArgs->getInt(self::WIDTH_KEY));

        $rootDirFullPath = FileUtil::listToPath([sys_get_temp_dir(), ClassNameUtil::fqToShort(__CLASS__)]);
        $loggerProxyDebug && $loggerProxyDebug->log(__LINE__, '', compact('rootDirFullPath'));
        self::ensureEmptyTempDirectory($rootDirFullPath);
        $stageDirFullPath = FileUtil::listToPath([$rootDirFullPath, 'stage']);
        self::assertTrue(mkdir($stageDirFullPath));
        $backupDirFullPath = FileUtil::listToPath([$rootDirFullPath, 'backup']);
        self::assertTrue(mkdir($backupDirFullPath));

        $createdDirectories = [];
        $createdFiles = [];
        self::createDummyFileSystemEntries($stageDirFullPath, $width, $depth, /* nameSuffix: */ '', /* out */ $createdDirectories, /* ref */ $createdFiles);
        FileUtil::copyDirectoryContents($stageDirFullPath, $backupDirFullPath);

        self::execScriptInToolsBuild('generateChecksumsForFiles.php', [$stageDirFullPath], expectedExitCode: 0);
        $verifyChecksumsForFilesInStageDir = function (bool $expectedToSucceed) use ($stageDirFullPath): void {
            self::execScriptInToolsBuild('verifyChecksumsForFiles.php', [$stageDirFullPath], expectedExitCode: $expectedToSucceed? 0 : ChecksumsForFiles::FAILURE_EXIT_CODE);
        };
        $verifyChecksumsForFilesInStageDir(expectedToSucceed: true);

        /**
         * @param list<string> $filesUnderStageDir
         */
        $restoreFilesFromBackup = function (array $filesUnderStageDir) use ($stageDirFullPath, $backupDirFullPath): void {
            foreach ($filesUnderStageDir as $fileUnderStageDir) {
                $fileUnderBackupDir = FileUtil::mapRelativePartOfPath($fileUnderStageDir, $stageDirFullPath, $backupDirFullPath);
                Assert::assertTrue(copy($fileUnderBackupDir, $fileUnderStageDir));
            }
        };

        // Test changes to files: change file contents, rename, delete
        foreach (RangeUtil::generateUpTo(max(3, count($createdFiles))) as $fileCount) {
            $selectedFiles = RandomUtil::arrayRandValues($createdFiles, $fileCount);

            // Test file contents being changed
            foreach ($selectedFiles as $fileFullPath) {
                FileUtil::putContents($fileFullPath, 'Changed contents of ' . $fileFullPath);
            }
            $verifyChecksumsForFilesInStageDir(expectedToSucceed: false);
            $restoreFilesFromBackup($selectedFiles);
            $verifyChecksumsForFilesInStageDir(expectedToSucceed: true);

            // Test file renamed
            $selectedFilesRenamed = [];
            foreach ($selectedFiles as $fileFullPath) {
                $fileRenamedFullPath = $fileFullPath . '_renamed';
                Assert::assertTrue(rename($fileFullPath, $fileRenamedFullPath));
                $selectedFilesRenamed[] = $fileRenamedFullPath;
            }
            $verifyChecksumsForFilesInStageDir(expectedToSucceed: false);
            foreach ($selectedFilesRenamed as $fileRenamedFullPath) {
                Assert::assertTrue(unlink($fileRenamedFullPath));
            }
            $restoreFilesFromBackup($selectedFiles);
            $verifyChecksumsForFilesInStageDir(expectedToSucceed: true);

            // Test file deleted
            foreach ($selectedFiles as $fileFullPath) {
                Assert::assertTrue(unlink($fileFullPath));
            }
            $verifyChecksumsForFilesInStageDir(expectedToSucceed: false);
            $restoreFilesFromBackup($selectedFiles);
            $verifyChecksumsForFilesInStageDir(expectedToSucceed: true);
        }

        // Test adding file(s)
        foreach (RangeUtil::generateUpTo(3) as $fileToAddCount) {
            foreach (RangeUtil::generateUpTo($depth) as $) {

            }
        }
    }
}
