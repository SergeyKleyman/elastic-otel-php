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

namespace Elastic\OTel\Log;

/**
 * Code in this file is part of implementation internals and thus it is not covered by the backward compatibility.
 *
 * @internal
 */
interface SinkInterface
{
    /**
     * @param int                    $statementLevel
     * @param string                 $message
     * @param array<string, mixed>[] $contextsStack
     * @param string                 $category
     * @param string                 $srcCodeFile
     * @param int                    $srcCodeLine
     * @param string                 $srcCodeFunc
     * @param ?bool                  $includeStacktrace
     * @param int                    $numberOfStackFramesToSkip
     *
     * @phpstan-param 0|positive-int $numberOfStackFramesToSkip
     */
    public function consume(
        int $statementLevel,
        string $message,
        array $contextsStack,
        string $category,
        string $srcCodeFile,
        int $srcCodeLine,
        string $srcCodeFunc,
        ?bool $includeStacktrace,
        int $numberOfStackFramesToSkip
    ): void;
}