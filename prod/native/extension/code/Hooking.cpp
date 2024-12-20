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

#include "Hooking.h"

#include "ModuleGlobals.h"

#include "PhpBridge.h"
#include "PhpErrorData.h"

#include <memory>
#include <string_view>
#include "InternalFunctionInstrumentation.h"
#include "PeriodicTaskExecutor.h"
#include "RequestScope.h"
#include "os/OsUtils.h"

#include <main/php_version.h>
#include <Zend/zend_API.h>
#include <Zend/zend_execute.h>
#include <Zend/zend_observer.h>

namespace elasticapm::php {

#if PHP_VERSION_ID < 80100
void elastic_observer_error_cb(int type, const char *error_filename, uint32_t error_lineno, zend_string *message) {
    std::string_view fileName = error_filename ? std::string_view{error_filename} : std::string_view{};
#else
void elastic_observer_error_cb(int type, zend_string *error_filename, uint32_t error_lineno, zend_string *message) {
    std::string_view fileName = error_filename ? std::string_view{ZSTR_VAL(error_filename), ZSTR_LEN(error_filename)} : std::string_view{};
#endif
    std::string_view msg = message && ZSTR_VAL(message) ? std::string_view{ZSTR_VAL(message), ZSTR_LEN(message)} : std::string_view{};

    ELOGF_DEBUG(ELASTICAPM_G(globals)->logger_, HOOKS, "elastic_observer_error_cb type: %d, fn: " PRsv ":%d, msg: " PRsv " ED: %p", type, PRsvArg(fileName), error_lineno, PRsvArg(msg), EG(current_execute_data));
    static bool errorHandling = false;
    if (errorHandling) {
        ELOGF_WARNING(ELASTICAPM_G(globals)->logger_, HOOKS, "elastic_observer_error_cb detected error handler loop, skipping error handler");
        return;
    }

    // we're looking if function (inside which error was thrown) is instrumented - if yes, w're skipping default error instrumentation and letting post hook to handler error.
    if (EG(current_execute_data)) {
        auto hash = getClassAndFunctionHashFromExecuteData(EG(current_execute_data));

        if (hash) {
            if (ELASTICAPM_G(globals)->logger_ && ELASTICAPM_G(globals)->logger_->doesMeetsLevelCondition(LogLevel::logLevel_debug)) {
                auto [cls, fun] = getClassAndFunctionName(EG(current_execute_data));
                ELOGF_DEBUG(ELASTICAPM_G(globals)->logger_, HOOKS, "elastic_observer_error_cb currentED: %p currentEXception: %p hash: 0x%X " PRsv "::" PRsv, EG(current_execute_data), EG(exception), hash, PRsvArg(cls), PRsvArg(fun));
            }

            auto callbacks = reinterpret_cast<InstrumentedFunctionHooksStorage_t *>(EAPM_GL(hooksStorage_).get())->find(hash);
            if (callbacks) {
                ELOGF_DEBUG(ELASTICAPM_G(globals)->logger_, HOOKS, "elastic_observer_error_cb type: %d, fn: " PRsv ":%d, msg: " PRsv ". Skipping default error instrumentation because function is instrumented and error will be passed to posthook", type, PRsvArg(fileName), error_lineno, PRsvArg(msg));
                return;
            }
        } else {
            ELOGF_WARNING(ELASTICAPM_G(globals)->logger_, HOOKS, "elastic_observer_error_cb currentED: %p currentEXception: %p func null, msg: " PRsv, EG(current_execute_data), EG(exception), PRsvArg(msg));
        }
    }

    errorHandling = true;
    ELASTICAPM_G(globals)->requestScope_->handleError(type, fileName, error_lineno, msg);
    errorHandling = false;
}

void Hooking::replaceHooks() {
    zend_observer_error_register(elastic_observer_error_cb);
}

} // namespace elasticapm::php
