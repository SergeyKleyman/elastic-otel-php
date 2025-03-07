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

namespace ElasticOTelTests\UnitTests\UtilTests\ConfigTests;

use Elastic\OTel\Util\TextUtil;
use ElasticOTelTests\Util\ArrayUtilForTests;
use ElasticOTelTests\Util\AssertEx;
use ElasticOTelTests\Util\Config\ConfigSnapshotForProd;
use ElasticOTelTests\Util\Config\ConfigSnapshotForTests;
use ElasticOTelTests\Util\Config\OptionForProdName;
use ElasticOTelTests\Util\Config\OptionForTestsName;
use ElasticOTelTests\Util\Config\OptionMetadata as OptionMetadata;
use ElasticOTelTests\Util\Config\OptionsForProdMetadata;
use ElasticOTelTests\Util\Config\OptionsForTestsMetadata;
use ElasticOTelTests\Util\DebugContext;
use ElasticOTelTests\Util\TestCaseBase;
use UnitEnum;

class OptionNamesAndSnapshotPropertiesTest extends TestCaseBase
{
    public function testOptionNamesAndMetadataMapMatch(): void
    {
        /**
         * @param UnitEnum[] $optNameCases
         * @param array<string, OptionMetadata<mixed>> $optMetas
         */
        $impl = function (array $optNameCases, array $optMetas): void {
            DebugContext::getCurrentScope(/* out */ $dbgCtx);
            $optNamesFromCases = array_map(fn($optNameCase) => $optNameCase->name, $optNameCases); // @phpstan-ignore property.nonObject
            sort(/* ref */ $optNamesFromCases);
            $optNamesFromMetas = array_keys($optMetas);
            sort(/* ref */ $optNamesFromMetas);
            $dbgCtx->add(compact('optNamesFromCases', 'optNamesFromMetas'));
            AssertEx::arraysHaveTheSameContent($optNamesFromCases, $optNamesFromMetas);
        };

        $impl(OptionForProdName::cases(), OptionsForProdMetadata::get());
        $impl(OptionForTestsName::cases(), OptionsForTestsMetadata::get());
    }

    /**
     * @return iterable<array{UnitEnum[], string[]}>
     */
    public static function dataProviderForTestOptionNamesAndSnapshotPropertiesMatch(): iterable
    {
        return [
            [OptionForProdName::cases(), ConfigSnapshotForProd::propertyNamesForOptions()],
            [OptionForTestsName::cases(), ConfigSnapshotForTests::propertyNamesForOptions()],
        ];
    }

    /**
     * @dataProvider dataProviderForTestOptionNamesAndSnapshotPropertiesMatch
     *
     * @param UnitEnum[] $optNameCases
     * @param string[] $propertyNamesForOptions
     */
    public function testOptionNamesAndSnapshotPropertiesMatch(array $optNameCases, array $propertyNamesForOptions): void
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);

        $remainingSnapPropNames = $propertyNamesForOptions;
        foreach ($optNameCases as $optNameCase) {
            $dbgCtx->add(compact('optNameCase', 'remainingSnapPropNames'));
            self::assertTrue(ArrayUtilForTests::removeFirstByValue(/* in,out */ $remainingSnapPropNames, TextUtil::snakeToCamelCase($optNameCase->name)));
        }

        self::assertEmpty($remainingSnapPropNames);
    }

    public function testOptionNameToEnvVarName(): void
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);

        /** @var class-string<OptionForProdName|OptionForTestsName> $optNameEnumClass */
        foreach ([OptionForProdName::class, OptionForTestsName::class] as $optNameEnumClass) {
            $dbgCtx->add(compact('optNameEnumClass'));
            foreach ($optNameEnumClass::cases() as $optName) {
                $dbgCtx->add(compact('optName'));
                $envVarName = $optName->toEnvVarName();
                $dbgCtx->add(compact('envVarName'));
                self::assertTrue(TextUtil::isSuffixOf(strtoupper($optName->name), $envVarName));
            }
        }
    }

    public function testLogRelated(): void
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);

        foreach (OptionForProdName::getAllLogLevelRelated() as $optName) {
            $dbgCtx->add(compact('optName'));
            self::assertTrue($optName->isLogLevelRelated());
        }

        foreach (OptionForProdName::cases() as $optName) {
            $dbgCtx->add(compact('optName'));
            if (TextUtil::isPrefixOf('log_level_', $optName->name)) {
                self::assertTrue($optName->isLogLevelRelated());
            }
        }
    }

    public function testProdOptionNameToEnvVar(): void
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);

        foreach (OptionForProdName::cases() as $optName) {
            $dbgCtx->add(compact('optName'));
            $envVarNamePrefix = $optName->getEnvVarNamePrefix();
            $dbgCtx->add(compact('envVarNamePrefix'));
            $envVarName = $optName->toEnvVarName();
            $dbgCtx->add(compact('envVarName'));
            self::assertStringStartsWith($envVarNamePrefix, $envVarName);
        }
    }
}
