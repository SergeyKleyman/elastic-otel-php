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

namespace ElasticOTelTests\UnitTests\Util;

use Elastic\OTel\Log\LogLevel;
use ElasticOTelTests\Util\Log\SinkBase;
use Override;

class MockLogPreformattedSink extends SinkBase
{
    /** @var MockLogPreformattedSinkStatement[] */
    public array $consumed = [];

    #[Override]
    protected function consumePreformatted(
        LogLevel $statementLevel,
        string $category,
        string $srcCodeFile,
        int $srcCodeLine,
        string $srcCodeFunc,
        string $messageWithContext
    ): void {
        $this->consumed[] = new MockLogPreformattedSinkStatement(
            $statementLevel,
            $category,
            $srcCodeFile,
            $srcCodeLine,
            $srcCodeFunc,
            $messageWithContext
        );
    }
}
