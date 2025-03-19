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

use Elastic\OTel\Util\TextUtil;
use ElasticOTelTests\ComponentTests\Util\AppCodeHostParams;
use ElasticOTelTests\ComponentTests\Util\AppCodeRequestParams;
use ElasticOTelTests\ComponentTests\Util\AppCodeTarget;
use ElasticOTelTests\ComponentTests\Util\ComponentTestCaseBase;
use ElasticOTelTests\ComponentTests\Util\DbAutoInstrumentationUtilForTests;
use ElasticOTelTests\ComponentTests\Util\MySqli\ApiFacade;
use ElasticOTelTests\ComponentTests\Util\MySqli\MySqliDbSpanDataExpectationsBuilder;
use ElasticOTelTests\ComponentTests\Util\MySqli\MySqliResultWrapped;
use ElasticOTelTests\ComponentTests\Util\MySqli\MySqliWrapped;
use ElasticOTelTests\ComponentTests\Util\SpanExpectations;
use ElasticOTelTests\ComponentTests\Util\WaitForEventCounts;
use ElasticOTelTests\Util\AmbientContextForTests;
use ElasticOTelTests\Util\Config\OptionForProdName;
use ElasticOTelTests\Util\DataProviderForTestBuilder;
use ElasticOTelTests\Util\DebugContext;
use ElasticOTelTests\Util\Log\LoggableToString;
use ElasticOTelTests\Util\MixedMap;
use OpenTelemetry\Contrib\Instrumentation\MySqli\MySqliInstrumentation;

/**
 * @group smoke
 * @group requires_external_services
 * @group requires_mysql_external_service
 */
final class MySqliAutoInstrumentationTest extends ComponentTestCaseBase
{
    private const INSTRUMENTATION_NAME = 'mysqli';
    private const IS_AUTO_INSTRUMENTATION_ENABLED_KEY = 'is_auto_instrumentation_enabled';

    private const IS_OOP_API_KEY = 'IS_OOP_API';

    public const CONNECT_DB_NAME_KEY = 'CONNECT_DB_NAME';
    public const WORK_DB_NAME_KEY = 'WORK_DB_NAME';

    private const QUERY_KIND_KEY = 'QUERY_KIND';
    private const QUERY_KIND_QUERY = 'query';
    private const QUERY_KIND_REAL_QUERY = 'real_query';
    private const QUERY_KIND_MULTI_QUERY = 'multi_query';
    private const QUERY_KIND_ALL_VALUES = [self::QUERY_KIND_QUERY, self::QUERY_KIND_REAL_QUERY, self::QUERY_KIND_MULTI_QUERY];

    private const MESSAGES
        = [
            'Just testing...'    => 1,
            'More testing...'    => 22,
            'SQLite3 is cool...' => 333,
        ];

    private const DROP_DATABASE_IF_EXISTS_SQL_PREFIX
        = /** @lang text */
        'DROP DATABASE IF EXISTS ';

    private const CREATE_DATABASE_SQL_PREFIX
        = /** @lang text */
        'CREATE DATABASE ';

    private const CREATE_DATABASE_IF_NOT_EXISTS_SQL_PREFIX
        = /** @lang text */
        'CREATE DATABASE IF NOT EXISTS ';

