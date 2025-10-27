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
use ElasticOTelTests\Util\RangeUtil;
use ElasticOTelTests\Util\RepoRootDir;
use PHPUnit\Framework\Assert;

/**
 * @group does_not_require_external_services
 */
final class ChecksumsForFilesTest extends ComponentTestCaseBase
{
    private const DEPTH_KEY = 'depth';
    private const WIDTH_KEY = 'width';

    /**
     * @param positive-int $width
     * @param non-negative-int $remainingDepth
     */
    private static function createDummyFileSystemEntries(string $dirFullPath, int $width, int $remainingDepth): void
    {
        foreach (RangeUtil::generateUpTo($width) as $widthIndex) {
            $fileName = 'file_' . $widthIndex . '_' . $remainingDepth;
            FileUtil::putContents(FileUtil::listToPath([$dirFullPath, $fileName]), $widthIndex === 0 ? '' : ('Contents of ' . $fileName));

            $subDirFullPath = FileUtil::listToPath([$dirFullPath, 'dir_' . $widthIndex . '_' . $remainingDepth]);
            Assert::assertTrue(mkdir($subDirFullPath));
            if ($remainingDepth !== 0) {
                self::createDummyFileSystemEntries($subDirFullPath, $width, $remainingDepth - 1);
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

    private static function execCommand(string $command): void
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
        self::assertSame(0, $exitCode);
    }

    /**
     * @param list<string> $argv
     */
    private static function execScriptInToolsBuild(string $scriptName, array $argv): void
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);
        $scriptFullPath = RepoRootDir::adaptRelativeUnixStylePath('tools/build/' . $scriptName);
        $argsAsOneString = '';
        foreach ($argv as $arg) {
            $argsAsOneString .= "\"$arg\"";
        }
        self::execCommand("php $scriptFullPath $argsAsOneString");
    }

    /**
     * @return iterable<string, array{MixedMap}>
     */
    public static function dataProviderForTestGenerateVerify(): iterable
    {
        return self::adaptDataProviderForTestBuilderToSmokeToDescToMixedMap(
            (new DataProviderForTestBuilder())
                ->addKeyedDimensionAllValuesCombinable(self::DEPTH_KEY, [0, 1, 2])
                ->addKeyedDimensionAllValuesCombinable(self::WIDTH_KEY, [1, 2, 10])
        );
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
        $width = AssertEx::isPositiveInt($testArgs->getInt(self::WIDTH_KEY));

        $rootDirFullPath = FileUtil::listToPath([sys_get_temp_dir(), ClassNameUtil::fqToShort(__CLASS__)]);
        $loggerProxyDebug && $loggerProxyDebug->log(__LINE__, '', compact('rootDirFullPath'));
        if (file_exists($rootDirFullPath)) {
            self::assertStringStartsWith(sys_get_temp_dir() . '/', $rootDirFullPath);
            self::assertDirectoryExists($rootDirFullPath);
            self::execCommand("rm -rf \"$rootDirFullPath\"");
        }
        $pollingCheckResult = (new PollingCheck(
            "directory `$rootDirFullPath' is not visible anymore after being deleted",
            30 * 1000 * 1000 /* <- 30 seconds - maxWaitTimeInMicroseconds */
        ))->run(
            function () use ($rootDirFullPath): bool {
                return !file_exists($rootDirFullPath);
            }
        );
        self::assertTrue($pollingCheckResult);

        self::assertTrue(mkdir($rootDirFullPath, recursive: true));
        self::assertDirectoryExists($rootDirFullPath);

        self::createDummyFileSystemEntries($rootDirFullPath, $width, $depth);

        self::execScriptInToolsBuild('generateChecksumsForFiles.php', [$rootDirFullPath]);
        self::execScriptInToolsBuild('verifyChecksumsForFiles.php', [$rootDirFullPath]);
    }
}
