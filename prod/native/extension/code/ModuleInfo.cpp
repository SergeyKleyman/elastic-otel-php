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


#include "ConfigurationManager.h"
#include "ConfigurationStorage.h"
#include "ModuleGlobals.h"

#include <php.h>
#include <ext/standard/info.h>


// BEGIN_EXTERN_C()
// PHPAPI zend_string *php_info_html_esc(const char *string);
// PHPAPI void php_print_info_htmlhead(void);
// PHPAPI void php_print_info(int flag);
// PHPAPI void php_print_style(void);
// PHPAPI void php_info_print_style(void);
// PHPAPI void php_info_print_table_colspan_header(int num_cols, const char *header);
// PHPAPI void php_info_print_table_header(int num_cols, ...);
// PHPAPI void php_info_print_table_row(int num_cols, ...);
// PHPAPI void php_info_print_table_row_ex(int num_cols, const char *, ...);
// PHPAPI void php_info_print_table_start(void);
// PHPAPI void php_info_print_table_end(void);
// PHPAPI void php_info_print_box_start(int bg);
// PHPAPI void php_info_print_box_end(void);
// PHPAPI void php_info_print_hr(void);
// PHPAPI void php_info_print_module(zend_module_entry *module);
// PHPAPI zend_string *php_get_uname(char mode);

// void register_phpinfo_constants(INIT_FUNC_ARGS);
// END_EXTERN_C()

// #endif /* INFO_H */

#define ELASTIC_PRODUCT_NAME "Elastic Distribution for OpenTelemetry PHP"

extern elasticapm::php::ConfigurationManager configManager;

void printPhpInfo(zend_module_entry *zend_module) {


    php_info_print_table_start();
    php_info_print_table_header( 1, ELASTIC_PRODUCT_NAME);
    php_info_print_table_end();

    php_info_print_table_colspan_header(2, "Effective configuration");
    php_info_print_table_start();
    php_info_print_table_header(2, "Configuration option", "Value");

    auto const &options = configManager.getOptionMetadata();
    for (auto const &option : options) {
        auto value = elasticapm::php::ConfigurationManager::accessOptionStringValueByMetadata(option.second, EAPM_GL(config_)->get());
        php_info_print_table_row(2, option.first.c_str(), option.second.secret ? "***" : value.c_str());
    }
    php_info_print_table_end();

    php_info_print_table_colspan_header(2, "INI configuration");
    display_ini_entries(zend_module);
}