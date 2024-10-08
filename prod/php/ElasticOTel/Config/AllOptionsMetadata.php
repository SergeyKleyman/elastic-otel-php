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

namespace Elastic\OTel\Config;

use Elastic\OTel\Util\StaticClassTrait;
use Elastic\OTel\Util\TimeUtil;

/**
 * Code in this file is part of implementation internals and thus it is not covered by the backward compatibility.
 *
 * @internal
 */
final class AllOptionsMetadata
{
    use StaticClassTrait;

    /**
     * @link https://github.com/elastic/apm/blob/e1ac6ecc841b525148cb293df9d852994d877773/specs/agents/sanitization.md#sanitize_field_names-configuration
     */
    private const SANITIZE_FIELD_NAMES_DEFAULT = 'password, passwd, pwd, secret, *key, *token*, *session*, *credit*, *card*, *auth*, set-cookie';

    /**
     * @var ?array<string, OptionMetadata<mixed>>
     */
    private static $vaLue = null;

    private static function buildDurationMetadataInMillisecondsWithMin(int $min, int $default): DurationOptionMetadata
    {
        return new DurationOptionMetadata(floatval($min), /* max */ null, DurationUnits::MILLISECONDS, floatval($default));
    }

    private static function buildDurationMetadataInMillisecondsNoMin(int $default): DurationOptionMetadata
    {
        return new DurationOptionMetadata(/* min */ null, /* max */ null, DurationUnits::MILLISECONDS, floatval($default));
    }

    private static function buildDurationMetadataInMilliseconds(int $default): DurationOptionMetadata
    {
        return self::buildDurationMetadataInMillisecondsWithMin(/* min */ 0, $default);
    }

    private static function buildDurationMetadataInSeconds(int $defaultInSeconds): DurationOptionMetadata
    {
        return new DurationOptionMetadata(/* min */ 0.0, /* max */ null, DurationUnits::SECONDS, floatval($defaultInSeconds * TimeUtil::NUMBER_OF_MILLISECONDS_IN_SECOND));
    }

    private static function buildPositiveOrZeroIntMetadata(int $default): IntOptionMetadata
    {
        return new IntOptionMetadata(/* min */ 0, /* max */ null, $default);
    }

    /**
     * @return array<string, OptionMetadata<mixed>> Option name to metadata
     */
    public static function get(): array
    {
        if (self::$vaLue !== null) {
            return self::$vaLue;
        }

        /** @var array<string, OptionMetadata<mixed>> $value */
        $value = [
            OptionNames::API_KEY                                    => new NullableStringOptionMetadata(),
            OptionNames::ASYNC_BACKEND_COMM                         => new BoolOptionMetadata(/* default */ true),
            OptionNames::BREAKDOWN_METRICS                          => new BoolOptionMetadata(/* default */ true),
            OptionNames::CAPTURE_ERRORS                             => new BoolOptionMetadata(/* default */ true),
            OptionNames::DEV_INTERNAL                               => new NullableWildcardListOptionMetadata(),
            OptionNames::DISABLE_INSTRUMENTATIONS                   => new NullableWildcardListOptionMetadata(),
            OptionNames::DISABLE_SEND                               => new BoolOptionMetadata(/* default */ false),
            OptionNames::ENABLED                                    => new BoolOptionMetadata(/* default */ true),
            OptionNames::ENVIRONMENT                                => new NullableStringOptionMetadata(),
            OptionNames::GLOBAL_LABELS                              => new NullableLabelsOptionMetadata(),
            OptionNames::HOSTNAME                                   => new NullableStringOptionMetadata(),
            OptionNames::LOG_LEVEL                                  => new NullableLogLevelOptionMetadata(),
            OptionNames::LOG_LEVEL_STDERR                           => new NullableLogLevelOptionMetadata(),
            OptionNames::LOG_LEVEL_SYSLOG                           => new NullableLogLevelOptionMetadata(),
            OptionNames::NON_KEYWORD_STRING_MAX_LENGTH              => self::buildPositiveOrZeroIntMetadata(/* default */ 10 * 1024),
            OptionNames::SANITIZE_FIELD_NAMES                       => new WildcardListOptionMetadata(WildcardListOptionParser::staticParse(self::SANITIZE_FIELD_NAMES_DEFAULT)),
            OptionNames::SECRET_TOKEN                               => new NullableStringOptionMetadata(),
            OptionNames::SERVER_TIMEOUT                             => self::buildDurationMetadataInSeconds(/* default */ 30),
            OptionNames::SERVICE_NAME                               => new NullableStringOptionMetadata(),
            OptionNames::SERVICE_NODE_NAME                          => new NullableStringOptionMetadata(),
            OptionNames::SERVICE_VERSION                            => new NullableStringOptionMetadata(),
            OptionNames::SPAN_COMPRESSION_ENABLED                   => new BoolOptionMetadata(/* default */ true),
            OptionNames::SPAN_COMPRESSION_EXACT_MATCH_MAX_DURATION  => self::buildDurationMetadataInMilliseconds(/* default */ 50),
            OptionNames::SPAN_COMPRESSION_SAME_KIND_MAX_DURATION    => self::buildDurationMetadataInMilliseconds(/* default */ 0),
            OptionNames::SPAN_STACK_TRACE_MIN_DURATION              => self::buildDurationMetadataInMillisecondsNoMin(/* default */ OptionDefaultValues::SPAN_STACK_TRACE_MIN_DURATION),
            OptionNames::STACK_TRACE_LIMIT                          => new IntOptionMetadata(/* min */ null, /* max */ null, /* default */ OptionDefaultValues::STACK_TRACE_LIMIT),
            OptionNames::TRANSACTION_IGNORE_URLS                    => new NullableWildcardListOptionMetadata(),
            OptionNames::TRANSACTION_MAX_SPANS                      => self::buildPositiveOrZeroIntMetadata(OptionDefaultValues::TRANSACTION_MAX_SPANS),
            OptionNames::TRANSACTION_SAMPLE_RATE                    => new FloatOptionMetadata(/* min */ 0.0, /* max */ 1.0, /* default */ 1.0),
            OptionNames::URL_GROUPS                                 => new NullableWildcardListOptionMetadata(),
            OptionNames::VERIFY_SERVER_CERT                         => new BoolOptionMetadata(/* default */ true),
        ];

        self::$vaLue = $value;
        return self::$vaLue;
    }
}