    private const CREATE_TABLE_SQL
        = /** @lang text */
        'CREATE TABLE messages (
            id INT AUTO_INCREMENT,
            text TEXT,
            time INTEGER,
            PRIMARY KEY(id)
        )';

    private const INSERT_SQL
        = /** @lang text */
        'INSERT INTO messages (text, time) VALUES (?, ?)';

    private const SELECT_SQL
        = /** @lang text */
        'SELECT * FROM messages';

    public function testPrerequisitesSatisfied(): void
    {
        $extensionName = 'mysqli';
        self::assertTrue(extension_loaded($extensionName), 'Required extension ' . $extensionName . ' is not loaded');

        self::assertNotNull(AmbientContextForTests::testConfig()->mysqlHost);
        self::assertNotNull(AmbientContextForTests::testConfig()->mysqlPort);
        self::assertNotNull(AmbientContextForTests::testConfig()->mysqlUser);
        self::assertNotNull(AmbientContextForTests::testConfig()->mysqlPassword);
        self::assertNotNull(AmbientContextForTests::testConfig()->mysqlDb);

        $mySQLiApiFacade = new ApiFacade(/* isOOPApi */ true);
        $mySQLi = $mySQLiApiFacade->connect(
            AmbientContextForTests::testConfig()->mysqlHost,
            AmbientContextForTests::testConfig()->mysqlPort,
            AmbientContextForTests::testConfig()->mysqlUser,
            AmbientContextForTests::testConfig()->mysqlPassword,
            AmbientContextForTests::testConfig()->mysqlDb
        );
        self::assertNotNull($mySQLi);
        // Method mysqli::ping() is deprecated since PHP 8.4
        if (PHP_VERSION_ID < 80400) {
            self::assertTrue($mySQLi->ping());
        }
    }

    /**
     * @param MySqliWrapped $mySQLi
     * @param string[]      $queries
     * @param string        $kind
     *
     * @return void
     */
    private static function runQueriesUsingKind(MySqliWrapped $mySQLi, array $queries, string $kind): void
    {
        switch ($kind) {
            case self::QUERY_KIND_MULTI_QUERY:
                $multiQuery = '';
                foreach ($queries as $query) {
                    if (!TextUtil::isEmptyString($multiQuery)) {
                        $multiQuery .= ';';
                    }
                    $multiQuery .= $query;
                }
                self::assertTrue($mySQLi->multiQuery($multiQuery));
                while (true) {
                    $result = $mySQLi->storeResult();
                    if ($result === false) {
                        self::assertEmpty($mySQLi->error());
                    } else {
                        $result->close();
                    }
                    if (!$mySQLi->moreResults()) {
                        break;
                    }
                    self::assertTrue($mySQLi->nextResult());
                }
                break;
            case self::QUERY_KIND_REAL_QUERY:
                foreach ($queries as $query) {
                    self::assertTrue($mySQLi->realQuery($query));
                }
                break;
            case self::QUERY_KIND_QUERY:
                foreach ($queries as $query) {
                    self::assertTrue($mySQLi->query($query));
                }
                break;
            default:
                self::fail();
        }
    }

    /**
     * @param MySqliDbSpanDataExpectationsBuilder $expectationsBuilder
     * @param string[]                            $queries
     * @param string                              $kind
     * @param SpanExpectations[]                 &$expectedSpans
     */
    private static function addExpectationsForQueriesUsingKind(
        MySqliDbSpanDataExpectationsBuilder $expectationsBuilder,
        array $queries,
        string $kind,
        array &$expectedSpans
    ): void {
        switch ($kind) {
            case self::QUERY_KIND_MULTI_QUERY:
                $multiQuery = '';
                foreach ($queries as $query) {
                    if (!TextUtil::isEmptyString($multiQuery)) {
                        $multiQuery .= ';';
                    }
                    $multiQuery .= $query;
                }
                $expectedSpans[] = $expectationsBuilder->setNameUsingDbStatement($multiQuery)->build();
                break;
            case self::QUERY_KIND_QUERY:
            case self::QUERY_KIND_REAL_QUERY:
                foreach ($queries as $query) {
                    $expectedSpans[] = $expectationsBuilder->setNameUsingDbStatement($query)->build();
                }
                break;
            default:
                self::fail();
        }
    }

    /**
     * @return string[]
     */
    private static function allDbNames(): array
    {
        $defaultDbName = AmbientContextForTests::testConfig()->mysqlDb;
        self::assertNotNull($defaultDbName);
        return [$defaultDbName, $defaultDbName . '_ALT'];
    }

    /**
     * @return string[]
     */
    private static function queriesToResetDbState(): array
    {
        $queries = [];
        foreach (self::allDbNames() as $dbName) {
            $queries[] = self::DROP_DATABASE_IF_EXISTS_SQL_PREFIX . $dbName;
        }
        $queries[] = self::CREATE_DATABASE_SQL_PREFIX . AmbientContextForTests::testConfig()->mysqlDb;
        return $queries;
    }

    private static function resetDbState(MySqliWrapped $mySQLi, string $queryKind): void
    {
        $queries = self::queriesToResetDbState();
        self::runQueriesUsingKind($mySQLi, $queries, $queryKind);
    }

    /**
     * @param MySqliDbSpanDataExpectationsBuilder $expectationsBuilder
     * @param string                              $queryKind
     * @param SpanExpectations[]             &$expectedSpans
     */
    private static function addExpectationsForResetDbState(
        MySqliDbSpanDataExpectationsBuilder $expectationsBuilder,
        string $queryKind,
        /* out */ array &$expectedSpans
    ): void {
        $queries = self::queriesToResetDbState();
        self::addExpectationsForQueriesUsingKind($expectationsBuilder, $queries, $queryKind, /* out */ $expectedSpans);
    }

    /**
     * @return iterable<array{MixedMap}>
     */
    public static function dataProviderForTestAutoInstrumentation(): iterable
    {
        $disableInstrumentationsVariants = [
            ''       => true,
            'mysqli' => false,
            'db'     => false,
        ];

        /** @var array<?string> $connectDbNameVariants */
        $connectDbNameVariants = [AmbientContextForTests::testConfig()->mysqlDb];
        if (ApiFacade::canDbNameBeNull()) {
            $connectDbNameVariants[] = null;
        }

        $result = (new DataProviderForTestBuilder())
            ->addGeneratorOnlyFirstValueCombinable(AutoInstrumentationUtilForTests::disableInstrumentationsDataProviderGenerator($disableInstrumentationsVariants))
            ->addBoolKeyedDimensionAllValuesCombinable(self::IS_OOP_API_KEY)
            ->addCartesianProductOnlyFirstValueCombinable([self::CONNECT_DB_NAME_KEY => $connectDbNameVariants, self::WORK_DB_NAME_KEY    => self::allDbNames()])
            ->addKeyedDimensionOnlyFirstValueCombinable(self::QUERY_KIND_KEY, self::QUERY_KIND_ALL_VALUES)
            ->addGeneratorOnlyFirstValueCombinable(DbAutoInstrumentationUtilForTests::wrapTxRelatedArgsDataProviderGenerator())
            ->build();

        return self::adaptToSmoke(DataProviderForTestBuilder::convertEachDataSetToMixedMap($result));
    }

    /**
     * @param MixedMap  $args
     * @param ?bool    &$isOOPApi
     * @param ?string  &$connectDbName
     * @param ?string  &$workDbName
     * @param ?string  &$queryKind
     * @param ?bool    &$wrapInTx
     * @param ?bool    &$rollback
     *
     * @param-out bool    $isOOPApi
     * @param-out ?string $connectDbName
     * @param-out string  $workDbName
     * @param-out string  $queryKind
     * @param-out bool    $wrapInTx
     * @param-out bool    $rollback
     */
    public static function extractSharedArgs(
        MixedMap $args,
        ?bool &$isOOPApi /* <- out */,
        ?string &$connectDbName /* <- out */,
        ?string &$workDbName /* <- out */,
        ?string &$queryKind /* <- out */,
        ?bool &$wrapInTx /* <- out */,
        ?bool &$rollback /* <- out */
    ): void {
        $isOOPApi = $args->getBool(self::IS_OOP_API_KEY);
        $connectDbName = $args->getNullableString(self::CONNECT_DB_NAME_KEY);
        $workDbName = $args->getString(self::WORK_DB_NAME_KEY);
        $queryKind = $args->getString(self::QUERY_KIND_KEY);
        $wrapInTx = $args->getBool(DbAutoInstrumentationUtilForTests::WRAP_IN_TX_KEY);
        $rollback = $args->getBool(DbAutoInstrumentationUtilForTests::ROLLBACK_KEY);
    }

    public static function appCodeForTestAutoInstrumentation(MixedMap $appCodeArgs): void
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);

        self::assertTrue(extension_loaded('curl'));

        $enableCurlInstrumentationForClient = $appCodeArgs->getBool(AutoInstrumentationUtilForTests::DISABLE_INSTRUMENTATIONS_KEY);
        if ($enableCurlInstrumentationForClient) {
            self::assertTrue(class_exists(MySqliInstrumentation::class, autoload: false));
            self::assertSame(MySqliInstrumentation::NAME, self::INSTRUMENTATION_NAME); // @phpstan-ignore staticMethod.alreadyNarrowedType
        }

        self::extractSharedArgs(
                      $appCodeArgs,
            /* out */ $isOOPApi,
            /* out */ $connectDbName,
            /* out */ $workDbName,
            /* out */ $queryKind,
            /* out */ $wrapInTx,
            /* out */ $rollback
        );
        $host = $appCodeArgs->getString(DbAutoInstrumentationUtilForTests::HOST_KEY);
        $port = $appCodeArgs->getInt(DbAutoInstrumentationUtilForTests::PORT_KEY);
        $user = $appCodeArgs->getString(DbAutoInstrumentationUtilForTests::USER_KEY);
        $password = $appCodeArgs->getString(DbAutoInstrumentationUtilForTests::PASSWORD_KEY);

        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        $mySQLiApiFacade = new ApiFacade($isOOPApi);
        $mySQLi = $mySQLiApiFacade->connect($host, $port, $user, $password, $connectDbName);
        self::assertNotNull($mySQLi);

        // Method mysqli::ping() is deprecated since PHP 8.4
        if (PHP_VERSION_ID < 80400) {
            self::assertTrue($mySQLi->ping());
        }

        if ($connectDbName !== $workDbName) {
            self::assertTrue($mySQLi->query(self::CREATE_DATABASE_IF_NOT_EXISTS_SQL_PREFIX . $workDbName));
            self::assertTrue($mySQLi->selectDb($workDbName));
        }

        self::assertTrue($mySQLi->query(self::CREATE_TABLE_SQL));

        if ($wrapInTx) {
            self::assertTrue($mySQLi->beginTransaction());
        }

        self::assertNotFalse($stmt = $mySQLi->prepare(self::INSERT_SQL));
        foreach (self::MESSAGES as $msgText => $msgTime) {
            self::assertTrue($stmt->bindParam('si', $msgText, $msgTime));
            self::assertTrue($stmt->execute());
        }
        self::assertTrue($stmt->close());

        self::assertInstanceOf(MySqliResultWrapped::class, $queryResult = $mySQLi->query(self::SELECT_SQL));
        self::assertSame(count(self::MESSAGES), $queryResult->numRows());
        $rowCount = 0;
        while (true) {
            $row = $queryResult->fetchAssoc();
            if (!is_array($row)) {
                self::assertNull($row);
                self::assertSame(count(self::MESSAGES), $rowCount);
                break;
            }
            ++$rowCount;
            $dbgCtx = LoggableToString::convert(['$row' => $row, '$queryResult' => $queryResult]);
            $msgText = $row['text'];
            self::assertIsString($msgText);
            self::assertArrayHasKey($msgText, self::MESSAGES, $dbgCtx);
            self::assertEquals(self::MESSAGES[$msgText], $row['time'], $dbgCtx);
        }
        $queryResult->close();

        if ($wrapInTx) {
            self::assertTrue($rollback ? $mySQLi->rollback() : $mySQLi->commit());
        }

        self::resetDbState($mySQLi, $queryKind);
        self::assertTrue($mySQLi->close());
    }

    /**
     * @dataProvider dataProviderForTestAutoInstrumentation
     */
    public function testAutoInstrumentation(MixedMap $testArgs): void
    {
        self::runAndEscalateLogLevelOnFailure(
            self::buildDbgDescForTestWithArgs(__CLASS__, __FUNCTION__, $testArgs),
            function () use ($testArgs): void {
                $this->implTestAutoInstrumentation($testArgs);
            }
        );
    }

    private function implTestAutoInstrumentation(MixedMap $testArgs): void
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);

        self::assertNotEmpty(self::MESSAGES);

        $logger = self::getLoggerStatic(__NAMESPACE__, __CLASS__, __FILE__);
        ($loggerProxy = $logger->ifTraceLevelEnabled(__LINE__, __FUNCTION__))
        && $loggerProxy->log('Entered', ['$testArgs' => $testArgs]);

        $disableInstrumentationsOptVal = $testArgs->getString(AutoInstrumentationUtilForTests::DISABLE_INSTRUMENTATIONS_KEY);
        $isInstrumentationEnabled = $testArgs->getBool(AutoInstrumentationUtilForTests::IS_INSTRUMENTATION_ENABLED_KEY);

        self::extractSharedArgs(
                      $testArgs,
            /* out */ $isOOPApi,
            /* out */ $connectDbName,
            /* out */ $workDbName,
            /* out */ $queryKind,
            /* out */ $wrapInTx,
            /* out */ $rollback
        );

        $testCaseHandle = $this->getTestCaseHandle();

        $appCodeArgs = $testArgs->clone();

        $appCodeArgs[DbAutoInstrumentationUtilForTests::HOST_KEY] = AmbientContextForTests::testConfig()->mysqlHost;
        $appCodeArgs[DbAutoInstrumentationUtilForTests::PORT_KEY] = AmbientContextForTests::testConfig()->mysqlPort;
        $appCodeArgs[DbAutoInstrumentationUtilForTests::USER_KEY] = AmbientContextForTests::testConfig()->mysqlUser;
        $appCodeArgs[DbAutoInstrumentationUtilForTests::PASSWORD_KEY]
            = AmbientContextForTests::testConfig()->mysqlPassword;

        $expectationsBuilder = new MySqliDbSpanDataExpectationsBuilder($isOOPApi);
        /** @var SpanExpectations[] $expectedSpans */
        $expectedSpans = [];
        if ($isInstrumentationEnabled) {
            $expectedSpans[] = $expectationsBuilder->setNameUsingApiNames('mysqli', '__construct', 'mysqli_connect')->build();

            // Method mysqli::ping() is deprecated since PHP 8.4
            if (PHP_VERSION_ID < 80400) {
                $expectedSpans[] = $expectationsBuilder->setNameUsingApiNames('mysqli', 'ping')->build();
            }

            if ($connectDbName !== $workDbName) {
                $expectedSpans[] = $expectationsBuilder->setNameUsingDbStatement(self::CREATE_DATABASE_IF_NOT_EXISTS_SQL_PREFIX . $workDbName)->build();
                $expectationsBuilder = new MySqliDbSpanDataExpectationsBuilder($isOOPApi);
                $expectedSpans[] = $expectationsBuilder->setNameUsingApiNames('mysqli', 'select_db')->build();
            }

            $expectedSpans[] = $expectationsBuilder->setNameUsingDbStatement(self::CREATE_TABLE_SQL)->build();

            if ($wrapInTx) {
                $expectedSpans[] = $expectationsBuilder->setNameUsingApiNames('mysqli', 'begin_transaction')->build();
            }

            foreach (self::MESSAGES as $ignored) {
                $expectedSpans[] = $expectationsBuilder->setNameUsingDbStatement(self::INSERT_SQL)->build();
            }

            $expectedSpans[] = $expectationsBuilder->setNameUsingDbStatement(self::SELECT_SQL)->build();

            if ($wrapInTx) {
                $expectedSpans[] = $expectationsBuilder->setNameUsingApiNames('mysqli', $rollback ? 'rollback' : 'commit')->build();
            }

            self::addExpectationsForResetDbState($expectationsBuilder, $queryKind, /* out */ $expectedSpans);
        }

        $appCodeHost = $testCaseHandle->ensureMainAppCodeHost(
            function (AppCodeHostParams $appCodeParams) use ($disableInstrumentationsOptVal): void {
                if (!empty($disableInstrumentationsOptVal)) {
                    $appCodeParams->setProdOption(OptionForProdName::disabled_instrumentations, $disableInstrumentationsOptVal);
                }
                self::disableTimingDependentFeatures($appCodeParams);
            }
        );
        $appCodeHost->execAppCode(
            AppCodeTarget::asRouted([__CLASS__, 'appCodeForTestAutoInstrumentation']),
            function (AppCodeRequestParams $appCodeRequestParams) use ($appCodeArgs): void {
                $appCodeRequestParams->setAppCodeArgs($appCodeArgs);
            }
        );

        $exportedData = $testCaseHandle->waitForEnoughExportedData(WaitForEventCounts::spans(count($expectedSpans)));
        $dbgCtx->add(compact('exportedData'));

        // SpanSequenceValidator::updateExpectationsEndTime($expectedSpans);
        // SpanSequenceValidator::assertSequenceAsExpected($expectedSpans, array_values($dataFromAgent->idToSpan));
        self::dummyAssert();
    }
}
