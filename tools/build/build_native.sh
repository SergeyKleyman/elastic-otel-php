#!/bin/bash

SKIP_CONFIGURE=false

show_help() {
    echo "Usage: $0 --build_architecture <architecture> [--ncpu <num_cpus>] [--conan_user_home <cache_path>] [--skip_configure]"
    echo
    echo "Arguments:"
    echo "  --build_architecture     Required. Build architecture (e.g., 'linux-x86-64')."
    echo "  --ncpu                   Optional. Number of CPUs to use for building. Default is one less than the installed CPUs."
    echo "  --conan_user_home        Optional. Path to local user cache for Conan."
    echo "  --skip_configure         Optional. Skip the configuration step."
    echo "  --interactive            Optional. Run container in interactive mode."
    echo
    echo "Example:"
    echo "  $0 --build_architecture linux-x86-64 --ncpu 4 --conan_user_home ~/ --skip_configure"
}

parse_args() {
    while [[ "$#" -gt 0 ]]; do
        case $1 in
            --build_architecture)
                BUILD_ARCHITECTURE="$2"
                shift
                ;;
            --ncpu)
                NCPU=" -j$2 "
                shift
                ;;
            --conan_user_home)
                REPLACE_CONAN_USER_HOME="$2"
                shift
                ;;
            --skip_configure)
                SKIP_CONFIGURE=true
                ;;
            --interactive)
                INTERACTIVE=" -i "
                ;;
            --help)
                show_help
                exit 0
                ;;
            *)
                echo "Unknown parameter passed: $1"
                show_help
                exit 1
                ;;
        esac
        shift
    done
}

parse_args "$@"

if [[ -z "$BUILD_ARCHITECTURE" ]]; then
    echo "Error: Missing required arguments."
    show_help
    exit 1
fi

# Building mount point and environment if $REPLACE_CONAN_USER_HOME not empty
if [[ -n "$REPLACE_CONAN_USER_HOME" ]]; then
    echo "CONAN_USER_HOME: ${REPLACE_CONAN_USER_HOME}"
    # due safety not mounting user home folder but only .conan
    mkdir -p ${REPLACE_CONAN_USER_HOME}/.conan
    CONAN_USER_HOME_MP="-e CONAN_USER_HOME="${REPLACE_CONAN_USER_HOME}" -v "${REPLACE_CONAN_USER_HOME}/.conan:${REPLACE_CONAN_USER_HOME}/.conan""
fi

echo "BUILD_ARCHITECTURE: $BUILD_ARCHITECTURE"
echo "NCPU: $NCPU"
echo "SKIP_CONFIGURE: $SKIP_CONFIGURE"

if [ "$SKIP_CONFIGURE" = true ]; then
    echo "Skipping configuration step..."
else
    CONFIGURE="cmake --preset ${BUILD_ARCHITECTURE}-release  && "
fi

if [ "$GITHUB_ACTIONS" = true ]; then
    USERID=" -u : "
else
    USERID=" -u $(id -u):$(id -g) "
fi

docker run --rm -t ${INTERACTIVE} ${USERID} -v ${PWD}:/source \
    ${CONAN_USER_HOME_MP} \
    -w /source/prod/native \
    elasticobservability/apm-agent-php-dev:native-build-gcc-12.2.0-${BUILD_ARCHITECTURE}-0.0.2 \
    sh -c "id && echo CONAN_USER_HOME=\$CONAN_USER_HOME && ${CONFIGURE} cmake --build --preset ${BUILD_ARCHITECTURE}-release ${NCPU} && ctest --preset ${BUILD_ARCHITECTURE}-release --verbose"
