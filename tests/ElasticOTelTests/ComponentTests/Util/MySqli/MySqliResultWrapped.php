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

namespace ElasticOTelTests\ComponentTests\Util\MySqli;

use ElasticOTelTests\Util\Log\LoggableInterface;
use ElasticOTelTests\Util\Log\LoggableTrait;
use mysqli_result;

/**
 * Code in this file is part of implementation internals and thus it is not covered by the backward compatibility.
 *
 * @internal
 */
final class MySqliResultWrapped implements LoggableInterface
{
    use LoggableTrait;

    public function __construct(
        private readonly mysqli_result $wrappedObj,
        private readonly bool $isOOPApi
    ) {
    }

    public function numRows(): int|string
    {
        return $this->isOOPApi
            ? $this->wrappedObj->num_rows
            : mysqli_num_rows($this->wrappedObj);
    }

    /**
     * According to the docs https://www.php.net/manual/en/mysqli-result.fetch-assoc.php
     * return type is array|null|false
     * Returns
     *      - an associative array representing the fetched row,
     *          where each key in the array represents the name of one of the result set's columns,
     *      - null if there are no more rows in the result set
     *      - false on failure
     *
     * @return array<mixed>|null|false
     */
    public function fetchAssoc(): array|null|false
    {
        return $this->isOOPApi
            ? $this->wrappedObj->fetch_assoc()
            : mysqli_fetch_assoc($this->wrappedObj);
    }

    public function close(): void
    {
        $this->isOOPApi
            ? $this->wrappedObj->close()
            : mysqli_free_result($this->wrappedObj);
    }
}
